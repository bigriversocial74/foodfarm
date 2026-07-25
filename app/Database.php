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

        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $port = (int)($this->config['port'] ?? 3306);
        $name = (string)($this->config['name'] ?? 'homestead');
        $charset = (string)($this->config['charset'] ?? 'utf8mb4');
        $user = (string)($this->config['user'] ?? 'root');
        $password = (string)($this->config['password'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Homestead could not connect to the database.', 0, $exception);
        }

        return $this->pdo;
    }
}
