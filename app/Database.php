<?php

declare(strict_types=1);

namespace Homestead;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = trim((string)($this->config['host'] ?? '127.0.0.1'));
        $port = (int)($this->config['port'] ?? 3306);
        $name = trim((string)($this->config['name'] ?? 'homestead'));
        $charset = strtolower(trim((string)($this->config['charset'] ?? 'utf8mb4')));
        $user = (string)($this->config['user'] ?? 'root');
        $password = (string)($this->config['password'] ?? '');

        if ($host === '' || $port < 1 || $port > 65535
            || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name)
            || !in_array($charset, ['utf8mb4', 'utf8'], true)
            || $user === '') {
            throw new RuntimeException('Homestead database configuration is invalid.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->pdo->exec("SET SESSION time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $exception) {
            throw new RuntimeException('Homestead could not connect to the database.', 0, $exception);
        }

        return $this->pdo;
    }
}
