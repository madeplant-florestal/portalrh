<?php

/**
 * Entrevista de Desligamento — regras de negócio. Uma entrevista IDENTIFICADA por CONTRATO oficial
 * (`metadados_id`), respondida pelo ex-colaborador por link individual. Fonte dos contratos: espelho
 * `colaboradores_metadados` (nunca `colaboradores` legado; `codigo_pessoa` nunca agrupa contratos).
 *
 * Decisões da V1:
 *   - Elegível: demissão já efetivada (`demissao <= hoje`) e motivo oficial diferente de 020 (Falecimento).
 *   - NÃO existe classificação Voluntário/Involuntário aqui (nem mapa do People Analytics/Dashboard de
 *     Turnover): o motivo oficial é só snapshot; o motivo declarado é independente.
 *   - Token: bin2hex(random_bytes(32)); só o SHA-256 é persistido; o bruto existe apenas no retorno de
 *     gerar()/regenerar(). Prazo de 30 dias. Regenerar troca o token; respondida nunca é reaberta.
 *   - Situação derivada de timestamps (respondida > cancelada > expirada > pendente), sem coluna de status.
 *   - Gestor Imediato e Área não existem na V1 (sem fonte oficial / semântica definida).
 */
class EntrevistaDesligamentoService
{
    public const PRAZO_DIAS = 30;
    /** Motivo oficial (METADADOS) fora da população elegível: Falecimento. */
    public const MOTIVO_OFICIAL_INELEGIVEL = '020';
    public const LIMITE_TEXTO = 2000;
    public const LIMITE_LISTAGEM = 200;

    public const SITUACOES = ['pendente', 'respondida', 'expirada', 'cancelada'];
    public const DIAS_FILTRO = [30, 60, 90, 180, 365, 0];

    public const ESCALA_1_5 = [
        1 => 'Muito insatisfeito',
        2 => 'Insatisfeito',
        3 => 'Neutro',
        4 => 'Satisfeito',
        5 => 'Muito satisfeito',
    ];

    public const MOTIVOS_DECLARADOS = [
        'nova_oportunidade' => 'Nova oportunidade profissional',
        'remuneracao' => 'Remuneração',
        'beneficios' => 'Benefícios',
        'lideranca' => 'Relacionamento com liderança',
        'equipe' => 'Relacionamento com equipe',
        'reconhecimento' => 'Falta de reconhecimento',
        'crescimento' => 'Falta de desenvolvimento/crescimento',
        'mudanca_cidade' => 'Mudança de cidade',
        'pessoais_familiares' => 'Motivos pessoais/familiares',
        'condicoes_trabalho' => 'Condições de trabalho',
        'jornada' => 'Jornada de trabalho',
        'desempenho' => 'Desempenho',
        'reestruturacao' => 'Reestruturação da empresa',
        'outro' => 'Outro',
    ];

    public const FATORES_CONTRIBUINTES = [
        'remuneracao' => 'Remuneração',
        'beneficios' => 'Benefícios',
        'lideranca' => 'Liderança',
        'equipe' => 'Equipe',
        'crescimento' => 'Crescimento profissional',
        'reconhecimento' => 'Reconhecimento',
        'clima' => 'Clima organizacional',
        'comunicacao' => 'Comunicação',
        'condicoes_trabalho' => 'Condições de trabalho',
        'jornada' => 'Jornada de trabalho',
        'outro' => 'Outro',
    ];

    /** Seções avaliadas de 1 a 5: campo (= coluna) => enunciado. `escala`: satisfacao (legenda ESCALA_1_5, só em Experiência) ou neutra. */
    public const SECOES_ESCALA = [
        'experiencia' => [
            'titulo' => 'Experiência na empresa',
            'escala' => 'satisfacao',
            'itens' => [
                'exp_remuneracao' => 'Remuneração',
                'exp_beneficios' => 'Benefícios',
                'exp_condicoes_trabalho' => 'Condições de trabalho',
                'exp_comunicacao' => 'Comunicação da empresa',
                'exp_desenvolvimento' => 'Oportunidades de desenvolvimento',
                'exp_reconhecimento' => 'Reconhecimento pelo trabalho realizado',
                'exp_clima' => 'Clima organizacional',
            ],
        ],
        'lideranca' => [
            'titulo' => 'Liderança',
            'escala' => 'neutra',
            'itens' => [
                'lid_respeito' => 'Respeito no relacionamento',
                'lid_comunicacao' => 'Comunicação e orientação',
                'lid_abertura' => 'Abertura para ouvir sugestões',
                'lid_desenvolvimento' => 'Apoio ao desenvolvimento profissional',
                'lid_justica' => 'Tratamento justo e ético',
            ],
        ],
        'cultura' => [
            'titulo' => 'Cultura e valores',
            'escala' => 'neutra',
            'itens' => [
                'cul_respeito' => 'Respeito',
                'cul_honestidade' => 'Honestidade',
                'cul_lealdade' => 'Lealdade',
                'cul_etica' => 'Ética',
                'cul_coragem' => 'Coragem',
                'cul_ousadia' => 'Ousadia',
            ],
        ],
        'integracao' => [
            'titulo' => 'Integração e desenvolvimento',
            'escala' => 'neutra',
            'itens' => [
                'int_compreender' => 'O treinamento de integração ajudou você a compreender a empresa e sua função?',
                'int_treinamento' => 'Você recebeu treinamento adequado para desempenhar suas atividades?',
                'int_expectativa' => 'O trabalho realizado correspondeu ao que foi apresentado durante o processo seletivo?',
            ],
        ],
    ];

    /** Contextualização da seção Cultura e valores (orientação para as seis avaliações; sem campo de resposta próprio). */
    public const PERGUNTA_CULTURA_PRATICA = 'Na sua percepção, a empresa pratica seus valores no dia a dia?';
    /** Legenda neutra das escalas 1–5 fora de Experiência: o RH não definiu rótulos para elas. */
    public const LEGENDA_ESCALA_NEUTRA = 'Escala de 1 a 5: 1 é a avaliação mais baixa e 5 é a mais alta.';
    public const PERGUNTA_EXPERIENCIA_GERAL = 'Em uma escala de 0 a 10, como você avalia sua experiência geral trabalhando na Madeplant?';
    public const PERGUNTA_ENPS = 'Em uma escala de 0 a 10, o quanto você recomendaria a Madeplant como um bom lugar para trabalhar?';
    public const PERGUNTAS_ABERTAS = [
        'aberta_continuar' => 'O que a empresa faz muito bem e deveria continuar fazendo?',
        'aberta_melhorar' => 'O que poderia ser melhorado?',
        'aberta_mensagem' => 'Existe alguma mensagem ou sugestão que gostaria de deixar para a empresa?',
    ];

    private EntrevistaDesligamentoRepository $repository;

    public function __construct(?EntrevistaDesligamentoRepository $repository = null)
    {
        $this->repository = $repository ?? new EntrevistaDesligamentoRepository();
    }

    // ================================================================== regras puras (estáticas)

    /**
     * Elegibilidade a partir do contrato oficial: demissão efetivada e motivo diferente de Falecimento.
     * Mesma regra aplicada em SQL por EntrevistaDesligamentoRepository::listarElegiveis().
     *
     * @return array{elegivel:bool,motivo:?string,mensagem:?string} motivo: sem_demissao | demissao_futura | falecimento
     */
    public static function elegibilidade(array $contrato, DateTimeImmutable $hoje): array
    {
        $demissao = self::data($contrato['demissao'] ?? null);
        if ($demissao === null) {
            return ['elegivel' => false, 'motivo' => 'sem_demissao', 'mensagem' => 'Este contrato não possui desligamento registrado no METADADOS.'];
        }
        if ($demissao > $hoje->setTime(0, 0)) {
            return ['elegivel' => false, 'motivo' => 'demissao_futura', 'mensagem' => 'O desligamento deste contrato ainda não foi efetivado (data futura).'];
        }
        if (trim((string)($contrato['motivo_rescisao_codigo'] ?? '')) === self::MOTIVO_OFICIAL_INELEGIVEL) {
            return ['elegivel' => false, 'motivo' => 'falecimento', 'mensagem' => 'Contratos encerrados por Falecimento não recebem Entrevista de Desligamento.'];
        }
        return ['elegivel' => true, 'motivo' => null, 'mensagem' => null];
    }

    /** respondida > cancelada > expirada > pendente (derivada de timestamps). */
    public static function situacao(array $entrevista, DateTimeImmutable $agora): string
    {
        if (!empty($entrevista['respondida_em'])) {
            return 'respondida';
        }
        if (!empty($entrevista['cancelada_em'])) {
            return 'cancelada';
        }
        $expira = self::data($entrevista['expira_em'] ?? null);
        if ($expira === null || $expira <= $agora) {
            return 'expirada';
        }
        return 'pendente';
    }

    public static function classificarEnps(int $nota): string
    {
        if ($nota >= 9) {
            return 'Promotor';
        }
        return $nota >= 7 ? 'Neutro' : 'Detrator';
    }

    /** eNPS = %Promotores − %Detratores, 1 casa; null sem respostas. */
    public static function calcularEnps(array $distribuicao): ?float
    {
        $total = (int)($distribuicao['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return round((((int)$distribuicao['promotores'] - (int)$distribuicao['detratores']) / $total) * 100, 1);
    }

    public static function primeiroNome(?string $nome): string
    {
        $partes = preg_split('/\s+/u', trim((string)$nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $partes === [] ? '' : mb_convert_case($partes[0], MB_CASE_TITLE, 'UTF-8');
    }

    /** Tempo entre admissão e desligamento em texto ("2 anos e 3 meses", "15 dias"); null se indeterminável. */
    public static function tempoDeEmpresa(?string $admissao, ?string $demissao): ?string
    {
        $a = self::data($admissao);
        $d = self::data($demissao);
        if ($a === null || $d === null || $d < $a) {
            return null;
        }
        $dif = $a->diff($d);
        $anos = (int)$dif->y;
        $meses = (int)$dif->m;
        $partes = [];
        if ($anos > 0) {
            $partes[] = $anos . ($anos === 1 ? ' ano' : ' anos');
        }
        if ($meses > 0) {
            $partes[] = $meses . ($meses === 1 ? ' mês' : ' meses');
        }
        if ($partes === []) {
            $dias = (int)$dif->days;
            return $dias === 0 ? 'menos de 1 dia' : $dias . ($dias === 1 ? ' dia' : ' dias');
        }
        return implode(' e ', $partes);
    }

    /**
     * Diferenças entre o snapshot e o dado oficial ATUAL do espelho (sinalização administrativa; o
     * snapshot nunca é sobrescrito).
     *
     * @return string[]
     */
    public static function divergencias(array $entrevista): array
    {
        $msgs = [];
        $atual = $entrevista['atual_demissao'] ?? null;
        $snap = (string)($entrevista['snap_demissao'] ?? '');
        if ($atual === null || $atual === '') {
            $msgs[] = 'A demissão não consta mais no METADADOS.';
        } elseif ((string)$atual !== $snap) {
            $msgs[] = 'Data de demissão oficial atual (' . date('d/m/Y', strtotime((string)$atual)) . ') difere da registrada na geração (' . date('d/m/Y', strtotime($snap)) . ').';
        }
        $motivoAtual = trim((string)($entrevista['atual_motivo_codigo'] ?? ''));
        $motivoSnap = trim((string)($entrevista['snap_motivo_codigo'] ?? ''));
        if ($motivoAtual !== $motivoSnap) {
            $msgs[] = 'Motivo oficial atual (' . ($motivoAtual !== '' ? $motivoAtual : 'vazio') . ') difere do registrado na geração (' . ($motivoSnap !== '' ? $motivoSnap : 'vazio') . ').';
        }
        return $msgs;
    }

    /**
     * Valida e normaliza a resposta pública. Nunca confia no navegador: classificação eNPS não é lida do POST.
     *
     * @return array{ok:bool,erros:string[],dados:array,fatores:string[],valores:array}
     */
    public static function validarResposta(array $post): array
    {
        $erros = [];
        $dados = [];
        $valores = [];

        $motivo = is_string($post['motivo_principal'] ?? null) ? $post['motivo_principal'] : '';
        $valores['motivo_principal'] = array_key_exists($motivo, self::MOTIVOS_DECLARADOS) ? $motivo : '';
        if ($valores['motivo_principal'] === '') {
            $erros[] = 'Selecione o motivo principal do seu desligamento.';
        }
        $dados['motivo_principal'] = $valores['motivo_principal'] !== '' ? $valores['motivo_principal'] : null;

        $escalaIncompleta = false;
        foreach (self::SECOES_ESCALA as $secao) {
            foreach (array_keys($secao['itens']) as $campo) {
                $nota = self::inteiroNaFaixa($post[$campo] ?? null, 1, 5);
                $valores[$campo] = $nota === null ? '' : (string)$nota;
                $dados[$campo] = $nota;
                $escalaIncompleta = $escalaIncompleta || $nota === null;
            }
        }
        if ($escalaIncompleta) {
            $erros[] = 'Responda todas as avaliações de 1 a 5 (Experiência, Liderança, Cultura e valores, Integração).';
        }

        foreach (['experiencia_geral' => 'a nota de experiência geral', 'enps' => 'a nota de recomendação (eNPS)'] as $campo => $rotulo) {
            $nota = self::inteiroNaFaixa($post[$campo] ?? null, 0, 10);
            $valores[$campo] = $nota === null ? '' : (string)$nota;
            $dados[$campo] = $nota;
            if ($nota === null) {
                $erros[] = 'Informe ' . $rotulo . ' (inteiro de 0 a 10).';
            }
        }

        $fatores = [];
        $brutos = $post['fatores'] ?? [];
        if (!is_array($brutos)) {
            $brutos = [];
        }
        foreach ($brutos as $fator) {
            if (!is_string($fator) || !array_key_exists($fator, self::FATORES_CONTRIBUINTES)) {
                $erros[] = 'Fator contribuinte inválido.';
                continue;
            }
            $fatores[$fator] = $fator;
        }
        $fatores = array_values($fatores);
        $valores['fatores'] = $fatores;

        $textos = ['motivo_descricao' => 'Descreva brevemente'];
        foreach (self::PERGUNTAS_ABERTAS as $campo => $pergunta) {
            $textos[$campo] = $pergunta;
        }
        foreach ($textos as $campo => $rotulo) {
            $texto = self::textoSeguro($post[$campo] ?? '');
            $valores[$campo] = $texto;
            if (mb_strlen($texto) > self::LIMITE_TEXTO) {
                $erros[] = '"' . $rotulo . '": máximo de ' . self::LIMITE_TEXTO . ' caracteres.';
            }
            $dados[$campo] = $texto !== '' ? $texto : null;
        }

        return ['ok' => $erros === [], 'erros' => array_values(array_unique($erros)), 'dados' => $dados, 'fatores' => $fatores, 'valores' => $valores];
    }

    private static function inteiroNaFaixa(mixed $valor, int $min, int $max): ?int
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }
        $valor = (string)$valor;
        if (!preg_match('/^(0|[1-9][0-9]?)$/', $valor)) {
            return null;
        }
        $n = (int)$valor;
        return $n >= $min && $n <= $max ? $n : null;
    }

    private static function textoSeguro(mixed $valor): string
    {
        if (!is_string($valor)) {
            return '';
        }
        $texto = Security::sanitizeString($valor);
        $texto = str_replace("\r\n", "\n", $texto);
        return trim((string)preg_replace('/[^\P{C}\n\t]+/u', '', $texto));
    }

    private static function data(mixed $valor): ?DateTimeImmutable
    {
        if ($valor instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($valor);
        }
        $texto = trim((string)$valor);
        if ($texto === '' || str_starts_with($texto, '0000')) {
            return null;
        }
        try {
            return new DateTimeImmutable($texto);
        } catch (Throwable) {
            return null;
        }
    }

    private static function formatoBanco(DateTimeImmutable $data): string
    {
        return $data->format('Y-m-d H:i:s');
    }

    // ================================================================== administração

    /** Critérios de elegibilidade repassados ao repository (mesma regra de elegibilidade()). */
    public static function criteriosElegibilidade(DateTimeImmutable $hoje): array
    {
        return ['hoje' => $hoje->format('Y-m-d'), 'motivo_excluido' => self::MOTIVO_OFICIAL_INELEGIVEL];
    }

    /**
     * Dados da tela administrativa. `visao`: elegiveis | pendente | respondida | expirada | cancelada.
     * Só situação operacional — nunca respostas.
     *
     * @param array $filtros visao, busca, codigo_empresa, dias
     */
    public function montarPainel(array $filtros, DateTimeImmutable $agora): array
    {
        $visao = in_array($filtros['visao'] ?? '', array_merge(['elegiveis'], self::SITUACOES), true) ? $filtros['visao'] : 'elegiveis';
        $filtroRepo = [
            'busca' => (string)($filtros['busca'] ?? ''),
            'codigo_empresa' => (string)($filtros['codigo_empresa'] ?? ''),
            'dias' => (int)($filtros['dias'] ?? 0),
        ];
        $criterios = self::criteriosElegibilidade($agora);

        $painel = [
            'visao' => $visao,
            'contagem' => $this->repository->contagemPorSituacao(self::formatoBanco($agora)),
            'elegiveis_total' => $this->repository->contarElegiveis($criterios, []),
            'itens' => [],
            'total_visao' => null,
            'limite' => self::LIMITE_LISTAGEM,
            'empresas' => $this->repository->opcoesEmpresa(),
        ];

        if ($visao === 'elegiveis') {
            $painel['itens'] = $this->repository->listarElegiveis($criterios, $filtroRepo, self::LIMITE_LISTAGEM);
            $painel['total_visao'] = $this->repository->contarElegiveis($criterios, $filtroRepo);
        } else {
            $itens = $this->repository->listarEntrevistas($visao, self::formatoBanco($agora), $filtroRepo, self::LIMITE_LISTAGEM);
            foreach ($itens as &$item) {
                $item['situacao'] = self::situacao($item, $agora);
                $item['divergencias'] = self::divergencias($item);
                $item['tempo_empresa'] = self::tempoDeEmpresa($item['snap_admissao'] ?? null, $item['snap_demissao'] ?? null);
            }
            unset($item);
            $painel['itens'] = $itens;
        }
        return $painel;
    }

    /**
     * Indicadores derivados: contagens operacionais + taxa de resposta (respondidas ÷ geradas) e, só com
     * `$comRespostas` (permissão `resultados`), o eNPS consolidado das respondidas.
     */
    public function indicadores(DateTimeImmutable $agora, bool $comRespostas): array
    {
        $c = $this->repository->contagemPorSituacao(self::formatoBanco($agora));
        $out = $c + [
            'taxa_resposta' => $c['geradas'] > 0 ? round(($c['respondidas'] / $c['geradas']) * 100, 1) : null,
            'enps' => null,
            'enps_distribuicao' => null,
        ];
        if ($comRespostas) {
            $dist = $this->repository->distribuicaoEnps();
            $out['enps_distribuicao'] = $dist;
            $out['enps'] = self::calcularEnps($dist);
        }
        return $out;
    }

    public function resultadoIndividual(int $id, DateTimeImmutable $agora): ?array
    {
        $e = $this->repository->buscarPorId($id);
        if ($e === null) {
            return null;
        }
        $e['situacao'] = self::situacao($e, $agora);
        $e['divergencias'] = self::divergencias($e);
        $e['tempo_empresa'] = self::tempoDeEmpresa($e['snap_admissao'] ?? null, $e['snap_demissao'] ?? null);
        $e['fatores'] = $this->repository->fatoresDaEntrevista($id);
        $e['enps_classificacao'] = $e['enps'] !== null ? self::classificarEnps((int)$e['enps']) : null;
        return $e;
    }

    /**
     * Gera a entrevista de um contrato elegível. O token BRUTO só existe neste retorno.
     *
     * @return array{ok:bool,error?:string,id?:int,token?:string,expira_em?:string}
     */
    public function gerar(int $metadadosId, int $usuarioId, DateTimeImmutable $agora): array
    {
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return ['ok' => false, 'error' => 'Contrato não encontrado.'];
        }
        $elegibilidade = self::elegibilidade($contrato, $agora);
        if (!$elegibilidade['elegivel']) {
            return ['ok' => false, 'error' => (string)$elegibilidade['mensagem']];
        }
        if ($this->repository->buscarPorMetadadosId($metadadosId) !== null) {
            return ['ok' => false, 'error' => 'Já existe uma entrevista para este contrato. Localize-a na lista de situações e use Regenerar link, se necessário.'];
        }

        $token = bin2hex(random_bytes(32));
        $expira = $agora->modify('+' . self::PRAZO_DIAS . ' days');
        try {
            $id = $this->repository->inserir(
                $metadadosId,
                hash('sha256', $token),
                self::formatoBanco($agora),
                self::formatoBanco($expira),
                $usuarioId,
                self::snapshotDoContrato($contrato)
            );
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'Já existe uma entrevista para este contrato.'];
            }
            throw $e;
        }
        return ['ok' => true, 'id' => $id, 'nome' => (string)$contrato['nome'], 'token' => $token, 'expira_em' => self::formatoBanco($expira)];
    }

    /**
     * Novo link para uma entrevista NÃO respondida (pendente, expirada ou cancelada): invalida o token
     * anterior, renova a expiração e mantém o snapshot original. Nunca reabre uma respondida.
     *
     * @return array{ok:bool,error?:string,token?:string,expira_em?:string}
     */
    public function regenerar(int $id, int $usuarioId, DateTimeImmutable $agora): array
    {
        $entrevista = $this->repository->buscarPorId($id);
        if ($entrevista === null) {
            return ['ok' => false, 'error' => 'Entrevista não encontrada.'];
        }
        if (!empty($entrevista['respondida_em'])) {
            return ['ok' => false, 'error' => 'Esta entrevista já foi respondida e não pode ser reaberta.'];
        }
        $contrato = $this->repository->buscarContrato((int)$entrevista['metadados_id']);
        $elegibilidade = $contrato === null ? ['elegivel' => false, 'mensagem' => 'Contrato não encontrado no METADADOS.'] : self::elegibilidade($contrato, $agora);
        if (!$elegibilidade['elegivel']) {
            return ['ok' => false, 'error' => 'Não é possível regenerar: ' . lcfirst((string)$elegibilidade['mensagem'])];
        }

        $token = bin2hex(random_bytes(32));
        $expira = $agora->modify('+' . self::PRAZO_DIAS . ' days');
        $ok = $this->repository->regenerar($id, hash('sha256', $token), self::formatoBanco($agora), self::formatoBanco($expira), $usuarioId);
        if (!$ok) {
            return ['ok' => false, 'error' => 'Esta entrevista já foi respondida e não pode ser reaberta.'];
        }
        return ['ok' => true, 'nome' => (string)$entrevista['snap_nome'], 'token' => $token, 'expira_em' => self::formatoBanco($expira)];
    }

    /** @return array{ok:bool,error?:string} */
    public function cancelar(int $id, int $usuarioId, DateTimeImmutable $agora): array
    {
        $entrevista = $this->repository->buscarPorId($id);
        if ($entrevista === null) {
            return ['ok' => false, 'error' => 'Entrevista não encontrada.'];
        }
        if (self::situacao($entrevista, $agora) !== 'pendente') {
            return ['ok' => false, 'error' => 'Só é possível cancelar entrevistas pendentes.'];
        }
        if (!$this->repository->cancelar($id, self::formatoBanco($agora), $usuarioId)) {
            return ['ok' => false, 'error' => 'A entrevista mudou de situação e não pôde ser cancelada.'];
        }
        return ['ok' => true];
    }

    /** Snapshot oficial mínimo — sem CPF, nascimento ou salário. */
    private static function snapshotDoContrato(array $contrato): array
    {
        $vazioParaNull = static fn(mixed $v): ?string => ($v === null || trim((string)$v) === '') ? null : trim((string)$v);
        return [
            'snap_nome' => (string)$contrato['nome'],
            'snap_codigo_empresa' => (string)$contrato['codigo_empresa'],
            'snap_empresa' => $vazioParaNull($contrato['empresa'] ?? null),
            'snap_codigo_unidade' => (string)$contrato['codigo_unidade'],
            'snap_unidade' => $vazioParaNull($contrato['unidade'] ?? null),
            'snap_codigo_cargo' => $vazioParaNull($contrato['codigo_cargo'] ?? null),
            'snap_cargo' => $vazioParaNull($contrato['cargo'] ?? null),
            'snap_admissao' => $vazioParaNull($contrato['admissao'] ?? null),
            'snap_demissao' => (string)$contrato['demissao'],
            'snap_motivo_codigo' => $vazioParaNull($contrato['motivo_rescisao_codigo'] ?? null),
            'snap_motivo_descricao' => $vazioParaNull($contrato['motivo_rescisao_descricao'] ?? null),
        ];
    }

    // ================================================================== página pública

    /**
     * Resolve o token bruto para o estado da página pública: invalido | concluida | indisponivel
     * (cancelada ou expirada — mesma tela neutra) | formulario. Contexto mínimo: primeiro nome, cargo,
     * admissão, desligamento, tempo de empresa — tudo do SNAPSHOT.
     *
     * @return array{estado:string,contexto:?array}
     */
    public function resolverParaPagina(string $tokenBruto, DateTimeImmutable $agora): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $tokenBruto)) {
            return ['estado' => 'invalido', 'contexto' => null];
        }
        $entrevista = $this->repository->buscarPorHash(hash('sha256', $tokenBruto));
        if ($entrevista === null) {
            return ['estado' => 'invalido', 'contexto' => null];
        }
        $situacao = self::situacao($entrevista, $agora);
        if ($situacao === 'respondida') {
            return ['estado' => 'concluida', 'contexto' => null];
        }
        if ($situacao !== 'pendente') {
            return ['estado' => 'indisponivel', 'contexto' => null];
        }
        return [
            'estado' => 'formulario',
            'contexto' => [
                'id' => (int)$entrevista['id'],
                'primeiro_nome' => self::primeiroNome($entrevista['snap_nome'] ?? ''),
                'cargo' => (string)($entrevista['snap_cargo'] ?? ''),
                'admissao' => $entrevista['snap_admissao'] ?? null,
                'demissao' => $entrevista['snap_demissao'] ?? null,
                'tempo_empresa' => self::tempoDeEmpresa($entrevista['snap_admissao'] ?? null, $entrevista['snap_demissao'] ?? null),
            ],
        ];
    }

    /**
     * Valida e conclui a entrevista. A gravação é atômica e condicional (ver
     * EntrevistaDesligamentoRepository::responder()): só uma submissão conclui.
     *
     * @return array{ok:bool,estado?:string,erros?:string[],valores?:array,contexto?:array}
     */
    public function registrarResposta(string $tokenBruto, array $post, DateTimeImmutable $agora): array
    {
        $resolvido = $this->resolverParaPagina($tokenBruto, $agora);
        if ($resolvido['estado'] !== 'formulario') {
            return ['ok' => false, 'estado' => $resolvido['estado']];
        }

        $validacao = self::validarResposta($post);
        if (!$validacao['ok']) {
            return ['ok' => false, 'estado' => 'formulario', 'erros' => $validacao['erros'], 'valores' => $validacao['valores'], 'contexto' => $resolvido['contexto']];
        }

        $gravou = $this->repository->responder(
            (int)$resolvido['contexto']['id'],
            hash('sha256', $tokenBruto),
            self::formatoBanco($agora),
            $validacao['dados'],
            $validacao['fatores']
        );
        if (!$gravou) {
            // Perdeu a corrida (respondida/cancelada/regenerada/expirada entre a leitura e a gravação).
            return ['ok' => false, 'estado' => $this->resolverParaPagina($tokenBruto, $agora)['estado']];
        }
        return ['ok' => true, 'estado' => 'concluida'];
    }
}
