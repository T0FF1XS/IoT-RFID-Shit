<?php
// Payment-aware endpoint the ESP32 posts taps to.
// If an order is pending in MySQL, this tap is a payment; otherwise it is a check-in.
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
$pdo->beginTransaction();
try {
    // Lock the single pending order so two taps cannot pay for it twice.
    $pending = $pdo->query('SELECT total FROM pending_orders WHERE id = 1 FOR UPDATE')->fetch();
    $hasPending = $pending && (float)$pending['total'] > 0;

    $stmt = $pdo->prepare('SELECT name, balance FROM users WHERE uid = ? FOR UPDATE');
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    $access = 'denied';
    $name = $user ? $user['name'] : 'Unknown';
    $balance = $user ? (float)$user['balance'] : 0;
    $error = null;

    if ($hasPending) {
        $total = (float)$pending['total'];
        if (!$user) {
            $error = 'Card not registered';
        } elseif ($balance < $total) {
            $error = 'Insufficient balance';
        } else {
            $balance -= $total;
            $pdo->prepare('UPDATE users SET balance = ? WHERE uid = ?')->execute([$balance, $uid]);
            $pdo->prepare('INSERT INTO transactions (uid, name, type, amount, balance_after, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $user['name'], 'purchase', $total, $balance, date('Y-m-d H:i:s')]);
            $access = 'granted';
            $pdo->exec('DELETE FROM pending_orders WHERE id = 1');
        }
    } else {
        if ($user) { $access = 'granted'; } else { $error = 'Card not registered'; }
    }

    $log = $pdo->prepare('INSERT INTO access_logs (device_id, uid, name, access, balance, created_at)
                          VALUES (?, ?, ?, ?, ?, ?)');
    $log->execute([$deviceId, $uid, $name, $access, $balance, date('Y-m-d H:i:s')]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_out(['access' => $access, 'name' => $name, 'balance' => $balance,
          'error' => $error, 'charged' => $hasPending && $access === 'granted']);
