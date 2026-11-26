<?php

/**
 * Política CENTRAL de duração da sessão (Etapa "Expiração de sessão", 2026-11).
 *
 * Havia TRÊS relógios diferentes e só um era conhecido:
 *   1. lógico (aplicação): `Security::enforceInactivityTimeout(1200)` — 20 min fixos no bootstrap (a mensagem "20 minutos" vem daí);
 *   2. coleta do PHP: `session.gc_maxlifetime` NUNCA era definido — vale o padrão do servidor (24 min em instalações comuns). Mesmo subindo o limite
 *      lógico, o coletor apagaria o arquivo de sessões ociosas por mais de ~24 min (a sessão "some" e o usuário cai no login sem a mensagem de expiração);
 *   3. cookie: `lifetime => 0` (cookie de sessão do navegador) — não expira por tempo, só ao fechar o navegador. Mantido de propósito (sem sessão permanente).
 * Esta classe define os dois limites da aplicação e DERIVA o `gc_maxlifetime` deles, para nunca mais divergirem.
 *
 *   ociosidade   = tempo sem NENHUMA requisição autenticada (renovada a cada requisição legítima; nada de "ping" automático). Padrão 8 h.
 *   limite total = teto absoluto desde o login, mesmo com atividade contínua (nenhuma sessão vive para sempre). Padrão 24 h.
 *
 * Configuração: `security.session_idle_timeout_seconds` e `security.session_absolute_lifetime_seconds` (Config::app()), com limites de sanidade.
 * Relógio de teste: SÓ em CLI, via variável de ambiente RH_TEST_NOW (inoperante na web).
 */
final class SessionPolicy
{
    public const PADRAO_OCIOSIDADE = 28800;   // 8 h
    public const PADRAO_LIMITE_TOTAL = 86400; // 24 h
    private const MIN_OCIOSIDADE = 300;       // 5 min (piso de sanidade)
    private const MAX_OCIOSIDADE = 43200;     // 12 h (teto de sanidade)
    /** Sobra do coletor sobre o maior limite, para ele nunca apagar uma sessão ainda válida pela política. */
    private const MARGEM_GC = 3600;

    public static function ociosidade(): int
    {
        $v = (int)(Config::app()['security']['session_idle_timeout_seconds'] ?? self::PADRAO_OCIOSIDADE);
        return max(self::MIN_OCIOSIDADE, min(self::MAX_OCIOSIDADE, $v > 0 ? $v : self::PADRAO_OCIOSIDADE));
    }

    public static function limiteTotal(): int
    {
        $v = (int)(Config::app()['security']['session_absolute_lifetime_seconds'] ?? self::PADRAO_LIMITE_TOTAL);
        return max(self::ociosidade(), $v > 0 ? $v : self::PADRAO_LIMITE_TOTAL);
    }

    /** `session.gc_maxlifetime` coerente com a política (o coletor só apaga arquivos mais velhos que isto). */
    public static function gcMaxLifetime(): int
    {
        return self::limiteTotal() + self::MARGEM_GC;
    }

    /** "Agora" da política. Em CLI aceita RH_TEST_NOW (epoch) para testes com relógio controlado. */
    public static function agora(): int
    {
        if (PHP_SAPI === 'cli') {
            $t = getenv('RH_TEST_NOW');
            if ($t !== false && ctype_digit((string)$t)) {
                return (int)$t;
            }
        }
        return time();
    }

    /**
     * Aplica as diretivas do PHP ANTES do session_start. Retorna o que o servidor REALMENTE aceitou (hospedagens podem bloquear ini_set).
     *
     * @return array{gc_maxlifetime_pedido:int,gc_maxlifetime_efetivo:int,aceito:bool}
     */
    public static function aplicarIni(): array
    {
        $pedido = self::gcMaxLifetime();
        @ini_set('session.gc_maxlifetime', (string)$pedido);
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
        @ini_set('session.use_strict_mode', '1');   // o servidor só aceita IDs que ele mesmo emitiu (fixação de sessão)
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_lifetime', '0');   // cookie de sessão do navegador: sem persistência após fechar o navegador
        $efetivo = (int)ini_get('session.gc_maxlifetime');
        return ['gc_maxlifetime_pedido' => $pedido, 'gc_maxlifetime_efetivo' => $efetivo, 'aceito' => $efetivo >= $pedido];
    }

    /**
     * Avalia (e renova, se válida) a sessão. PURO em relação à resposta HTTP: devolve 'ok' | 'inatividade' | 'limite_total'; só altera $_SESSION quando 'ok'.
     * Uma sessão já expirada NUNCA é renovada. Sessões anteriores a esta política (sem `session_started_at`) começam a contar o limite total na 1ª requisição.
     */
    public static function avaliar(int $agora): string
    {
        $logado = !empty($_SESSION['user_id']);
        if ($logado) {
            $ultima = $_SESSION['last_activity'] ?? null;
            if ($ultima !== null && ($agora - (int)$ultima) > self::ociosidade()) {
                return 'inatividade';
            }
            $inicio = $_SESSION['session_started_at'] ?? null;
            if ($inicio !== null && ($agora - (int)$inicio) > self::limiteTotal()) {
                return 'limite_total';
            }
            if ($inicio === null) {
                $_SESSION['session_started_at'] = $agora;
            }
        }
        $_SESSION['last_activity'] = $agora;
        return 'ok';
    }

    /**
     * Impressão da credencial gravada na sessão no login (Auth::establishSession). Deriva do `usuarios.senha_hash` (bcrypt com salt: toda
     * troca de senha gera um valor novo, por QUALQUER caminho — recuperação, redefinição administrativa, senha temporária, script/SQL manual).
     * Só a impressão vai para a sessão, nunca o hash.
     */
    public static function impressaoCredencial(string $senhaHash): string
    {
        return hash('sha256', 'rhmadeplant-sessao|' . $senhaHash);
    }

    /**
     * Revogação por troca de senha: 'ok' | 'credencial_alterada'. Sessão autenticada só continua válida se a senha atual do usuário no banco
     * for a mesma do momento do login — trocar a senha encerra TODAS as sessões abertas antes, na próxima requisição de cada uma. Usuário
     * inexistente ou falha de leitura = encerrada (falha fechada). Sessões abertas antes desta regra (sem impressão) adotam a credencial atual na
     * 1ª requisição — mesmo tratamento de `session_started_at` em avaliar(); o teto total (limiteTotal) limita essa transição.
     */
    public static function avaliarCredencial(): string
    {
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        if ($usuarioId <= 0) {
            return 'ok';
        }
        try {
            $stmt = Database::conn()->prepare('SELECT senha_hash FROM usuarios WHERE id = ? LIMIT 1');
            $stmt->execute([$usuarioId]);
            $hash = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            Logger::warning('Sessão encerrada: falha ao verificar a credencial do usuário', ['usuario_id' => $usuarioId, 'erro' => $e->getMessage()]);
            return 'credencial_alterada';
        }
        if (!is_string($hash) || $hash === '') {
            return 'credencial_alterada';
        }
        $atual = self::impressaoCredencial($hash);
        $daSessao = $_SESSION['credencial_impressao'] ?? null;
        if (!is_string($daSessao) || $daSessao === '') {
            $_SESSION['credencial_impressao'] = $atual;
            return 'ok';
        }
        return hash_equals($daSessao, $atual) ? 'ok' : 'credencial_alterada';
    }
}
