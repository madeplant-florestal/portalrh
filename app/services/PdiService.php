<?php

/**
 * PDI — Plano de Desenvolvimento Individual (V1 ASSISTIDA por RH/Gestor). Processo persistente: rascunho → não
 * iniciado → em andamento → concluído (ou cancelado), com ações (máx. 3), acompanhamentos e trilha de auditoria.
 *
 * Princípios desta V1:
 *   - Contrato oficial (`metadados_id`) + SNAPSHOT na abertura; divergência com o METADADOS só é SINALIZADA. Vários
 *     PDIs por contrato; recontratação = outro contrato; nunca CPF/`codigo_pessoa`/`colaboradores` legado.
 *   - Gestor escolhido EXPLICITAMENTE (usuário do Portal + snapshot do nome); nada de inferência por cargo, setor,
 *     legado ou aprovador. Sem Área.
 *   - O colaborador NÃO tem login: o "espaço do colaborador" e comentários em nome dele são registrados por RH/Gestor
 *     e marcados `registrado_em_nome_do_colaborador` (nunca fingem autoria do colaborador).
 *   - Autorização em DUAS camadas: permissão individual (pdi.visualizar/gerenciar/acompanhar; Admin só pelo bypass
 *     central) e ESCOPO POR LINHA — Admin/RH veem todos; os demais só PDIs em que são o gestor responsável.
 *   - Toda alteração relevante grava evento em `pdi_eventos` na MESMA transação. Acompanhamentos e eventos são
 *     append-only. Desligamento/mudança de cargo ou unidade NUNCA encerram nem alteram o PDI sozinhos.
 *   - Sem anexos, sem assinatura/ciência, sem notificações, sem cifragem (V1).
 */
class PdiService
{
    public const ORIGENS = [
        'avaliacao_experiencia' => 'Avaliação de Experiência',
        'feedback' => 'Feedback',
        'avaliacao_desempenho' => 'Avaliação de Desempenho',
        'desenvolvimento_carreira' => 'Desenvolvimento de Carreira',
    ];

    public const STATUS = [
        'rascunho' => 'Rascunho',
        'nao_iniciado' => 'Não iniciado',
        'em_andamento' => 'Em andamento',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    public const STATUS_ACAO = [
        'nao_iniciada' => 'Não iniciada',
        'em_andamento' => 'Em andamento',
        'concluida' => 'Concluída',
    ];

    public const AVALIACOES_FINAIS = [
        'nao_evoluiu' => 'Não evoluiu',
        'evoluiu_parcialmente' => 'Evoluiu parcialmente',
        'objetivo_atingido' => 'Objetivo atingido',
        'superou_expectativa' => 'Superou expectativa',
    ];

    public const RESPONSAVEIS = [
        'colaborador' => 'Colaborador',
        'gestor' => 'Gestor',
        'rh' => 'RH',
        'outro' => 'Outro',
    ];

    public const MAX_ACOES = 3;
    public const MAX_COMPETENCIAS = 10;
    public const LIMITE_TEXTO = 4000;
    public const LIMITE_DESCRICAO_ACAO = 500;
    public const LIMITE_NOME = 180;
    public const LIMITE_LISTAGEM = 200;
    public const PRAZOS_FILTRO = ['atrasado' => 'Atrasado', 'no_prazo' => 'No prazo'];

    /** Status em que a estrutura do plano ainda pode ser editada. */
    public const STATUS_EDITAVEIS = ['rascunho', 'nao_iniciado', 'em_andamento'];
    /** Status "ativos" para atraso: só o que está de fato em curso ou aguardando início. */
    public const STATUS_COM_PRAZO = ['nao_iniciado', 'em_andamento'];

    private const ROTULOS_EVENTO = [
        'criacao' => 'PDI criado (rascunho)',
        'liberacao' => 'PDI liberado (não iniciado)',
        'inicio' => 'PDI iniciado',
        'conclusao' => 'PDI concluído',
        'reabertura' => 'PDI reaberto',
        'reabertura_motivo' => 'Motivo da reabertura',
        'cancelamento' => 'PDI cancelado',
        'cancelamento_motivo' => 'Motivo do cancelamento',
        'avaliacao_final' => 'Avaliação final',
        'alteracao_prazo' => 'Prazo alterado',
        'alteracao_gestor' => 'Gestor responsável alterado',
        'alteracao_origem' => 'Origem alterada',
        'alteracao_objetivo' => 'Objetivo alterado',
        'alteracao_desenvolvimento' => 'Desenvolvimento profissional alterado',
        'competencia_incluida' => 'Competência incluída',
        'competencia_removida' => 'Competência removida',
        'acao_incluida' => 'Ação incluída',
        'acao_alterada' => 'Ação alterada',
        'acao_removida' => 'Ação removida',
        'mudanca_responsavel_acao' => 'Responsável da ação alterado',
        'acao_status' => 'Status da ação alterado',
        'acompanhamento' => 'Acompanhamento registrado',
        'espaco_colaborador' => 'Espaço do colaborador registrado (em nome do colaborador)',
        'evidencias' => 'Evidências de evolução atualizadas',
        'decisao_contrato_desligado' => 'Decisão sobre PDI de contrato desligado',
    ];

    private PdiRepository $repository;

    public function __construct(?PdiRepository $repository = null)
    {
        $this->repository = $repository ?? new PdiRepository();
    }

    // ================================================================== ator, permissão e escopo por linha

    /** Ator da sessão atual (id e role). A sinalização `supervisor` NÃO é lida: não dá escopo global no PDI. */
    public static function atorDaSessao(): array
    {
        return [
            'id' => (int)($_SESSION['user_id'] ?? 0),
            'role' => strtolower(trim((string)($_SESSION['user_role'] ?? ''))),
        ];
    }

    /**
     * Escopo TOTAL só para Admin (bypass central) e RH — este último sempre condicionado à permissão individual, que é
     * exigida antes em toda ação. Qualquer outro usuário (inclusive marcado como supervisor) fica restrito aos PDIs em
     * que é o gestor responsável.
     */
    public static function escopoTotal(array $ator): bool
    {
        return in_array((string)($ator['role'] ?? ''), ['admin', 'rh'], true);
    }

    /** null = sem restrição (Admin/RH com permissão); id = só PDIs em que o usuário é o gestor responsável. */
    public static function escopoGestor(array $ator): ?int
    {
        return self::escopoTotal($ator) ? null : (int)$ator['id'];
    }

    public static function papelDoAtor(array $ator): string
    {
        if (($ator['role'] ?? '') === 'admin') {
            return 'admin';
        }
        return ($ator['role'] ?? '') === 'rh' ? 'rh' : 'gestor';
    }

    public static function podeAcessar(array $pdi, array $ator): bool
    {
        return (int)($ator['id'] ?? 0) > 0 && (self::escopoTotal($ator) || (int)$pdi['gestor_usuario_id'] === (int)$ator['id']);
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

    /** PDI acessível ao ator (permissão + escopo por linha) ou null — não distingue "não existe" de "sem acesso". */
    private function pdiAcessivel(int $id, array $ator): ?array
    {
        $pdi = $this->repository->buscarPdi($id);
        return ($pdi !== null && self::podeAcessar($pdi, $ator)) ? $pdi : null;
    }

    // ================================================================== regras puras

    public static function dataValida(mixed $valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return $valor;
    }

    private static function textoSeguro(mixed $valor): string
    {
        if (!is_string($valor)) {
            return '';
        }
        $texto = str_replace("\r\n", "\n", Security::sanitizeString($valor));
        return trim((string)preg_replace('/[^\P{C}\n\t]+/u', '', $texto));
    }

    private static function normalizar(mixed $v): ?string
    {
        $t = $v === null ? '' : trim((string)$v);
        return $t === '' ? null : $t;
    }

    /**
     * Transições permitidas do status de negócio (mais os estados técnicos rascunho/cancelado).
     * concluido → em_andamento só por reabertura (Admin/RH); cancelado é final.
     */
    public const TRANSICOES = [
        'rascunho' => ['nao_iniciado', 'em_andamento', 'cancelado'],
        'nao_iniciado' => ['em_andamento', 'cancelado'],
        'em_andamento' => ['concluido', 'cancelado'],
        'concluido' => ['em_andamento'],
        'cancelado' => [],
    ];

    public static function transicaoPermitida(string $de, string $para): bool
    {
        return in_array($para, self::TRANSICOES[$de] ?? [], true);
    }

    /** Atraso é só SINALIZAÇÃO derivada de datas (nunca muda o status). */
    public static function situacaoPrazo(array $pdi, DateTimeImmutable $hoje): array
    {
        if (!in_array((string)$pdi['status'], self::STATUS_COM_PRAZO, true)) {
            return ['atrasado' => false, 'dias_atraso' => 0];
        }
        $prevista = new DateTimeImmutable((string)$pdi['data_prevista_conclusao']);
        $dias = (int)$prevista->diff($hoje->setTime(0, 0))->format('%r%a');
        return ['atrasado' => $dias > 0, 'dias_atraso' => max(0, $dias)];
    }

    /** @return array{total:int,concluidas:int,percentual:?int} */
    public static function progressoAcoes(array $acoes): array
    {
        $total = count($acoes);
        $concluidas = count(array_filter($acoes, static fn(array $a): bool => ($a['status'] ?? '') === 'concluida'));
        return ['total' => $total, 'concluidas' => $concluidas, 'percentual' => $total > 0 ? (int)round(($concluidas / $total) * 100) : null];
    }

    public static function acaoAtrasada(array $acao, array $pdi, DateTimeImmutable $hoje): bool
    {
        return in_array((string)$pdi['status'], self::STATUS_COM_PRAZO, true)
            && ($acao['status'] ?? '') !== 'concluida'
            && (string)$acao['prazo'] < $hoje->format('Y-m-d');
    }

    /**
     * Divergências entre o SNAPSHOT da abertura e o contrato oficial ATUAL, além do desligamento. Só sinaliza:
     * nada encerra, altera ou apaga o PDI.
     *
     * @return array{itens:array,desligado:bool,desligamento:?string}
     */
    public static function divergencias(array $pdi, DateTimeImmutable $hoje): array
    {
        $itens = [];
        $igual = static fn(mixed $a, mixed $b): bool => trim((string)$a) === trim((string)$b);
        if (!$igual($pdi['snap_codigo_cargo'] ?? '', $pdi['atual_codigo_cargo'] ?? '')) {
            $itens[] = ['tipo' => 'cargo', 'mensagem' => 'O cargo oficial atual do contrato é diferente do cargo na abertura do PDI.'];
        } elseif (!empty($pdi['atual_data_inicio_cargo']) && !$igual($pdi['snap_data_inicio_cargo'] ?? '', $pdi['atual_data_inicio_cargo'])) {
            $itens[] = ['tipo' => 'cargo', 'mensagem' => 'Houve nova movimentação de cargo no METADADOS depois da abertura do PDI.'];
        }
        if (!$igual($pdi['snap_codigo_unidade'] ?? '', $pdi['atual_codigo_unidade'] ?? '') || !$igual($pdi['snap_codigo_empresa'] ?? '', $pdi['atual_codigo_empresa'] ?? '')) {
            $itens[] = ['tipo' => 'unidade', 'mensagem' => 'A empresa/unidade oficial atual do contrato é diferente da registrada na abertura do PDI.'];
        }
        $demissao = self::normalizar($pdi['atual_demissao'] ?? null);
        $desligado = $demissao !== null && $demissao <= $hoje->format('Y-m-d');
        if ($demissao !== null) {
            $data = date('d/m/Y', strtotime($demissao));
            $itens[] = $desligado
                ? ['tipo' => 'desligado', 'mensagem' => "O contrato foi desligado em {$data}. O PDI foi mantido: o RH decide concluir, cancelar ou manter."]
                : ['tipo' => 'desligamento_agendado', 'mensagem' => "Há desligamento previsto para {$data}."];
        }
        return ['itens' => $itens, 'desligado' => $desligado, 'desligamento' => $demissao];
    }

    /** Competências a partir de um texto (uma por linha): sem vazios/duplicadas, máx. 10 de até 180 caracteres. */
    public static function parseCompetencias(mixed $entrada): array
    {
        $erros = [];
        $itens = [];
        $linhas = is_array($entrada) ? $entrada : preg_split('/\R/u', is_string($entrada) ? $entrada : '');
        foreach ($linhas as $linha) {
            $t = self::textoSeguro($linha);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > self::LIMITE_NOME) {
                $erros[] = 'Cada competência deve ter no máximo ' . self::LIMITE_NOME . ' caracteres.';
                continue;
            }
            $itens[mb_strtolower($t)] ??= $t;
        }
        $itens = array_values($itens);
        if (count($itens) > self::MAX_COMPETENCIAS) {
            $erros[] = 'Máximo de ' . self::MAX_COMPETENCIAS . ' competências.';
        }
        return ['itens' => $itens, 'erros' => array_values(array_unique($erros))];
    }

    /**
     * Ações por slot (ordem 1–3). Slot vazio = sem ação. Devolve valores normalizados e erros; a resolução do
     * responsável (usuário/nome) é feita à parte porque consulta o banco.
     *
     * @return array{acoes:array<int,array>,erros:string[]}
     */
    public static function parseAcoes(mixed $entrada, ?string $abertura, ?string $prevista): array
    {
        $erros = [];
        $acoes = [];
        $entrada = is_array($entrada) ? $entrada : [];
        foreach ($entrada as $chave => $linha) {
            if (!is_array($linha)) {
                continue;
            }
            $preenchida = trim((string)($linha['descricao'] ?? '')) !== '' || trim((string)($linha['prazo'] ?? '')) !== ''
                || trim((string)($linha['responsavel_nome'] ?? '')) !== '' || trim((string)($linha['responsavel_usuario_id'] ?? '')) !== '';
            if (!ctype_digit((string)$chave) || (int)$chave < 1 || (int)$chave > self::MAX_ACOES) {
                if ($preenchida) {
                    $erros[] = 'Cada PDI aceita no máximo ' . self::MAX_ACOES . ' ações.';
                }
                continue;
            }
            if (!$preenchida) {
                continue;
            }
            $ordem = (int)$chave;
            $descricao = self::textoSeguro($linha['descricao'] ?? '');
            $tipo = (string)($linha['responsavel_tipo'] ?? '');
            $prazo = self::dataValida($linha['prazo'] ?? null);
            if ($descricao === '') {
                $erros[] = "Ação {$ordem}: descreva a ação de desenvolvimento.";
            } elseif (mb_strlen($descricao) > self::LIMITE_DESCRICAO_ACAO) {
                $erros[] = "Ação {$ordem}: descrição com no máximo " . self::LIMITE_DESCRICAO_ACAO . ' caracteres.';
            }
            if (!array_key_exists($tipo, self::RESPONSAVEIS)) {
                $erros[] = "Ação {$ordem}: informe o responsável (colaborador, gestor, RH ou outro).";
            }
            if ($prazo === null) {
                $erros[] = "Ação {$ordem}: informe um prazo válido.";
            } elseif ($abertura !== null && $prazo < $abertura) {
                $erros[] = "Ação {$ordem}: o prazo não pode ser anterior à abertura do PDI.";
            } elseif ($prevista !== null && $prazo > $prevista) {
                $erros[] = "Ação {$ordem}: o prazo não pode ser posterior à data prevista de conclusão do PDI.";
            }
            $nome = self::textoSeguro($linha['responsavel_nome'] ?? '');
            if (mb_strlen($nome) > self::LIMITE_NOME) {
                $erros[] = "Ação {$ordem}: nome do responsável com no máximo " . self::LIMITE_NOME . ' caracteres.';
            }
            $acoes[$ordem] = [
                'ordem' => $ordem,
                'descricao' => $descricao,
                'responsavel_tipo' => $tipo,
                'responsavel_usuario_id' => ctype_digit((string)($linha['responsavel_usuario_id'] ?? '')) && (int)$linha['responsavel_usuario_id'] > 0 ? (int)$linha['responsavel_usuario_id'] : null,
                'responsavel_nome' => $nome,
                'prazo' => $prazo,
            ];
        }
        ksort($acoes);
        return ['acoes' => $acoes, 'erros' => array_values(array_unique($erros))];
    }

    /**
     * Valida a ESTRUTURA do plano (sem consultar o banco). Origem, datas, limites e formatos são validados; os textos
     * do desenvolvimento e as competências são OPCIONAIS (nenhuma obrigatoriedade de negócio não aprovada pelo RH).
     *
     * @return array{erros:string[],dados:array,competencias:string[],acoes:array}
     */
    public static function validarEstrutura(array $post): array
    {
        $erros = [];
        $origem = is_string($post['origem_tipo'] ?? null) ? $post['origem_tipo'] : '';
        if (!array_key_exists($origem, self::ORIGENS)) {
            $erros[] = 'Selecione a origem do PDI.';
        }
        $refTipo = self::normalizar(is_string($post['origem_ref_tipo'] ?? null) ? $post['origem_ref_tipo'] : null);
        $refIdBruto = self::normalizar(is_string($post['origem_ref_id'] ?? null) ? $post['origem_ref_id'] : null);
        if (($refTipo === null) !== ($refIdBruto === null)) {
            $erros[] = 'A referência de origem exige tipo e identificador juntos.';
        } elseif ($refTipo !== null && (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $refTipo) || !ctype_digit((string)$refIdBruto) || (int)$refIdBruto < 1)) {
            $erros[] = 'Referência de origem inválida.';
        }

        $abertura = self::dataValida($post['data_abertura'] ?? null);
        $prevista = self::dataValida($post['data_prevista_conclusao'] ?? null);
        if ($abertura === null) {
            $erros[] = 'Informe uma data de abertura válida.';
        }
        if ($prevista === null) {
            $erros[] = 'Informe uma data prevista de conclusão válida.';
        }
        if ($abertura !== null && $prevista !== null && $prevista < $abertura) {
            $erros[] = 'A data prevista de conclusão não pode ser anterior à data de abertura.';
        }

        $textos = [
            'pontos_fortes' => 'Pontos fortes',
            'oportunidades_desenvolvimento' => 'Oportunidades de desenvolvimento',
            'objetivo_esperado' => 'O que se espera alcançar',
        ];
        $dados = [
            'origem_tipo' => $origem,
            'origem_ref_tipo' => $refTipo,
            'origem_ref_id' => $refTipo !== null && ctype_digit((string)$refIdBruto) ? (int)$refIdBruto : null,
            'data_abertura' => $abertura,
            'data_prevista_conclusao' => $prevista,
        ];
        foreach ($textos as $campo => $rotulo) {
            $t = self::textoSeguro($post[$campo] ?? '');
            if (mb_strlen($t) > self::LIMITE_TEXTO) {
                $erros[] = "{$rotulo}: máximo de " . self::LIMITE_TEXTO . ' caracteres.';
            }
            $dados[$campo] = $t !== '' ? $t : null;
        }

        $competencias = self::parseCompetencias($post['competencias'] ?? '');
        $erros = array_merge($erros, $competencias['erros']);
        $acoes = self::parseAcoes($post['acoes'] ?? [], $abertura, $prevista);
        $erros = array_merge($erros, $acoes['erros']);

        return ['erros' => array_values(array_unique($erros)), 'dados' => $dados, 'competencias' => $competencias['itens'], 'acoes' => $acoes['acoes']];
    }

    /** Rótulo legível do evento (a trilha é exibida ao RH/Gestor). */
    public static function rotuloEvento(string $tipo): string
    {
        return self::ROTULOS_EVENTO[$tipo] ?? $tipo;
    }

    /** Formata o valor de um evento conforme o campo (datas, status, listas fechadas). */
    public static function formatarValorEvento(?string $campo, ?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        $campo = (string)$campo;
        if (str_starts_with($campo, 'data_') && self::dataValida($valor) !== null) {
            return date('d/m/Y', strtotime($valor));
        }
        if (preg_match('/\.prazo$/', $campo) && self::dataValida($valor) !== null) {
            return date('d/m/Y', strtotime($valor));
        }
        return match (true) {
            $campo === 'status' || $campo === 'decisao' => self::STATUS[$valor] ?? $valor,
            str_ends_with($campo, '.status') || str_starts_with($campo, 'acao_status') => self::STATUS_ACAO[$valor] ?? $valor,
            $campo === 'avaliacao_final' => self::AVALIACOES_FINAIS[$valor] ?? $valor,
            $campo === 'origem_tipo' => self::ORIGENS[$valor] ?? $valor,
            default => $valor,
        };
    }

    // ================================================================== consultas (permissão + escopo)

    /** Lista no escopo do ator. Filtros inválidos são ignorados; nunca amplia o escopo. */
    public function listar(array $filtros, array $ator, DateTimeImmutable $hoje): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.visualizar')) {
            return $erro + ['itens' => []];
        }
        $unidade = null;
        if (is_string($filtros['unidade'] ?? null) && str_contains($filtros['unidade'], '|')) {
            [$e, $u] = explode('|', $filtros['unidade'], 2);
            $unidade = ['codigo_empresa' => $e, 'codigo_unidade' => $u];
        }
        $f = [
            'status' => array_key_exists((string)($filtros['status'] ?? ''), self::STATUS) ? $filtros['status'] : '',
            'origem' => array_key_exists((string)($filtros['origem'] ?? ''), self::ORIGENS) ? $filtros['origem'] : '',
            'prazo' => array_key_exists((string)($filtros['prazo'] ?? ''), self::PRAZOS_FILTRO) ? $filtros['prazo'] : '',
            'busca' => mb_substr(self::textoSeguro($filtros['busca'] ?? ''), 0, 60),
            'empresa' => self::textoSeguro($filtros['empresa'] ?? ''),
            'cargo' => self::textoSeguro($filtros['cargo'] ?? ''),
            'gestor' => ctype_digit((string)($filtros['gestor'] ?? '')) ? (int)$filtros['gestor'] : 0,
            'unidade' => $unidade,
        ];
        $escopo = self::escopoGestor($ator);
        $linhas = $this->repository->listar($f, $escopo, $hoje->format('Y-m-d'), self::LIMITE_LISTAGEM);
        foreach ($linhas as &$l) {
            $l['prazo'] = self::situacaoPrazo($l, $hoje);
            $total = (int)$l['total_acoes'];
            $l['progresso'] = ['total' => $total, 'concluidas' => (int)$l['acoes_concluidas'], 'percentual' => $total > 0 ? (int)round(((int)$l['acoes_concluidas'] / $total) * 100) : null];
            $l['acoes_atrasadas'] = in_array((string)$l['status'], self::STATUS_COM_PRAZO, true) ? (int)$l['acoes_atrasadas'] : 0;
        }
        unset($l);
        return ['ok' => true, 'itens' => $linhas, 'filtros' => $f, 'opcoes' => $this->repository->opcoesFiltro($escopo), 'limite' => self::LIMITE_LISTAGEM];
    }

    /** Detalhe completo no escopo do ator; null se não existe OU se o ator não tem acesso à linha. */
    public function detalhe(int $id, array $ator, DateTimeImmutable $hoje): ?array
    {
        if (self::semPermissao($ator, 'pdi.visualizar') !== null) {
            return null;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return null;
        }
        $acoes = $this->repository->acoes($id);
        foreach ($acoes as &$a) {
            $a['atrasada'] = self::acaoAtrasada($a, $pdi, $hoje);
        }
        unset($a);
        $editavel = in_array((string)$pdi['status'], self::STATUS_EDITAVEIS, true);
        $gestorTemAcesso = self::temPermissao(['id' => (int)$pdi['gestor_usuario_id']], 'pdi.visualizar');
        return [
            'pdi' => $pdi,
            'competencias' => $this->repository->competencias($id),
            'acoes' => $acoes,
            'acompanhamentos' => $this->repository->acompanhamentos($id),
            'eventos' => $this->repository->eventos($id),
            'prazo' => self::situacaoPrazo($pdi, $hoje),
            'progresso' => self::progressoAcoes($acoes),
            'divergencias' => self::divergencias($pdi, $hoje),
            'gestor_sem_permissao' => !$gestorTemAcesso,
            'pode' => [
                'gerenciar' => $editavel && self::temPermissao($ator, 'pdi.gerenciar'),
                'acompanhar' => self::temPermissao($ator, 'pdi.acompanhar'),
                'espaco' => $editavel && (self::temPermissao($ator, 'pdi.gerenciar') || self::temPermissao($ator, 'pdi.acompanhar')),
                'reabrir' => (string)$pdi['status'] === 'concluido' && self::escopoTotal($ator) && self::temPermissao($ator, 'pdi.acompanhar'),
            ],
        ];
    }

    /** Contratos ativos para a criação (busca por nome/empresa/unidade). Exige pdi.gerenciar. */
    public function buscarContratos(string $busca, array $ator, DateTimeImmutable $hoje): array
    {
        $busca = mb_substr(self::textoSeguro($busca), 0, 60);
        if (self::semPermissao($ator, 'pdi.gerenciar') !== null || mb_strlen($busca) < 2) {
            return [];
        }
        return $this->repository->buscarContratos($busca, $hoje->format('Y-m-d'));
    }

    public function contratoParaCriacao(int $metadadosId, array $ator, DateTimeImmutable $hoje): ?array
    {
        if (self::semPermissao($ator, 'pdi.gerenciar') !== null) {
            return null;
        }
        return $this->repository->buscarContrato($metadadosId);
    }

    public function usuariosParaEscolha(array $ator): array
    {
        return self::semPermissao($ator, 'pdi.gerenciar') === null ? $this->repository->usuariosAtivos() : [];
    }

    // ================================================================== criação e edição da estrutura

    /** @return array{ok:bool,error?:string,erros?:string[],id?:int} */
    public function criar(array $post, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.gerenciar')) {
            return $erro;
        }
        $contrato = $this->repository->buscarContrato(ctype_digit((string)($post['metadados_id'] ?? '')) ? (int)$post['metadados_id'] : 0);
        if ($contrato === null) {
            return self::falha('Selecione o colaborador (contrato oficial do METADADOS).');
        }
        $hoje = $agora->format('Y-m-d');
        if (!empty($contrato['demissao']) && (string)$contrato['demissao'] <= $hoje) {
            return self::falha('Este contrato já foi desligado — não é possível abrir um novo PDI para ele.');
        }

        // Admin/RH escolhem o gestor; quem não tem escopo total só cria PDI vinculado a si próprio.
        $gestorId = self::escopoTotal($ator) ? (ctype_digit((string)($post['gestor_usuario_id'] ?? '')) ? (int)$post['gestor_usuario_id'] : 0) : (int)$ator['id'];
        $gestor = $gestorId > 0 ? $this->repository->usuarioAtivo($gestorId) : null;
        $v = self::validarEstrutura($post);
        $erros = $v['erros'];
        if ($gestor === null) {
            $erros[] = 'Selecione o gestor responsável (usuário ativo do Portal).';
        }
        $acoes = $gestor !== null ? $this->resolverAcoes($v['acoes'], $gestor, (string)$contrato['nome'], $erros) : [];
        if ($erros !== []) {
            return self::falha(array_values(array_unique($erros)));
        }

        $dados = $v['dados'] + [
            'metadados_id' => (int)$contrato['metadados_id'],
            'snap_nome' => (string)$contrato['nome'],
            'snap_codigo_empresa' => (string)$contrato['codigo_empresa'],
            'snap_empresa' => self::normalizar($contrato['empresa'] ?? null),
            'snap_codigo_unidade' => (string)$contrato['codigo_unidade'],
            'snap_unidade' => self::normalizar($contrato['unidade'] ?? null),
            'snap_codigo_cargo' => self::normalizar($contrato['codigo_cargo'] ?? null),
            'snap_cargo' => self::normalizar($contrato['cargo'] ?? null),
            'snap_admissao' => self::normalizar($contrato['admissao'] ?? null),
            'snap_data_inicio_cargo' => self::normalizar($contrato['data_inicio_cargo'] ?? null),
            'gestor_usuario_id' => (int)$gestor['id'],
            'gestor_nome_snapshot' => (string)$gestor['nome'],
            'status' => 'rascunho',
            'criado_por_usuario_id' => (int)$ator['id'],
        ];
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $id = $this->repository->transacao(function () use ($dados, $v, $acoes, $agoraSql, $ator, $ip): int {
            $id = $this->repository->inserirPdi($dados, $agoraSql);
            foreach ($v['competencias'] as $texto) {
                $this->repository->inserirCompetencia($id, $texto, $agoraSql);
            }
            foreach ($acoes as $a) {
                $this->repository->inserirAcao($id, $a, $agoraSql);
            }
            $this->evento($id, 'criacao', 'status', null, 'rascunho', $ator, $ip, $agoraSql);
            return $id;
        });
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Edita a estrutura (gerenciar). Só em rascunho/não iniciado/em andamento; concluído e cancelado ficam
     * bloqueados. Cada mudança relevante vira evento na mesma transação.
     */
    public function atualizarEstrutura(int $id, array $post, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.gerenciar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        if (!in_array((string)$pdi['status'], self::STATUS_EDITAVEIS, true)) {
            return self::falha('PDI ' . mb_strtolower(self::STATUS[$pdi['status']]) . ': a edição comum está bloqueada.');
        }

        $v = self::validarEstrutura($post);
        $erros = $v['erros'];

        $gestorId = (int)$pdi['gestor_usuario_id'];
        $gestorNome = (string)$pdi['gestor_nome_snapshot'];
        if (self::escopoTotal($ator) && ctype_digit((string)($post['gestor_usuario_id'] ?? '')) && (int)$post['gestor_usuario_id'] !== $gestorId) {
            $novo = $this->repository->usuarioAtivo((int)$post['gestor_usuario_id']);
            if ($novo === null) {
                $erros[] = 'Gestor responsável inválido (usuário ativo do Portal).';
            } else {
                $gestorId = (int)$novo['id'];
                $gestorNome = (string)$novo['nome'];
            }
        }
        $acoes = $this->resolverAcoes($v['acoes'], ['id' => $gestorId, 'nome' => $gestorNome], (string)$pdi['snap_nome'], $erros);
        if ($erros !== []) {
            return self::falha(array_values(array_unique($erros)));
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->transacao(function () use ($id, $pdi, $v, $acoes, $gestorId, $gestorNome, $agoraSql, $ator, $ip): void {
            $novos = $v['dados'] + ['gestor_usuario_id' => $gestorId, 'gestor_nome_snapshot' => $gestorNome];
            $mudou = [];
            $mapaEvento = [
                'data_abertura' => 'alteracao_prazo', 'data_prevista_conclusao' => 'alteracao_prazo', 'origem_tipo' => 'alteracao_origem',
                'objetivo_esperado' => 'alteracao_objetivo', 'pontos_fortes' => 'alteracao_desenvolvimento',
                'oportunidades_desenvolvimento' => 'alteracao_desenvolvimento',
            ];
            foreach (['data_abertura', 'data_prevista_conclusao', 'origem_tipo', 'objetivo_esperado', 'pontos_fortes', 'oportunidades_desenvolvimento'] as $campo) {
                if (self::normalizar($pdi[$campo]) !== self::normalizar($novos[$campo])) {
                    $mudou[$campo] = $novos[$campo];
                    $this->evento($id, $mapaEvento[$campo], $campo, self::normalizar($pdi[$campo]), self::normalizar($novos[$campo]), $ator, $ip, $agoraSql);
                }
            }
            if (self::normalizar($pdi['origem_ref_tipo']) !== self::normalizar($novos['origem_ref_tipo']) || (int)$pdi['origem_ref_id'] !== (int)$novos['origem_ref_id']) {
                $mudou['origem_ref_tipo'] = $novos['origem_ref_tipo'];
                $mudou['origem_ref_id'] = $novos['origem_ref_id'];
                $this->evento($id, 'alteracao_origem', 'origem_ref', trim((string)$pdi['origem_ref_tipo'] . ' #' . (string)$pdi['origem_ref_id']), trim((string)$novos['origem_ref_tipo'] . ' #' . (string)$novos['origem_ref_id']), $ator, $ip, $agoraSql);
            }
            if ((int)$pdi['gestor_usuario_id'] !== $gestorId) {
                $mudou['gestor_usuario_id'] = $gestorId;
                $mudou['gestor_nome_snapshot'] = $gestorNome;
                $this->evento($id, 'alteracao_gestor', 'gestor_usuario_id', (string)$pdi['gestor_nome_snapshot'], $gestorNome, $ator, $ip, $agoraSql);
            }
            if ($mudou !== []) {
                $this->repository->atualizarPdi($id, $mudou, $agoraSql);
            }

            // competências: diff por texto (sem diferenciar maiúsculas)
            $atuais = [];
            foreach ($this->repository->competencias($id) as $c) {
                $atuais[mb_strtolower((string)$c['competencia_texto'])] = $c;
            }
            $desejadas = [];
            foreach ($v['competencias'] as $texto) {
                $desejadas[mb_strtolower($texto)] = $texto;
            }
            foreach ($atuais as $chave => $c) {
                if (!isset($desejadas[$chave])) {
                    $this->repository->removerCompetencia((int)$c['id'], $id);
                    $this->evento($id, 'competencia_removida', 'competencia', (string)$c['competencia_texto'], null, $ator, $ip, $agoraSql);
                }
            }
            foreach ($desejadas as $chave => $texto) {
                if (!isset($atuais[$chave])) {
                    $this->repository->inserirCompetencia($id, $texto, $agoraSql);
                    $this->evento($id, 'competencia_incluida', 'competencia', null, $texto, $ator, $ip, $agoraSql);
                }
            }

            // ações por slot (ordem)
            $existentes = [];
            foreach ($this->repository->acoes($id) as $a) {
                $existentes[(int)$a['ordem']] = $a;
            }
            for ($ordem = 1; $ordem <= self::MAX_ACOES; $ordem++) {
                $velha = $existentes[$ordem] ?? null;
                $nova = $acoes[$ordem] ?? null;
                if ($velha !== null && $nova === null) {
                    $this->repository->removerAcao($id, $ordem);
                    $this->evento($id, 'acao_removida', "acao_{$ordem}", (string)$velha['descricao'], null, $ator, $ip, $agoraSql);
                } elseif ($velha === null && $nova !== null) {
                    $this->repository->inserirAcao($id, $nova, $agoraSql);
                    $this->evento($id, 'acao_incluida', "acao_{$ordem}", null, $nova['descricao'], $ator, $ip, $agoraSql);
                } elseif ($velha !== null && $nova !== null) {
                    $alterou = false;
                    if ((string)$velha['descricao'] !== $nova['descricao']) {
                        $this->evento($id, 'acao_alterada', "acao_{$ordem}.descricao", (string)$velha['descricao'], $nova['descricao'], $ator, $ip, $agoraSql);
                        $alterou = true;
                    }
                    if ((string)$velha['prazo'] !== $nova['prazo']) {
                        $this->evento($id, 'acao_alterada', "acao_{$ordem}.prazo", (string)$velha['prazo'], $nova['prazo'], $ator, $ip, $agoraSql);
                        $alterou = true;
                    }
                    $respAntes = $velha['responsavel_tipo'] . ':' . (string)$velha['responsavel_nome_snapshot'] . '#' . (string)$velha['responsavel_usuario_id'];
                    $respDepois = $nova['responsavel_tipo'] . ':' . (string)$nova['responsavel_nome_snapshot'] . '#' . (string)$nova['responsavel_usuario_id'];
                    if ($respAntes !== $respDepois) {
                        $this->evento($id, 'mudanca_responsavel_acao', "acao_{$ordem}.responsavel", self::descreverResponsavel($velha), self::descreverResponsavel($nova), $ator, $ip, $agoraSql);
                        $alterou = true;
                    }
                    if ($alterou) {
                        $this->repository->atualizarAcao($id, $ordem, $nova, $agoraSql);
                    }
                }
            }
        });
        return ['ok' => true, 'id' => $id];
    }

    private static function descreverResponsavel(array $a): string
    {
        return (self::RESPONSAVEIS[$a['responsavel_tipo']] ?? $a['responsavel_tipo']) . ' — ' . (string)($a['responsavel_nome_snapshot'] ?? '');
    }

    /**
     * Resolve o responsável de cada ação: gestor (o do PDI), colaborador (nome do snapshot, sem usuário na V1) ou
     * RH/outro (usuário ativo do Portal OU nome em texto). Acrescenta erros; devolve as ações prontas por ordem.
     */
    private function resolverAcoes(array $acoes, array $gestor, string $nomeColaborador, array &$erros): array
    {
        $prontas = [];
        foreach ($acoes as $ordem => $a) {
            $usuarioId = null;
            $nome = null;
            switch ($a['responsavel_tipo']) {
                case 'gestor':
                    $usuarioId = (int)$gestor['id'];
                    $nome = (string)$gestor['nome'];
                    break;
                case 'colaborador':
                    $nome = $nomeColaborador;
                    break;
                case 'rh':
                case 'outro':
                    if ($a['responsavel_usuario_id'] !== null) {
                        $u = $this->repository->usuarioAtivo((int)$a['responsavel_usuario_id']);
                        if ($u === null) {
                            $erros[] = "Ação {$ordem}: usuário responsável inválido.";
                            break;
                        }
                        $usuarioId = (int)$u['id'];
                        $nome = (string)$u['nome'];
                    } elseif ($a['responsavel_nome'] !== '') {
                        $nome = $a['responsavel_nome'];
                    } else {
                        $erros[] = "Ação {$ordem}: informe o nome do responsável (ou escolha um usuário do Portal).";
                    }
                    break;
            }
            $prontas[$ordem] = [
                'ordem' => $ordem,
                'descricao' => $a['descricao'],
                'responsavel_tipo' => $a['responsavel_tipo'],
                'responsavel_usuario_id' => $usuarioId,
                'responsavel_nome_snapshot' => $nome,
                'prazo' => $a['prazo'],
            ];
        }
        return $prontas;
    }

    // ================================================================== acompanhamento

    private function evento(int $pdiId, string $tipo, ?string $campo, ?string $anterior, ?string $novo, array $ator, ?string $ip, string $agora, bool $emNomeDoColaborador = false): void
    {
        $this->repository->inserirEvento($pdiId, $tipo, $campo, $anterior, $novo, (int)$ator['id'], self::papelDoAtor($ator), $emNomeDoColaborador, $ip, $agora);
    }

    /** Comentário APPEND-ONLY (nunca editado): correções entram como novo comentário. */
    public function adicionarAcompanhamento(int $id, string $comentario, bool $emNomeDoColaborador, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        if (!in_array((string)$pdi['status'], self::STATUS_COM_PRAZO, true)) {
            return self::falha('Acompanhamentos só podem ser registrados em PDIs não iniciados ou em andamento.');
        }
        $texto = self::textoSeguro($comentario);
        if ($texto === '') {
            return self::falha('Escreva o comentário do acompanhamento.');
        }
        if (mb_strlen($texto) > self::LIMITE_TEXTO) {
            return self::falha('Comentário: máximo de ' . self::LIMITE_TEXTO . ' caracteres.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $papel = $emNomeDoColaborador ? 'colaborador_assistido' : self::papelDoAtor($ator);
        $novoId = $this->repository->transacao(function () use ($id, $ator, $papel, $emNomeDoColaborador, $texto, $agoraSql, $ip): int {
            $novoId = $this->repository->inserirAcompanhamento($id, (int)$ator['id'], $papel, $emNomeDoColaborador, $texto, $agoraSql);
            $this->evento($id, 'acompanhamento', 'acompanhamento_id', null, (string)$novoId, $ator, $ip, $agoraSql, $emNomeDoColaborador);
            return $novoId;
        });
        return ['ok' => true, 'id' => $novoId];
    }

    /**
     * "Espaço do colaborador" registrado ASSISTIDAMENTE por RH/Gestor (gerenciar OU acompanhar). Cada campo alterado
     * gera evento marcado `registrado_em_nome_do_colaborador`, com quem registrou.
     */
    public function registrarEspacoColaborador(int $id, ?string $momento, ?string $pontos, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.gerenciar', 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        if (!in_array((string)$pdi['status'], self::STATUS_EDITAVEIS, true)) {
            return self::falha('PDI ' . mb_strtolower(self::STATUS[$pdi['status']]) . ': a edição comum está bloqueada.');
        }
        $novos = ['momento_profissional' => self::textoSeguro($momento), 'pontos_desenvolver_colaborador' => self::textoSeguro($pontos)];
        foreach ($novos as $campo => $t) {
            if (mb_strlen($t) > self::LIMITE_TEXTO) {
                return self::falha('Máximo de ' . self::LIMITE_TEXTO . ' caracteres por campo.');
            }
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->transacao(function () use ($id, $pdi, $novos, $ator, $ip, $agoraSql): void {
            $mudou = [];
            foreach ($novos as $campo => $t) {
                if (self::normalizar($pdi[$campo]) !== self::normalizar($t)) {
                    $mudou[$campo] = self::normalizar($t);
                    $this->evento($id, 'espaco_colaborador', $campo, self::normalizar($pdi[$campo]), self::normalizar($t), $ator, $ip, $agoraSql, true);
                }
            }
            if ($mudou !== []) {
                $this->repository->atualizarPdi($id, $mudou, $agoraSql);
            }
        });
        return ['ok' => true, 'id' => $id];
    }

    public function registrarEvidencias(int $id, ?string $texto, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        if (!in_array((string)$pdi['status'], self::STATUS_COM_PRAZO, true)) {
            return self::falha('Evidências só podem ser registradas em PDIs não iniciados ou em andamento.');
        }
        $t = self::textoSeguro($texto);
        if (mb_strlen($t) > self::LIMITE_TEXTO) {
            return self::falha('Evidências: máximo de ' . self::LIMITE_TEXTO . ' caracteres.');
        }
        if (self::normalizar($pdi['evidencias_evolucao']) === self::normalizar($t)) {
            return ['ok' => true, 'id' => $id];
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->transacao(function () use ($id, $pdi, $t, $ator, $ip, $agoraSql): void {
            $this->repository->atualizarPdi($id, ['evidencias_evolucao' => self::normalizar($t)], $agoraSql);
            $this->evento($id, 'evidencias', 'evidencias_evolucao', self::normalizar($pdi['evidencias_evolucao']), self::normalizar($t), $ator, $ip, $agoraSql);
        });
        return ['ok' => true, 'id' => $id];
    }

    public function alterarStatusAcao(int $id, int $ordem, string $status, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        if ((string)$pdi['status'] !== 'em_andamento') {
            return self::falha('O status das ações só muda com o PDI em andamento.');
        }
        if (!array_key_exists($status, self::STATUS_ACAO)) {
            return self::falha('Status de ação inválido.');
        }
        $acao = null;
        foreach ($this->repository->acoes($id) as $a) {
            if ((int)$a['ordem'] === $ordem) {
                $acao = $a;
            }
        }
        if ($acao === null) {
            return self::falha('Ação não encontrada.');
        }
        if ((string)$acao['status'] === $status) {
            return ['ok' => true, 'id' => $id];
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->transacao(function () use ($id, $ordem, $acao, $status, $ator, $ip, $agoraSql): void {
            $this->repository->atualizarStatusAcao($id, $ordem, $status, $agoraSql);
            $this->evento($id, 'acao_status', "acao_{$ordem}.status", (string)$acao['status'], $status, $ator, $ip, $agoraSql);
        });
        return ['ok' => true, 'id' => $id];
    }

    // ================================================================== transições de status

    /** rascunho → não iniciado: abertura efetiva do PDI (sem exigências adicionais de conteúdo). */
    public function liberar(int $id, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        return $this->transitar($id, 'nao_iniciado', $ator, $agora, $ip, 'liberacao');
    }

    /** rascunho/não iniciado → em andamento: a única exigência é existir ao menos 1 ação. */
    public function iniciar(int $id, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        return $this->transitar($id, 'em_andamento', $ator, $agora, $ip, 'inicio');
    }

    public function cancelar(int $id, string $motivo, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        $motivo = self::textoSeguro($motivo);
        if ($motivo === '') {
            return self::falha('Informe o motivo do cancelamento.');
        }
        return $this->transitar($id, 'cancelado', $ator, $agora, $ip, 'cancelamento', ['motivo' => $motivo]);
    }

    /** em andamento → concluído: exige avaliação final; preenche a data real; bloqueia a edição comum. */
    public function concluir(int $id, ?string $avaliacao, ?string $comentarios, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if (!array_key_exists((string)$avaliacao, self::AVALIACOES_FINAIS)) {
            return self::falha('Selecione a avaliação final para concluir o PDI.');
        }
        $comentarios = self::textoSeguro($comentarios);
        if (mb_strlen($comentarios) > self::LIMITE_TEXTO) {
            return self::falha('Comentários finais: máximo de ' . self::LIMITE_TEXTO . ' caracteres.');
        }
        return $this->transitar($id, 'concluido', $ator, $agora, $ip, 'conclusao', ['avaliacao' => $avaliacao, 'comentarios' => $comentarios]);
    }

    /** concluído → em andamento: só Admin/RH, com motivo; limpa avaliação final e data real (preservadas no histórico). */
    public function reabrir(int $id, string $motivo, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if (!self::escopoTotal($ator)) {
            return self::falha('Somente Admin/RH podem reabrir um PDI concluído.');
        }
        $motivo = self::textoSeguro($motivo);
        if ($motivo === '') {
            return self::falha('Informe o motivo da reabertura.');
        }
        return $this->transitar($id, 'em_andamento', $ator, $agora, $ip, 'reabertura', ['motivo' => $motivo]);
    }

    private function transitar(int $id, string $destino, array $ator, DateTimeImmutable $agora, ?string $ip, string $tipoEvento, array $extra = []): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        $origem = (string)$pdi['status'];
        // `iniciar` não pode servir de atalho para reabrir um concluído; `reabrir` só parte de concluído.
        $origemValida = match ($tipoEvento) {
            'inicio' => in_array($origem, ['rascunho', 'nao_iniciado'], true),
            'reabertura' => $origem === 'concluido',
            default => true,
        };
        if (!$origemValida || !self::transicaoPermitida($origem, $destino)) {
            return self::falha('Transição não permitida: ' . self::STATUS[$origem] . ' → ' . self::STATUS[$destino] . '.');
        }
        // Única exigência de negócio para sair do rascunho/não iniciado: ao INICIAR, ao menos 1 ação.
        if ($tipoEvento === 'inicio' && $this->repository->acoes($id) === []) {
            return self::falha('Para iniciar, o PDI precisa ter ao menos 1 ação no plano de ação.');
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        $hoje = $agora->format('Y-m-d');
        $div = self::divergencias($pdi, $agora);
        $this->repository->transacao(function () use ($id, $pdi, $origem, $destino, $tipoEvento, $extra, $ator, $ip, $agoraSql, $hoje, $div): void {
            $campos = ['status' => $destino];
            if ($tipoEvento === 'conclusao') {
                $campos += ['data_real_conclusao' => $hoje, 'avaliacao_final' => $extra['avaliacao'], 'comentarios_finais' => self::normalizar($extra['comentarios'])];
                $this->evento($id, 'avaliacao_final', 'avaliacao_final', null, $extra['avaliacao'], $ator, $ip, $agoraSql);
            }
            if ($tipoEvento === 'reabertura') {
                $campos += ['data_real_conclusao' => null, 'avaliacao_final' => null, 'comentarios_finais' => null];
                $this->evento($id, 'avaliacao_final', 'avaliacao_final', (string)$pdi['avaliacao_final'], null, $ator, $ip, $agoraSql);
                $this->evento($id, 'reabertura_motivo', 'motivo', null, $extra['motivo'], $ator, $ip, $agoraSql);
            }
            $this->repository->atualizarPdi($id, $campos, $agoraSql);
            $this->evento($id, $tipoEvento, 'status', $origem, $destino, $ator, $ip, $agoraSql);
            if ($tipoEvento === 'cancelamento') {
                $this->evento($id, 'cancelamento_motivo', 'motivo', null, $extra['motivo'], $ator, $ip, $agoraSql);
            }
            // Contrato desligado: a decisão do RH (concluir/cancelar) fica registrada explicitamente.
            if ($div['desligado'] && in_array($destino, ['concluido', 'cancelado'], true)) {
                $this->evento($id, 'decisao_contrato_desligado', 'decisao', (string)$div['desligamento'], $destino, $ator, $ip, $agoraSql);
            }
        });
        return ['ok' => true, 'id' => $id];
    }

    /** Contrato desligado e o RH decide MANTER o PDI: a decisão é registrada (o PDI nunca é alterado sozinho). */
    public function manterAposDesligamento(int $id, string $justificativa, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'pdi.acompanhar')) {
            return $erro;
        }
        $pdi = $this->pdiAcessivel($id, $ator);
        if ($pdi === null) {
            return self::falha('PDI não encontrado.');
        }
        $div = self::divergencias($pdi, $agora);
        if (!$div['desligado']) {
            return self::falha('O contrato deste PDI não está desligado.');
        }
        if (!in_array((string)$pdi['status'], self::STATUS_EDITAVEIS, true)) {
            return self::falha('PDI ' . mb_strtolower(self::STATUS[$pdi['status']]) . ': não há decisão pendente.');
        }
        $texto = self::textoSeguro($justificativa);
        if ($texto === '') {
            return self::falha('Informe a justificativa para manter o PDI.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->repository->transacao(function () use ($id, $div, $texto, $ator, $ip, $agoraSql): void {
            $this->evento($id, 'decisao_contrato_desligado', 'decisao', (string)$div['desligamento'], 'mantido — ' . $texto, $ator, $ip, $agoraSql);
        });
        return ['ok' => true, 'id' => $id];
    }
}
