<?php

/**
 * Acesso a dados do fluxo COLETIVO (QR Code) da Pesquisa de Integração. Lê SOMENTE o espelho
 * oficial `colaboradores_metadados` (+ catálogo `cargos` para a descrição oficial do Cargo) —
 * nunca escreve nele, nunca usa `colaboradores` legado para Nome/Cargo/Empresa e nunca escreve em
 * `colaboradores`. `colaboradores` só é LIDO na checagem de duplicidade entre fluxos (linhas
 * antigas só têm colaborador_id; o contrato oficial delas está em colaboradores.metadados_id).
 *
 * CPF e data de nascimento entram aqui apenas como parâmetros de LOCALIZAÇÃO (prepared
 * statements) — nunca são selecionados de volta nem gravados em `pesquisas_integracao`.
 *
 * Sem DDL em runtime — a migration 2026-09-19-pesquisa-integracao-qr.sql é a única fonte da
 * estrutura.
 */
class PesquisaIntegracaoQr
{
    /** Contrato ATIVO: flag do espelho + sem demissão já vencida. Nunca inclui CPF/nascimento no SELECT. */
    private const CAMPOS_CONTRATO = "cm.id, cm.nome,
               COALESCE(NULLIF(cm.empresa, ''), cm.codigo_empresa) AS empresa,
               COALESCE(
                 (SELECT COALESCE(c.descricao_oficial, c.nome) FROM cargos c
                   WHERE c.codigo_cargo COLLATE utf8mb4_general_ci = cm.codigo_cargo COLLATE utf8mb4_general_ci
                   LIMIT 1),
                 NULLIF(cm.cargo, '')
               ) AS cargo";

    private const SELECT_CONTRATO = "SELECT " . self::CAMPOS_CONTRATO . "
        FROM colaboradores_metadados cm
        WHERE cm.ativo = 1 AND (cm.demissao IS NULL OR cm.demissao >= CURDATE())";

    /**
     * Respostas ORIGINADAS PELO QR: linhas criadas por inserirResposta() — `colaborador_id` e
     * `token_hash` NULL (o fluxo individual sempre grava os dois, PesquisaIntegracao::create()),
     * `metadados_id` preenchido. `metadados_id IS NOT NULL` sozinho NÃO distingue a origem (pesquisas
     * individuais de colaboradores com contrato oficial também o gravam). Limitação conhecida: uma
     * pesquisa individual PENDENTE completada via QR (completarPesquisaPendente()) mantém
     * colaborador_id/token e por isso fica fora deste conjunto — não há coluna de origem para ela.
     */
    private const FILTRO_ORIGEM_QR = 'p.colaborador_id IS NULL AND p.token_hash IS NULL AND p.metadados_id IS NOT NULL AND p.respondida_em IS NOT NULL';

    /**
     * Contratos ativos cujo CPF E data de nascimento coincidem simultaneamente.
     *
     * @return array<int,array{id:int,nome:string,empresa:?string,cargo:?string}>
     */
    public static function contratosAtivosPorCpfENascimento(string $cpfDigitos, string $nascimentoYmd): array
    {
        $stmt = Database::conn()->prepare(
            self::SELECT_CONTRATO . ' AND cm.cpf = ? AND cm.nascimento = ? ORDER BY cm.admissao DESC, cm.id DESC'
        );
        $stmt->execute([$cpfDigitos, $nascimentoYmd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Revalida um contrato pelo id oficial (ainda ativo?) — usado ao exibir e ao enviar. */
    public static function contratoAtivoPorId(int $metadadosId): ?array
    {
        $stmt = Database::conn()->prepare(self::SELECT_CONTRATO . ' AND cm.id = ? LIMIT 1');
        $stmt->execute([$metadadosId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Pesquisa já existente para o MESMO evento (contrato oficial + data da integração),
     * independente do fluxo de origem: linha nova (metadados_id direto) OU linha antiga (só
     * colaborador_id, contrato oficial via colaboradores.metadados_id). Nunca usa CPF. Prefere a
     * linha já respondida.
     */
    public static function buscarPesquisaDoEvento(int $metadadosId, string $dataIntegracao): ?array
    {
        $stmt = Database::conn()->prepare(
            'SELECT p.id, p.respondida_em, p.colaborador_id
             FROM pesquisas_integracao p
             LEFT JOIN colaboradores col ON col.id = p.colaborador_id
             WHERE p.integracao_data_relacionada = ?
               AND (p.metadados_id = ? OR col.metadados_id = ?)
             ORDER BY (p.respondida_em IS NOT NULL) DESC, p.id ASC
             LIMIT 1'
        );
        $stmt->execute([$dataIntegracao, $metadadosId, $metadadosId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Nova resposta do fluxo QR: identidade oficial direta (metadados_id), sem colaborador_id e sem
     * token. `integracao_data_relacionada` = data da SESSÃO de integração; `respondida_em` = agora.
     * A violação do UNIQUE (metadados_id, integracao_data_relacionada) sobe como PDOException 1062.
     */
    public static function inserirResposta(int $metadadosId, string $dataIntegracao, array $notas, ?string $comentarios): int
    {
        $stmt = Database::conn()->prepare(
            'INSERT INTO pesquisas_integracao (
                colaborador_id, metadados_id, integracao_data_relacionada, token_hash,
                nota_nps, nota_clareza, nota_acolhimento, nota_normas, nota_utilidade,
                nota_satisfacao_geral, comentarios, respondida_em
            ) VALUES (NULL, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $metadadosId, $dataIntegracao,
            $notas['nota_nps'], $notas['nota_clareza'], $notas['nota_acolhimento'],
            $notas['nota_normas'], $notas['nota_utilidade'], $notas['nota_satisfacao_geral'],
            $comentarios,
        ]);
        return (int)Database::conn()->lastInsertId();
    }

    /**
     * Completa uma pesquisa individual PENDENTE (gerada pelo RH para o mesmo contrato/evento, ainda
     * sem resposta) em vez de criar uma segunda linha — assim o link individual antigo passa a
     * constar como respondido e não permite resposta duplicada. Também registra o metadados_id
     * oficial na linha. `respondida_em IS NULL` no WHERE protege contra corrida.
     */
    public static function completarPesquisaPendente(int $pesquisaId, int $metadadosId, array $notas, ?string $comentarios): bool
    {
        $stmt = Database::conn()->prepare(
            'UPDATE pesquisas_integracao
             SET metadados_id = ?, nota_nps = ?, nota_clareza = ?, nota_acolhimento = ?, nota_normas = ?,
                 nota_utilidade = ?, nota_satisfacao_geral = ?, comentarios = ?, respondida_em = NOW()
             WHERE id = ? AND respondida_em IS NULL'
        );
        $stmt->execute([
            $metadadosId, $notas['nota_nps'], $notas['nota_clareza'], $notas['nota_acolhimento'],
            $notas['nota_normas'], $notas['nota_utilidade'], $notas['nota_satisfacao_geral'],
            $comentarios, $pesquisaId,
        ]);
        return $stmt->rowCount() > 0;
    }


    /** @return array<int,array{integracao_data_relacionada:string,nota_nps:int|string}> Uma linha por resposta QR (só data + NPS) — base do resumo por integração. */
    public static function respostasQrParaResumo(): array
    {
        $stmt = Database::conn()->query(
            'SELECT p.integracao_data_relacionada, p.nota_nps FROM pesquisas_integracao p WHERE ' . self::FILTRO_ORIGEM_QR
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array Respostas QR completas de UMA integração (data), mais recentes primeiro. */
    public static function respostasQrDaIntegracao(string $dataIntegracao): array
    {
        $stmt = Database::conn()->prepare(
            'SELECT p.metadados_id, p.nota_nps, p.nota_clareza, p.nota_acolhimento, p.nota_normas,
                    p.nota_utilidade, p.nota_satisfacao_geral, p.comentarios, p.respondida_em
             FROM pesquisas_integracao p
             WHERE ' . self::FILTRO_ORIGEM_QR . ' AND p.integracao_data_relacionada = ?
             ORDER BY p.respondida_em DESC, p.id DESC'
        );
        $stmt->execute([$dataIntegracao]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Nome/Cargo/Empresa oficiais (espelho + catálogo de cargos) por id de contrato, INDEPENDENTE de
     * o contrato ainda estar ativo (o respondente pode ter sido desligado depois). Nunca seleciona
     * CPF/nascimento/salário.
     *
     * @param int[] $ids
     * @return array<int,array{id:int,nome:string,empresa:?string,cargo:?string}> indexado por id
     */
    public static function contratosPorIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = Database::conn()->prepare(
            'SELECT ' . self::CAMPOS_CONTRATO . ' FROM colaboradores_metadados cm WHERE cm.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $porId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $porId[(int)$linha['id']] = $linha;
        }
        return $porId;
    }
}
