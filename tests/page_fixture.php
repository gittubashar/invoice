<?php
declare(strict_types=1);
$path = tempnam(sys_get_temp_dir(), 'invoice-page-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = $argv[1] ?? 'payment-methods';
if ($_GET['page'] === 'clients') $_GET['add'] = 1;
if ($_GET['page'] === 'collections') {
    require_once dirname(__DIR__) . '/db.php';
    $_GET['id'] = create_invoice([
        'client_name' => 'Collection Client', 'client_phone' => '01712345678', 'invoice_type' => 'one_time',
        'issue_date' => date('Y-m-d'), 'due_date' => add_days(date('Y-m-d'), 7),
        'item_service_id' => [''], 'item_name' => ['Collection test'], 'item_description' => [''], 'item_qty' => ['1'], 'item_price' => ['100'],
    ]);
}
session_save_path(dirname(__DIR__) . '/storage/sessions');
session_name('invoice_admin');
session_id('invoice-page-' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['admin_id'] = 1;
ob_start();
require dirname(__DIR__) . '/index.php';
$html = (string)ob_get_clean();
if (!str_contains($html, '<!doctype html>')) throw new RuntimeException('Page did not render');
if ($_GET['page'] === 'payment-methods' && (!str_contains($html, 'name="qr_file"') || !str_contains($html, 'name="account_number"'))) throw new RuntimeException('Payment method form did not render');
if ($_GET['page'] === 'new' && !str_contains($html, 'name="payment_method_id"')) throw new RuntimeException('Invoice payment method selector did not render');
if ($_GET['page'] === 'clients' && (!str_contains($html, 'name="action" value="save_client"') || !str_contains($html, 'name="client_phone"') || !str_contains($html, 'name="client_address"'))) throw new RuntimeException('Client creation form did not render');
if ($_GET['page'] === 'collections' && (!str_contains($html, 'name="action" value="collect_invoice_collection"') || !str_contains($html, 'value="partial"') || !str_contains($html, 'value="full"'))) throw new RuntimeException('Invoice collection form did not render');
echo "Page render passed: {$_GET['page']}\n";
session_destroy();
close_db();
foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
