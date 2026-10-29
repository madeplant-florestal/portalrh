<?php

/**
 * Exclusão analítica de contratos específicos (2026-10) — mecanismo central e reaproveitável
 * para "este contrato nunca deve entrar em nenhuma estatística/listagem analítica do Portal",
 * sem apagar o registro original em `colaboradores_metadados` e sem depender de nome hardcoded
 * ou de heurística genérica (a hipótese "setor vazio = excluir" foi avaliada e rejeitada — há
 * muitos contratos legítimos sem setor, nunca escondidos do RH por design).
 *
 * Cada linha em `colaboradores_metadados_exclusoes_analiticas` é uma decisão administrativa
 * explícita e auditável: motivo + quem excluiu + quando (ver migration
 * 2026-10-29-colaboradores-metadados-exclusoes-analiticas.sql). Ausência de linha para um
 * `metadados_id` = o contrato participa normalmente de tudo.
 *
 * `clausulaSqlExclusao()` é o único ponto que os repositories de camada analítica devem usar —
 * aplicado hoje em `ColaboradorMetadadosConsultaRepository` (listagem de Colaboradores),
 * `PeopleAnalyticsRepository::buscarContratos()`/`distribuicaoAtivosPorSetor()`,
 * `RhIndicadoresRepository::buscarContratos()` e `TurnoverDashboardRepository::buscarContratos()`
 * — nunca em telas administrativas de ponto único (ex.: `findById()`, usada pelo vínculo de
 * Usuários), que precisam continuar encontrando o contrato mesmo que ele esteja excluído das
 * estatísticas.
 */
class ColaboradorMetadadosElegibilidadeService
{
    private static bool $ensured = false;

    /**
     * Fragmento de WHERE autocontido (sem placeholders) para excluir contratos marcados — splica
     * direto em qualquer array de condições já existente, com o prefixo de alias correto da
     * query de destino (`m.id`, `cm.id`, ou `id` quando a query não usa alias).
     */
    public static function clausulaSqlExclusao(string $colunaId = 'id'): string
    {
        self::ensureSchema();
        return "{$colunaId} NOT IN (SELECT metadados_id FROM colaboradores_metadados_exclusoes_analiticas)";
    }

    public static function estaExcluido(int $metadadosId): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id = ?');
        $stmt->execute([$metadadosId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param int|null $usuarioId Quem excluiu — nulo só é aceitável em contexto de manutenção sem
     *                            usuário autenticado (ex.: script one-off); uma futura tela
     *                            administrativa deve sempre passar o usuário da sessão.
     * @return array{ok:bool,error?:string}
     */
    public static function excluir(int $metadadosId, string $motivo, ?int $usuarioId): array
    {
        self::ensureSchema();
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['ok' => false, 'error' => 'Informe o motivo da exclusão analítica.'];
        }

        $pdo = Database::conn();
        $existe = $pdo->prepare('SELECT COUNT(*) FROM colaboradores_metadados WHERE id = ?');
        $existe->execute([$metadadosId]);
        if ((int)$existe->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'Contrato não encontrado em colaboradores_metadados.'];
        }

        if (self::estaExcluido($metadadosId)) {
            return ['ok' => false, 'error' => 'Este contrato já está excluído das estatísticas.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO colaboradores_metadados_exclusoes_analiticas (metadados_id, motivo, excluido_por_usuario_id)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$metadadosId, $motivo, $usuarioId]);

        return ['ok' => true];
    }

    /** Reverte a exclusão (o contrato volta a participar normalmente das estatísticas). */
    public static function incluir(int $metadadosId): void
    {
        self::ensureSchema();
        $pdo = Database::conn();
        $pdo->prepare('DELETE FROM colaboradores_metadados_exclusoes_analiticas WHERE metadados_id = ?')->execute([$metadadosId]);
    }

    /** Para auditoria (nunca usado nos filtros de estatística — esses usam clausulaSqlExclusao()). */
    public static function listarExcluidos(): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->query(
            'SELECT e.id, e.metadados_id, e.motivo, e.excluido_por_usuario_id, e.created_at,
                    m.nome, m.numero_contrato, m.empresa,
                    u.nome AS excluido_por_nome
             FROM colaboradores_metadados_exclusoes_analiticas e
             JOIN colaboradores_metadados m ON m.id = e.metadados_id
             LEFT JOIN usuarios u ON u.id = e.excluido_por_usuario_id
             ORDER BY e.created_at DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS colaboradores_metadados_exclusoes_analiticas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            metadados_id INT NOT NULL,
            motivo TEXT NOT NULL,
            excluido_por_usuario_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cmea_metadados (metadados_id),
            KEY idx_cmea_usuario (excluido_por_usuario_id),
            CONSTRAINT fk_cmea_metadados FOREIGN KEY (metadados_id) REFERENCES colaboradores_metadados(id) ON DELETE RESTRICT,
            CONSTRAINT fk_cmea_usuario FOREIGN KEY (excluido_por_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
