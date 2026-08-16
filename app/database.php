<?php

declare(strict_types=1);

function llama_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = dirname(__DIR__, 2) . '/private/config.php';

    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Private Llama Scout configuration is missing.'
        );
    }

    $config = require $configPath;

    if (
        !is_array($config) ||
        empty($config['database'])
    ) {
        throw new RuntimeException(
            'Private Llama Scout configuration is invalid.'
        );
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database =
        llama_config()['database'];

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $database['host'] ?? 'localhost',
        $database['name'] ?? '',
        $database['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO(
        $dsn,
        $database['user'] ?? '',
        $database['password'] ?? '',
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false,
        ]
    );

    return $pdo;
}
