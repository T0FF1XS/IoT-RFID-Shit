<?php
// Returns recent taps + summary stats for the dashboard
require __DIR__ . '/db.php';

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pdo->exec('DELETE FROM access_logs');
    json_out(['message' => 'Logs cleared']);
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

$rows = $pdo->query("SELECT id, device_id, uid, name, access, balance, created_at
                     FROM access_logs ORDER BY id DESC LIMIT $limit")->fetchAll();

$stats = $pdo->query("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(access = 'granted'), 0) AS granted,
    COALESCE(SUM(access = 'denied'), 0) AS denied
    FROM access_logs")->fetch();
$stats['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

json_out(['logs' => $rows, 'stats' => $stats]);
