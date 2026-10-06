<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'ledger-pdf-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
require dirname(__DIR__) . '/db.php';

$invoiceId = create_invoice([
    'client_name'=>'Statement Client','client_phone'=>'01798765000','client_email'=>'statement@example.test','invoice_type'=>'one_time',
    'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Statement service'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['500'],
]);
collect_payment($invoiceId, '125', 'cash', 'STATEMENT', '', date('Y-m-d'));
$clientId = (int)query_one('SELECT client_id FROM invoices WHERE id=?', [$invoiceId])['client_id'];

session_save_path(dirname(__DIR__) . '/storage/sessions');
session_name('invoice_admin');
$sessionId = 'ledger-pdf-' . bin2hex(random_bytes(8));
session_id($sessionId);
session_start();
$_SESSION['admin_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['page'=>'client-ledger-pdf','id'=>$clientId];

ob_start();
register_shutdown_function(static function () use ($path, $sessionId): void {
    $body = (string)ob_get_contents();
    ob_end_clean();
    close_db();
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
    $sessionFile = dirname(__DIR__) . '/storage/sessions/sess_' . $sessionId;
    if (is_file($sessionFile)) unlink($sessionFile);
    if (!str_starts_with($body, '%PDF-') || !str_contains($body, '%%EOF')) throw new RuntimeException('Client ledger route did not return a PDF');
    echo "Client ledger PDF route passed.\n";
});
require dirname(__DIR__) . '/index.php';
