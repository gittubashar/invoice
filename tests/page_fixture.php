<?php
declare(strict_types=1);
$path = tempnam(sys_get_temp_dir(), 'invoice-page-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = $argv[1] ?? 'payment-methods';
if ($_GET['page'] === 'clients') $_GET['add'] = 1;
if (in_array($_GET['page'], ['collections', 'client', 'client-ledger', 'receipt', 'new-prefill'], true)) {
    require_once dirname(__DIR__) . '/db.php';
}
if ($_GET['page'] === 'new-prefill') {
    $_GET['page'] = 'new';
    $_GET['client_id'] = create_client_account(['client_name'=>'Prefilled Client','company_name'=>'Prefill Co','client_phone'=>'01987654321','client_email'=>'prefill@example.test']);
}
if ($_GET['page'] === 'client') {
    $_GET['id'] = create_client_account([
        'client_name' => 'Editable Client', 'company_name' => 'Fixture Co', 'client_address' => 'Dhaka',
        'client_phone' => '01887654321', 'client_email' => 'editable@example.test',
    ]);
    $_GET['edit'] = 1;
}
if ($_GET['page'] === 'collections') {
    $_GET['id'] = create_invoice([
        'client_name' => 'Collection Client', 'client_phone' => '01712345678', 'invoice_type' => 'one_time',
        'issue_date' => date('Y-m-d'), 'due_date' => add_days(date('Y-m-d'), 7),
        'item_service_id' => [''], 'item_name' => ['Collection test'], 'item_description' => [''], 'item_qty' => ['1'], 'item_price' => ['100'],
    ]);
}
if (in_array($_GET['page'], ['client-ledger', 'receipt'], true)) {
    $ledgerInvoice = create_invoice([
        'client_name'=>'Ledger Client','client_phone'=>'01612345678','client_email'=>'ledger@example.test','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Ledger test'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['200'],
    ]);
    $receipt = collect_multiple_invoices([$ledgerInvoice], '75', '5', 'one_time', 'cash', 'FIXTURE', '', date('Y-m-d'));
    if ($_GET['page'] === 'client-ledger') $_GET['id'] = (int)query_one('SELECT client_id FROM invoices WHERE id=?', [$ledgerInvoice])['client_id'];
    else $_GET['number'] = $receipt;
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
if ($_GET['page'] === 'client' && (!str_contains($html, 'name="action" value="update_client"') || !str_contains($html, 'name="action" value="delete_client"') || !str_contains($html, 'Editable Client'))) throw new RuntimeException('Client update/delete controls did not render');
if ($_GET['page'] === 'collections' && (!str_contains($html, 'name="action" value="collect_invoice_collection"') || !str_contains($html, 'name="action" value="collect_multiple_invoices"') || !str_contains($html, 'name="invoice_ids[]"') || !str_contains($html, 'name="discount"'))) throw new RuntimeException('Invoice collection forms did not render');
if ($_GET['page'] === 'new' && isset($_GET['client_id']) && !str_contains($html, 'Prefilled Client')) throw new RuntimeException('Client profile invoice prefill did not render');
if ($_GET['page'] === 'client-ledger' && !str_contains($html, 'LEDGER HISTORY')) throw new RuntimeException('Client ledger did not render');
if ($_GET['page'] === 'receipt' && !str_contains($html, 'MONEY RECEIPT')) throw new RuntimeException('Money receipt did not render');
$modulePages = [
    'invoice-dashboard' => ['Invoice', 'Create Invoice', 'Recurring Billing'],
    'clients-dashboard' => ['Clients', 'Client Ledger'],
    'services-dashboard' => ['Services', 'Service Catalog'],
    'payments-dashboard' => ['Payment Methods', 'Payment Methods'],
    'settings-dashboard' => ['Settings', 'Basic &amp; SMTP Settings'],
];
if (isset($modulePages[$_GET['page']])) {
    if (!str_contains($html, 'class="top-modules"') || !str_contains($html, 'class="active-module"')) throw new RuntimeException('Module navigation did not render');
    if (str_contains($html, 'class="module-switcher"')) throw new RuntimeException('Module switcher must not render in the sidebar');
    foreach ($modulePages[$_GET['page']] as $expected) if (!str_contains($html, $expected)) throw new RuntimeException('Missing module control: ' . $expected);
}
echo "Page render passed: {$_GET['page']}\n";
session_destroy();
close_db();
foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
