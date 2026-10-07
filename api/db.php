<?php
// Shared MySQL connection + schema (create the database in phpMyAdmin first).
date_default_timezone_set('Asia/Manila');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // XAMPP defaults; override in data/mysql.php if your MySQL setup differs.
    $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'tapit',
        'user' => 'root',
        'password' => '',
    ];
    $localConfig = __DIR__ . '/../data/mysql.php';
    if (is_file($localConfig)) {
        $config = array_replace($config, require $localConfig);
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $config['database'])) {
        throw new RuntimeException('Invalid MySQL database name');
    }
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        uid VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        balance DECIMAL(12,2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS access_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(40),
        uid VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin,
        name VARCHAR(40),
        access VARCHAR(10) NOT NULL,
        balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uid VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        name VARCHAR(40),
        type VARCHAR(20) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX transactions_uid_id (uid, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_orders (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        total DECIMAL(12,2) NOT NULL,
        qty INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $pdo;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function clean_uid($uid): string {
    $uid = strtoupper(trim((string)$uid));
    return preg_match('/^[0-9A-F]{4,20}$/', $uid) ? $uid : '';
}
