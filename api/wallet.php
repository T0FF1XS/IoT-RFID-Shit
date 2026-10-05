<?php
// Wallet operations: cashier loads points, kiosk deducts purchases
// POST { uid, amount, type: "load" | "purchase" }  -> new balance
// GET  ?uid=XXXX -> transaction history
require __DIR__ . '/db.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $uid = clean_uid($_GET['uid'] ?? '');
    if ($uid === '') json_out(['error' => 'Invalid uid'], 400);
    $stmt = $pdo->prepare('SELECT uid, name, balance FROM users WHERE uid = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) json_out(['error' => 'Card not registered'], 404);
    $tx = $pdo->prepare('SELECT type, amount, balance_after, created_at FROM transactions WHERE uid = ? ORDER BY id DESC LIMIT 20');
    $tx->execute([$uid]);
    json_out(['user' => $user, 'transactions' => $tx->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $uid = is_array($data) ? clean_uid($data['uid'] ?? '') : '';
    $amount = is_array($data) ? (float)($data['amount'] ?? 0) : 0;
    $type = is_array($data) ? (string)($data['type'] ?? '') : '';

    if ($uid === '' || $amount <= 0 || !in_array($type, ['load', 'purchase'], true)) {
        json_out(['error' => 'Valid uid, positive amount, and type (load|purchase) required'], 400);
    }

    $stmt = $pdo->prepare('SELECT name, balance FROM users WHERE uid = ?');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) json_out(['error' => 'Card not registered'], 404);

    $newBalance = $type === 'load'
        ? (float)$user['balance'] + $amount
        : (float)$user['balance'] - $amount;

    if ($newBalance < 0) {
        json_out(['error' => 'Insufficient balance', 'balance' => (float)$user['balance']], 400);
    }

    $pdo->prepare('UPDATE users SET balance = ? WHERE uid = ?')->execute([$newBalance, $uid]);
    $pdo->prepare('INSERT INTO transactions (uid, name, type, amount, balance_after, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$uid, $user['name'], $type, $amount, $newBalance, date('Y-m-d H:i:s')]);

    json_out(['message' => ucfirst($type) . ' OK', 'balance' => $newBalance, 'name' => $user['name']]);
}

json_out(['error' => 'Method not allowed'], 405);
