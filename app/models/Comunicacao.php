<?php
/**
 * Histórico IMUTÁVEL de comunicações preparadas/enviadas a um candidato (Sprint "Histórico de
 * Comunicação" — migration 2026-09-16-comunicacoes-candidato.sql).
 *
 * Nunca confundir com `Mensagem` (o TEMPLATE editável). Cada linha aqui guarda uma FOTOGRAFIA do
 * texto já renderizado (`conteudo`) — editar o template depois não muda o histórico já gravado.
 * Vínculo é com a PARTICIPAÇÃO do candidato no processo (`candidatura_id` -> `candidaturas.id`),
 * nunca com um cadastro "candidato" global — o mesmo CPF pode ter várias `candidaturas`.
 */
class Comunicacao
{
    private static bool $ensured = false;

    public const SITUACOES = ['preparada', 'enviada', 'erro'];
    public const ORIGENS = ['MANUAL', 'AUTOMATICA'];

    public static function allByCandidatura(int $candidaturaId): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'SELECT c.*, m.titulo AS mensagem_titulo, u.nome AS usuario_nome
             FROM comunicacoes c
             LEFT JOIN mensagens m ON m.id = c.mensagem_id
             LEFT JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.candidatura_id = ?
             ORDER BY c.created_at DESC'
        );
        $stmt->execute([$candidaturaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM comunicacoes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{candidatura_id:int,conteudo:string,mensagem_id?:?int,canal?:string,origem?:string,usuario_id?:?int,situacao?:string} $data
     * @return array{ok:bool,id?:int,error?:string}
     */
    public static function create(array $data): array
    {
        self::ensureSchema();
        $candidaturaId = (int)($data['candidatura_id'] ?? 0);
        $conteudo = (string)($data['conteudo'] ?? '');
        $origem = strtoupper(trim((string)($data['origem'] ?? 'MANUAL')));
        $usuarioId = isset($data['usuario_id']) && $data['usuario_id'] !== null ? (int)$data['usuario_id'] : null;
        $situacao = (string)($data['situacao'] ?? 'preparada');

        if ($candidaturaId <= 0) {
            return ['ok' => false, 'error' => 'Candidatura inválida.'];
        }
        if (trim($conteudo) === '') {
            return ['ok' => false, 'error' => 'O conteúdo da comunicação não pode ser vazio.'];
        }
        if (!in_array($origem, self::ORIGENS, true)) {
            return ['ok' => false, 'error' => 'Origem inválida.'];
        }
        if (!in_array($situacao, self::SITUACOES, true)) {
            return ['ok' => false, 'error' => 'Situação inválida.'];
        }
        // Origem MANUAL precisa de um usuário humano responsável; AUTOMATICA nunca usa um usuário
        // "fake" — usuario_id fica NULL (ver §6 da sprint).
        if ($origem === 'MANUAL' && $usuarioId === null) {
            return ['ok' => false, 'error' => 'Comunicação de origem manual precisa de um usuário responsável.'];
        }
        if ($origem === 'AUTOMATICA' && $usuarioId !== null) {
            return ['ok' => false, 'error' => 'Comunicação de origem automática não pode ter um usuário responsável (evite identidade artificial).'];
        }

        $mensagemId = isset($data['mensagem_id']) && $data['mensagem_id'] !== null ? (int)$data['mensagem_id'] : null;
        $canal = trim((string)($data['canal'] ?? 'whatsapp')) ?: 'whatsapp';

        $stmt = Database::conn()->prepare(
            'INSERT INTO comunicacoes (candidatura_id, mensagem_id, conteudo, canal, situacao, origem, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$candidaturaId, $mensagemId, $conteudo, $canal, $situacao, $origem, $usuarioId]);

        return ['ok' => true, 'id' => (int)Database::conn()->lastInsertId()];
    }

    /** Preparado para o webhook futuro (não implementado nesta sprint) confirmar o envio real. */
    public static function marcarEnviada(int $id, ?string $identificadorExterno = null): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare(
            'UPDATE comunicacoes SET situacao = ?, enviada_em = NOW(), identificador_externo = ? WHERE id = ?'
        );
        return $stmt->execute(['enviada', $identificadorExterno, $id]);
    }

    public static function marcarErro(int $id, string $mensagemErro): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('UPDATE comunicacoes SET situacao = ?, erro_mensagem = ? WHERE id = ?');
        return $stmt->execute(['erro', $mensagemErro, $id]);
    }

    /** Só pode ser chamado quando existir confirmação real do provedor — nunca por presunção. */
    public static function marcarEntregue(int $id): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('UPDATE comunicacoes SET entregue_em = NOW() WHERE id = ? AND entregue_em IS NULL');
        return $stmt->execute([$id]);
    }

    /** Só pode ser chamado quando existir confirmação real do provedor — nunca por presunção. */
    public static function marcarVisualizada(int $id): bool
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('UPDATE comunicacoes SET visualizada_em = NOW() WHERE id = ? AND visualizada_em IS NULL');
        return $stmt->execute([$id]);
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-09-16-comunicacoes-candidato.sql
     * ainda não rodou (não há runner de migrations neste projeto — ver CLAUDE.md §3.7).
     */
    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS comunicacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidatura_id INT NOT NULL,
            mensagem_id INT NULL,
            conteudo TEXT NOT NULL,
            canal VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
            situacao ENUM('preparada', 'enviada', 'erro') NOT NULL DEFAULT 'preparada',
            origem ENUM('MANUAL', 'AUTOMATICA') NOT NULL DEFAULT 'MANUAL',
            usuario_id INT NULL,
            enviada_em DATETIME NULL,
            entregue_em DATETIME NULL,
            visualizada_em DATETIME NULL,
            identificador_externo VARCHAR(191) NULL,
            erro_mensagem TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_comunicacoes_candidatura (candidatura_id),
            KEY idx_comunicacoes_mensagem (mensagem_id),
            KEY idx_comunicacoes_usuario (usuario_id),
            CONSTRAINT fk_comunicacoes_candidatura FOREIGN KEY (candidatura_id) REFERENCES candidaturas(id) ON DELETE CASCADE,
            CONSTRAINT fk_comunicacoes_mensagem FOREIGN KEY (mensagem_id) REFERENCES mensagens(id) ON DELETE SET NULL,
            CONSTRAINT fk_comunicacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
