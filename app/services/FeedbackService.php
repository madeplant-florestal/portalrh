<?php

/**
 * Formulário de Feedback e Desenvolvimento (Etapa 4, 2026-09). Estrutura OFICIAL preservada — não
 * confundir com a Avaliação de Experiência: escalas diferentes (Atende / Desenvolvimento Necessário,
 * nunca 1–5), textos de apoio próprios por valor cultural.
 *
 * Comentário obrigatório SOMENTE quando o gestor marca "Desenvolvimento Necessário" (§34) — validado
 * aqui no backend (nunca confia só no frontend) e também via CHECK no banco
 * (chk_feedback_valores_comentario).
 *
 * "Próximos passos" pode futuramente alimentar um PDI, mas NUNCA automaticamente nesta rodada (§41) —
 * a criação de PDI a partir de um Feedback fica para quando o usuário confirmar explicitamente, uma
 * frente futura (o schema do PDI já reserva `origem_tipo = 'feedback'` para isso).
 */
class FeedbackService
{
    public const TIPOS = [
        'reconhecimento' => 'Reconhecimento', 'desenvolvimento' => 'Desenvolvimento',
        'alinhamento' => 'Alinhamento', 'acompanhamento' => 'Acompanhamento',
    ];

    public const VALORES_CULTURAIS = [
        'respeito' => [
            'label' => 'Respeito',
            'descricao' => 'Demonstra consideração pelas pessoas, mantém relacionamentos profissionais saudáveis e valoriza diferentes opiniões e perspectivas.',
            'comportamentos' => [
                'Trata as pessoas com educação e cordialidade', 'Escuta opiniões e pontos de vista diferentes',
                'Mantém postura profissional mesmo em situações de divergência', 'Colabora com a equipe',
            ],
        ],
        'honestidade' => [
            'label' => 'Honestidade',
            'descricao' => 'Age com transparência, sinceridade e coerência em suas atitudes e comunicações.',
            'comportamentos' => [
                'Comunica informações de forma clara e verdadeira', 'Assume responsabilidades por suas ações',
                'Reconhece erros quando necessário', 'Age de forma coerente entre discurso e prática',
            ],
        ],
        'lealdade' => [
            'label' => 'Lealdade',
            'descricao' => 'Demonstra comprometimento com a equipe, a empresa e os objetivos organizacionais.',
            'comportamentos' => [
                'Cumpre compromissos assumidos', 'Atua alinhado aos objetivos da empresa',
                'Contribui para um ambiente de confiança', 'Preserva a imagem e os interesses da organização',
            ],
        ],
        'etica' => [
            'label' => 'Ética',
            'descricao' => 'Age de acordo com os princípios, normas e valores da empresa, promovendo relações íntegras e responsáveis.',
            'comportamentos' => [
                'Cumpre normas e procedimentos', 'Age com responsabilidade e imparcialidade',
                'Respeita a confidencialidade das informações', 'Toma decisões corretas mesmo diante de situações difíceis',
            ],
        ],
        'coragem' => [
            'label' => 'Coragem',
            'descricao' => 'Enfrenta desafios com responsabilidade, posiciona-se quando necessário e busca soluções diante das dificuldades.',
            'comportamentos' => [
                'Assume responsabilidades', 'Enfrenta situações desafiadoras de forma construtiva',
                'Propõe soluções para problemas', 'Demonstra segurança ao expressar opiniões e ideias',
            ],
        ],
        'ousadia' => [
            'label' => 'Ousadia',
            'descricao' => 'Busca novas possibilidades, propõe melhorias e contribui para a evolução contínua da empresa.',
            'comportamentos' => [
                'Apresenta sugestões de melhoria', 'Demonstra iniciativa',
                'Busca formas mais eficientes de realizar as atividades', 'Está aberto a mudanças e novas ideias',
            ],
        ],
    ];

    public const AVALIACAO_OPCOES = ['atende' => 'Atende', 'desenvolvimento_necessario' => 'Desenvolvimento Necessário'];
    public const RESULTADO_OPCOES = [
        'reconhecido_alinhado' => 'Reconhecido e alinhado às expectativas',
        'em_desenvolvimento' => 'Em desenvolvimento',
        'necessita_acompanhamento' => 'Necessita acompanhamento específico',
    ];

    private FeedbackRepository $repository;
    private AvaliacoesDesenvolvimentoAuditoriaService $auditoria;

    public function __construct(?FeedbackRepository $repository = null, ?AvaliacoesDesenvolvimentoAuditoriaService $auditoria = null)
    {
        $this->repository = $repository ?? new FeedbackRepository();
        $this->auditoria = $auditoria ?? new AvaliacoesDesenvolvimentoAuditoriaService();
    }

    // ============================================================ permissão / escopo (mesmo padrão do PDI/Experiência)

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

    public static function podeAcessar(array $feedback, array $ator): bool
    {
        return (int)($ator['id'] ?? 0) > 0 && (self::escopoTotal($ator) || (int)$feedback['gestor_usuario_id'] === (int)$ator['id']);
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

    private function feedbackAcessivel(int $id, array $ator): ?array
    {
        $feedback = $this->repository->buscarPorId($id);
        return ($feedback !== null && self::podeAcessar($feedback, $ator)) ? $feedback : null;
    }

    // ============================================================ validação dos valores culturais (§34/§63)

    /** @return array{ok:bool,error?:string,linhas?:array} */
    private function validarValores(array $dados): array
    {
        $linhas = [];
        foreach (array_keys(self::VALORES_CULTURAIS) as $valor) {
            $avaliacao = $dados['valor_' . $valor] ?? '';
            $avaliacao = array_key_exists($avaliacao, self::AVALIACAO_OPCOES) ? $avaliacao : null;
            $comentario = trim((string)($dados['comentario_' . $valor] ?? ''));
            if ($avaliacao === 'desenvolvimento_necessario' && $comentario === '') {
                return ['ok' => false, 'error' => 'Comentário obrigatório para "' . self::VALORES_CULTURAIS[$valor]['label'] . '" quando marcado como Desenvolvimento Necessário.'];
            }
            $linhas[] = [$valor, $avaliacao, $comentario !== '' ? $comentario : null];
        }
        return ['ok' => true, 'linhas' => $linhas];
    }

    // ============================================================ ciclo de vida

    public function contratoParaCriacao(int $metadadosId, array $ator): array
    {
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return self::falha('Contrato não encontrado.');
        }
        $gestor = (new UsuarioGestorRepository())->gestorDoUsuarioDoContrato($metadadosId);
        return ['ok' => true, 'contrato' => $contrato, 'gestor' => $gestor];
    }

    public function buscarContratos(string $busca): array
    {
        return $this->repository->buscarContratos($busca, (new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    public function usuariosParaEscolha(array $ator): array
    {
        return self::escopoTotal($ator) ? $this->repository->usuariosAtivos() : [];
    }

    public function criar(array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'feedback.avaliar')) {
            return $erro;
        }
        $metadadosId = (int)($dados['metadados_id'] ?? 0);
        if ($metadadosId <= 0) {
            return self::falha('Selecione um colaborador.');
        }
        $tipo = (string)($dados['tipo'] ?? '');
        if (!array_key_exists($tipo, self::TIPOS)) {
            return self::falha('Selecione um tipo de feedback válido.');
        }
        $dataFeedback = $dados['data_feedback'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dataFeedback)) {
            return self::falha('Informe a data do feedback.');
        }
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return self::falha('Contrato não encontrado.');
        }
        $gestorUsuarioId = (int)($dados['gestor_usuario_id'] ?? $ator['id']);
        $gestorUsuario = self::escopoTotal($ator) ? $this->repository->usuarioAtivo($gestorUsuarioId) : $this->repository->usuarioAtivo((int)$ator['id']);
        if ($gestorUsuario === null) {
            return self::falha('Selecione um gestor responsável válido.');
        }

        $validacao = $this->validarValores($dados);
        if (!($validacao['ok'] ?? false)) {
            return $validacao;
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($contrato, $tipo, $dataFeedback, $dados, $gestorUsuario, $validacao, $ator, $ip, $agoraSql): array {
            $id = $this->repository->inserir([
                'metadados_id' => (int)$contrato['metadados_id'],
                'snap_nome' => (string)$contrato['nome'], 'snap_codigo_empresa' => (string)$contrato['codigo_empresa'],
                'snap_empresa' => $contrato['empresa'], 'snap_codigo_unidade' => (string)$contrato['codigo_unidade'],
                'snap_unidade' => $contrato['unidade'], 'snap_codigo_setor' => $contrato['codigo_setor'], 'snap_setor' => $contrato['setor'],
                'snap_codigo_cargo' => $contrato['codigo_cargo'], 'snap_cargo' => $contrato['cargo'],
                'gestor_usuario_id' => (int)$gestorUsuario['id'], 'gestor_nome_snapshot' => (string)$gestorUsuario['nome'],
                'tipo' => $tipo, 'data_feedback' => $dataFeedback, 'status' => 'rascunho',
                'pontos_fortes' => $this->textoOuNull($dados['pontos_fortes'] ?? null),
                'pontos_desenvolvimento' => $this->textoOuNull($dados['pontos_desenvolvimento'] ?? null),
                'proximos_passos' => $this->textoOuNull($dados['proximos_passos'] ?? null),
                'criado_por_usuario_id' => (int)$ator['id'], 'criado_em' => $agoraSql, 'atualizado_em' => $agoraSql,
            ]);
            $this->repository->salvarValores($id, $validacao['linhas']);
            $this->auditoria->registrarEvento('feedback', $id, 'criacao', null, null, 'rascunho', $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    public function atualizar(int $id, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'feedback.avaliar')) {
            return $erro;
        }
        $feedback = $this->feedbackAcessivel($id, $ator);
        if ($feedback === null) {
            return self::falha('Feedback não encontrado.');
        }
        if ((string)$feedback['status'] === 'concluido') {
            return self::falha('Este feedback já foi concluído. Peça a um RH/Admin para reabrir antes de editar.');
        }
        $validacao = $this->validarValores($dados);
        if (!($validacao['ok'] ?? false)) {
            return $validacao;
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $dados, $validacao, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, [
                'pontos_fortes' => $this->textoOuNull($dados['pontos_fortes'] ?? null),
                'pontos_desenvolvimento' => $this->textoOuNull($dados['pontos_desenvolvimento'] ?? null),
                'proximos_passos' => $this->textoOuNull($dados['proximos_passos'] ?? null),
                'atualizado_em' => $agoraSql,
            ]);
            $this->repository->salvarValores($id, $validacao['linhas']);
            $this->auditoria->registrarEvento('feedback', $id, 'atualizacao_rascunho', null, null, null, $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    public function concluir(int $id, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'feedback.avaliar')) {
            return $erro;
        }
        $feedback = $this->feedbackAcessivel($id, $ator);
        if ($feedback === null) {
            return self::falha('Feedback não encontrado.');
        }
        if ((string)$feedback['status'] === 'concluido') {
            return self::falha('Este feedback já está concluído.');
        }
        $resultado = (string)($dados['resultado_geral'] ?? '');
        if (!array_key_exists($resultado, self::RESULTADO_OPCOES)) {
            return self::falha('Selecione o Resultado Geral.');
        }
        // O formulário de encerramento só envia resultado_geral/observacoes_finais (nunca valor_*/
        // comentario_*/pontos_fortes/pontos_desenvolvimento/proximos_passos — esses pertencem ao
        // formulário principal de rascunho, já persistidos via criar()/atualizar()). Concluir NUNCA
        // reconstrói nem reenvia esses dados: só altera o que realmente pertence ao encerramento
        // (mesmo princípio aplicado em AvaliacaoDesempenhoService::concluir()).
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $dados, $resultado, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, [
                'resultado_geral' => $resultado,
                'observacoes_finais' => $this->textoOuNull($dados['observacoes_finais'] ?? null),
                'status' => 'concluido', 'concluido_em' => $agoraSql, 'atualizado_em' => $agoraSql,
            ]);
            $this->auditoria->registrarEvento('feedback', $id, 'conclusao', 'resultado_geral', null, $resultado, $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    /**
     * Espaço do colaborador (§42) — nesta fase, preenchido de forma ASSISTIDA (RH/Gestor registra o
     * relato do colaborador), mesmo padrão do "espaço do colaborador" já usado no PDI. Só altera este
     * campo — nunca as respostas do gestor.
     */
    public function registrarEspacoColaborador(int $id, string $texto, array $ator, DateTimeImmutable $agora): array
    {
        if ($erro = self::semPermissao($ator, 'feedback.avaliar')) {
            return $erro;
        }
        $feedback = $this->feedbackAcessivel($id, $ator);
        if ($feedback === null) {
            return self::falha('Feedback não encontrado.');
        }
        $texto = trim($texto);
        if ($texto === '') {
            return self::falha('Informe o relato do colaborador.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->atualizar($id, ['espaco_colaborador' => $texto, 'espaco_colaborador_preenchido_em' => $agoraSql, 'atualizado_em' => $agoraSql]);
        $this->auditoria->registrarEvento('feedback', $id, 'espaco_colaborador', null, null, null, $ator, null, $agoraSql);
        return ['ok' => true];
    }

    public function registrarCienciaColaborador(int $id, string $nomeColaborador, array $ator, DateTimeImmutable $agora): array
    {
        if ($erro = self::semPermissao($ator, 'feedback.avaliar')) {
            return $erro;
        }
        $feedback = $this->feedbackAcessivel($id, $ator);
        if ($feedback === null) {
            return self::falha('Feedback não encontrado.');
        }
        if ((string)$feedback['status'] !== 'concluido') {
            return self::falha('Só é possível registrar ciência de um feedback concluído.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->auditoria->registrarCiencia('feedback', $id, 'colaborador', null, $nomeColaborador, $agoraSql);
        $this->auditoria->registrarEvento('feedback', $id, 'ciencia_colaborador', null, null, $agoraSql, $ator, null, $agoraSql);
        return ['ok' => true];
    }

    public function reabrir(int $id, string $justificativa, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if (!self::escopoTotal($ator) || self::temPermissao($ator, 'feedback.avaliar') === false) {
            return self::falha('Só Admin/RH podem reabrir um feedback concluído.');
        }
        $feedback = $this->repository->buscarPorId($id);
        if ($feedback === null) {
            return self::falha('Feedback não encontrado.');
        }
        if ((string)$feedback['status'] !== 'concluido') {
            return self::falha('Só um feedback concluído pode ser reaberto.');
        }
        $texto = trim($justificativa);
        if ($texto === '') {
            return self::falha('Informe a justificativa para reabrir.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $texto, $ator, $ip, $agoraSql): array {
            $this->repository->atualizar($id, ['status' => 'rascunho', 'concluido_em' => null, 'atualizado_em' => $agoraSql]);
            $this->auditoria->registrarEvento('feedback', $id, 'reabertura', 'status', 'concluido', 'rascunho — ' . $texto, $ator, $ip, $agoraSql);
            return ['ok' => true];
        });
    }

    public function detalhe(int $id, array $ator): ?array
    {
        $feedback = $this->feedbackAcessivel($id, $ator);
        if ($feedback === null) {
            return null;
        }
        return [
            'feedback' => $feedback,
            'valores' => $this->repository->buscarValores($id),
            'ciencias' => $this->auditoria->listarCiencias('feedback', $id),
            'eventos' => $this->auditoria->listarEventos('feedback', $id),
        ];
    }

    public function listar(array $filtros, array $ator): array
    {
        return $this->repository->listar($filtros, self::escopoGestor($ator), 200);
    }

    /**
     * Candidatos a "necessidade de desenvolvimento" para alimentar um PDI (Etapa 7, §11) — só feedbacks
     * CONCLUÍDOS, no escopo do ator. Prioriza valores marcados "Desenvolvimento Necessário", mas também expõe
     * pontos de desenvolvimento/próximos passos/observações mesmo em feedbacks de reconhecimento (§10 — a
     * decisão de gerar PDI é sempre do gestor/RH, nunca automática). Nunca altera o feedback original (§11).
     *
     * @return array{metadados_id:int,snap_nome:string,gestor_usuario_id:int,itens:array<int,array{chave:string,texto:string}>}|null
     */
    public function candidatosParaPdi(int $id, array $ator): ?array
    {
        $d = $this->detalhe($id, $ator);
        if ($d === null || (string)$d['feedback']['status'] !== 'concluido') {
            return null;
        }
        $f = $d['feedback'];
        $itens = [];
        if (trim((string)($f['pontos_desenvolvimento'] ?? '')) !== '') {
            $itens[] = ['chave' => 'pontos_desenvolvimento', 'texto' => 'Pontos de desenvolvimento: ' . trim((string)$f['pontos_desenvolvimento'])];
        }
        foreach ($d['valores'] as $valor => $v) {
            $comentario = trim((string)($v['comentario'] ?? ''));
            if (($v['avaliacao'] ?? null) === 'desenvolvimento_necessario') {
                $rotulo = self::VALORES_CULTURAIS[$valor]['label'] ?? $valor;
                $itens[] = ['chave' => 'valor_' . $valor, 'texto' => $rotulo . ' — Desenvolvimento Necessário' . ($comentario !== '' ? ': ' . $comentario : '')];
            }
        }
        if (trim((string)($f['proximos_passos'] ?? '')) !== '') {
            $itens[] = ['chave' => 'proximos_passos', 'texto' => 'Próximos passos: ' . trim((string)$f['proximos_passos'])];
        }
        if (trim((string)($f['observacoes_finais'] ?? '')) !== '') {
            $rotuloResultado = self::RESULTADO_OPCOES[$f['resultado_geral']] ?? (string)$f['resultado_geral'];
            $itens[] = ['chave' => 'observacoes_finais', 'texto' => 'Observações finais (' . $rotuloResultado . '): ' . trim((string)$f['observacoes_finais'])];
        }
        return [
            'metadados_id' => (int)$f['metadados_id'],
            'snap_nome' => (string)$f['snap_nome'],
            'gestor_usuario_id' => (int)$f['gestor_usuario_id'],
            'itens' => $itens,
        ];
    }

    private function textoOuNull(mixed $v): ?string
    {
        $t = trim((string)$v);
        return $t !== '' ? $t : null;
    }
}
