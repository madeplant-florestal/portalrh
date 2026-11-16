<?php
class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): \PDO
    {
        if (self::$pdo === null) {
            $config = Config::app()['database'];
            
            try {
                self::$pdo = new \PDO($config['dsn'], $config['user'], $config['pass'], $config['options']);
                // Politica temporal (Frente D): a SESSAO do banco opera em UTC, entao NOW()/CURRENT_TIMESTAMP gravam e leem UTC
                // em qualquer servidor. Em producao o servidor ja e UTC (nenhuma mudanca); nao altera o fuso global do MariaDB.
                self::$pdo->exec("SET time_zone = '+00:00'");
            } catch (\PDOException $e) {
                // Em desenvolvimento, log o erro mas não pare a aplicação
                if (Config::app()['env'] === 'dev') {
                    error_log('Database connection failed: ' . $e->getMessage());
                    throw new \RuntimeException('MySQL não está rodando. Inicie o MySQL no XAMPP Control Panel.');
                }
                throw new \RuntimeException('Erro ao conectar ao banco de dados: ' . $e->getMessage());
            }
        }
        
        return self::$pdo;
    }
}