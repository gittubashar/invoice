<?php
declare(strict_types=1);
$path = tempnam(sys_get_temp_dir(), 'invoice-page-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
$_SERVER['REQUEST_METHOD'] = 'GET';
$fixturePage = $argv[1] ?? 'payment-methods';
$_GET['page'] = in_array($fixturePage, ['collections-unselected', 'collections-search'], true) ? 'collections' : (in_array($fixturePage, ['invoice-identity-on', 'invoice-identity-off', 'invoice-collection'], true) ? 'invoice' : ($fixturePage === 'edit-discount' ? 'edit' : $fixturePage));
if ($_GET['page'] === 'clients') $_GET['add'] = 1;
if (in_array($_GET['page'], ['dashboard', 'invoice-dashboard', 'collections', 'client', 'client-ledger', 'receipt', 'new-prefill', 'invoice', 'invoices', 'recurring', 'edit', 'clients'], true)) {
    require_once dirname(__DIR__) . '/db.php';
}
if ($_GET['page'] === 'clients') create_client_account(['client_name'=>'List Client','company_name'=>'List Co','client_phone'=>'01911112222','client_email'=>'list@example.test']);
if ($_GET['page'] === 'invoices') {
    create_invoice([
        'client_name'=>'Purple Client','client_phone'=>'01412345678','client_email'=>'purple@example.test','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Mail test'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['100'],
    ]);
    $_GET['q'] = 'purple';
}
if ($fixturePage === 'edit-discount') {
    $_GET['id'] = create_invoice([
        'client_name'=>'Discount Client','client_phone'=>'01398765432','client_email'=>'discount@example.test','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Editable invoice'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['1000'],
    ]);
}
if (in_array($fixturePage, ['invoice-identity-on', 'invoice-identity-off'], true)) {
    $_GET['id'] = create_invoice([
        'client_name'=>'Identity Client','client_phone'=>'01312345678','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Identity test'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['100'],
    ]);
    $logos = array_values(array_filter(glob(dirname(__DIR__) . '/assets/uploads/*') ?: [], static fn(string $file): bool => (bool)preg_match('/[a-f0-9]{32}\.(?:png|jpg|webp)$/', basename($file))));
    $enabled = $fixturePage === 'invoice-identity-on' ? '1' : '0';
    save_settings(['site_title'=>'Fixture Brand','slogan'=>'Fixture Slogan','logo_path'=>$logos ? 'assets/uploads/' . basename($logos[0]) : '', 'pdf_show_logo'=>$enabled, 'pdf_show_title'=>$enabled, 'pdf_show_slogan'=>$enabled]);
}
if ($fixturePage === 'invoice-collection') {
    $_GET['id'] = create_invoice([
        'client_name'=>'Partial Client','client_phone'=>'01376543210','client_email'=>'partial@example.test','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Partial collection invoice'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['1000'],
    ]);
    collect_payment((int)$_GET['id'], '350', 'cash', 'PARTIAL-1', '', date('Y-m-d'));
}
if (in_array($_GET['page'], ['dashboard', 'invoice-dashboard'], true)) {
    $dashboardInvoice = create_invoice([
        'client_name'=>'Dashboard Client','client_phone'=>'01512345678','invoice_type'=>'recurring','frequency'=>'monthly',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Dashboard subscription'],'item_description'=>['Monthly service'],'item_qty'=>['1'],'item_price'=>['1000'],
    ]);
    collect_payment($dashboardInvoice, '250', 'cash', 'DASHBOARD', '', date('Y-m-d'));
}
if ($_GET['page'] === 'recurring') {
    create_invoice([
        'client_name'=>'Recurring Client','client_phone'=>'01598765432','invoice_type'=>'recurring','frequency'=>'monthly',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Recurring plan'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['1000'],
        'discount_enabled'=>'1','discount_type'=>'percent','discount_value'=>'12.5','discount_scope'=>'every_cycle','discount_note'=>'Fixture offer',
    ]);
}
if ($_GET['page'] === 'new-prefill') {
    $_GET['page'] = 'new';
    $_GET['client_id'] = create_client_account(['client_name'=>'Prefilled Client','company_name'=>'Prefill Co','client_phone'=>'01987654321','client_email'=>'prefill@example.test']);
}
if ($_GET['page'] === 'client') {
    $clientInvoiceId = create_invoice([
        'client_name'=>'Editable Client','company_name'=>'Fixture Co','client_phone'=>'01887654321','client_email'=>'','invoice_type'=>'one_time',
        'issue_date'=>date('Y-m-d'),'due_date'=>add_days(date('Y-m-d'),7),'item_service_id'=>[''],'item_name'=>['Client profile invoice'],'item_description'=>[''],'item_qty'=>['1'],'item_price'=>['100'],
    ]);
    $_GET['id'] = (int)query_one('SELECT client_id FROM invoices WHERE id=?', [$clientInvoiceId])['client_id'];
    update_client_account((int)$_GET['id'], ['client_name'=>'Editable Client','company_name'=>'Fixture Co','client_address'=>'Dhaka','client_phone'=>'01887654321','client_email'=>'editable@example.test']);
    $_GET['edit'] = 1;
}
if ($_GET['page'] === 'collections' && $fixturePage !== 'collections-unselected') {
    $collectionInvoiceId = create_invoice([
        'client_name' => 'Collection Client', 'client_phone' => '01712345678', 'invoice_type' => 'one_time',
        'issue_date' => date('Y-m-d'), 'due_date' => add_days(date('Y-m-d'), 7),
        'item_service_id' => [''], 'item_name' => ['Collection test'], 'item_description' => [''], 'item_qty' => ['1'], 'item_price' => ['100'],
    ]);
    $_GET['q'] = 'Collection Client';
    if ($fixturePage === 'collections') {
        $_GET['id'] = $collectionInvoiceId;
        $_GET['client_id'] = (int)query_one('SELECT client_id FROM invoices WHERE id=?', [$collectionInvoiceId])['client_id'];
    }
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
if (str_contains($html, 'Simple billing, clear accounts') || str_contains($html, 'class="sidebar-note"')) throw new RuntimeException('Removed sidebar information card rendered');
if ($_GET['page'] === 'payment-methods' && (!str_contains($html, 'name="qr_file"') || !str_contains($html, 'name="account_number"'))) throw new RuntimeException('Payment method form did not render');
if ($_GET['page'] === 'new' && !str_contains($html, 'name="payment_method_id"')) throw new RuntimeException('Invoice payment method selector did not render');
if ($_GET['page'] === 'new' && (!str_contains($html, 'name="discount_enabled"') || !str_contains($html, 'name="discount_type"') || !str_contains($html, 'name="discount_scope"') || !str_contains($html, 'id="summary-discount"'))) throw new RuntimeException('Recurring invoice discount controls did not render');
if ($fixturePage === 'edit-discount' && (!str_contains($html, 'Invoice Discount') || !str_contains($html, 'name="discount_enabled"') || !str_contains($html, 'name="discount_value"') || !str_contains($html, 'Saving makes the updated invoice ready to send again'))) throw new RuntimeException('Existing invoice discount controls did not render');
if ($_GET['page'] === 'invoices' && (!str_contains($html, 'class="row-mail"') || !str_contains($html, '> Send Mail</button>') || !str_contains($html, 'name="action" value="send_invoice_reminder"') || !str_contains($html, '> Send Reminder</button>') || !str_contains($html, 'name="return_q" value="purple"'))) throw new RuntimeException('Invoice list mail actions did not render');
if ($_GET['page'] === 'clients' && (!str_contains($html, 'name="action" value="save_client"') || !str_contains($html, 'name="client_phone"') || !str_contains($html, 'name="client_address"') || !str_contains($html, '>Profile Edit</a>') || !str_contains($html, '>Ledger</a>') || !str_contains($html, 'page=client-ledger&amp;id='))) throw new RuntimeException('Client creation form, profile edit, or ledger action did not render');
if ($_GET['page'] === 'client' && (!str_contains($html, 'name="action" value="update_client"') || !str_contains($html, 'name="action" value="delete_client"') || !str_contains($html, 'Editable Client') || !str_contains($html, '> Send Mail</button>') || !str_contains($html, '> Send Reminder</button>') || !str_contains($html, 'name="return_page" value="client"') || !str_contains($html, 'name="return_client_id" value="' . (int)$_GET['id'] . '"'))) throw new RuntimeException('Client update/delete or invoice mail controls did not render');
if ($_GET['page'] === 'collections') {
    if (!str_contains($html, 'name="q"') || !str_contains($html, 'Name, mobile number, or email') || !str_contains($html, 'data-collection-client-search') || !str_contains($html, 'data-search-url=')) throw new RuntimeException('Live client collection search did not render');
    if ($fixturePage === 'collections-unselected') {
        if (!str_contains($html, 'Select a client to view invoices') || str_contains($html, 'name="invoice_ids[]"')) throw new RuntimeException('Unselected collections page must not list invoices');
    } elseif ($fixturePage === 'collections-search') {
        if (!str_contains($html, 'class="collection-client-results"') || !str_contains($html, 'client_id=')) throw new RuntimeException('Client search results did not render as selectable links');
        if (str_contains($html, 'name="invoice_ids[]"')) throw new RuntimeException('Searching clients must not expose invoices before client selection');
    } else {
        if (!str_contains($html, 'name="action" value="collect_invoice_collection"') || !str_contains($html, 'name="action" value="collect_multiple_invoices"') || !str_contains($html, 'name="invoice_ids[]"') || !str_contains($html, 'name="discount"')) throw new RuntimeException('Invoice collection forms did not render');
        $invoicePosition = strpos($html, 'OUTSTANDING INVOICES');
        $formPosition = strpos($html, 'SINGLE INVOICE COLLECTION');
        if ($invoicePosition === false || $formPosition === false || $formPosition < $invoicePosition) throw new RuntimeException('Collection form must render below client invoices');
    }
}
if ($_GET['page'] === 'new' && isset($_GET['client_id']) && !str_contains($html, 'Prefilled Client')) throw new RuntimeException('Client profile invoice prefill did not render');
if ($_GET['page'] === 'client-ledger' && (!str_contains($html, 'LEDGER HISTORY') || !str_contains($html, 'PDF Statement') || !str_contains($html, '<th>S.N.</th>') || !str_contains($html, '<th>Debit</th>') || !str_contains($html, '<th>Credit</th>') || !str_contains($html, 'Closing Balance'))) throw new RuntimeException('Client ledger statement did not render');
if ($_GET['page'] === 'receipt' && !str_contains($html, 'MONEY RECEIPT')) throw new RuntimeException('Money receipt did not render');
if ($fixturePage === 'invoice-identity-off' && (str_contains($html, 'class="paper-logo"') || str_contains($html, 'class="paper-brand"') || str_contains($html, 'class="paper-slogan"'))) throw new RuntimeException('Disabled invoice identity elements rendered');
if ($fixturePage === 'invoice-identity-on' && (!str_contains($html, 'class="paper-brand"') || !str_contains($html, 'class="paper-slogan"'))) throw new RuntimeException('Enabled invoice title or slogan did not render');
if ($fixturePage === 'invoice-collection' && (!str_contains($html, 'class="paper-collection-history"') || !str_contains($html, 'Collection History') || !str_contains($html, 'BDT 350') || !str_contains($html, '> Send Reminder</button>'))) throw new RuntimeException('Invoice collection history or reminder action did not render');
$modulePages = [
    'invoice-dashboard' => ['Invoice', 'Create Invoice', 'Recurring Billing'],
    'clients' => ['Clients', 'Client Ledger'],
    'services' => ['Services', 'Service Catalog'],
    'payment-methods' => ['Payment Methods'],
    'settings' => ['Settings', 'Basic Settings', 'SMTP'],
];
if (isset($modulePages[$_GET['page']])) {
    if (!str_contains($html, 'class="top-modules"') || !str_contains($html, 'class="active-module"')) throw new RuntimeException('Module navigation did not render');
    if (str_contains($html, 'class="module-switcher"')) throw new RuntimeException('Module switcher must not render in the sidebar');
    if (str_contains($html, '<span>Overview</span>')) throw new RuntimeException('Redundant module overview link rendered in the sidebar');
    foreach ($modulePages[$_GET['page']] as $expected) if (!str_contains($html, $expected)) throw new RuntimeException('Missing module control: ' . $expected);
}
if ($_GET['page'] === 'invoice-dashboard') {
    foreach (['Quick Actions', 'COLLECTION TREND', 'INVOICE STATUS', 'Upcoming Recurring', 'Latest Collections'] as $expected) if (!str_contains($html, $expected)) throw new RuntimeException('Missing invoice dashboard section: ' . $expected);
    foreach (['clients', 'services', 'payment-methods', 'settings'] as $target) if (!str_contains($html, 'href="' . e(url($target)) . '"')) throw new RuntimeException('Module does not link directly to control page: ' . $target);
    foreach (['clients-dashboard', 'services-dashboard', 'payments-dashboard', 'settings-dashboard'] as $legacyTarget) if (str_contains($html, 'href="' . e(url($legacyTarget)) . '"')) throw new RuntimeException('Module still links to redundant overview page: ' . $legacyTarget);
}
if ($_GET['page'] === 'recurring' && (!str_contains($html, 'name="action" value="update_recurrence_discount"') || !str_contains($html, '12.5%') || !str_contains($html, 'Fixture offer'))) throw new RuntimeException('Recurring discount management did not render');
echo "Page render passed: {$fixturePage}\n";
session_destroy();
close_db();
foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (is_file($file)) unlink($file);
