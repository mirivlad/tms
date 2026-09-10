<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    /**
     * @param array{host:string, port:int|string, name:string, user:string, password:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            (int) $this->config['port'],
            $this->config['name'],
        );

        try {
            return new PDO($dsn, $this->config['user'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed.', 0, $exception);
        }
    }
}
