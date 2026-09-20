<?php

/**
 * Acesso a dados do GESTOR IMEDIATO (`usuarios.gestor_usuario_id -> usuarios.id`): hierarquia operacional própria do
 * Portal, reutilizável por módulos futuros (PDI, Avaliação de Experiência, Feedback, aprovações, dashboards).
 *
 * Conceito INDEPENDENTE de `usuarios.aprovador_usuario_id` (aprovador de Solicitação de Vaga): nada aqui lê ou escreve
 * o aprovador. Também NÃO toca em estruturas legadas (`colaboradores`, `usuario_colaboradores`, `lider_colaborador_id`,
 * `is_gestor`). "Ativo" segue a regra real do Portal (User::setActiveStatus): `email_verified_at IS NOT NULL`.
 *
 * Sem regra de negócio: existência, ativo, autorreferência e ciclo são validados em UsuarioGestorService.
 */
class UsuarioGestorRepository
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

    public function transacao(callable $acao): mixed
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $resultado = $acao();
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function todas(string $sql, array $params = []): array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Usuário (sem e-mail de contato além do necessário para exibição) com estado ativo e gestor atual. */
    public function usuario(int $id): ?array
    {
        $rows = $this->todas(
            'SELECT id, nome, email, role, gestor_usuario_id, (email_verified_at IS NOT NULL) AS ativo FROM usuarios WHERE id = ? LIMIT 1',
            [$id]
        );
        return $rows[0] ?? null;
    }

    /** Gestor imediato do usuário (id, nome, e-mail, ativo) ou null. */
    public function gestorDe(int $usuarioId): ?array
    {
        $rows = $this->todas(
            'SELECT g.id, g.nome, g.email, g.role, (g.email_verified_at IS NOT NULL) AS ativo
             FROM usuarios u INNER JOIN usuarios g ON g.id = u.gestor_usuario_id
             WHERE u.id = ? LIMIT 1',
            [$usuarioId]
        );
        return $rows[0] ?? null;
    }

    /** Mapa completo id => gestor_usuario_id (uma consulta; base de ciclo, cadeia e descendentes em memória). */
    public function mapaHierarquia(): array
    {
        $mapa = [];
        foreach ($this->todas('SELECT id, gestor_usuario_id FROM usuarios') as $r) {
            $mapa[(int)$r['id']] = $r['gestor_usuario_id'] !== null ? (int)$r['gestor_usuario_id'] : null;
        }
        return $mapa;
    }

    /** Usuários ATIVOS elegíveis a gestor (uma consulta, sem N+1); cargo do Portal (`usuarios.cargo_id`) quando houver. */
    public function ativos(): array
    {
        return $this->todas(
            "SELECT u.id, u.nome, u.email, u.role, COALESCE(c.descricao_oficial, c.nome) AS cargo
             FROM usuarios u LEFT JOIN cargos c ON c.id = u.cargo_id
             WHERE u.email_verified_at IS NOT NULL
             ORDER BY u.nome ASC, u.id ASC"
        );
    }

    /** Liderados DIRETOS de um gestor. */
    public function subordinadosDiretos(int $gestorId): array
    {
        return $this->todas(
            'SELECT id, nome, email, role, (email_verified_at IS NOT NULL) AS ativo FROM usuarios WHERE gestor_usuario_id = ? ORDER BY nome ASC, id ASC',
            [$gestorId]
        );
    }

    public function contarSubordinadosDiretos(int $gestorId): int
    {
        $rows = $this->todas('SELECT COUNT(*) AS n FROM usuarios WHERE gestor_usuario_id = ?', [$gestorId]);
        return (int)($rows[0]['n'] ?? 0);
    }

    public function definir(int $usuarioId, ?int $gestorId): void
    {
        $this->connection()->prepare('UPDATE usuarios SET gestor_usuario_id = ? WHERE id = ?')->execute([$gestorId, $usuarioId]);
    }

    /**
     * Gestor imediato do USUÁRIO do Portal associado ao contrato oficial (`usuarios.colaborador_metadados_id`).
     * Só a relação nova: nenhuma leitura do legado. Devolve também se o gestor está ativo.
     */
    public function gestorDoUsuarioDoContrato(int $metadadosId): ?array
    {
        $rows = $this->todas(
            'SELECT g.id, g.nome, (g.email_verified_at IS NOT NULL) AS ativo, u.nome AS usuario_nome
             FROM usuarios u INNER JOIN usuarios g ON g.id = u.gestor_usuario_id
             WHERE u.colaborador_metadados_id = ? LIMIT 1',
            [$metadadosId]
        );
        return $rows[0] ?? null;
    }
}
