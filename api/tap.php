<?php
// Receives card taps from the ESP32 kiosk and returns the access decision
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$uid = is_array($data) ? clean_uid($data['uid'] ?? '') : '';
if ($uid === '') {
    json_out(['error' => 'Invalid or missing JSON payload'], 400);
}
$deviceId = substr((string)($data['device_id'] ?? 'Unknown'), 0, 40);

$pdo = db();
$stmt = $pdo->prepare('SELECT name, balance FROM users WHERE uid = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$access = $user ? 'granted' : 'denied';
$name = $user ? $user['name'] : 'Unknown';
$balance = $user ? (float)$user['balance'] : 0;

$log = $pdo->prepare('INSERT INTO access_logs (device_id, uid, name, access, balance, created_at)
                      VALUES (?, ?, ?, ?, ?, ?)');
$log->execute([$deviceId, $uid, $name, $access, $balance, date('Y-m-d H:i:s')]);

json_out(['access' => $access, 'name' => $name, 'balance' => $balance]);
