<?php
// Stores the order waiting to be paid (single-kiosk, single pending order)
require __DIR__ . '/db.php';

$file = __DIR__ . '/../data/pending.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(file_exists($file) ? json_decode(file_get_contents($file), true) : null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $total = is_array($data) ? (float)($data['total'] ?? 0) : 0;
    $qty = is_array($data) ? (int)($data['qty'] ?? 0) : 0;
    if ($total <= 0 || $qty <= 0) json_out(['error' => 'total and qty required'], 400);
    file_put_contents($file, json_encode(['total' => $total, 'qty' => $qty, 'created_at' => date('Y-m-d H:i:s')]));
    json_out(['message' => 'Pending order saved']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (file_exists($file)) unlink($file);
    json_out(['message' => 'Cleared']);
}

json_out(['error' => 'Method not allowed'], 405);
