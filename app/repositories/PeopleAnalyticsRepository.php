<?php

/**
 * Acesso a dados do People Analytics (Tela Inicial/Dashboard principal) — mesma disciplina de
 * `RhIndicadoresRepository`: lê exclusivamente `colaboradores_metadados` (espelho oficial do
 * METADADOS) para dados organizacionais/cadastrais oficiais, nunca `colaboradores` (tabela hub
 * legada) como fonte de indicadores oficiais. `colaboradores`/`solicitacoes_vaga`/
 * `pesquisas_integracao` só são usadas para os indicadores que pertencem de fato ao Portal
 * (Integração, Avaliação de Experiência, NPS de Integração) — nunca para dados cadastrais.
 *
 * Nunca seleciona CPF, nome, data de nascimento isolada sem uso, ou salário fora do necessário
 * para os indicadores — mesma disciplina de privacidade de `RhIndicadoresRepository`.
 *
 * Nunca escreve no SQL Server do METADADOS — só o espelho MySQL local, já sincronizado.
 */
class PeopleAnalyticsRepository
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
     * Todos os contratos do espelho que atendem aos filtros de Empresa/Setor — sem filtro de
     * data (os cálculos de headcount histórico/turnover precisam do histórico completo de cada
     * contrato, não só do período exibido). Uma única consulta alimenta Headcount, Admissões,
     * Desligamentos, Turnover Geral, Turnover por Faixa Etária e os agrupamentos por Empresa
     * (Headcount/Turnover/Desligamentos por Empresa) — evita N+1.
     *
     * `codigo_empresa`/`empresa` (texto) reaproveitam exatamente a mesma fonte já usada em
     * opcoesFiltro() — nunca a tabela local `empresas` do Recrutamento (catálogo de outra
     * dimensão/geração, id-based, incompleto em relação ao universo do METADADOS).
     *
     * `identificador` (chave técnica `codigo_empresa-codigo_unidade-numero_contrato`, nunca PII):
     * usado só para aplicar os conjuntos de exclusão de MetadadosMovimentacaoService::
     * classificarMovimentacoes() (transferência interempresa) — nunca para exibição direta.
     *
     * @param array $filtros Chaves aceitas: codigo_empresa, codigo_setor. Ausente/vazio = sem filtro.
     * @return array Cada item: identificador, codigo_pessoa, admissao, demissao,
     *               motivo_rescisao_codigo, motivo_rescisao_descricao, nascimento, sexo,
     *               codigo_setor, ativo, codigo_empresa, empresa, ausente_na_origem. `sexo`
     *               (RHPESSOAS.SEXO): dimensão demográfica agregável, trazida para o diagnóstico
     *               "Transferências + Turnover por Sexo/Gênero" — nunca renomeada para `genero`
     *               nesta camada. `ausente_na_origem` (ver migration 2026-09-24-colaboradores-
     *               metadados-reconciliacao-ausencia.sql): só serve para excluir da população
     *               VIGENTE agora (card Headcount Atual/Headcount por Empresa) — nunca aplicado a
     *               Turnover, Admissões ou Desligamentos, que continuam olhando o histórico
     *               completo (exceto os pares classificados como transferência interempresa, ver
     *               buscarContratosParaMovimentacao()). NUNCA seleciona cpf/nome — mesma
     *               disciplina de privacidade documentada na classe.
     */
    public function buscarContratos(array $filtros = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'codigo_empresa = ?';
            $params[] = $filtros['codigo_empresa'];
        }
        // Sentinela ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO ("Setor não
        // informado" selecionável no filtro superior, §17 da correção de 2026-09): traduzido
        // aqui para a condição real, nunca comparado como se fosse um código de setor de verdade.
        if (($filtros['codigo_setor'] ?? '') === ColaboradorMetadadosConsultaRepository::SETOR_NAO_INFORMADO) {
            $where[] = "(codigo_setor IS NULL OR codigo_setor = '')";
        } elseif (!empty($filtros['codigo_setor'])) {
            $where[] = 'codigo_setor = ?';
            $params[] = $filtros['codigo_setor'];
        }

        $sql = 'SELECT identificador, codigo_pessoa, admissao, demissao, motivo_rescisao_codigo,
                       motivo_rescisao_descricao, nascimento, sexo, codigo_setor, ativo,
                       codigo_empresa, empresa, ausente_na_origem
                FROM colaboradores_metadados';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fonte MÍNIMA e GLOBAL (nunca filtrada por Empresa/Setor — precisa enxergar as duas pontas
     * de uma transferência interempresa, que por definição estão em empresas diferentes) para
     * MetadadosMovimentacaoService::classificarMovimentacoes(). Único ponto do módulo People
     * Analytics que seleciona `cpf` — exceção deliberada e explicitamente autorizada (correção de
     * 2026-09, transferências interempresa) à disciplina de privacidade de buscarContratos(): o
     * CPF nunca sai desta função/do Service que a consome — serve só para parear a MESMA pessoa
     * entre dois contratos, nunca é devolvido em `$painel` nem chega a nenhuma view.
     *
     * `data_ultima_transferencia` (RHCONTRATOS.DATAULTTRANSFERENCIA sincronizado, 2026-09):
     * fonte oficial da data efetiva de transferência, usada por
     * classificarTransferenciaContinua() para derivar a vigência analítica — nunca para alterar
     * admissao/demissao.
     *
     * @return array Cada item: identificador, cpf, codigo_empresa, admissao, demissao,
     *               ausente_na_origem, data_ultima_transferencia.
     */
    public function buscarContratosParaMovimentacao(): array
    {
        $sql = 'SELECT identificador, cpf, codigo_empresa, admissao, demissao, ausente_na_origem,
                       data_ultima_transferencia
                FROM colaboradores_metadados';
        return $this->connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Opções de filtro — Empresa por `codigo_empresa` (agrupada pelo código, nome mais recente
     * associado); Setor por `codigo_setor`, resolvido pelo catálogo local oficial `setores`
     * (COLLATE explícito: colaboradores_metadados usa a collation do espelho, setores usa
     * utf8mb4_general_ci — sem isso o JOIN falha com "Illegal mix of collations" no MariaDB de
     * produção, mesmo ajuste já aplicado na correção dos contadores de Empresas/Setores).
     */
    public function opcoesFiltro(): array
    {
        $pdo = $this->connection();

        $empresas = $pdo->query(
            'SELECT codigo_empresa, MAX(empresa) AS empresa
             FROM colaboradores_metadados
             WHERE codigo_empresa IS NOT NULL AND codigo_empresa <> \'\'
             GROUP BY codigo_empresa
             ORDER BY empresa'
        )->fetchAll(PDO::FETCH_ASSOC);

        $setores = $pdo->query(
            "SELECT cm.codigo_setor, COALESCE(s.descricao_oficial, s.nome, MAX(cm.setor)) AS nome
             FROM colaboradores_metadados cm
             LEFT JOIN setores s
               ON s.codigo_setor COLLATE utf8mb4_general_ci = cm.codigo_setor COLLATE utf8mb4_general_ci
             WHERE cm.codigo_setor IS NOT NULL AND cm.codigo_setor <> ''
             GROUP BY cm.codigo_setor, s.descricao_oficial, s.nome
             ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'empresas' => $empresas,
            'setores' => $setores,
        ];
    }

    /**
     * Contratos ATIVOS por Setor, resolvido pelo catálogo oficial local — unidade oficial é o
     * CONTRATO (mesma convenção de Headcount/Admissões/Desligamentos desta tela), nunca pessoa
     * distinta. Quem não tem `codigo_setor` no espelho entra em "Setor não informado" — nunca
     * redistribuído, nunca inferido por cargo/centro de custo/cadastro legado.
     *
     * Sem parâmetro de data (é sempre "agora") — por isso exclui `ausente_na_origem = 1`
     * incondicionalmente, ao contrário dos cálculos de headcount/turnover por período em
     * buscarContratos(), que preservam o histórico completo.
     */
    public function distribuicaoAtivosPorSetor(array $filtros = []): array
    {
        $where = ["cm.ativo = 1", "cm.ausente_na_origem = 0"];
        $params = [];
        if (!empty($filtros['codigo_empresa'])) {
            $where[] = 'cm.codigo_empresa = ?';
            $params[] = $filtros['codigo_empresa'];
        }

        $sql = "SELECT cm.codigo_setor, COALESCE(s.descricao_oficial, s.nome) AS nome_oficial,
                       COUNT(*) AS contratos
                FROM colaboradores_metadados cm
                LEFT JOIN setores s
                  ON s.codigo_setor COLLATE utf8mb4_general_ci = cm.codigo_setor COLLATE utf8mb4_general_ci
                WHERE " . implode(' AND ', $where) . "
                GROUP BY cm.codigo_setor, s.descricao_oficial, s.nome
                ORDER BY contratos DESC";
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Integrações (onboarding) marcadas como 'realizada' dentro do período — dado operacional do
     * Portal (`colaboradores.integracao_status`/`.integracao_data`), nunca do METADADOS.
     */
    public function contarIntegracoesRealizadas(DateTimeImmutable $inicio, DateTimeImmutable $fim): int
    {
        $stmt = $this->connection()->prepare(
            "SELECT COUNT(*) FROM colaboradores
             WHERE integracao_status = 'realizada'
               AND integracao_data BETWEEN ? AND ?"
        );
        $stmt->execute([$inicio->format('Y-m-d'), $fim->format('Y-m-d')]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Notas de NPS da Pesquisa de Integração, só de respostas efetivamente concluídas dentro do
     * período (por `respondida_em`) — nunca de `pesquisas_experiencia` (processo seletivo).
     *
     * @return int[] Lista de notas 0-10.
     */
    public function buscarNotasNpsIntegracao(DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT nota_nps FROM pesquisas_integracao
             WHERE respondida_em IS NOT NULL
               AND respondida_em >= ? AND respondida_em <= ?'
        );
        $stmt->execute([$inicio->format('Y-m-d 00:00:00'), $fim->format('Y-m-d 23:59:59')]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Avaliação de Período de Experiência (90 dias) — campo real já existente em
     * `solicitacoes_vaga.avaliacao_90_dias`, preenchido no "controle interno de RH" após a
     * admissão. "Realizadas" ancoradas ao período pela data em que o prazo de 90 dias vence
     * (`data_admissao + 90 dias`) — nunca `updated_at` (sem comprovação semântica). "Pendentes" é
     * um retrato de AGORA (mesma convenção de "Vagas Abertas" — backlog atual, não histórico).
     */
    public function avaliacaoExperiencia(DateTimeImmutable $inicio, DateTimeImmutable $fim, int $limiteDias): array
    {
        $pdo = $this->connection();

        $stmtRealizadas = $pdo->prepare(
            "SELECT COUNT(*) FROM solicitacoes_vaga
             WHERE avaliacao_90_dias IS NOT NULL
               AND data_admissao IS NOT NULL
               AND DATE_ADD(data_admissao, INTERVAL ? DAY) BETWEEN ? AND ?"
        );
        $stmtRealizadas->execute([$limiteDias, $inicio->format('Y-m-d'), $fim->format('Y-m-d')]);
        $realizadas = (int)$stmtRealizadas->fetchColumn();

        $stmtPendentes = $pdo->prepare(
            "SELECT COUNT(*) FROM solicitacoes_vaga
             WHERE avaliacao_90_dias IS NULL
               AND data_admissao IS NOT NULL
               AND DATE_ADD(data_admissao, INTERVAL ? DAY) <= CURDATE()"
        );
        $stmtPendentes->execute([$limiteDias]);
        $pendentes = (int)$stmtPendentes->fetchColumn();

        return ['realizadas' => $realizadas, 'pendentes' => $pendentes];
    }
}
