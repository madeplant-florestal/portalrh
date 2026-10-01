<?php

/**
 * Avaliação de Desempenho NATIVA (Etapa 6, 2026-09) — domínio "Avaliações e Desenvolvimento".
 * Implementação limpa: NÃO evolui nem depende do legado `colaborador_avaliacoes`/`AvaliacaoDesempenho`
 * (sobre `colaboradores`/CPF em claro, sem gestor/snapshot/status/ciência — ver auditoria da Etapa 6).
 * O legado continua existindo só em `/admin/avaliacoes` (Cadastros), sem nenhuma relação de código.
 *
 * Escala 1–5 CENTRALIZADA aqui (ESCALA_MIN/ESCALA_MAX/ESCALA_LABELS) — nunca magic number solto em
 * controller/view/repository; trocar a escala no futuro é mudar só estas constantes.
 *
 * `resultado_final` é SEMPRE o parecer explícito do avaliador — nunca calculado a partir da média
 * das notas (§6 da aprovação). Notas/GAPs são só evidência de apoio ao julgamento. Indicadores
 * derivados (`indicadoresDerivados()`) existem só para EXIBIÇÃO — nunca persistidos, nunca viram
 * `resultado_final` automaticamente (§7).
 *
 * Permissão/escopo por linha: MESMO padrão de PdiService/AvaliacaoExperienciaService/FeedbackService
 * (Admin/RH = escopo total; qualquer outro usuário só acessa avaliações em que é o gestor
 * responsável, via `usuarios.gestor_usuario_id`).
 */
class AvaliacaoDesempenhoService
{
    public const ESCALA_MIN = 1;
    public const ESCALA_MAX = 5;
    /** Semântica ÚNICA da escala — evita que cada avaliador interprete 1–5 de forma diferente (§4). */
    public const ESCALA_LABELS = [
        1 => 'Muito abaixo do esperado',
        2 => 'Abaixo do esperado',
        3 => 'Atende ao esperado',
        4 => 'Acima do esperado',
        5 => 'Supera significativamente o esperado',
    ];

    public const RESULTADO_OPCOES = [
        'supera_expectativas' => 'Supera expectativas',
        'atende_expectativas' => 'Atende expectativas',
        'atende_parcialmente' => 'Atende parcialmente',
        'nao_atende' => 'Não atende',
    ];

    public const ROTULOS_STATUS = ['rascunho' => 'Em preenchimento', 'concluido' => 'Concluída', 'cancelado' => 'Cancelada'];

    /** Nº fixo de slots renderizados no formulário (`criterios[n][...]`) — mesma convenção sem-JS de PdiService::MAX_ACOES. */
    public const MAX_CRITERIOS = 10;

    private AvaliacaoDesempenhoRepository $repository;
    private AvaliacoesDesenvolvimentoAuditoriaService $auditoria;

    public function __construct(?AvaliacaoDesempenhoRepository $repository = null, ?AvaliacoesDesenvolvimentoAuditoriaService $auditoria = null)
    {
        $this->repository = $repository ?? new AvaliacaoDesempenhoRepository();
        $this->auditoria = $auditoria ?? new AvaliacoesDesenvolvimentoAuditoriaService();
    }

    // ============================================================ permissão / escopo (mesmo padrão do módulo)

    public static function atorDaSessao(): array
    {
        return ['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => strtolower(trim((string)($_SESSION['user_role'] ?? '')))];
    }

    public static function escopoTotal(array $ator): bool
    {
        return in_array((string)($ator['role'] ?? ''), ['admin', 'rh'], true);
    }

    public static function escopoGestor(array $ator): ?int
    {
        return self::escopoTotal($ator) ? null : (int)$ator['id'];
    }

    public static function podeAcessar(array $avaliacao, array $ator): bool
    {
        return (int)($ator['id'] ?? 0) > 0 && (self::escopoTotal($ator) || (int)$avaliacao['gestor_usuario_id'] === (int)$ator['id']);
    }

    private static function temPermissao(array $ator, string $permissao): bool
    {
        return (int)($ator['id'] ?? 0) > 0 && Authorization::usuarioTemPermissao((int)$ator['id'], $permissao);
    }

    private static function semPermissao(array $ator, string ...$permissoes): ?array
    {
        foreach ($permissoes as $p) {
            if (self::temPermissao($ator, $p)) {
                return null;
            }
        }
        return self::falha('Você não tem permissão para esta ação.');
    }

    private static function falha(array|string $mensagens): array
    {
        $lista = array_values((array)$mensagens);
        return ['ok' => false, 'error' => (string)($lista[0] ?? 'Erro.'), 'erros' => $lista];
    }

    private function avaliacaoAcessivel(int $id, array $ator): ?array
    {
        $avaliacao = $this->repository->buscarPorId($id);
        return ($avaliacao !== null && self::podeAcessar($avaliacao, $ator)) ? $avaliacao : null;
    }

    // ============================================================ indicadores derivados (§7 — nunca persistidos)

    /**
     * @param array $criterios linhas de avaliacoes_desempenho_criterios
     * @return array{media_nota_atual:?float,media_nota_esperada:?float,qtd_com_gap:int,maior_gap:int,percentual_atendidos:?float}
     */
    public static function indicadoresDerivados(array $criterios): array
    {
        $atuais = [];
        $esperadas = [];
        $qtdComGap = 0;
        $maiorGap = 0;
        $qtdAtendidos = 0;
        $qtdComNotaEsperada = 0;
        foreach ($criterios as $c) {
            $atual = $c['nota_atual'] !== null ? (int)$c['nota_atual'] : null;
            $esperada = $c['nota_esperada'] !== null ? (int)$c['nota_esperada'] : null;
            if ($atual !== null) {
                $atuais[] = $atual;
            }
            if ($esperada !== null) {
                $esperadas[] = $esperada;
            }
            if ($atual !== null && $esperada !== null) {
                $gap = $esperada - $atual;
                $qtdComNotaEsperada++;
                if ($gap > 0) {
                    $qtdComGap++;
                    $maiorGap = max($maiorGap, $gap);
                } else {
                    $qtdAtendidos++;
                }
            }
        }
        return [
            'media_nota_atual' => $atuais !== [] ? round(array_sum($atuais) / count($atuais), 1) : null,
            'media_nota_esperada' => $esperadas !== [] ? round(array_sum($esperadas) / count($esperadas), 1) : null,
            'qtd_com_gap' => $qtdComGap,
            'maior_gap' => $maiorGap,
            'percentual_atendidos' => $qtdComNotaEsperada > 0 ? round(($qtdAtendidos / $qtdComNotaEsperada) * 100, 1) : null,
        ];
    }

    // ============================================================ ciclo de vida

    public function novoParaContrato(int $metadadosId, array $ator): array
    {
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return self::falha('Contrato não encontrado.');
        }
        $gestor = (new UsuarioGestorRepository())->gestorDoUsuarioDoContrato($metadadosId);
        return ['ok' => true, 'contrato' => $contrato, 'gestor' => $gestor, 'avaliacoesAnteriores' => $this->repository->buscarPorContrato($metadadosId)];
    }

    public function buscarContratos(string $busca): array
    {
        return $this->repository->buscarContratos($busca, (new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    public function usuariosParaEscolha(array $ator): array
    {
        return self::escopoTotal($ator) ? $this->repository->usuariosAtivos() : [];
    }

    /**
     * Normaliza os critérios do POST — `criterios[n][competencia_texto|nota_atual|nota_esperada|comentario]`,
     * `n` de 1 a MAX_CRITERIOS (mesma convenção sem-JS de slots fixos de PdiService::MAX_ACOES). Slots com
     * `competencia_texto` vazio são descartados (linha não usada).
     */
    private function normalizarCriterios(array $dados): array
    {
        $brutos = (array)($dados['criterios'] ?? []);
        $linhas = [];
        for ($n = 1; $n <= self::MAX_CRITERIOS; $n++) {
            $linha = (array)($brutos[$n] ?? []);
            $texto = trim((string)($linha['competencia_texto'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $comentario = trim((string)($linha['comentario'] ?? ''));
            $linhas[] = [
                'competencia_texto' => $texto,
                'competencia_id' => null,
                'nota_atual' => $this->notaValida($linha['nota_atual'] ?? null),
                'nota_esperada' => $this->notaValida($linha['nota_esperada'] ?? null),
                'comentario' => $comentario !== '' ? $comentario : null,
            ];
        }
        return $linhas;
    }

    private function notaValida(mixed $bruto): ?int
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }
        $n = (string)$bruto;
        if (!ctype_digit($n)) {
            return null;
        }
        $n = (int)$n;
        return ($n >= self::ESCALA_MIN && $n <= self::ESCALA_MAX) ? $n : null;
    }

    public function criar(array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_desempenho.avaliar')) {
            return $erro;
        }
        $metadadosId = (int)($dados['metadados_id'] ?? 0);
        if ($metadadosId <= 0) {
            return self::falha('Selecione um colaborador.');
        }
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return self::falha('Contrato não encontrado.');
        }
        $ciclo = trim((string)($dados['ciclo'] ?? ''));
        if ($ciclo === '') {
            return self::falha('Informe o ciclo/período da avaliação.');
        }
        $periodoInicio = $dados['periodo_inicio'] ?? '';
        $periodoFim = $dados['periodo_fim'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$periodoInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$periodoFim)) {
            return self::falha('Informe o período de início e fim da avaliação.');
        }
        if ($periodoFim < $periodoInicio) {
            return self::falha('O período fim não pode ser anterior ao período início.');
        }
        $gestorUsuarioId = (int)($dados['gestor_usuario_id'] ?? $ator['id']);
        $gestorUsuario = self::escopoTotal($ator) ? $this->repository->usuarioAtivo($gestorUsuarioId) : $this->repository->usuarioAtivo((int)$ator['id']);
        if ($gestorUsuario === null) {
            return self::falha('Selecione um gestor responsável válido.');
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($contrato, $ciclo, $periodoInicio, $periodoFim, $dados, $gestorUsuario, $ator, $ip, $agoraSql): array {
            $id = $this->repository->inserir([
                'metadados_id' => (int)$contrato['metadados_id'],
                'snap_nome' => (string)$contrato['nome'], 'snap_codigo_empresa' => (string)$contrato['codigo_empresa'],
                'snap_empresa' => $contrato['empresa'], 'snap_codigo_unidade' => (string)$contrato['codigo_unidade'],
                'snap_unidade' => $contrato['unidade'], 'snap_codigo_setor' => $contrato['codigo_setor'], 'snap_setor' => $contrato['setor'],
                'snap_codigo_cargo' => $contrato['codigo_cargo'], 'snap_cargo' => $contrato['cargo'],
                'snap_admissao' => (string)$contrato['admissao'],
                'gestor_usuario_id' => (int)$gestorUsuario['id'], 'gestor_nome_snapshot' => (string)$gestorUsuario['nome'],
                'ciclo' => $ciclo, 'periodo_inicio' => $periodoInicio, 'periodo_fim' => $periodoFim,
                'status' => 'rascunho',
                'pontos_fortes' => $this->textoOuNull($dados['pontos_fortes'] ?? null),
                'gaps_identificados' => $this->textoOuNull($dados['gaps_identificados'] ?? null),
                'plano_acao_sugerido' => $this->textoOuNull($dados['plano_acao_sugerido'] ?? null),
                'criado_por_usuario_id' => (int)$ator['id'], 'criado_em' => $agoraSql, 'atualizado_em' => $agoraSql,
            ]);
            $this->repository->salvarCriterios($id, $this->normalizarCriterios($dados));
            $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'criacao', null, null, 'rascunho', $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    public function atualizar(int $id, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_desempenho.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->avaliacaoAcessivel($id, $ator);
        if ($avaliacao === null) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] !== 'rascunho') {
            return self::falha('Esta avaliação não está mais em preenchimento — peça a um RH/Admin para reabrir antes de editar.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $dados, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, [
                'pontos_fortes' => $this->textoOuNull($dados['pontos_fortes'] ?? null),
                'gaps_identificados' => $this->textoOuNull($dados['gaps_identificados'] ?? null),
                'plano_acao_sugerido' => $this->textoOuNull($dados['plano_acao_sugerido'] ?? null),
                'atualizado_em' => $agoraSql,
            ]);
            $this->repository->salvarCriterios($id, $this->normalizarCriterios($dados));
            $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'atualizacao_rascunho', null, null, null, $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    /**
     * Conclusão exige (§11): vínculo/gestor/ciclo/período já garantidos na criação; pelo menos um
     * critério preenchido (com nota_atual); resultado_final; data_realizacao (a própria conclusão).
     * Comentários (pontos_fortes/gaps/plano_acao/parecer/comentário por critério) são SEMPRE opcionais.
     */
    public function concluir(int $id, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_desempenho.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->avaliacaoAcessivel($id, $ator);
        if ($avaliacao === null) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] === 'concluido') {
            return self::falha('Esta avaliação já está concluída.');
        }
        if ((string)$avaliacao['status'] === 'cancelado') {
            return self::falha('Esta avaliação foi cancelada e não pode ser concluída.');
        }
        $resultado = (string)($dados['resultado_final'] ?? '');
        if (!array_key_exists($resultado, self::RESULTADO_OPCOES)) {
            return self::falha('Selecione o Resultado Final.');
        }
        // Os critérios já foram salvos via "Salvar rascunho" — o formulário de encerramento só envia
        // resultado_final/parecer_comentario (nunca reenvia criterios[]), então a validação e a
        // persistência aqui usam o que JÁ ESTÁ no banco, nunca $dados (evita apagar critérios/pontos
        // fortes/GAPs/plano de ação ao concluir com um POST que não os carrega).
        $criteriosExistentes = $this->repository->buscarCriterios($id);
        $comAlgumaNota = array_filter($criteriosExistentes, static fn(array $c): bool => $c['nota_atual'] !== null);
        if ($comAlgumaNota === []) {
            return self::falha('Preencha a nota de pelo menos um critério antes de concluir.');
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $dados, $resultado, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, [
                'resultado_final' => $resultado,
                'parecer_comentario' => $this->textoOuNull($dados['parecer_comentario'] ?? null),
                'status' => 'concluido', 'data_realizacao' => $agoraSql,
                'atualizado_em' => $agoraSql,
            ]);
            $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'conclusao', 'resultado_final', null, $resultado, $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    public function cancelar(int $id, string $motivo, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_desempenho.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->avaliacaoAcessivel($id, $ator);
        if ($avaliacao === null) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] !== 'rascunho') {
            return self::falha('Só uma avaliação em preenchimento pode ser cancelada.');
        }
        $texto = trim($motivo);
        if ($texto === '') {
            return self::falha('Informe o motivo do cancelamento.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $texto, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, ['status' => 'cancelado', 'atualizado_em' => $agoraSql]);
            $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'cancelamento', 'status', 'rascunho', 'cancelado — ' . $texto, $ator, $ip, $agoraSql);
            return ['ok' => true];
        });
    }

    public function registrarCienciaColaborador(int $id, string $nomeColaborador, array $ator, DateTimeImmutable $agora): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_desempenho.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->avaliacaoAcessivel($id, $ator);
        if ($avaliacao === null) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] !== 'concluido') {
            return self::falha('Só é possível registrar ciência de uma avaliação concluída.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->auditoria->registrarCiencia('avaliacao_desempenho', $id, 'colaborador', null, $nomeColaborador, $agoraSql);
        $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'ciencia_colaborador', null, null, $agoraSql, $ator, null, $agoraSql);
        return ['ok' => true];
    }

    /** Só Admin/RH, sempre com justificativa registrada em evento — mesmo padrão de PDI/Experiência/Feedback. */
    public function reabrir(int $id, string $justificativa, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if (!self::escopoTotal($ator) || self::temPermissao($ator, 'avaliacao_desempenho.avaliar') === false) {
            return self::falha('Só Admin/RH podem reabrir uma avaliação concluída.');
        }
        $avaliacao = $this->repository->buscarPorId($id);
        if ($avaliacao === null) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] !== 'concluido') {
            return self::falha('Só uma avaliação concluída pode ser reaberta.');
        }
        $texto = trim($justificativa);
        if ($texto === '') {
            return self::falha('Informe a justificativa para reabrir.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $texto, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, ['status' => 'rascunho', 'data_realizacao' => null, 'resultado_final' => null, 'atualizado_em' => $agoraSql]);
            $this->auditoria->registrarEvento('avaliacao_desempenho', $id, 'reabertura', 'status', 'concluido', 'rascunho — ' . $texto, $ator, $ip, $agoraSql);
            return ['ok' => true];
        });
    }

    public function detalhe(int $id, array $ator): ?array
    {
        $avaliacao = $this->repository->buscarPorId($id);
        if ($avaliacao === null || !self::podeAcessar($avaliacao, $ator)) {
            return null;
        }
        $criterios = $this->repository->buscarCriterios($id);
        return [
            'avaliacao' => $avaliacao,
            'criterios' => $criterios,
            'indicadores' => self::indicadoresDerivados($criterios),
            'ciencias' => $this->auditoria->listarCiencias('avaliacao_desempenho', $id),
            'eventos' => $this->auditoria->listarEventos('avaliacao_desempenho', $id),
        ];
    }

    public function listar(array $filtros, array $ator): array
    {
        return $this->repository->listar($filtros, self::escopoGestor($ator), 200);
    }

    /**
     * Candidatos a "necessidade de desenvolvimento" para alimentar um PDI (Etapa 7, §12/§13) — só avaliações
     * CONCLUÍDAS, no escopo do ator. GAP continua DERIVADO (nota_esperada - nota_atual): aparece só como
     * indicador no texto do item, nunca é recalculado/persistido aqui. Nunca altera a avaliação original.
     *
     * @return array{metadados_id:int,snap_nome:string,gestor_usuario_id:int,itens:array<int,array{chave:string,texto:string}>}|null
     */
    public function candidatosParaPdi(int $id, array $ator): ?array
    {
        $d = $this->detalhe($id, $ator);
        if ($d === null || (string)$d['avaliacao']['status'] !== 'concluido') {
            return null;
        }
        $av = $d['avaliacao'];
        $itens = [];
        foreach ($d['criterios'] as $c) {
            $atual = $c['nota_atual'] !== null ? (int)$c['nota_atual'] : null;
            $esperada = $c['nota_esperada'] !== null ? (int)$c['nota_esperada'] : null;
            if ($atual !== null && $esperada !== null && $esperada > $atual) {
                $comentario = trim((string)($c['comentario'] ?? ''));
                $texto = (string)$c['competencia_texto'] . ' — GAP ' . ($esperada - $atual) . ' (nota atual ' . $atual . ', esperada ' . $esperada . ')';
                $itens[] = ['chave' => 'criterio_' . $c['id'], 'texto' => $texto . ($comentario !== '' ? ': ' . $comentario : '')];
            }
        }
        if (trim((string)($av['gaps_identificados'] ?? '')) !== '') {
            $itens[] = ['chave' => 'gaps_identificados', 'texto' => 'GAPs identificados: ' . trim((string)$av['gaps_identificados'])];
        }
        if (trim((string)($av['plano_acao_sugerido'] ?? '')) !== '') {
            $itens[] = ['chave' => 'plano_acao_sugerido', 'texto' => 'Plano de ação sugerido: ' . trim((string)$av['plano_acao_sugerido'])];
        }
        if (trim((string)($av['parecer_comentario'] ?? '')) !== '') {
            $rotuloResultado = self::RESULTADO_OPCOES[$av['resultado_final']] ?? (string)$av['resultado_final'];
            $itens[] = ['chave' => 'parecer', 'texto' => 'Parecer (' . $rotuloResultado . '): ' . trim((string)$av['parecer_comentario'])];
        }
        return [
            'metadados_id' => (int)$av['metadados_id'],
            'snap_nome' => (string)$av['snap_nome'],
            'gestor_usuario_id' => (int)$av['gestor_usuario_id'],
            'itens' => $itens,
        ];
    }

    private function textoOuNull(mixed $v): ?string
    {
        $t = trim((string)$v);
        return $t !== '' ? $t : null;
    }
}
