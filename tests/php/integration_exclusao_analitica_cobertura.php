<?php

/**
 * Cobertura ESTÁTICA da exclusão analítica GLOBAL (contratos marcados em
 * colaboradores_metadados_exclusoes_analiticas nunca entram em listagem, contagem, média, filtro,
 * exportação nem relatório do RH — ver ColaboradorMetadadosElegibilidadeService).
 *
 * Varre app/ e, para cada `FROM|JOIN` sobre `colaboradores_metadados` OU sobre uma tabela de módulo
 * que guarda o vínculo em `metadados_id`, exige que a FUNÇÃO que contém a consulta:
 *   (a) use a cláusula central (clausulaSqlExclusao / clausulaSqlExclusaoPorMetadadosId), OU
 *   (b) chame um helper do MESMO arquivo que, ele próprio, aplica a cláusula (ex.: montarFiltros(),
 *       condicoesContrato(), filtroSnapshot()) — o helper é verificado, não presumido, OU
 *   (c) esteja na lista EXPLÍCITA de exceções abaixo, cada uma com o motivo.
 * Uma consulta nova que esqueça a exclusão faz este teste FALHAR, em vez de reabrir a divergência
 * entre módulos.
 *
 * Exceções legítimas: sincronização/reconciliação do METADADOS (precisam ver TODOS os contratos);
 * busca PONTUAL por id/token (administração, autenticação, vínculo de Usuários, edição de um
 * registro já aberto — a rastreabilidade administrativa continua possível); constantes de consulta
 * cujo filtro é aplicado nos pontos de uso (verificados pelas funções que as usam).
 */

$raiz = realpath(__DIR__ . '/../../app');
$tabelas = [
    'colaboradores_metadados', 'pdis', 'avaliacoes_experiencia', 'avaliacoes_desempenho',
    'entrevistas_desligamento', 'entrevista_desligamento_liberacoes', 'feedbacks', 'pesquisas_integracao',
];

/** "arquivo::função" (ou "arquivo::*") => motivo. "(const)" = constante de classe cujo filtro é aplicado nos pontos de uso. */
$excecoes = [
    // --- sincronização, reconciliação e administração do espelho ---
    'services/MetadadosSyncService.php::*' => 'sincronização do METADADOS (vê todos os contratos)',
    'repositories/ColaboradorMetadadosRepository.php::*' => 'upsert/reconciliação do sincronizador',
    'services/ColaboradorMetadadosReconciliationService.php::*' => 'reconciliação local×METADADOS (análise administrativa)',
    'services/ColaboradorMetadadosLinkService.php::*' => 'vínculo local×METADADOS (administrativo)',
    'services/ColaboradorExtensaoLocalService.php::*' => 'materialização da extensão local por id (administrativo)',
    'services/ColaboradorMetadadosElegibilidadeService.php::*' => 'o próprio mecanismo de exclusão (cadastro/auditoria das exclusões)',
    'core/SchemaManager.php::*' => 'manutenção de schema',
    // --- busca PONTUAL por id/token (administração, autenticação, registro já aberto) ---
    'models/User.php::vincularMetadados' => 'valida existência do contrato ao vincular um usuário (por id, administrativo)',
    'services/UsuarioContextoOrganizacionalService.php::resolverContextoOficial' => 'herança de setor/cargo do vínculo do usuário (por id; não quebra login)',
    'models/Colaborador.php::mesclarComEspelhoOficial' => 'mescla o espelho de UM contrato por id (cadastro local)',
    'repositories/ColaboradorMetadadosConsultaRepository.php::findById' => 'um contrato por id (administração de Usuários / auditoria)',
    'repositories/AvaliacaoDesempenhoRepository.php::buscarContrato' => 'contrato por id (registro já aberto)',
    'repositories/AvaliacaoDesempenhoRepository.php::buscarPorId' => 'registro por id',
    'repositories/AvaliacaoDesempenhoRepository.php::buscarPorContrato' => 'registros de UM contrato já escolhido',
    'repositories/AvaliacaoExperienciaRepository.php::buscarContrato' => 'contrato por id (registro já aberto)',
    'repositories/AvaliacaoExperienciaRepository.php::buscarAvaliacao' => 'registro de UM contrato/tipo já escolhido',
    'repositories/AvaliacaoExperienciaRepository.php::buscarPorId' => 'registro por id',
    'repositories/AvaliacaoExperienciaRepository.php::codigoSetorAtual' => 'setor atual de UM contrato (checagem de acesso por id)',
    'repositories/FeedbackRepository.php::buscarContrato' => 'contrato por id (registro já aberto)',
    'repositories/FeedbackRepository.php::buscarPorId' => 'registro por id',
    'repositories/PdiRepository.php::buscarPdi' => 'PDI por id (registro já aberto)',
    'repositories/PdiRepository.php::pdisPorOrigem' => 'navegação reversa de UM documento de origem',
    'repositories/PdiRepository.php::pdisDoContrato' => 'PDIs de UM contrato já escolhido',
    'repositories/EntrevistaDesligamentoRepository.php::buscarLiberacao' => 'liberação de UM contrato por id',
    'repositories/EntrevistaDesligamentoRepository.php::buscarPorMetadadosId' => 'entrevista de UM contrato por id',
    'repositories/EntrevistaDesligamentoRepository.php::buscarPorHash' => 'fluxo público por token da entrevista',
    'models/PesquisaIntegracao.php::findByColaboradorEData' => 'pesquisa de UM colaborador/data (fluxo individual)',
    'models/PesquisaIntegracao.php::find' => 'pesquisa por id',
    'models/PesquisaIntegracao.php::findByRawToken' => 'fluxo público por token da pesquisa',
    'models/PesquisaIntegracaoQr.php::buscarPesquisaDoEvento' => 'checagem de duplicidade de UM contrato/evento',
    'models/PesquisaIntegracaoQr.php::contratosPorIds' => 'nome/cargo de respondentes já listados (por id)',
    // --- constantes de consulta: o filtro é aplicado onde são usadas (verificado pelas funções que as usam) ---
    'models/PesquisaIntegracaoQr.php::(const)' => 'SELECT_CONTRATO: filtro aplicado em contratosAtivosPorCpfENascimento()/contratoAtivoPorId()',
    'repositories/DashboardIntegracaoRepository.php::(const)' => 'SELECT_BASE: filtro aplicado em montarWhere()',
    'repositories/EntrevistaDesligamentoRepository.php::(const)' => 'SELECT_CONTRATO/FROM_OPERACIONAL: filtro em condicoesElegiveis()/listarEntrevistas(); usos por id são pontuais',
    'repositories/PdiRepository.php::(const)' => 'SELECT_CONTRATO: filtro em buscarContratos(); buscarContrato() é por id',
    // --- fora desta entrega (decisão pendente com o RH) ---
    'models/MovimentacaoPessoal.php::*' => 'módulo Movimentação de Pessoal (cadastro local) — fora do escopo desta entrega',
];

$falhas = [];
$achados = 0;

$ls = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
$arquivos = [];
foreach ($ls as $arquivo) {
    if ($arquivo->getExtension() === 'php') {
        $arquivos[str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1))] = $arquivo->getPathname();
    }
}

foreach ($arquivos as $rel => $path) {
    $linhas = preg_split('/\R/', (string)file_get_contents($path));

    $funcoes = [];
    foreach ($linhas as $i => $l) {
        if (preg_match('/^\s*(?:(?:public|private|protected|static|final)\s+)*function\s+(\w+)\s*\(/', $l, $m)) {
            $funcoes[] = ['ini' => $i, 'nome' => $m[1]];
        }
    }
    foreach ($funcoes as $k => $f) {
        $funcoes[$k]['fim'] = isset($funcoes[$k + 1]) ? $funcoes[$k + 1]['ini'] - 1 : count($linhas) - 1;
        $funcoes[$k]['corpo'] = implode("\n", array_slice($linhas, $f['ini'], $funcoes[$k]['fim'] - $f['ini'] + 1));
    }
    // Helpers que, eles mesmos, aplicam a cláusula (verificados, não presumidos).
    $aplicadoras = [];
    foreach ($funcoes as $f) {
        if (str_contains($f['corpo'], 'clausulaSqlExclusao')) {
            $aplicadoras[] = $f['nome'];
        }
    }

    foreach ($linhas as $i => $l) {
        foreach ($tabelas as $t) {
            if (!preg_match('/\b(?:FROM|JOIN)\s+' . preg_quote($t, '/') . '(?![A-Za-z0-9_])/i', $l)) {
                continue;
            }
            if (preg_match('/^\s*(\*|\/\/|#)/', $l)) {
                continue; // comentário
            }
            $achados++;

            $fn = null;
            foreach ($funcoes as $f) {
                if ($i >= $f['ini'] && $i <= $f['fim']) {
                    $fn = $f;
                    break;
                }
            }
            $nome = $fn['nome'] ?? '(const)';
            $corpo = $fn['corpo'] ?? $l;

            if (isset($excecoes["{$rel}::*"]) || isset($excecoes["{$rel}::{$nome}"])) {
                continue; // exceção justificada
            }
            if (str_contains($corpo, 'clausulaSqlExclusao')) {
                continue; // (a)
            }
            $viaHelper = false;
            foreach ($aplicadoras as $h) {
                if ($h !== $nome && preg_match('/(?:->|::)' . preg_quote($h, '/') . '\s*\(/', $corpo)) {
                    $viaHelper = true; // (b)
                    break;
                }
            }
            if ($viaHelper) {
                continue;
            }
            $falhas[] = sprintf('%s:%d  %s() lê %s sem a exclusão analítica', $rel, $i + 1, $nome, $t);
        }
    }
}

// Cada exceção precisa continuar apontando para algo que existe (evita lista de exceções morta).
foreach ($excecoes as $chave => $motivo) {
    [$arq, $fn] = explode('::', $chave, 2);
    if (!isset($arquivos[$arq])) {
        $falhas[] = "exceção aponta para arquivo inexistente: {$chave}";
    }
}

echo "Pontos de leitura analisados: {$achados}\n";
if ($falhas !== []) {
    fwrite(STDERR, "\nPontos SEM exclusão analítica (corrija ou, se for exceção legítima, registre com motivo em \$excecoes):\n");
    foreach ($falhas as $f) {
        fwrite(STDERR, "  [FALHOU] {$f}\n");
    }
    fwrite(STDERR, "\n" . count($falhas) . " ponto(s) sem cobertura.\n");
    exit(1);
}
echo "\nEXCLUSAO_ANALITICA_COBERTURA_OK\n";
