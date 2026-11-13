<?php

/**
 * Acesso a dados do Dashboard de Recrutamento e Seleção.
 *
 * Fontes:
 *   - `candidaturas`/`pipeline_movements`/`pipeline_stages`/`vagas` (Portal, geração legada de
 *     recrutamento) para funil, tempo por etapa e tempo de contratação;
 *   - `solicitacoes_vaga`/`solicitacao_vaga_stages` (Kanban de Solicitação de Vaga) para vagas
 *     abertas/fechadas — nunca `vagas.ativo`, que só controla a visibilidade pública da vaga, não
 *     o ciclo operacional de recrutamento (ver decisão registrada na sprint);
 *   - `pesquisas_experiencia` para a Qualidade da Contratação;
 *   - `colaboradores_metadados` (espelho oficial do METADADOS) para Turnover/Efetivação/
 *     Desligamento na experiência — nunca escreve no SQL Server, sempre lê o espelho local já
 *     sincronizado (mesma disciplina de `RhIndicadoresRepository`).
 *
 * Nunca seleciona nome/e-mail/CPF/telefone do candidato — só IDs, datas e estágios (mesma
 * disciplina de privacidade de `RhIndicadoresRepository`).
 */
class RecrutamentoIndicadoresRepository
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::conn();
        }
        return $this->pdo;
    }

    /**
     * Candidaturas do período (coorte por `created_at`), com o estágio atual já resolvido —
     * alimenta o funil, o tempo por etapa e o tempo de contratação a partir de uma única consulta.
     *
     * @return array Cada item: id, vaga_id, stage_id, created_at.
     */
    public function buscarCandidaturasCohort(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        ?int $empresaId,
        ?int $vagaId
    ): array {
        $where = ['c.created_at >= ?', 'c.created_at <= ?'];
        $params = [$inicio->format('Y-m-d 00:00:00'), $fim->format('Y-m-d 23:59:59')];

        if ($empresaId !== null) {
            $where[] = 'v.empresa_id = ?';
            $params[] = $empresaId;
        }
        if ($vagaId !== null) {
            $where[] = 'c.vaga_id = ?';
            $params[] = $vagaId;
        }

        $sql = 'SELECT c.id, c.vaga_id, c.stage_id, c.created_at
                FROM candidaturas c
                INNER JOIN vagas v ON v.id = c.vaga_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.created_at ASC';

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Movimentações reais de pipeline das candidaturas informadas, em ordem cronológica — fonte
     * única para "passagem real pela etapa" (funil) e para duração concluída por etapa. Nunca usa
     * `candidatura_stage_metadata` (sobrescrita a cada troca de etapa, não é histórico).
     *
     * @param int[] $candidaturaIds
     * @return array Cada item: candidatura_id, stage_novo_id, created_at.
     */
    public function buscarMovimentacoes(array $candidaturaIds): array
    {
        if ($candidaturaIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($candidaturaIds), '?'));
        $sql = "SELECT candidatura_id, stage_novo_id, created_at
                FROM pipeline_movements
                WHERE candidatura_id IN ($placeholders)
                ORDER BY candidatura_id ASC, created_at ASC, id ASC";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute(array_values($candidaturaIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Quantidade de solicitações de vaga atualmente abertas (situação operacional diferente de
     * "fechada"/"cancelada") — é um retrato de AGORA, não filtrado por período, mesma convenção de
     * "headcount atual" em RhIndicadoresRepository. Fonte oficial única, reaproveitada por
     * PeopleAnalyticsService (card "Vagas Abertas") e RecrutamentoIndicadoresService (Dashboard de
     * Recrutamento) — nenhum filtro paralelo em nenhum dos dois.
     *
     * Bloco 5 (2026-10, pedido do RH): `sv.cancelada_em IS NULL` somado ao filtro por estágio do
     * Kanban (`st.slug`) — defesa em profundidade contra o estágio (`situacao_kanban_id`) ficar
     * fora de sincronia com o cancelamento real (`cancelada_em`, setado só por
     * SolicitacaoVagaPipelineService::moveToStage() ao mover para o estágio 'cancelada').
     *
     * Bloco 6 (2026-10, pedido do RH — regra definitiva): `status_fluxo` e `situacao_kanban_id` são
     * DUAS máquinas de estado DESACOPLADAS de propósito (ver migration 2026-08-25, comentário de
     * cabeçalho) — `situacao_kanban_id` só muda por drag-and-drop manual no Kanban, enquanto
     * `SolicitacaoVaga::approve()`/`saveRhControl()` sempre atualizam `status_fluxo` de forma
     * síncrona e confiável. Por isso o filtro é por INCLUSÃO do único status que representa
     * aprovação efetiva (`SolicitacaoVaga::STATUS_APROVADA` = 'aprovada') — não por exclusão de
     * status negativos: `pendente_lider`/`pendente_rh` (ainda não é uma vaga aprovada, é só uma
     * solicitação em avaliação), `reprovada_lider`/`reprovada_rh` e `concluida` NUNCA contam,
     * mesmo que o card do Kanban nunca tenha sido movido. O filtro por estágio/cancelamento
     * continua como defesa em profundidade adicional (ex.: aprovada mas com o card já movido para
     * "Fechada"/"Cancelada" manualmente).
     */
    public function contarSolicitacoesAbertas(?int $empresaId): array
    {
        $where = [
            "sv.status_fluxo = '" . SolicitacaoVaga::STATUS_APROVADA . "'",
            "st.slug NOT IN ('fechada', 'cancelada')",
            'sv.cancelada_em IS NULL',
            // Exclusão analítica GLOBAL: vaga ligada a colaborador de contrato excluído não conta nas estatísticas.
            ColaboradorMetadadosElegibilidadeService::clausulaSqlExclusaoProcesso(SolicitacaoVaga::COLUNAS_COLABORADOR_PROCESSO),
        ];
        $params = [];
        if ($empresaId !== null) {
            $where[] = 'se.empresa_id = ?';
            $params[] = $empresaId;
        }

        $sql = 'SELECT COUNT(*) AS total
                FROM solicitacoes_vaga sv
                INNER JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id
                INNER JOIN setores se ON se.id = sv.setor_id
                WHERE ' . implode(' AND ', $where);
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return (array)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * ANÚNCIOS ATIVOS x POSIÇÕES SOLICITADAS (2026-11-13) — situação ATUAL (nunca recortada por período).
     *
     * Por que existe: `contarSolicitacoesAbertas()` conta SOLICITAÇÕES aprovadas em aberto e não olha a
     * tabela `vagas`; por isso uma aprovada cujo anúncio ainda é rascunho (`vagas.ativo = 0`) entrava no
     * card "Vagas Abertas". Aqui a unidade é o ANÚNCIO que o público realmente vê (`vagas.ativo = 1`, o
     * mesmo critério de Vaga::allActive()) e a quantidade vem da Solicitação:
     *  - anúncio ligado a solicitação: só conta se a solicitação segue aprovada, não cancelada e fora de
     *    fechada/cancelada (mesma regra de contarSolicitacoesAbertas), e sem vínculo a contrato excluído;
     *  - anúncio MANUAL (cadastro excepcional, sem solicitação): conta como anúncio ativo, mas NÃO tem campo
     *    de quantidade — suas posições ficam "não informadas", nunca presumidas como 1;
     *  - posições = SUM(quantidade_vagas) por SOLICITAÇÃO distinta (vários anúncios da mesma solicitação,
     *    candidaturas e republicações não multiplicam);
     *  - posições preenchidas/restantes NÃO são calculadas: não há evento confiável de contratação efetiva.
     *
     * @return array{anuncios_ativos:int,anuncios_manuais:int,posicoes_solicitadas:int,solicitacoes_com_anuncio:int,
     *               aguardando_publicacao:int,por_cargo:array<int,array{cargo:string,anuncios:int,posicoes:?int}>}
     */
    public function resumirAnunciosEPosicoes(?int $empresaId): array
    {
        $where = [
            'v.ativo = 1',
            "(v.solicitacao_vaga_id IS NULL OR (sv.status_fluxo = '" . SolicitacaoVaga::STATUS_APROVADA . "'"
                . " AND st.slug NOT IN ('fechada', 'cancelada') AND sv.cancelada_em IS NULL AND "
                . ColaboradorMetadadosElegibilidadeService::clausulaSqlExclusaoProcesso(SolicitacaoVaga::COLUNAS_COLABORADOR_PROCESSO) . '))',
        ];
        $params = [];
        if ($empresaId !== null) {
            $where[] = '((v.solicitacao_vaga_id IS NULL AND v.empresa_id = ?) OR se.empresa_id = ?)';
            array_push($params, $empresaId, $empresaId);
        }

        $stmt = $this->connection()->prepare(
            'SELECT v.id AS vaga_id, v.titulo, v.solicitacao_vaga_id, sv.quantidade_vagas, sv.cargo_id, c.nome AS cargo_nome
             FROM vagas v
             LEFT JOIN solicitacoes_vaga sv ON sv.id = v.solicitacao_vaga_id
             LEFT JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id
             LEFT JOIN setores se ON se.id = sv.setor_id
             LEFT JOIN cargos c ON c.id = sv.cargo_id
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        $anuncios = 0;
        $manuais = 0;
        $solicitacoes = [];
        $grupos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $anuncios++;
            $solicitacaoId = $linha['solicitacao_vaga_id'] !== null ? (int)$linha['solicitacao_vaga_id'] : null;
            if ($solicitacaoId === null) {
                $manuais++;
                $chave = 'manual';
                $rotulo = 'Cadastro manual (sem solicitação)';
                $grupos[$chave]['posicoes'] = null;
            } else {
                $chave = 'cargo:' . (int)$linha['cargo_id'];
                $rotulo = (string)($linha['cargo_nome'] ?? '') !== '' ? (string)$linha['cargo_nome'] : (string)$linha['titulo'];
                $grupos[$chave]['posicoes'] = $grupos[$chave]['posicoes'] ?? 0;
                // Posições contadas UMA vez por solicitação, mesmo com vários anúncios ligados a ela.
                if (!isset($solicitacoes[$solicitacaoId])) {
                    $solicitacoes[$solicitacaoId] = max(0, (int)$linha['quantidade_vagas']);
                    $grupos[$chave]['posicoes'] += $solicitacoes[$solicitacaoId];
                }
            }
            $grupos[$chave]['cargo'] = $rotulo;
            $grupos[$chave]['anuncios'] = ($grupos[$chave]['anuncios'] ?? 0) + 1;
        }

        $porCargo = array_values($grupos);
        usort($porCargo, static fn(array $a, array $b): int => ($b['posicoes'] ?? -1) <=> ($a['posicoes'] ?? -1) ?: strcmp($a['cargo'], $b['cargo']));

        $abertas = (int)($this->contarSolicitacoesAbertas($empresaId)['total'] ?? 0);
        return [
            'anuncios_ativos' => $anuncios,
            'anuncios_manuais' => $manuais,
            'posicoes_solicitadas' => array_sum($solicitacoes),
            'solicitacoes_com_anuncio' => count($solicitacoes),
            'aguardando_publicacao' => max(0, $abertas - count($solicitacoes)),
            'por_cargo' => array_map(static fn(array $g): array => [
                'cargo' => (string)$g['cargo'], 'anuncios' => (int)$g['anuncios'], 'posicoes' => $g['posicoes'] === null ? null : (int)$g['posicoes'],
            ], $porCargo),
        ];
    }

    /** Solicitações fechadas no período (situação = 'fechada', recorte por `fechada_em`). */
    public function contarSolicitacoesFechadas(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?int $empresaId): array
    {
        $where = ["st.slug = 'fechada'", 'sv.fechada_em >= ?', 'sv.fechada_em <= ?',
            ColaboradorMetadadosElegibilidadeService::clausulaSqlExclusaoProcesso(SolicitacaoVaga::COLUNAS_COLABORADOR_PROCESSO)];
        $params = [$inicio->format('Y-m-d 00:00:00'), $fim->format('Y-m-d 23:59:59')];
        if ($empresaId !== null) {
            $where[] = 'se.empresa_id = ?';
            $params[] = $empresaId;
        }

        $sql = 'SELECT COUNT(*) AS total
                FROM solicitacoes_vaga sv
                INNER JOIN solicitacao_vaga_stages st ON st.id = sv.situacao_kanban_id
                INNER JOIN setores se ON se.id = sv.setor_id
                WHERE ' . implode(' AND ', $where);
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return (array)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Pesquisas de Experiência respondidas no período (por `respondida_em`), já filtráveis por
     * empresa/vaga através da candidatura de origem.
     *
     * @return array Cada item: nota_clareza, nota_tempo_retorno, nota_atendimento.
     */
    public function buscarPesquisasRespondidas(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        ?int $empresaId,
        ?int $vagaId
    ): array {
        $where = ['pe.respondida_em IS NOT NULL', 'pe.respondida_em >= ?', 'pe.respondida_em <= ?'];
        $params = [$inicio->format('Y-m-d 00:00:00'), $fim->format('Y-m-d 23:59:59')];
        if ($empresaId !== null) {
            $where[] = 'v.empresa_id = ?';
            $params[] = $empresaId;
        }
        if ($vagaId !== null) {
            $where[] = 'c.vaga_id = ?';
            $params[] = $vagaId;
        }

        $sql = 'SELECT pe.nota_clareza, pe.nota_tempo_retorno, pe.nota_atendimento
                FROM pesquisas_experiencia pe
                INNER JOIN candidaturas c ON c.id = pe.candidatura_id
                INNER JOIN vagas v ON v.id = c.vaga_id
                WHERE ' . implode(' AND ', $where);
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Empresas ativas para o filtro — dimensão local de recrutamento (vagas.empresa_id). */
    public function opcoesEmpresas(): array
    {
        $sql = 'SELECT id, nome FROM empresas WHERE ativo = 1 ORDER BY nome';
        return $this->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Vagas para o filtro — todas (fechadas/inativas também podem ter candidaturas históricas). */
    public function opcoesVagas(): array
    {
        $sql = 'SELECT id, titulo FROM vagas ORDER BY created_at DESC';
        return $this->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
