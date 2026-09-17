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
     * "headcount atual" em RhIndicadoresRepository.
     */
    public function contarSolicitacoesAbertas(?int $empresaId): array
    {
        $where = ["st.slug NOT IN ('fechada', 'cancelada')"];
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

    /** Solicitações fechadas no período (situação = 'fechada', recorte por `fechada_em`). */
    public function contarSolicitacoesFechadas(DateTimeImmutable $inicio, DateTimeImmutable $fim, ?int $empresaId): array
    {
        $where = ["st.slug = 'fechada'", 'sv.fechada_em >= ?', 'sv.fechada_em <= ?'];
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
