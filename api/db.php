<?php
// Shared database + helper functions (SQLite, no setup needed)
date_default_timezone_set('Asia/Manila');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . $dir . '/tagit.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        uid TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT,
        uid TEXT,
        name TEXT,
        access TEXT,
        created_at TEXT)");

    // Migration: add balance column if missing
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('balance', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN balance REAL NOT NULL DEFAULT 0");
    }
    $logCols = $pdo->query("PRAGMA table_info(access_logs)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('balance', $logCols, true)) {
        $pdo->exec("ALTER TABLE access_logs ADD COLUMN balance REAL NOT NULL DEFAULT 0");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL,
        name TEXT,
        type TEXT NOT NULL,
        amount REAL NOT NULL,
        balance_after REAL NOT NULL,
        created_at TEXT NOT NULL)");
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
