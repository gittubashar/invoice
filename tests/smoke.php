<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'billflow-test-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
$keyPath = $path . '.key';
putenv('INVOICE_SMTP_KEY_PATH=' . $keyPath);
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/media.php';
require dirname(__DIR__) . '/print_invoice.php';
require dirname(__DIR__) . '/pdf_invoice.php';

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $params = []): string { return 'index.php?' . http_build_query(['page' => $page] + $params); }
function favicon_tag(): string { return ''; }
function format_date(?string $date): string { return $date ? date('d M Y', strtotime($date)) : '—'; }
function status_label(string $value): string { return ['paid' => 'পরিশোধিত', 'partial' => 'আংশিক', 'overdue' => 'মেয়াদোত্তীর্ণ', 'unpaid' => 'অপরিশোধিত'][$value] ?? $value; }

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $pdo = db();
    $superAdmin = query_one('SELECT name, email, role, password_hash FROM admins WHERE email=?', [DEFAULT_SUPER_ADMIN_EMAIL]);
    expect($superAdmin !== false && $superAdmin['role'] === 'super_admin', 'Default super admin must be created');
    expect($superAdmin['password_hash'] === DEFAULT_SUPER_ADMIN_HASH, 'Provided bcrypt hash must be stored exactly');
    expect((bool)preg_match('/^\$2a\$12\$[.\/A-Za-z0-9]{53}$/', $superAdmin['password_hash']), 'Default hash must have bcrypt format');
    $compatHash = '$2a$' . substr(password_hash('compat-test', PASSWORD_BCRYPT), 4);
    expect(password_verify('compat-test', $compatHash), 'PHP must verify $2a$ bcrypt hashes');
    save_settings(['site_title' => 'Test Billing', 'slogan' => 'Simple billing', 'mobile_number' => '+8801712345678', 'email' => 'billing@example.test']);
    expect(setting('site_title') === 'Test Billing', 'Basic setting must persist');
    $encrypted = encrypt_smtp_password('smtp-test-secret');
    expect($encrypted !== 'smtp-test-secret' && decrypt_smtp_password($encrypted) === 'smtp-test-secret', 'SMTP password must be encrypted and recoverable');
    save_settings(['smtp_host' => 'smtp.example.test', 'smtp_password' => $encrypted]);
    $pdo->prepare('INSERT INTO services (name, description, price_cents) VALUES (?,?,?)')->execute(['Hosting', 'Monthly plan', 120000]);
    $serviceId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO payment_methods (type, name, account_name, account_number, mobile_number, qr_path) VALUES (?,?,?,?,?,?)')->execute(['mfs', 'bKash', 'Example Ltd', '01712345678', '01712345678', '']);
    $methodId = (int)$pdo->lastInsertId();
    $base = [
        'client_name' => 'Test Client', 'client_phone' => '+8801712345678', 'client_email' => 'test@example.com', 'company_name' => 'Example Ltd',
        'issue_date' => date('Y-m-d'), 'due_date' => add_days(date('Y-m-d'), 7), 'notes' => 'Test',
        'item_service_id' => [$serviceId, ''], 'item_name' => ['Hosting', 'Manual setup'],
        'item_description' => ['Monthly plan', ''], 'item_qty' => ['1', '2'], 'item_price' => ['1200', '250'], 'payment_method_id' => $methodId,
    ];
    $first = create_invoice($base + ['invoice_type' => 'one_time']);
    $second = create_invoice(array_replace($base, ['client_name' => 'Ignored duplicate', 'client_phone' => '01712345678', 'company_name' => 'Renamed Ltd', 'invoice_type' => 'recurring', 'frequency' => 'monthly', 'issue_date' => add_days(date('Y-m-d'), -62), 'due_date' => add_days(date('Y-m-d'), -55)]));
    expect((int)query_one('SELECT COUNT(*) total FROM clients')['total'] === 1, 'Phone must reuse client');
    $phoneMatches = search_clients('017123');
    $emailMatches = search_clients('test@example');
    expect(count($phoneMatches) === 1 && $phoneMatches[0]['name'] === 'Test Client', 'Client live search must match a partial phone number');
    expect(count($emailMatches) === 1 && $emailMatches[0]['phone'] === '01712345678', 'Client live search must match a partial email address');
    expect(query_one('SELECT billing_name FROM invoices WHERE id=?', [$first])['billing_name'] === 'Test Client', 'Invoice must snapshot billing name');
    expect(query_one('SELECT company_name FROM clients LIMIT 1')['company_name'] === 'Renamed Ltd', 'Client company must update when explicitly provided');
    expect(query_one('SELECT billing_company_name FROM invoices WHERE id=?', [$first])['billing_company_name'] === 'Example Ltd', 'Old invoice company snapshot must remain unchanged');
    expect(query_one('SELECT billing_company_name FROM invoices WHERE id=?', [$second])['billing_company_name'] === 'Renamed Ltd', 'Recurring first invoice must store company');
    expect((int)query_one('SELECT total_cents FROM invoices WHERE id=?', [$first])['total_cents'] === 170000, 'Invoice total must equal items');
    expect((int)query_one('SELECT payment_method_id FROM invoices WHERE id=?', [$first])['payment_method_id'] === $methodId, 'Invoice must store selected payment method');
    expect((int)query_one('SELECT payment_method_id FROM recurrences WHERE id=(SELECT recurrence_id FROM invoices WHERE id=?)', [$second])['payment_method_id'] === $methodId, 'Recurring schedule must store selected method');
    collect_payment($first, '500', 'bkash', 'TXN123', '', date('Y-m-d'));
    $row = invoice_rows('WHERE i.id=?', [$first])[0];
    expect(invoice_status($row) === 'partial', 'Partial payment status');
    expect((int)$row['paid_cents'] === 50000, 'Payment amount');
    try {
        collect_payment($first, '1200.01', 'cash', '', '', date('Y-m-d'));
        throw new RuntimeException('Overpayment should be rejected');
    } catch (InvalidArgumentException $expected) {}
    collect_payment($first, '1200', 'cash', '', '', date('Y-m-d'));
    expect(invoice_status(invoice_rows('WHERE i.id=?', [$first])[0]) === 'paid', 'Full payment status');
    $generated = generate_due_invoices();
    expect($generated >= 1, 'Due recurring invoice should generate');
    expect((int)query_one("SELECT COUNT(*) total FROM invoices WHERE recurrence_id = (SELECT recurrence_id FROM invoices WHERE id = ?) AND billing_company_name = 'Renamed Ltd'", [$second])['total'] === $generated + 1, 'Generated recurring invoices must carry company');
    expect((int)query_one('SELECT COUNT(*) total FROM invoices WHERE recurrence_id=(SELECT recurrence_id FROM invoices WHERE id=?) AND payment_method_id=?', [$second, $methodId])['total'] === $generated + 1, 'Generated recurring invoices must carry payment method');
    expect(generate_due_invoices() === 0, 'Recurring generation must be idempotent');
    expect((int)query_one('SELECT COUNT(*) total FROM invoices WHERE recurrence_id=(SELECT recurrence_id FROM invoices WHERE id=?)', [$second])['total'] === $generated + 1, 'Recurring invoice count');
    expect(next_cycle_date('2026-01-31', 'monthly', 31, 1) === '2026-02-28', 'Month-end billing in February');
    expect(next_cycle_date('2026-02-28', 'monthly', 31, 1) === '2026-03-31', 'Month-end billing returns to anchor day');
    $originalNumber = query_one('SELECT number FROM invoices WHERE id=?', [$first])['number'];
    $reduced = array_replace($base, ['client_name' => 'Edited Recipient', 'client_phone' => '01722222222', 'item_service_id' => [''], 'item_name' => ['Reduced bill'], 'item_description' => [''], 'item_qty' => ['1'], 'item_price' => ['1600']]);
    try {
        edit_invoice($first, $reduced);
        throw new RuntimeException('Edit below collected amount should be rejected');
    } catch (InvalidArgumentException $expected) {}
    expect((int)query_one('SELECT total_cents FROM invoices WHERE id=?', [$first])['total_cents'] === 170000, 'Rejected edit must preserve invoice total');
    $updated = array_replace($reduced, ['company_name' => 'Edited Co', 'client_email' => 'edited@example.test', 'item_price' => ['1800'], 'notes' => 'Updated invoice note']);
    edit_invoice($first, $updated);
    $editedInvoice = invoice_rows('WHERE i.id=?', [$first])[0];
    expect($editedInvoice['number'] === $originalNumber && $editedInvoice['client_name'] === 'Edited Recipient' && $editedInvoice['client_phone'] === '01722222222', 'Edit must preserve number and update billing recipient');
    expect((int)$editedInvoice['total_cents'] === 180000 && (int)$editedInvoice['paid_cents'] === 170000 && invoice_status($editedInvoice) === 'partial', 'Edit must retain payments and recalculate balance');
    expect((int)query_one('SELECT COUNT(*) total FROM invoice_items WHERE invoice_id=?', [$first])['total'] === 1, 'Edit must replace invoice items');
    expect((int)$editedInvoice['payment_method_id'] === $methodId, 'Edit must retain selected payment method');
    $pdo->prepare('UPDATE payment_methods SET active=0 WHERE id=?')->execute([$methodId]);
    expect(selected_payment_method_id(['payment_method_id' => $methodId], $methodId) === $methodId, 'Existing invoice can retain inactive method');
    try {
        selected_payment_method_id(['payment_method_id' => $methodId]);
        throw new RuntimeException('Inactive method should be rejected for new invoices');
    } catch (InvalidArgumentException $expected) {}
    expect((int)query_one('SELECT COUNT(*) total FROM clients')['total'] === 2, 'Changed phone must link a new client account');
    $manualClientId = create_client_account(['client_name' => 'Manual Client', 'company_name' => 'Manual Co', 'client_phone' => '01812345678', 'client_email' => 'manual@example.test']);
    expect((int)query_one('SELECT COUNT(*) total FROM clients WHERE id=? AND company_name=?', [$manualClientId, 'Manual Co'])['total'] === 1, 'Client page must create a standalone client account');
    try {
        create_client_account(['client_name' => 'Duplicate Client', 'client_phone' => '01812345678', 'client_email' => 'other@example.test']);
        throw new RuntimeException('Duplicate client phone should be rejected');
    } catch (InvalidArgumentException $expected) {}
    collect_payment($first, '100', 'cash', '', '', date('Y-m-d'));
    expect(invoice_status(invoice_rows('WHERE i.id=?', [$first])[0]) === 'paid', 'Edited invoice can be fully collected');
    $recurringInvoice = query_one('SELECT issue_date, due_date, recurrence_id FROM invoices WHERE id=?', [$second]);
    $recurringEdit = array_replace($base, ['company_name' => 'Renamed Ltd', 'issue_date' => $recurringInvoice['issue_date'], 'due_date' => $recurringInvoice['due_date'], 'item_service_id' => [''], 'item_name' => ['One changed cycle'], 'item_description' => [''], 'item_qty' => ['1'], 'item_price' => ['1900']]);
    edit_invoice($second, $recurringEdit);
    expect((int)query_one('SELECT total_cents FROM invoices WHERE id=?', [$second])['total_cents'] === 190000, 'Recurring invoice can be edited');
    expect((int)query_one('SELECT COUNT(*) total FROM recurrence_items WHERE recurrence_id=?', [$recurringInvoice['recurrence_id']])['total'] === 2, 'Editing one recurring invoice must not change the schedule');
    $qrPath = 'assets/uploads/' . bin2hex(random_bytes(16)) . '.png';
    $qrFullPath = dirname(__DIR__) . '/' . $qrPath;
    if (!is_dir(dirname($qrFullPath))) mkdir(dirname($qrFullPath), 0775, true);
    file_put_contents($qrFullPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/S9sAAAAASUVORK5CYII='));
    $pdo->prepare('UPDATE payment_methods SET qr_path=? WHERE id=?')->execute([$qrPath, $methodId]);
    $printInvoice = invoice_rows('WHERE i.id=?', [$second])[0];
    $printItems = query_all('SELECT * FROM invoice_items WHERE invoice_id=?', [$second]);
    ob_start();
    render_invoice_print($printInvoice, $printItems, invoice_payment_methods($printInvoice));
    $printHtml = (string)ob_get_clean();
    expect(str_contains($printHtml, $printInvoice['number']) && str_contains($printHtml, 'bKash') && str_contains($printHtml, $qrPath), 'Print view must render invoice, selected method and uploaded QR');
    expect(!str_contains($printHtml, 'company-logo') && !str_contains($printHtml, 'company-mark'), 'Print view must omit logo space when no logo is uploaded');
    $pdo->prepare('INSERT INTO payment_methods (type, name, qr_path) VALUES (?,?,?)')->execute(['bank', 'Example Bank', '']);
    $pdo->prepare('INSERT INTO payment_methods (type, name, qr_path) VALUES (?,?,?)')->execute(['mfs', 'Example Wallet', $qrPath]);
    $pdo->prepare('INSERT INTO payment_methods (type, name, account_number, qr_path) VALUES (?,?,?,?)')->execute(['bank', 'Second Bank', '987654321', '']);
    $unassignedInvoice = $printInvoice;
    $unassignedInvoice['payment_method_id'] = null;
    $availableMethods = invoice_payment_methods($unassignedInvoice);
    expect(count($availableMethods) === 3, 'Unassigned invoices must show all active payment methods');
    ob_start();
    render_invoice_print($unassignedInvoice, $printItems, $availableMethods);
    $fallbackPrintHtml = (string)ob_get_clean();
    expect(str_contains($fallbackPrintHtml, 'Example Bank') && str_contains($fallbackPrintHtml, 'Example Wallet') && str_contains($fallbackPrintHtml, $qrPath), 'Unassigned invoice PDF must include active methods and QR');
    $pdf = render_invoice_pdf($unassignedInvoice, $printItems, $availableMethods);
    expect(str_starts_with($pdf, '%PDF-') && str_contains($pdf, '%%EOF') && strlen($pdf) > 10000, 'Download must be a complete PDF file');
    $pageCount = (new \Mpdf\Mpdf(['tempDir' => dirname(__DIR__) . '/storage/mpdf']))->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
    expect($pageCount === 1, 'PDF with three payment methods must fit on one A4 page');
    $changedHash = password_hash('new-local-password', PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE admins SET password_hash=? WHERE email=?')->execute([$changedHash, DEFAULT_SUPER_ADMIN_EMAIL]);
    $pdo = null;
    close_db();
    db();
    expect(query_one('SELECT password_hash FROM admins WHERE email=?', [DEFAULT_SUPER_ADMIN_EMAIL])['password_hash'] === $changedHash, 'One-time seed must preserve later password changes');
    expect(setting('site_title') === 'Test Billing' && setting('smtp_host') === 'smtp.example.test', 'Settings must survive database reopen');
    echo "Smoke test passed: invoice edits, payment methods, one-page inline PDF, settings persistence, collection, recurring generation.\n";
} finally {
    $pdo = null;
    close_db();
    if (isset($qrFullPath) && is_file($qrFullPath)) unlink($qrFullPath);
    foreach ([$path, $path . '-wal', $path . '-shm', $keyPath] as $file) if (is_file($file)) unlink($file);
}
