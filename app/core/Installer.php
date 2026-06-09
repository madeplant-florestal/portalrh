<?php
class Installer
{
    public static function isInstalled(): bool
    {
        return is_file(self::installLockPath());
    }

    public static function requirements(): array
    {
        $checks = [];
        $checks[] = self::check('PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='));
        $checks[] = self::check('Extensão PDO', extension_loaded('pdo'));
        $checks[] = self::check('Extensão pdo_mysql', extension_loaded('pdo_mysql'));
        $checks[] = self::check('Diretório storage gravável', self::isWritableDir(self::storagePath()));
        $checks[] = self::check('Diretório public/uploads gravável', self::isWritableDir(self::basePath() . '/public/uploads'));
        $checks[] = self::check('Arquivo SQL raiz disponível', is_file(self::rootSqlPath()) || is_file(self::basePath() . '/database/schema.sql'));
        return $checks;
    }

    public static function run(array $input, callable $logger): array
    {
        Logger::init(Config::app());
        self::ensureDir(self::storagePath() . '/logs');
        $installLog = self::storagePath() . '/logs/install-' . date('Ymd-His') . '.log';
        $log = function (string $message) use ($logger, $installLog): void {
            $line = '[' . date('c') . '] ' . $message;
            $logger($line);
            @file_put_contents($installLog, $line . PHP_EOL, FILE_APPEND);
        };

        $log('Iniciando processo de instalação.');
        Logger::info('Installer started', Logger::captureContext(http_response_code(), ['installer' => ['step' => 'start']]));
        if (self::isInstalled()) {
            throw new RuntimeException('Instalação já foi concluída anteriormente.');
        }

        $requirements = self::requirements();
        foreach ($requirements as $item) {
            if (!$item['ok']) {
                throw new RuntimeException('Requisito não atendido: ' . $item['label']);
            }
        }

        $config = self::buildConfig($input);
        $configMode = strtolower(trim((string)($input['config_mode'] ?? 'config')));
        $targetConfigPath = $configMode === 'local' ? self::localConfigPath() : self::configPath();
        self::ensureDir(dirname($targetConfigPath));
        $allowOverwrite = ((string)($input['allow_overwrite_config'] ?? '') === '1');
        if (!is_file($targetConfigPath)) {
            self::writeConfigAtomic($targetConfigPath, $config);
            $log('Arquivo de configuração criado: ' . str_replace(self::basePath() . '/', '', $targetConfigPath));
        } elseif ($allowOverwrite) {
            self::writeConfigAtomic($targetConfigPath, $config);
            $log('Arquivo de configuração sobrescrito: ' . str_replace(self::basePath() . '/', '', $targetConfigPath));
        } else {
            $log('Arquivo de configuração existente preservado: ' . str_replace(self::basePath() . '/', '', $targetConfigPath));
        }

        $pdo = self::connect($config['database'], $log);
        self::importSchema($pdo, $log);
        self::runSchemaEnsure($log);
        self::createAdminIfNeeded($input, $log);
        self::ensureRuntimeDirs($log);

        self::writeInstallLock($log);
        $log('Instalação concluída com sucesso.');
        Logger::info('Installer finished', Logger::captureContext(http_response_code(), ['installer' => ['step' => 'done']]));

        return [
            'log_file' => $installLog,
            'self_delete' => self::trySelfDelete($log),
        ];
    }

    private static function buildConfig(array $input): array
    {
        $app = Config::app();
        $dbCurrent = $app['database'] ?? [];
        $mailCurrent = $app['mail'] ?? [];
        $secCurrent = $app['security'] ?? [];
        $logCurrent = $app['logging'] ?? [];

        $dsn = trim((string)($input['db_dsn'] ?? ($dbCurrent['dsn'] ?? '')));
        $user = trim((string)($input['db_user'] ?? ($dbCurrent['user'] ?? '')));
        $pass = array_key_exists('db_pass', $input) ? (string)$input['db_pass'] : (string)($dbCurrent['pass'] ?? '');
        $mailFrom = trim((string)($input['mail_from'] ?? ($mailCurrent['from'] ?? '')));
        $mailTo = trim((string)($input['mail_to_hr'] ?? ($mailCurrent['to_hr'] ?? '')));
        $supervisorEmail = trim((string)($input['supervisor_email'] ?? ($secCurrent['supervisor_email'] ?? '')));
        $supervisorPassword = array_key_exists('supervisor_password', $input) ? (string)$input['supervisor_password'] : (string)($secCurrent['supervisor_password'] ?? '');
        $env = trim((string)($input['app_env'] ?? ($app['env'] ?? 'prod')));
        $logLevel = trim((string)($input['log_level'] ?? ($logCurrent['level'] ?? 'INFO')));
        $alertEmail = trim((string)($input['log_alert_email'] ?? ($logCurrent['alert_email'] ?? '')));
        $viewerKey = trim((string)($input['log_viewer_key'] ?? ($logCurrent['viewer_key'] ?? '')));
        if ($dsn === '' || $user === '' || $mailFrom === '' || $mailTo === '' || $supervisorEmail === '' || $supervisorPassword === '') {
            throw new RuntimeException('Preencha todos os campos obrigatórios do instalador.');
        }

        return [
            'env' => $env === '' ? 'prod' : $env,
            'security' => [
                'supervisor_email' => $supervisorEmail,
                'supervisor_password' => $supervisorPassword,
            ],
            'mail' => [
                'enabled' => true,
                'from' => $mailFrom,
                'to_hr' => $mailTo,
            ],
            'logging' => [
                'level' => $logLevel === '' ? 'INFO' : strtoupper($logLevel),
                'alert_email' => $alertEmail,
                'viewer_key' => $viewerKey,
            ],
            'database' => [
                'dsn' => $dsn,
                'user' => $user,
                'pass' => $pass,
            ],
        ];
    }

    private static function writeConfigAtomic(string $path, array $config): void
    {
        $export = var_export($config, true);
        $content = "<?php\nreturn " . $export . ";\n";
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Não foi possível gravar arquivo temporário de configuração.');
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível finalizar escrita do arquivo de configuração.');
        }
        @chmod($path, 0644);
    }

    public static function preflightSummary(): array
    {
        $requirements = self::requirements();
        $failed = [];
        foreach ($requirements as $req) {
            if (!$req['ok']) {
                $failed[] = $req['label'];
            }
        }
        $configFiles = [
            'config' => is_file(self::configPath()),
            'local' => is_file(self::localConfigPath()),
            'lock' => is_file(self::installLockPath()),
        ];
        return [
            'ok' => count($failed) === 0,
            'failed_requirements' => $failed,
            'config_files' => $configFiles,
        ];
    }

    private static function connect(array $db, callable $log): PDO
    {
        $log('Conectando ao banco de dados.');
        try {
            $pdo = new PDO(
                (string)$db['dsn'],
                (string)$db['user'],
                (string)$db['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        } catch (Throwable $e) {
            throw new RuntimeException('Falha na conexão com banco de dados: ' . $e->getMessage());
        }
    }

    private static function importSchema(PDO $pdo, callable $log): void
    {
        $schemaFile = is_file(self::rootSqlPath()) ? self::rootSqlPath() : self::basePath() . '/database/schema.sql';
        $sql = (string)file_get_contents($schemaFile);
        if (trim($sql) === '') {
            throw new RuntimeException('Arquivo SQL de instalação está vazio.');
        }
        $log('Importando estrutura inicial a partir de ' . basename($schemaFile) . '.');
        $pdo->exec($sql);
    }

    private static function runSchemaEnsure(callable $log): void
    {
        $log('Executando migrações incrementais.');
        SchemaManager::ensure();
    }

    private static function createAdminIfNeeded(array $input, callable $log): void
    {
        $email = trim((string)($input['admin_email'] ?? ''));
        $password = (string)($input['admin_password'] ?? '');
        if ($email === '' || $password === '') {
            $log('Credenciais de admin não informadas. Etapa de admin ignorada.');
            return;
        }
        $existing = User::findByEmail($email);
        if ($existing) {
            $log('Usuário admin já existe: ' . $email);
            return;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        User::create('Administrador', $email, $hash, 'admin');
        $log('Usuário admin criado: ' . $email);
    }

    private static function ensureRuntimeDirs(callable $log): void
    {
        $dirs = [
            self::storagePath() . '/sessions',
            self::storagePath() . '/resumes',
            self::storagePath() . '/ratelimit',
            self::storagePath() . '/audit',
            self::storagePath() . '/logs',
            self::basePath() . '/public/uploads/logos',
        ];
        foreach ($dirs as $dir) {
            self::ensureDir($dir);
        }
        $log('Diretórios de runtime verificados.');
    }

    private static function writeInstallLock(callable $log): void
    {
        $lockFile = self::installLockPath();
        $payload = json_encode(['installed_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($lockFile, $payload . PHP_EOL) === false) {
            throw new RuntimeException('Não foi possível criar lock de instalação.');
        }
        $log('Lock de instalação criado.');
    }

    private static function trySelfDelete(callable $log): bool
    {
        $self = self::basePath() . '/public/install.php';
        if (!is_file($self)) {
            return false;
        }
        $deleted = @unlink($self);
        if ($deleted) {
            $log('Instalador web removido automaticamente.');
        } else {
            $log('Falha ao remover instalador automaticamente. Remova public/install.php manualmente.');
        }
        return $deleted;
    }

    private static function check(string $label, bool $ok): array
    {
        return ['label' => $label, 'ok' => $ok];
    }

    private static function isWritableDir(string $path): bool
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        return is_dir($path) && is_writable($path);
    }

    private static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (!is_dir($path)) {
            throw new RuntimeException('Não foi possível criar diretório: ' . $path);
        }
    }

    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function storagePath(): string
    {
        return self::basePath() . '/storage';
    }

    private static function localConfigPath(): string
    {
        return self::basePath() . '/app/config/local.php';
    }

    private static function configPath(): string
    {
        return self::basePath() . '/app/config/config.php';
    }

    private static function installLockPath(): string
    {
        return self::storagePath() . '/install.done';
    }

    private static function rootSqlPath(): string
    {
        return self::basePath() . '/recrutamento.sql';
    }
}
