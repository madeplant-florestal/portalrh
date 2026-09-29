<?php

/**
 * Avaliação do Período de Experiência (45/90 dias — Etapa 4, 2026-09). Preserva a estrutura oficial
 * (escala 1–5 por critério dos 6 valores culturais, avaliação técnica Sim/Parcialmente/Não, adaptação
 * Excelente/Boa/Regular/Insatisfatória, parecer final com justificativa condicional). Registrar o
 * parecer NUNCA gera efetivação/desligamento automático — toda decisão continua humana/RH (§20/§21).
 *
 * PENDÊNCIAS 45/90 são DERIVADAS (nunca pré-criadas): derivarStatus() cruza a janela de datas com o que
 * já existe em `avaliacoes_experiencia`. Só existe LINHA na tabela quando a avaliação é efetivamente
 * salva (rascunho) ou concluída.
 *
 * Permissão/escopo por linha: MESMO padrão de PdiService (Admin/RH = escopo total; qualquer outro
 * usuário só acessa avaliações/pendências em que é o gestor responsável, via
 * `usuarios.gestor_usuario_id` — nunca aprovador, nunca a sinalização de supervisor).
 */
class AvaliacaoExperienciaService
{
    public const TIPOS = ['45' => '45 dias', '90' => '90 dias'];
    public const ANTECEDENCIA_ALERTA_DIAS = 5;
    /** Implantação inicial (§54 da Etapa 4): sem backlog histórico — só entram candidatos "de agora em
     *  diante" (contratos sem avaliação e admitidos dentro desta janela). Uma avaliação já iniciada/
     *  concluída SEMPRE aparece, não importa a idade da admissão (histórico real nunca é escondido). */
    public const JANELA_RELEVANTE_DIAS = 95;

    public const VALORES_CULTURAIS = [
        'respeito' => ['label' => 'Respeito', 'criterios' => [
            'Trata colegas e liderança com respeito', 'Trabalha bem em equipe',
            'Mantém postura profissional', 'Demonstra boa comunicação',
        ]],
        'honestidade' => ['label' => 'Honestidade', 'criterios' => [
            'Age com sinceridade', 'Assume erros quando necessário',
            'Transmite informações corretas', 'Demonstra coerência nas atitudes',
        ]],
        'lealdade' => ['label' => 'Lealdade', 'criterios' => [
            'Cumpre responsabilidades', 'Demonstra comprometimento',
            'Preserva a imagem da empresa', 'Atua alinhado às orientações',
        ]],
        'etica' => ['label' => 'Ética', 'criterios' => [
            'Cumpre normas e procedimentos', 'Age corretamente no ambiente de trabalho',
            'Demonstra responsabilidade', 'Mantém postura ética',
        ]],
        'ousadia' => ['label' => 'Ousadia', 'criterios' => [
            'Demonstra iniciativa', 'Busca soluções para problemas',
            'Sugere melhorias', 'Aprende rapidamente',
        ]],
        'coragem' => ['label' => 'Coragem', 'criterios' => [
            'Assume responsabilidades', 'Enfrenta desafios sem evitar situações',
            'Demonstra segurança nas atividades', 'Busca resolver problemas',
        ]],
    ];

    public const ESCALA = [1 => 'Não atende', 2 => 'Atende parcialmente', 3 => 'Atende', 4 => 'Supera', 5 => 'Referência'];
    public const TECNICA_OPCOES = ['sim' => 'Sim', 'parcialmente' => 'Parcialmente', 'nao' => 'Não'];
    public const ADAPTACAO_OPCOES = ['excelente' => 'Excelente', 'boa' => 'Boa', 'regular' => 'Regular', 'insatisfatoria' => 'Insatisfatória'];
    public const PARECER_OPCOES = [
        'apto_efetivacao' => 'Apto para efetivação',
        'efetivacao_acompanhamento' => 'Efetivação com acompanhamento',
        'prorrogacao_experiencia' => 'Necessita prorrogação da experiência',
        'nao_recomendado' => 'Não recomendado para efetivação',
    ];
    /** Justificativa obrigatória para todos os pareceres, exceto "Apto para efetivação" (§19). */
    public const PARECER_EXIGE_JUSTIFICATIVA = ['efetivacao_acompanhamento', 'prorrogacao_experiencia', 'nao_recomendado'];

    private AvaliacaoExperienciaRepository $repository;
    private AvaliacoesDesenvolvimentoAuditoriaService $auditoria;

    public function __construct(?AvaliacaoExperienciaRepository $repository = null, ?AvaliacoesDesenvolvimentoAuditoriaService $auditoria = null)
    {
        $this->repository = $repository ?? new AvaliacaoExperienciaRepository();
        $this->auditoria = $auditoria ?? new AvaliacoesDesenvolvimentoAuditoriaService();
    }

    // ============================================================ permissão / escopo (mesmo padrão do PDI)

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

    public static function papelDoAtor(array $ator): string
    {
        if (($ator['role'] ?? '') === 'admin') {
            return 'admin';
        }
        return ($ator['role'] ?? '') === 'rh' ? 'rh' : 'gestor';
    }

    public static function podeAcessarGestorId(?int $gestorId, array $ator): bool
    {
        return (int)($ator['id'] ?? 0) > 0 && (self::escopoTotal($ator) || $gestorId === (int)$ator['id']);
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

    // ============================================================ derivação de status (§23/§24)

    /**
     * @param array $item linha de candidatosPendencia(): admissao, demissao, avaliacao_id,
     *                     avaliacao_status, data_realizacao, parecer
     * @return array{status:string,data_prevista:DateTimeImmutable,dias_para_prazo:int}
     */
    public static function derivarStatus(array $item, string $tipo, DateTimeImmutable $hoje, AvaliacoesDesenvolvimentoAuditoriaService $auditoria): array
    {
        $admissao = new DateTimeImmutable((string)$item['admissao']);
        $dataPrevista = $admissao->modify('+' . $tipo . ' days');
        $alertaAPartir = $dataPrevista->modify('-' . self::ANTECEDENCIA_ALERTA_DIAS . ' days');
        $hojeSemHora = $hoje->setTime(0, 0);
        $diasParaPrazo = (int)$hojeSemHora->diff($dataPrevista)->format('%r%a');

        $avaliacaoId = $item['avaliacao_id'] !== null ? (int)$item['avaliacao_id'] : null;
        $avaliacaoStatus = $item['avaliacao_status'] ?? null;

        if ($avaliacaoStatus === 'concluido') {
            $temCiencia = $avaliacaoId !== null && $auditoria->temCiencia('avaliacao_experiencia', $avaliacaoId, 'colaborador');
            return ['status' => $temCiencia ? 'realizada' : 'aguardando_ciencia', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }
        if ($avaliacaoStatus === 'cancelado') {
            return ['status' => 'cancelada', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }

        $demissao = !empty($item['demissao']) ? new DateTimeImmutable((string)$item['demissao']) : null;
        $desligadoAntesDoPrazo = $demissao !== null && $demissao <= $hojeSemHora && $demissao < $dataPrevista;
        if ($desligadoAntesDoPrazo && $avaliacaoStatus !== 'rascunho') {
            return ['status' => 'nao_aplicavel_desligado', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }

        if ($avaliacaoStatus === 'rascunho') {
            return ['status' => 'em_preenchimento', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }
        if ($hojeSemHora < $alertaAPartir) {
            return ['status' => 'futura', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }
        if ($hojeSemHora <= $dataPrevista) {
            return ['status' => 'pendente', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
        }
        return ['status' => 'vencida', 'data_prevista' => $dataPrevista, 'dias_para_prazo' => $diasParaPrazo];
    }

    public const ROTULOS_STATUS = [
        'futura' => 'Futura', 'pendente' => 'Pendente', 'vencida' => 'Vencida',
        'em_preenchimento' => 'Em preenchimento', 'aguardando_ciencia' => 'Aguardando ciência',
        'realizada' => 'Realizada', 'nao_aplicavel_desligado' => 'Não aplicável — desligado antes do prazo',
        'cancelada' => 'Cancelada',
    ];

    /**
     * Lista derivada de pendências/avaliações (§26/§27). Uma chamada por tipo (45 e 90) — sem N+1: o
     * gestor de cada contrato já vem resolvido em lote pelo repository.
     *
     * `(string)$tipo` é OBRIGATÓRIO aqui: chaves de array puramente numéricas ('45'/'90') são
     * convertidas por PHP para int nas próprias constantes de TIPOS/ROTULOS_STATUS — sem o cast,
     * `$i['tipo']` guardaria int(45)/int(90) e qualquer comparação ESTRITA (`===`) contra a string
     * '45'/'90' vinda do GET falharia sempre (o filtro de Tipo da tela nunca combinava nada).
     */
    public function listarPendencias(array $filtros, array $ator, DateTimeImmutable $hoje): array
    {
        $escopoGestor = self::escopoGestor($ator);
        $cutoff = $hoje->modify('-' . self::JANELA_RELEVANTE_DIAS . ' days')->format('Y-m-d');

        $itens = [];
        foreach (array_keys(self::TIPOS) as $tipoChave) {
            $tipo = (string)$tipoChave;
            foreach ($this->repository->candidatosPendencia($tipo, $cutoff, $filtros, $escopoGestor) as $row) {
                $derivado = self::derivarStatus($row, $tipo, $hoje, $this->auditoria);
                $itens[] = array_merge($row, $derivado, ['tipo' => $tipo]);
            }
        }

        if (!empty($filtros['status'])) {
            $itens = array_values(array_filter($itens, static fn(array $i): bool => $i['status'] === $filtros['status']));
        }
        if (!empty($filtros['tipo'])) {
            $tipoFiltro = (string)$filtros['tipo'];
            $itens = array_values(array_filter($itens, static fn(array $i): bool => $i['tipo'] === $tipoFiltro));
        }

        usort($itens, static fn(array $a, array $b): int => $a['dias_para_prazo'] <=> $b['dias_para_prazo']);

        $contagem = array_fill_keys(array_keys(self::ROTULOS_STATUS), 0);
        foreach ($itens as $i) {
            $contagem[$i['status']]++;
        }

        return ['itens' => $itens, 'contagem' => $contagem];
    }

    // ============================================================ ciclo de vida

    /** Contrato + avaliação existente (se houver) + critérios, prontos para o formulário. */
    public function contratoParaAvaliar(int $metadadosId, string $tipo, array $ator): array
    {
        if (!array_key_exists($tipo, self::TIPOS)) {
            return self::falha('Tipo de avaliação inválido.');
        }
        $contrato = $this->repository->buscarContrato($metadadosId);
        if ($contrato === null) {
            return self::falha('Contrato não encontrado.');
        }
        $gestor = (new UsuarioGestorRepository())->gestorDoUsuarioDoContrato($metadadosId);
        $gestorId = $gestor !== null ? (int)$gestor['id'] : null;
        if (!self::podeAcessarGestorId($gestorId, $ator) && !self::escopoTotal($ator)) {
            return self::falha('Você não tem acesso a este colaborador.');
        }

        $avaliacao = $this->repository->buscarAvaliacao($metadadosId, $tipo);
        $criterios = $avaliacao !== null ? $this->repository->buscarCriterios((int)$avaliacao['id']) : [];
        $ciencias = $avaliacao !== null ? $this->auditoria->listarCiencias('avaliacao_experiencia', (int)$avaliacao['id']) : [];

        return [
            'ok' => true, 'contrato' => $contrato, 'gestor' => $gestor, 'avaliacao' => $avaliacao,
            'criterios' => $criterios, 'ciencias' => $ciencias, 'tipo' => $tipo,
        ];
    }

    /** @param array $dados campos do formulário já com Security::sanitizeString/parseInt aplicados pelo controller */
    public function salvarRascunho(int $metadadosId, string $tipo, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_experiencia.avaliar')) {
            return $erro;
        }
        $ctx = $this->contratoParaAvaliar($metadadosId, $tipo, $ator);
        if (!($ctx['ok'] ?? false)) {
            return $ctx;
        }
        $contrato = $ctx['contrato'];
        if (!empty($contrato['demissao']) && $ctx['avaliacao'] === null) {
            return self::falha('Este contrato já está desligado e nunca teve esta avaliação iniciada — não é possível abrir uma nova.');
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        $gestorUsuarioId = (int)($dados['gestor_usuario_id'] ?? ($ctx['gestor']['id'] ?? $ator['id']));
        $gestorUsuario = $this->repository->usuarioAtivo($gestorUsuarioId);
        if ($gestorUsuario === null) {
            return self::falha('Selecione um gestor responsável válido.');
        }

        return $this->repository->transacao(function () use ($ctx, $contrato, $tipo, $dados, $gestorUsuarioId, $gestorUsuario, $ator, $ip, $agoraSql, $metadadosId): array {
            $camposEditaveis = [
                'valores_comentario_respeito' => $dados['valores_comentario_respeito'] ?? null,
                'valores_comentario_honestidade' => $dados['valores_comentario_honestidade'] ?? null,
                'valores_comentario_lealdade' => $dados['valores_comentario_lealdade'] ?? null,
                'valores_comentario_etica' => $dados['valores_comentario_etica'] ?? null,
                'valores_comentario_ousadia' => $dados['valores_comentario_ousadia'] ?? null,
                'valores_comentario_coragem' => $dados['valores_comentario_coragem'] ?? null,
                'tecnica_capacidade' => $dados['tecnica_capacidade'] ?? null,
                'tecnica_comentarios' => $dados['tecnica_comentarios'] ?? null,
                'adaptacao_nivel' => $dados['adaptacao_nivel'] ?? null,
                'adaptacao_comentarios' => $dados['adaptacao_comentarios'] ?? null,
                'feedback_geral' => $dados['feedback_geral'] ?? null,
                'gestor_usuario_id' => $gestorUsuarioId,
                'gestor_nome_snapshot' => (string)$gestorUsuario['nome'],
                'atualizado_em' => $agoraSql,
            ];

            if ($ctx['avaliacao'] === null) {
                $admissao = new DateTimeImmutable((string)$contrato['admissao']);
                $id = $this->repository->inserir(array_merge($camposEditaveis, [
                    'metadados_id' => $metadadosId, 'tipo' => $tipo,
                    'snap_nome' => (string)$contrato['nome'], 'snap_codigo_empresa' => (string)$contrato['codigo_empresa'],
                    'snap_empresa' => $contrato['empresa'], 'snap_codigo_unidade' => (string)$contrato['codigo_unidade'],
                    'snap_unidade' => $contrato['unidade'], 'snap_codigo_setor' => $contrato['codigo_setor'], 'snap_setor' => $contrato['setor'],
                    'snap_codigo_cargo' => $contrato['codigo_cargo'], 'snap_cargo' => $contrato['cargo'],
                    'snap_admissao' => (string)$contrato['admissao'], 'status' => 'rascunho',
                    'data_prevista' => $admissao->modify('+' . $tipo . ' days')->format('Y-m-d'),
                    'criado_por_usuario_id' => (int)$ator['id'], 'criado_em' => $agoraSql,
                ]));
                $this->auditoria->registrarEvento('avaliacao_experiencia', $id, 'criacao', null, null, 'rascunho', $ator, null, $agoraSql);
            } else {
                $id = (int)$ctx['avaliacao']['id'];
                if ((string)$ctx['avaliacao']['status'] === 'concluido') {
                    return self::falha('Esta avaliação já foi concluída. Peça a um RH/Admin para reabrir antes de editar.');
                }
                $this->repository->atualizar($id, $camposEditaveis);
                $this->auditoria->registrarEvento('avaliacao_experiencia', $id, 'atualizacao_rascunho', null, null, null, $ator, null, $agoraSql);
            }

            $this->repository->salvarCriterios($id, $this->montarNotas($dados));
            return ['ok' => true, 'id' => $id];
        });
    }

    public function concluir(int $id, array $dados, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_experiencia.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->repository->buscarPorId($id);
        if ($avaliacao === null || !self::podeAcessarGestorId((int)$avaliacao['gestor_usuario_id'], $ator)) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] === 'concluido') {
            return self::falha('Esta avaliação já está concluída.');
        }

        $parecer = (string)($dados['parecer'] ?? '');
        if (!array_key_exists($parecer, self::PARECER_OPCOES)) {
            return self::falha('Selecione um parecer final válido.');
        }
        $justificativa = trim((string)($dados['parecer_justificativa'] ?? ''));
        if (in_array($parecer, self::PARECER_EXIGE_JUSTIFICATIVA, true) && $justificativa === '') {
            return self::falha('Justificativa obrigatória para este parecer.');
        }

        $agoraSql = $agora->format('Y-m-d H:i:s');
        return $this->repository->transacao(function () use ($id, $parecer, $justificativa, $dados, $ator, $ip, $agoraSql): array {
            $this->repository->salvarCriterios($id, $this->montarNotas($dados));
            $this->repository->atualizar($id, [
                'valores_comentario_respeito' => $dados['valores_comentario_respeito'] ?? null,
                'valores_comentario_honestidade' => $dados['valores_comentario_honestidade'] ?? null,
                'valores_comentario_lealdade' => $dados['valores_comentario_lealdade'] ?? null,
                'valores_comentario_etica' => $dados['valores_comentario_etica'] ?? null,
                'valores_comentario_ousadia' => $dados['valores_comentario_ousadia'] ?? null,
                'valores_comentario_coragem' => $dados['valores_comentario_coragem'] ?? null,
                'tecnica_capacidade' => $dados['tecnica_capacidade'] ?? null,
                'tecnica_comentarios' => $dados['tecnica_comentarios'] ?? null,
                'adaptacao_nivel' => $dados['adaptacao_nivel'] ?? null,
                'adaptacao_comentarios' => $dados['adaptacao_comentarios'] ?? null,
                'feedback_geral' => $dados['feedback_geral'] ?? null,
                'parecer' => $parecer, 'parecer_justificativa' => $justificativa !== '' ? $justificativa : null,
                'status' => 'concluido', 'data_realizacao' => $agoraSql,
                'atualizado_em' => $agoraSql,
            ]);
            $this->auditoria->registrarEvento('avaliacao_experiencia', $id, 'conclusao', 'parecer', null, $parecer, $ator, $ip, $agoraSql);
            return ['ok' => true, 'id' => $id];
        });
    }

    /** Ciência do colaborador — ASSISTIDA nesta fase (RH/Gestor confirma que foi dada ciência ao colaborador). */
    public function registrarCienciaColaborador(int $id, string $nomeColaborador, array $ator, DateTimeImmutable $agora): array
    {
        if ($erro = self::semPermissao($ator, 'avaliacao_experiencia.avaliar')) {
            return $erro;
        }
        $avaliacao = $this->repository->buscarPorId($id);
        if ($avaliacao === null || !self::podeAcessarGestorId((int)$avaliacao['gestor_usuario_id'], $ator)) {
            return self::falha('Avaliação não encontrada.');
        }
        if ((string)$avaliacao['status'] !== 'concluido') {
            return self::falha('Só é possível registrar ciência de uma avaliação concluída.');
        }
        $agoraSql = $agora->format('Y-m-d H:i:s');
        $this->auditoria->registrarCiencia('avaliacao_experiencia', $id, 'colaborador', null, $nomeColaborador, $agoraSql);
        $this->auditoria->registrarEvento('avaliacao_experiencia', $id, 'ciencia_colaborador', null, null, $agoraSql, $ator, null, $agoraSql);
        return ['ok' => true];
    }

    public function reabrir(int $id, string $justificativa, array $ator, DateTimeImmutable $agora, ?string $ip): array
    {
        if (!self::escopoTotal($ator) || (self::temPermissao($ator, 'avaliacao_experiencia.avaliar') === false)) {
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
            $this->repository->atualizar($id, ['status' => 'rascunho', 'data_realizacao' => null, 'atualizado_em' => $agoraSql]);
            $this->auditoria->registrarEvento('avaliacao_experiencia', $id, 'reabertura', 'status', 'concluido', 'rascunho — ' . $texto, $ator, $ip, $agoraSql);
            return ['ok' => true];
        });
    }

    public function detalhe(int $id, array $ator): ?array
    {
        $avaliacao = $this->repository->buscarPorId($id);
        if ($avaliacao === null || !self::podeAcessarGestorId((int)$avaliacao['gestor_usuario_id'], $ator)) {
            return null;
        }
        return [
            'avaliacao' => $avaliacao,
            'criterios' => $this->repository->buscarCriterios($id),
            'ciencias' => $this->auditoria->listarCiencias('avaliacao_experiencia', $id),
            'eventos' => $this->auditoria->listarEventos('avaliacao_experiencia', $id),
        ];
    }

    public function opcoesFiltro(): array
    {
        return $this->repository->opcoesFiltro();
    }

    /** @return array<int,array{0:string,1:int,2:?int}> [valor, indice, nota] a partir de notas_<valor>_<indice> do POST */
    private function montarNotas(array $dados): array
    {
        $notas = [];
        foreach (array_keys(self::VALORES_CULTURAIS) as $valor) {
            for ($i = 1; $i <= 4; $i++) {
                $bruto = $dados['nota_' . $valor . '_' . $i] ?? null;
                $nota = ($bruto !== null && ctype_digit((string)$bruto) && (int)$bruto >= 1 && (int)$bruto <= 5) ? (int)$bruto : null;
                $notas[] = [$valor, $i, $nota];
            }
        }
        return $notas;
    }
}
