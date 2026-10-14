<?php

function getDBConnection(): PDO
{
    $envFile = __DIR__ . '/../.env';

    if (!is_readable($envFile)) {
        throw new RuntimeException(
            'Database configuration file not found.'
        );
    }

    $config = parse_ini_file($envFile);

    if ($config === false) {
        throw new RuntimeException(
            'Invalid database configuration.'
        );
    }

    $host = $config['DB_HOST'];
    $dbname = $config['DB_NAME'];
    $username = $config['DB_USER'];
    $password = $config['DB_PASS'];

    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

    return new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}