<?php
/**
 * Conexão dedicada ao METADADOS (SQL Server) — sistema oficial de RH/DP, fonte de verdade
 * de colaboradores a partir desta sprint. Usada exclusivamente por MetadadosSyncService e
 * scripts/sync_metadados_colaboradores.php.
 *
 * Nunca deve ser chamada durante navegação normal do Portal: o objetivo desta integração é
 * justamente que o Portal continue funcionando mesmo com o METADADOS indisponível, lendo
 * sempre da tabela espelho local (colaboradores_metadados), nunca ao vivo.
 */
class MetadadosDatabase
{
    private static ?PDO $pdo = null;

    public static function conn(): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (!in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException(
                'Driver pdo_sqlsrv indisponível nesta instalação do PHP. Instale a extensão ' .
                'oficial da Microsoft (pdo_sqlsrv) compatível com esta versão do PHP antes de ' .
                'sincronizar com o METADADOS.'
            );
        }

        $config = Config::app()['metadados'] ?? [];
        $dsn = (string)($config['dsn'] ?? '');

        if ($dsn === '') {
            throw new \RuntimeException(
                'Conexão com o METADADOS não configurada. Defina metadados.dsn/user/pass em ' .
                'app/config/local.php (ou build.php), nunca em config.php.'
            );
        }

        try {
            self::$pdo = new \PDO($dsn, (string)($config['user'] ?? ''), (string)($config['pass'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Falha ao conectar ao METADADOS (SQL Server): ' . $e->getMessage());
        }

        return self::$pdo;
    }
}
