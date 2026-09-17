<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'invoice-download-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
require dirname(__DIR__) . '/db.php';

db()->prepare('INSERT INTO payment_methods (type, name, account_number) VALUES (?,?,?)')->execute(['bank', 'Test Bank', '123456']);
$id = create_invoice([
    'client_name' => 'PDF Client',
    'client_phone' => '01712345678',
    'invoice_type' => 'one_time',
    'issue_date' => date('Y-m-d'),
    'due_date' => add_days(date('Y-m-d'), 7),
    'item_service_id' => [''],
    'item_name' => ['Test service'],
    'item_description' => [''],
    'item_qty' => ['1'],
    'item_price' => ['100'],
]);

session_save_path(dirname(__DIR__) . '/storage/sessions');
session_name('invoice_admin');
$sessionId = 'invoice-download-' . bin2hex(random_bytes(8));
session_id($sessionId);
session_start();
$_SESSION['admin_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['page' => 'download', 'id' => $id];

ob_start();
register_shutdown_function(static function () use ($path, $sessionId): void {
    $body = (string)ob_get_contents();
    ob_end_clean();
    close_db();
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
    $sessionFile = dirname(__DIR__) . '/storage/sessions/sess_' . $sessionId;
    if (is_file($sessionFile)) unlink($sessionFile);
    if (!str_starts_with($body, '%PDF-') || !str_contains($body, '%%EOF')) {
        throw new RuntimeException('Download route did not return a PDF');
    }
    echo "Download route passed: PDF bytes returned.\n";
});
require dirname(__DIR__) . '/index.php';
