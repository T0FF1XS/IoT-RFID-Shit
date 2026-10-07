<?php
// One-time, CLI-only import of the old SQLite data into an empty MySQL database.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../api/db.php';

$source = __DIR__ . '/../data/tagit.db';
if (!is_file($source)) {
    fwrite(STDERR, "No SQLite backup found at data/tagit.db\n");
    exit(1);
}

try {
    $sqlite = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $mysql = db();
    $mysql->beginTransaction();

    // Never merge into existing data: this script must be run on a fresh database.
    foreach (['users', 'access_logs', 'transactions', 'pending_orders'] as $table) {
        if ((int)$mysql->query("SELECT COUNT(*) FROM $table")->fetchColumn() !== 0) {
            throw new RuntimeException("MySQL table $table is not empty; import cancelled");
        }
    }

    $tables = [
        'users' => ['uid', 'name', 'created_at', 'balance'],
        'access_logs' => ['id', 'device_id', 'uid', 'name', 'access', 'balance', 'created_at'],
        'transactions' => ['id', 'uid', 'name', 'type', 'amount', 'balance_after', 'created_at'],
    ];
    foreach ($tables as $table => $columns) {
        $names = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $mysql->prepare("INSERT INTO $table ($names) VALUES ($placeholders)");
        $rows = $sqlite->query("SELECT $names FROM $table");
        $count = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $insert->execute(array_values($row));
            $count++;
        }
        echo "$table: $count imported\n";
    }

    $mysql->commit();
    echo "Import complete. The original data/tagit.db was not changed.\n";
} catch (Throwable $e) {
    if (isset($mysql) && $mysql->inTransaction()) $mysql->rollBack();
    fwrite(STDERR, "Import failed (no rows committed): " . $e->getMessage() . "\n");
    exit(1);
}
