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
     * Validação da sessão AUTENTICADA contra o cadastro atual (Segurança 3.3) — UMA consulta por requisição autenticada, feita pelo
     * bootstrap (Security::enforceInactivityTimeout) ANTES de qualquer controller, view ou gate.
     *
     * Devolve 'ok' | 'sessao_legada' | 'sessao_invalida' | 'conta_desativada' | 'conta_reativada' | 'credencial_alterada' | 'perfil_invalido'.
     * Qualquer valor ≠ 'ok' encerra a sessão (Auth::logout no chamador). Falha fechada: usuário excluído ou erro de leitura = 'sessao_invalida'.
     *   - sessão sem as âncoras do login (`credencial_impressao` e `conta_ativa_desde`) = LEGADA: encerrada, nunca adotada;
     *   - conta desativada (`email_verified_at` NULL — o mesmo estado que o login recusa) = encerrada;
     *   - `email_verified_at` diferente do login = a conta foi desativada e reativada (a reativação grava um novo instante) = encerrada;
     *   - senha trocada (impressão diferente) = encerrada (regra de 9198876, preservada);
     *   - role desconhecida = encerrada (sem autoridade).
     * Só com tudo válido a autoridade da sessão (`user_role`, `user_is_supervisor`) é REESCRITA a partir do cadastro atual
     * (Auth::autoridadeEfetiva) — mudança de perfil ou do flag de Supervisor vale nesta mesma requisição, sem novo login.
     * Nenhum hash, impressão ou cookie é registrado em log.
     */
    public static function avaliarSessaoAutenticada(): string
    {
        $usuarioId = (int)($_SESSION['user_id'] ?? 0);
        if ($usuarioId <= 0) {
            return 'ok';
        }
        try {
            $u = User::estadoParaSessao($usuarioId);
        } catch (\Throwable $e) {
            Logger::warning('Sessão encerrada: falha ao verificar a conta do usuário', ['usuario_id' => $usuarioId, 'tipo_erro' => get_class($e)]);
            return 'sessao_invalida';
        }
        if (!is_array($u) || (string)($u['senha_hash'] ?? '') === '') {
            return 'sessao_invalida';
        }
        $impressao = $_SESSION['credencial_impressao'] ?? null;
        if (!is_string($impressao) || $impressao === '' || !array_key_exists('conta_ativa_desde', $_SESSION)) {
            return 'sessao_legada';
        }
        $ativaDesde = (string)($u['email_verified_at'] ?? '');
        if ($ativaDesde === '') {
            return 'conta_desativada';
        }
        if (!hash_equals($impressao, self::impressaoCredencial((string)$u['senha_hash']))) {
            return 'credencial_alterada';
        }
        if (!hash_equals((string)$_SESSION['conta_ativa_desde'], $ativaDesde)) {
            return 'conta_reativada';
        }
        $autoridade = Auth::autoridadeEfetiva($u);
        if ($autoridade === null) {
            return 'perfil_invalido';
        }
        Auth::aplicarAutoridadeNaSessao($autoridade);
        return 'ok';
    }
}
