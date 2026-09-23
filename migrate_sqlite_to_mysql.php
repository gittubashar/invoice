<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';

$sourcePath = __DIR__ . '/storage/invoice.sqlite';
$fresh = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--fresh') $fresh = true;
    elseif (str_starts_with($argument, '--source=')) $sourcePath = substr($argument, 9);
}

if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite source not found: {$sourcePath}\n");
    exit(1);
}

try {
    $target = db();
    if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('DB_DRIVER must be mysql while running this migration.');
    }
    $source = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec('PRAGMA foreign_keys = ON');

    $tables = ['admins', 'app_meta', 'app_settings', 'payment_methods', 'clients', 'services', 'recurrences', 'recurrence_items', 'invoices', 'invoice_items', 'payments', 'email_deliveries'];
    $businessTables = ['payment_methods', 'clients', 'services', 'recurrences', 'invoices', 'payments'];
    $existing = 0;
    foreach ($businessTables as $table) $existing += (int)$target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($existing > 0 && !$fresh) {
        throw new RuntimeException('MySQL already contains business data. Re-run with --fresh only if replacing it is intended.');
    }

    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    $target->beginTransaction();
    try {
        if ($fresh) {
            foreach (array_reverse($tables) as $table) $target->exec("DELETE FROM `{$table}`");
        }
        $counts = [];
        foreach ($tables as $table) {
            $sourceColumns = array_column($source->query("PRAGMA table_info(`{$table}`)")->fetchAll(), 'name');
            if (!$sourceColumns) continue;
            $targetColumns = array_column($target->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(), 'Field');
            $columns = array_values(array_intersect($sourceColumns, $targetColumns));
            $quoted = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $update = implode(', ', array_map(static fn(string $column): string => "`{$column}`=VALUES(`{$column}`)", array_filter($columns, static fn(string $column): bool => $column !== 'id')));
            $sql = "INSERT INTO `{$table}` ({$quoted}) VALUES ({$placeholders})" . ($update !== '' ? " ON DUPLICATE KEY UPDATE {$update}" : '');
            $insert = $target->prepare($sql);
            $rows = $source->query("SELECT {$quoted} FROM `{$table}`");
            $count = 0;
            while ($row = $rows->fetch()) {
                $insert->execute(array_values($row));
                $count++;
            }
            $counts[$table] = $count;
        }
        $target->commit();
    } catch (Throwable $error) {
        if ($target->inTransaction()) $target->rollBack();
        throw $error;
    } finally {
        $target->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    echo "SQLite to MySQL migration completed.\n";
    foreach ($counts as $table => $count) echo str_pad($table, 20) . $count . " row(s)\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
