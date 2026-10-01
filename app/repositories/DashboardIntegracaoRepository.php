<?php

/**
 * Acesso a dados do Dashboard de Integração/Onboarding (Etapa 8, 2026-10). Sem regra de negócio
 * (NPS/médias/taxa ficam em DashboardIntegracaoService) — só leitura agregada de `pesquisas_integracao`.
 *
 * Período sempre ancorado em `integracao_data_relacionada` (o EVENTO de integração, nunca
 * `respondida_em` — uma resposta pode chegar dias depois do evento e não deve "pular" de mês/ano no
 * filtro). Empresa/Unidade/Setor resolvidos pelo espelho oficial `colaboradores_metadados`, com
 * bridge para `colaboradores.metadados_id` nas linhas antigas do fluxo individual que só têm
 * `colaborador_id` (mesmo padrão de `PesquisaIntegracaoQr::buscarPesquisaDoEvento()`) — nunca CPF.
 */
class DashboardIntegracaoRepository
{
    private const SELECT_BASE = "SELECT p.id, p.colaborador_id, p.token_hash, p.integracao_data_relacionada,
               p.nota_nps, p.nota_clareza, p.nota_acolhimento, p.nota_normas, p.nota_utilidade,
               p.nota_satisfacao_geral, p.comentarios, p.respondida_em,
               COALESCE(p.metadados_id, col.metadados_id) AS metadados_id,
               cm.codigo_empresa, cm.codigo_unidade, cm.codigo_setor,
               NULLIF(cm.empresa, '') AS empresa, NULLIF(cm.unidade, '') AS unidade, NULLIF(cm.setor, '') AS setor,
               COALESCE(
                 (SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                   WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci LIMIT 1),
                 NULLIF(cm.cargo, '')
               ) AS cargo,
               NULLIF(cm.nome, '') AS nome
        FROM pesquisas_integracao p
        LEFT JOIN colaboradores col ON col.id = p.colaborador_id
        LEFT JOIN colaboradores_metadados cm ON cm.id = COALESCE(p.metadados_id, col.metadados_id)";

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
     * @param array{inicio:string,fim:string,codigo_empresa:string,codigo_unidade:string,codigo_setor:string} $filtros
     */
    private function montarWhere(array $filtros): array
    {
        $where = ['p.integracao_data_relacionada BETWEEN ? AND ?'];
        $params = [$filtros['inicio'], $filtros['fim']];
        if ($filtros['codigo_empresa'] !== '') {
            $where[] = 'cm.codigo_empresa = ?';
            $params[] = $filtros['codigo_empresa'];
        }
        if ($filtros['codigo_unidade'] !== '') {
            $where[] = 'cm.codigo_unidade = ?';
            $params[] = $filtros['codigo_unidade'];
        }
        if ($filtros['codigo_setor'] !== '') {
            $where[] = 'cm.codigo_setor = ?';
            $params[] = $filtros['codigo_setor'];
        }
        return [$where, $params];
    }

    /** Todas as respostas (ambos os fluxos) dentro do período/filtros — RESPONDIDAS apenas. */
    public function respostas(array $filtros): array
    {
        [$where, $params] = $this->montarWhere($filtros);
        $where[] = 'p.respondida_em IS NOT NULL';
        $sql = self::SELECT_BASE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.respondida_em DESC, p.id DESC';
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pesquisas do FLUXO INDIVIDUAL (geradas previamente pelo RH — `colaborador_id`/`token_hash`
     * preenchidos) dentro do período/filtros, respondidas ou não. Único subconjunto onde "geradas"
     * é um evento distinto de "respondidas" (o fluxo QR só grava a linha quando já respondida).
     */
    public function pesquisasIndividuais(array $filtros): array
    {
        [$where, $params] = $this->montarWhere($filtros);
        $where[] = '(p.colaborador_id IS NOT NULL OR p.token_hash IS NOT NULL)';
        $sql = self::SELECT_BASE . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.integracao_data_relacionada DESC';
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Empresa/Unidade/Setor que já têm ALGUMA pesquisa de integração (respondida ou não) — opções seguras de filtro. */
    public function opcoesEmpresaUnidadeSetor(): array
    {
        $sql = "SELECT DISTINCT cm.codigo_empresa, NULLIF(cm.empresa, '') AS empresa,
                       cm.codigo_unidade, NULLIF(cm.unidade, '') AS unidade,
                       cm.codigo_setor, NULLIF(cm.setor, '') AS setor
                FROM pesquisas_integracao p
                LEFT JOIN colaboradores col ON col.id = p.colaborador_id
                INNER JOIN colaboradores_metadados cm ON cm.id = COALESCE(p.metadados_id, col.metadados_id)
                WHERE cm.codigo_empresa IS NOT NULL AND cm.codigo_empresa <> ''
                ORDER BY empresa, unidade, setor";
        return $this->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
