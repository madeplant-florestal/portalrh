<?php
/**
 * Vínculo `usuario_colaboradores` (1:1 usuário de login ↔ colaborador) + papéis operacionais do
 * Portal: `is_gestor` ("é líder"), `pode_solicitar_vaga` (autorização explícita para abrir
 * Solicitação de Vaga), `is_rh`, `lider_colaborador_id` (líder imediato, para roteamento de
 * aprovação), `ativo`.
 *
 * Geração nova (Repository puro, PDO injetável). Até esta sprint NENHUMA tela gerenciava esta
 * tabela — as linhas eram criadas à mão. A administração agora vive em /admin/colaboradores/{id}/acesso.
 *
 * Nenhuma dessas flags é inferida do METADADOS (cargo/nome). São regra de negócio do Portal,
 * administradas por RH/Admin. O METADADOS é fonte cadastral (colaborador/empresa/unidade/setor/cargo).
 */
class UsuarioColaboradorRepository
{
    /** Colunas de papel que atualizarPapeis() aceita — whitelist, nunca vem crua do request. */
    private const CAMPOS_PAPEL = ['is_gestor', 'is_rh', 'pode_solicitar_vaga', 'lider_colaborador_id', 'ativo'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conn();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function findByColaboradorId(int $colaboradorId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuario_colaboradores WHERE colaborador_id = ? LIMIT 1');
        $stmt->execute([$colaboradorId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByUsuarioId(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuario_colaboradores WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Garante o vínculo colaborador↔usuário. Lança se qualquer um dos dois já estiver vinculado a
     * outro registro (UNIQUE nos dois lados — 1:1). Idempotente quando o par já existe.
     */
    public function vincular(int $colaboradorId, int $usuarioId): int
    {
        $porColaborador = $this->findByColaboradorId($colaboradorId);
        if ($porColaborador !== null) {
            if ((int)$porColaborador['usuario_id'] !== $usuarioId) {
                throw new \RuntimeException('Este colaborador já está vinculado a outro usuário de acesso.');
            }
            return (int)$porColaborador['id'];
        }
        $porUsuario = $this->findByUsuarioId($usuarioId);
        if ($porUsuario !== null && (int)$porUsuario['colaborador_id'] !== $colaboradorId) {
            throw new \RuntimeException('Este usuário de acesso já está vinculado a outro colaborador.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO usuario_colaboradores (usuario_id, colaborador_id, is_gestor, is_rh, pode_solicitar_vaga, ativo)
             VALUES (?, ?, 0, 0, 0, 1)'
        );
        $stmt->execute([$usuarioId, $colaboradorId]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza os papéis do vínculo de um colaborador. `lider_colaborador_id` NULL = sem líder
     * imediato definido (o roteamento cai no fallback por setor).
     *
     * @param array<string,mixed> $flags subconjunto de self::CAMPOS_PAPEL
     */
    public function atualizarPapeis(int $colaboradorId, array $flags): void
    {
        $sets = [];
        $valores = [];
        foreach (self::CAMPOS_PAPEL as $campo) {
            if (!array_key_exists($campo, $flags)) {
                continue;
            }
            $sets[] = "{$campo} = ?";
            if ($campo === 'lider_colaborador_id') {
                $valores[] = $flags[$campo] !== null && (int)$flags[$campo] > 0 ? (int)$flags[$campo] : null;
            } else {
                $valores[] = (int)((bool)$flags[$campo]);
            }
        }
        if ($sets === []) {
            return;
        }
        $valores[] = $colaboradorId;
        $this->pdo->prepare('UPDATE usuario_colaboradores SET ' . implode(', ', $sets) . ' WHERE colaborador_id = ?')
            ->execute($valores);
    }

    /**
     * Colaboradores que possuem vínculo de acesso ativo — usado para popular o select de
     * "líder imediato".
     *
     * @return list<array{colaborador_id:int, nome:string, cargo_nome:string, setor_nome:?string}>
     */
    public function colaboradoresComAcessoAtivo(): array
    {
        $stmt = $this->pdo->query(
            "SELECT uc.colaborador_id, c.nome, cg.nome AS cargo_nome, s.nome AS setor_nome
             FROM usuario_colaboradores uc
             INNER JOIN colaboradores c ON c.id = uc.colaborador_id
             INNER JOIN cargos cg ON cg.id = c.cargo_id
             LEFT JOIN setores s ON s.id = c.setor_id
             WHERE uc.ativo = 1
             ORDER BY c.nome ASC"
        );
        return array_map(static function (array $r): array {
            return [
                'colaborador_id' => (int)$r['colaborador_id'],
                'nome' => (string)$r['nome'],
                'cargo_nome' => (string)$r['cargo_nome'],
                'setor_nome' => $r['setor_nome'] !== null ? (string)$r['setor_nome'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
