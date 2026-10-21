<?php
// require_once (não require): este arquivo pode ser incluído por um teste só para reaproveitar
// as funções abaixo, sem disparar a sincronização — ver o guard no fim do arquivo.
require_once __DIR__ . '/../app/core/bootstrap.php';

/**
 * Sincronização DIÁRIA automática das 5 dimensões do METADADOS (empresas, unidades, setores,
 * cargos, colaboradores) — pensado para rodar via Agendador de Tarefas do Windows (ou cron, numa
 * máquina Linux) na rede Madeplant, na mesma máquina com rota ao SQL Server RHMADEPLANT que hoje
 * roda scripts/sync_metadados_producao.php manualmente.
 *
 * Este arquivo NÃO reimplementa a sincronização — reaproveita scripts/sync_metadados_producao.php
 * inteiro, sem alterar uma linha dele, chamando-o como subprocesso (`--enviar --dimensao=todas`).
 * Só adiciona duas coisas que o uso manual não precisava:
 *
 *   1) LOCK contra execução simultânea, via MetadadosSyncExecucaoRepository::existeEmAndamento() —
 *      a MESMA trava que o botão "sincronizar agora" do Dashboard já usa. É uma trava em banco
 *      (metadados_sync_execucoes), não um lock file local: continua correta mesmo que o agendador
 *      rode em mais de uma máquina por engano.
 *   2) UM REGISTRO PRÓPRIO de início/fim/resultado desta execução agendada, gatilho='cron_diario',
 *      dimensao='todas', na MESMA tabela que o Dashboard já lê para "Última atualização" — nenhuma
 *      tabela nova, nenhuma migration nova.
 *
 * Se o SQL Server estiver indisponível, ou qualquer dimensão falhar, scripts/sync_metadados_producao.php
 * já captura o erro por dimensão e nunca envia lote nenhum para a dimensão que falhou (ver
 * sincronizarDimensao() nele) — este wrapper só reflete esse resultado no registro de execução,
 * nunca reinterpreta ausência de resposta como ausência de colaborador nem força nenhum estado.
 *
 * Uso: php scripts/cron_sincronizar_metadados_diario.php
 * Sem opções — é sempre a sincronização completa (todas as dimensões, envio real). Para simulação
 * ou dimensão isolada, use scripts/sync_metadados_producao.php diretamente.
 */

const CRON_METADADOS_JANELA_LOCK_SEGUNDOS = 900; // mesma janela usada por AdminMetadadosSyncController
const CRON_METADADOS_TIMEOUT_SUBPROCESSO_SEGUNDOS = 1800; // 30 min — teto de segurança, nunca trava o agendador pra sempre

function cronMetadadosUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Roda $scriptPath como subprocesso PHP, com um teto de tempo: se exceder $timeoutSegundos, mata
 * o processo em vez de deixar o agendador preso indefinidamente. Nunca interpreta timeout como
 * sucesso.
 *
 * Importante (compatibilidade Windows), duas armadilhas reais encontradas e corrigidas aqui:
 *   1) pipes de proc_open() no Windows NÃO respeitam stream_set_blocking() — uma leitura
 *      não-bloqueante vira bloqueante na prática. Por isso o polling do timeout usa só
 *      proc_get_status() (nunca lê os pipes) e os pipes só são drenados depois que o processo já
 *      não está mais rodando (terminou sozinho OU foi morto). Limitação aceita conscientemente:
 *      se o subprocesso escrever uma quantidade muito grande de stdout/stderr ANTES de terminar,
 *      o buffer do pipe do SO pode encher e travar a escrita dele até ser drenado — aceitável
 *      aqui porque scripts/sync_metadados_producao.php só imprime um resumo JSON ao final, não um
 *      fluxo contínuo.
 *   2) sem `bypass_shell`, proc_open() no Windows roda o comando através de `cmd.exe /c`, e
 *      proc_terminate() mata só esse `cmd.exe` — o `php.exe` real continua rodando, dono do
 *      timeout nunca força nada. `bypass_shell: true` faz o processo rastreado pelo proc_open()
 *      SER o php.exe de verdade, para proc_terminate() matar o processo certo.
 *
 * @return array{exit_code:int, stdout:string, stderr:string, matou_por_timeout:bool}
 */
function cronMetadadosExecutarSubprocesso(string $phpBinary, string $scriptPath, array $argumentos, int $timeoutSegundos): array
{
    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
    foreach ($argumentos as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $descritores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processo = proc_open($cmd, $descritores, $pipes, dirname($scriptPath), null, ['bypass_shell' => true]);
    if (!is_resource($processo)) {
        throw new RuntimeException('Não foi possível iniciar o subprocesso de sincronização.');
    }

    $inicio = time();
    $matouPorTimeout = false;

    while (true) {
        $status = proc_get_status($processo);
        if (!$status['running']) {
            break;
        }
        if ((time() - $inicio) > $timeoutSegundos) {
            proc_terminate($processo, 9);
            // Dá um instante para o SO liberar as pontas do pipe do lado do filho morto, para a
            // leitura final abaixo não arriscar bloquear.
            usleep(300000);
            $matouPorTimeout = true;
            break;
        }
        usleep(200000); // 200ms
    }

    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    // proc_close() sempre, para liberar o recurso do processo — mas o exit_code só é confiável
    // quando o processo terminou sozinho; morto por timeout, o código real não importa (nunca é
    // sucesso de qualquer forma), fixamos -1.
    $codigoReal = proc_close($processo);
    $exitCode = $matouPorTimeout ? -1 : $codigoReal;

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'matou_por_timeout' => $matouPorTimeout];
}

/** Só executa a rotina principal quando este arquivo é o script chamado diretamente — permite que
 * testes façam require_once nele só para reaproveitar as funções acima, sem disparar a sincronização. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $repo = new MetadadosSyncExecucaoRepository();
        $repo->marcarExpiradas(CRON_METADADOS_JANELA_LOCK_SEGUNDOS);

        if ($repo->existeEmAndamento(CRON_METADADOS_JANELA_LOCK_SEGUNDOS)) {
            Logger::warning('Sincronização diária do METADADOS ignorada: já existe uma em andamento.', ['gatilho' => 'cron_diario']);
            fwrite(STDOUT, 'Já existe uma sincronização em andamento — nada foi iniciado.' . PHP_EOL);
            exit(0);
        }

        $correlacaoId = cronMetadadosUuidV4();
        $repo->criarSolicitacao($correlacaoId, null, 'cron_diario', 'todas');

        $inicio = new DateTimeImmutable();
        $scriptAlvo = __DIR__ . DIRECTORY_SEPARATOR . 'sync_metadados_producao.php';
        $resultado = cronMetadadosExecutarSubprocesso(
            PHP_BINARY,
            $scriptAlvo,
            ['--enviar', '--dimensao=todas'],
            CRON_METADADOS_TIMEOUT_SUBPROCESSO_SEGUNDOS
        );
        $fim = new DateTimeImmutable();

        $saida = json_decode($resultado['stdout'], true);
        $ok = is_array($saida) && (bool)($saida['ok'] ?? false);
        $sucesso = $ok && $resultado['exit_code'] === 0 && !$resultado['matou_por_timeout'];

        $totalRecebidos = 0;
        $origem = null;
        if (is_array($saida) && is_array($saida['resultados'] ?? null)) {
            foreach ($saida['resultados'] as $r) {
                $totalRecebidos += (int)($r['total_registros'] ?? 0);
                if ($origem === null && !empty($r['origem'])) {
                    $origem = (string)$r['origem'];
                }
            }
        }

        $mensagem = null;
        if (!$sucesso) {
            $mensagem = $resultado['matou_por_timeout']
                ? 'Subprocesso de sincronização excedeu o tempo limite (' . CRON_METADADOS_TIMEOUT_SUBPROCESSO_SEGUNDOS . 's) e foi encerrado.'
                : ('exit_code=' . $resultado['exit_code'] . ' stderr=' . $resultado['stderr']);
        }

        $repo->registrarResultado(
            $correlacaoId,
            $sucesso ? MetadadosSyncExecucaoRepository::STATUS_SUCESSO : MetadadosSyncExecucaoRepository::STATUS_FALHA,
            [
                'origem' => $origem,
                'iniciado_em' => $inicio->format('Y-m-d H:i:s'),
                'concluido_em' => $fim->format('Y-m-d H:i:s'),
                'registros_recebidos' => $totalRecebidos,
                'mensagem_tecnica' => $mensagem !== null ? MetadadosSyncExecucaoRepository::sanitizarMensagem($mensagem) : null,
            ]
        );

        echo $resultado['stdout'];
        if ($resultado['stderr'] !== '') {
            fwrite(STDERR, $resultado['stderr']);
        }
        exit($sucesso ? 0 : 1);
    } catch (Throwable $e) {
        Logger::exception($e, 'ERROR', ['script' => 'cron_sincronizar_metadados_diario.php']);
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
