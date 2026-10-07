<?php
// Stores the order waiting to be paid (single-kiosk, single pending order).
require __DIR__ . '/db.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $order = $pdo->query('SELECT total, qty, created_at FROM pending_orders WHERE id = 1')->fetch();
    json_out($order ?: null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $total = is_array($data) ? (float)($data['total'] ?? 0) : 0;
    $qty = is_array($data) ? (int)($data['qty'] ?? 0) : 0;
    if ($total <= 0 || $qty <= 0) json_out(['error' => 'total and qty required'], 400);
    $stmt = $pdo->prepare('INSERT INTO pending_orders (id, total, qty, created_at) VALUES (1, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE total = VALUES(total), qty = VALUES(qty), created_at = VALUES(created_at)');
    $stmt->execute([$total, $qty, date('Y-m-d H:i:s')]);
    json_out(['message' => 'Pending order saved']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $pdo->exec('DELETE FROM pending_orders WHERE id = 1');
    json_out(['message' => 'Cleared']);
}

json_out(['error' => 'Method not allowed'], 405);
