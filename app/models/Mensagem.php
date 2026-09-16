<?php
/**
 * Templates de mensagem do processo seletivo (Sprint "Módulo de Mensagens" —
 * migration 2026-09-16-mensagens.sql). O Portal é a fonte OFICIAL destes textos; o n8n não
 * guarda cópia própria — ver app/services/MensagemService.php para a renderização com variáveis.
 *
 * `codigo` é a identidade técnica estável (ex.: `convocacao_entrevista_rh`) — nunca gerada a
 * partir do título e nunca reeditável depois de criada (ver AdminMensagensController::update()).
 */
class Mensagem
{
    private static bool $ensured = false;

    /** Formato aceito para `codigo`: minúsculas, dígitos e underscore, sem espaços/acentos. */
    public const CODIGO_REGEX = '/^[a-z0-9_]+$/';

    public static function all(): array
    {
        self::ensureSchema();
        $stmt = Database::conn()->query('SELECT * FROM mensagens ORDER BY titulo ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM mensagens WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Usado pelo `MensagemService` — só mensagens ATIVAS podem ser resolvidas operacionalmente. */
    public static function findAtivaByCodigo(string $codigo): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM mensagens WHERE codigo = ? AND ativo = 1 LIMIT 1');
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByCodigo(string $codigo): ?array
    {
        self::ensureSchema();
        $stmt = Database::conn()->prepare('SELECT * FROM mensagens WHERE codigo = ? LIMIT 1');
        $stmt->execute([$codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{ok:bool,id?:int,error?:string}
     */
    public static function create(array $data): array
    {
        self::ensureSchema();
        $codigo = strtolower(trim((string)($data['codigo'] ?? '')));
        $titulo = trim((string)($data['titulo'] ?? ''));
        $conteudo = (string)($data['conteudo'] ?? '');

        if (!preg_match(self::CODIGO_REGEX, $codigo)) {
            return ['ok' => false, 'error' => 'Código técnico inválido. Use apenas letras minúsculas, números e underscore (ex.: convocacao_entrevista_rh).'];
        }
        if ($titulo === '') {
            return ['ok' => false, 'error' => 'Informe o título da mensagem.'];
        }
        if (trim($conteudo) === '') {
            return ['ok' => false, 'error' => 'Informe o conteúdo da mensagem.'];
        }
        if (self::findByCodigo($codigo) !== null) {
            return ['ok' => false, 'error' => 'Já existe uma mensagem cadastrada com este código técnico.'];
        }

        $stmt = Database::conn()->prepare(
            'INSERT INTO mensagens (codigo, titulo, descricao, conteudo, ativo) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $codigo,
            $titulo,
            trim((string)($data['descricao'] ?? '')) !== '' ? trim((string)$data['descricao']) : null,
            $conteudo,
            !empty($data['ativo']) ? 1 : 0,
        ]);

        return ['ok' => true, 'id' => (int)Database::conn()->lastInsertId()];
    }

    /**
     * NUNCA altera `codigo` — a interface administrativa só envia título/descrição/conteúdo/ativo
     * (ver AdminMensagensController::update()); mesmo que um `codigo` chegasse aqui, este método
     * não o inclui no UPDATE, então não há caminho de escrita para ele após a criação.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function update(int $id, array $data): array
    {
        self::ensureSchema();
        $titulo = trim((string)($data['titulo'] ?? ''));
        $conteudo = (string)($data['conteudo'] ?? '');

        if ($titulo === '') {
            return ['ok' => false, 'error' => 'Informe o título da mensagem.'];
        }
        if (trim($conteudo) === '') {
            return ['ok' => false, 'error' => 'Informe o conteúdo da mensagem.'];
        }

        $stmt = Database::conn()->prepare(
            'UPDATE mensagens SET titulo = ?, descricao = ?, conteudo = ?, ativo = ? WHERE id = ?'
        );
        $stmt->execute([
            $titulo,
            trim((string)($data['descricao'] ?? '')) !== '' ? trim((string)$data['descricao']) : null,
            $conteudo,
            !empty($data['ativo']) ? 1 : 0,
            $id,
        ]);

        return ['ok' => true];
    }

    /**
     * Rede de segurança para ambientes onde a migration 2026-09-16-mensagens.sql ainda não rodou
     * (não há runner de migrations neste projeto — ver CLAUDE.md §3.7). Mesmo padrão de
     * SolicitacaoVagaStage::ensureSchema().
     */
    public static function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        Database::conn()->exec("CREATE TABLE IF NOT EXISTS mensagens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(80) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            descricao TEXT NULL,
            conteudo TEXT NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_mensagens_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
