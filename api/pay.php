<?php
// Payment-aware endpoint the ESP32 posts taps to.
// If an order is pending (data/pending.json), this tap is a PAYMENT:
//   - registered card + enough balance -> deduct, granted
//   - not registered / insufficient   -> denied + error message
// Otherwise it behaves like a normal check-in (granted, shows balance).
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

$pendingFile = __DIR__ . '/../data/pending.json';
$pending = file_exists($pendingFile) ? json_decode(file_get_contents($pendingFile), true) : null;
$hasPending = is_array($pending) && (float)($pending['total'] ?? 0) > 0;

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
        unlink($pendingFile);
    }
} else {
    if ($user) { $access = 'granted'; } else { $error = 'Card not registered'; }
}

$log = $pdo->prepare('INSERT INTO access_logs (device_id, uid, name, access, balance, created_at)
                      VALUES (?, ?, ?, ?, ?, ?)');
$log->execute([$deviceId, $uid, $name, $access, $balance, date('Y-m-d H:i:s')]);

json_out(['access' => $access, 'name' => $name, 'balance' => $balance,
          'error' => $error, 'charged' => $hasPending && $access === 'granted']);
