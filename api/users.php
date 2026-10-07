<?php
// Manage registered cards: GET = list, POST = add, DELETE = remove
require __DIR__ . '/db.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out($pdo->query('SELECT uid, name, balance, created_at FROM users ORDER BY name')->fetchAll());
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $uid = is_array($data) ? clean_uid($data['uid'] ?? '') : '';
    $name = is_array($data) ? trim((string)($data['name'] ?? '')) : '';
    if ($uid === '' || $name === '') {
        json_out(['error' => 'Valid UID (HEX) and name are required'], 400);
    }
    $stmt = $pdo->prepare('INSERT INTO users (uid, name, created_at, balance) VALUES (?, ?, ?, 0)
                           ON DUPLICATE KEY UPDATE name = VALUES(name)');
    $stmt->execute([$uid, substr($name, 0, 40), date('Y-m-d H:i:s')]);
    json_out(['message' => 'Card registered']);
}

if ($method === 'DELETE') {
    $uid = clean_uid($_GET['uid'] ?? '');
    if ($uid === '') json_out(['error' => 'Invalid UID'], 400);
    $stmt = $pdo->prepare('DELETE FROM users WHERE uid = ?');
    $stmt->execute([$uid]);
    json_out(['message' => 'Card removed']);
}

json_out(['error' => 'Method not allowed'], 405);
