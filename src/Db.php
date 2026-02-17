<?php
final class Db
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO(
            Config::env("DB_DSN"),
            Config::env("DB_USER"),
            Config::env("DB_PASS"),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:00'",
            ],
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $res = $fn($this->pdo);
            $this->pdo->commit();
            return $res;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
