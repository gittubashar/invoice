<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/print_invoice.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDirectory = __DIR__ . '/storage/sessions';
    if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0770, true);
    session_save_path($sessionDirectory);
    session_name('invoice_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $params = []): string { return 'index.php?' . http_build_query(['page' => $page] + $params); }
function redirect(string $page, array $params = []): never { header('Location: ' . url($page, $params)); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function flash(string $type, string $message): void { $_SESSION['flash'] = ['type' => $type, 'message' => $message]; }
function frequency_label(?string $value): string { return billing_frequency_options()[$value ?? ''] ?? 'One-time'; }
function status_label(string $value): string { return ['paid' => 'Paid', 'partial' => 'Partial', 'overdue' => 'Overdue', 'unpaid' => 'Unpaid'][$value] ?? $value; }
function format_date(?string $date): string { return $date ? date('d M Y', strtotime($date)) : '—'; }
function plural_count(int $number, string $label): string { return number_format($number) . ' ' . $label; }

$mailerAvailable = is_file(__DIR__ . '/vendor/autoload.php');
if ($mailerAvailable) require_once __DIR__ . '/invoice_mailer.php';

$page = (string)($_GET['page'] ?? 'invoice-dashboard');
try {
    $hasAdmin = (bool)query_one('SELECT id FROM admins LIMIT 1');
} catch (Throwable $error) {
    error_log('Invoice database bootstrap failed: ' . $error->getMessage());
    http_response_code(503);
    $envMissing = !is_file(__DIR__ . '/.env');
    $mysqlDriverMissing = !in_array('mysql', PDO::getAvailableDrivers(), true);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database setup required</title><style>body{margin:0;background:#f4f7f5;color:#243d34;font:15px/1.65 Arial,sans-serif}.setup{width:min(680px,calc(100% - 32px));margin:8vh auto;padding:30px;border:1px solid #dce7e1;border-radius:14px;background:#fff;box-shadow:0 14px 40px #193e3214}.setup h1{margin:0 0 8px;font-size:25px}.setup p{color:#687d74}.setup code{padding:3px 6px;border-radius:5px;background:#edf4f0;color:#086e5c}.setup li{margin:8px 0}.state{padding:11px 13px;border-radius:8px;background:#fff3ef;color:#9b4e38}</style></head><body><main class="setup"><h1>Database connection is incomplete</h1><p>The MySQL configuration or required PHP extension is missing. Technical details were written to the server error log.</p><div class="state">' . ($envMissing ? '<code>.env</code> was not found.' : '<code>.env</code> was found, but the MySQL connection failed.') . '</div><ol><li>Copy <code>.env.example</code> to <code>.env</code>.</li><li>Set DB_HOST, DB_DATABASE, DB_USERNAME, and DB_PASSWORD for the live MySQL server.</li><li>Enable the PHP <code>pdo_mysql</code> extension' . ($mysqlDriverMissing ? ' (currently unavailable)' : '') . '.</li><li>Run <code>composer install --no-dev</code> in the project root, or upload the complete <code>vendor</code> directory.</li></ol></main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Your session has expired. Refresh the page and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'login' && $hasAdmin) {
            $admin = query_one('SELECT * FROM admins WHERE email = ?', [strtolower(trim((string)($_POST['email'] ?? '')))]);
            if (!$admin || !password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
                throw new InvalidArgumentException('The email address or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            redirect('invoice-dashboard');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect('login');
        }
        if (empty($_SESSION['admin_id'])) redirect('login');
        if ($action === 'save_basic_settings') {
            $siteTitle = trim((string)($_POST['site_title'] ?? ''));
            $slogan = trim((string)($_POST['slogan'] ?? ''));
            $mobile = trim((string)($_POST['mobile_number'] ?? ''));
            $email = trim((string)($_POST['site_email'] ?? ''));
            $address = trim((string)($_POST['site_address'] ?? ''));
            $website = trim((string)($_POST['site_website'] ?? ''));
            $pdfShowLogo = !empty($_POST['pdf_show_logo']) ? '1' : '0';
            $pdfShowTitle = !empty($_POST['pdf_show_title']) ? '1' : '0';
            $pdfShowSlogan = !empty($_POST['pdf_show_slogan']) ? '1' : '0';
            if ($siteTitle === '' || mb_strlen($siteTitle) > 80) throw new InvalidArgumentException('Enter a site title between 1 and 80 characters.');
            if (mb_strlen($slogan) > 200) throw new InvalidArgumentException('Enter a slogan of up to 200 characters.');
            if ($mobile !== '' && (mb_strlen($mobile) > 25 || !preg_match('/^[+0-9()\-\s]+$/', $mobile))) throw new InvalidArgumentException('Enter a valid mobile number.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
            if (mb_strlen($address) > 300) throw new InvalidArgumentException('Enter an address of up to 300 characters.');
            if ($website !== '' && (mb_strlen($website) > 200 || !filter_var($website, FILTER_VALIDATE_URL) || !in_array(parse_url($website, PHP_URL_SCHEME), ['http', 'https'], true))) throw new InvalidArgumentException('Enter the full website URL beginning with http or https.');
            $oldLogo = setting('logo_path');
            $oldFavicon = setting('favicon_path');
            $oldSignature = setting('signature_path');
            $created = [];
            try {
                $newLogo = store_uploaded_image($_FILES['logo_file'] ?? null, 'logo');
                if ($newLogo !== null) $created[] = $newLogo;
                $newFavicon = store_uploaded_image($_FILES['favicon_file'] ?? null, 'favicon');
                if ($newFavicon !== null) $created[] = $newFavicon;
                $newSignature = store_uploaded_image($_FILES['signature_file'] ?? null, 'signature');
                if ($newSignature !== null) $created[] = $newSignature;
                $logoPath = $newLogo ?? (!empty($_POST['remove_logo']) ? '' : $oldLogo);
                $faviconPath = $newFavicon ?? (!empty($_POST['remove_favicon']) ? '' : $oldFavicon);
                $signaturePath = $newSignature ?? (!empty($_POST['remove_signature']) ? '' : $oldSignature);
                save_settings(['site_title' => $siteTitle, 'slogan' => $slogan, 'mobile_number' => $mobile, 'email' => $email, 'address' => $address, 'website' => $website, 'logo_path' => $logoPath, 'favicon_path' => $faviconPath, 'signature_path' => $signaturePath, 'pdf_show_logo' => $pdfShowLogo, 'pdf_show_title' => $pdfShowTitle, 'pdf_show_slogan' => $pdfShowSlogan]);
                if ($oldLogo !== $logoPath) delete_uploaded_asset($oldLogo);
                if ($oldFavicon !== $faviconPath) delete_uploaded_asset($oldFavicon);
                if ($oldSignature !== $signaturePath) delete_uploaded_asset($oldSignature);
            } catch (Throwable $error) {
                foreach ($created as $path) delete_uploaded_asset($path);
                throw $error;
            }
            flash('success', 'Basic settings have been saved.');
            redirect('settings', ['tab' => 'basic']);
        }
        if ($action === 'save_smtp_settings') {
            $host = trim((string)($_POST['smtp_host'] ?? ''));
            $port = filter_var($_POST['smtp_port'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string)($_POST['smtp_username'] ?? ''));
            $password = (string)($_POST['smtp_password'] ?? '');
            $encryption = (string)($_POST['smtp_encryption'] ?? 'tls');
            $fromName = trim((string)($_POST['smtp_from_name'] ?? ''));
            $fromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
            if (mb_strlen($host) > 255 || preg_match('/[\r\n]/', $host)) throw new InvalidArgumentException('Enter a valid SMTP host.');
            if ($port === false || $port < 1 || $port > 65535) throw new InvalidArgumentException('Enter an SMTP port between 1 and 65535.');
            if (mb_strlen($username) > 255 || mb_strlen($password) > 500 || mb_strlen($fromName) > 150) throw new InvalidArgumentException('The SMTP information is too long.');
            if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) throw new InvalidArgumentException('Select a valid encryption option.');
            if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid From Email address.');
            $values = ['smtp_host' => $host, 'smtp_port' => (string)$port, 'smtp_username' => $username, 'smtp_encryption' => $encryption, 'smtp_from_name' => $fromName, 'smtp_from_email' => $fromEmail];
            if (!empty($_POST['smtp_clear_password'])) $values['smtp_password'] = '';
            elseif ($password !== '') $values['smtp_password'] = encrypt_smtp_password($password);
            save_settings($values);
            flash('success', 'SMTP settings have been saved.');
            redirect('settings', ['tab' => 'smtp']);
        }
        if ($action === 'save_payment_method') {
            $id = (int)($_POST['method_id'] ?? 0);
            $existing = $id ? query_one('SELECT * FROM payment_methods WHERE id=?', [$id]) : false;
            if ($id && !$existing) throw new InvalidArgumentException('Payment method not found.');
            $type = (string)($_POST['type'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $accountName = trim((string)($_POST['account_name'] ?? ''));
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            $mobile = trim((string)($_POST['mobile_number'] ?? ''));
            $branch = trim((string)($_POST['branch'] ?? ''));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            if (!in_array($type, ['bank', 'mfs', 'card', 'other'], true)) throw new InvalidArgumentException('Select a payment method type.');
            if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a payment method name between 1 and 120 characters.');
            foreach ([$accountName, $accountNumber, $mobile, $branch] as $value) {
                if (mb_strlen($value) > 150) throw new InvalidArgumentException('Payment details must be 150 characters or fewer.');
            }
            if (mb_strlen($instructions) > 1000) throw new InvalidArgumentException('Payment instructions must be 1,000 characters or fewer.');
            $newQr = store_uploaded_image($_FILES['qr_file'] ?? null, 'qr');
            $oldQr = (string)($existing['qr_path'] ?? '');
            $qrPath = $newQr ?? (!empty($_POST['remove_qr']) ? '' : $oldQr);
            try {
                if ($existing) {
                    db()->prepare('UPDATE payment_methods SET type=?, name=?, account_name=?, account_number=?, mobile_number=?, branch=?, instructions=?, qr_path=? WHERE id=?')->execute([$type, $name, $accountName, $accountNumber, $mobile, $branch, $instructions, $qrPath, $id]);
                } else {
                    db()->prepare('INSERT INTO payment_methods (type, name, account_name, account_number, mobile_number, branch, instructions, qr_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$type, $name, $accountName, $accountNumber, $mobile, $branch, $instructions, $qrPath]);
                }
            } catch (Throwable $error) {
                if ($newQr !== null) delete_uploaded_asset($newQr);
                throw $error;
            }
            if ($oldQr !== $qrPath) delete_uploaded_asset($oldQr);
            flash('success', 'The payment method has been saved.');
            redirect('payment-methods');
        }
        if ($action === 'toggle_payment_method') {
            $id = (int)($_POST['method_id'] ?? 0);
            $stmt = db()->prepare('UPDATE payment_methods SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?');
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) throw new InvalidArgumentException('Payment method not found.');
            flash('success', 'The payment method status has been changed.');
            redirect('payment-methods');
        }
        if ($action === 'save_client') {
            $id = create_client_account($_POST);
            flash('success', 'The new client has been saved.');
            redirect('client', ['id' => $id]);
        }
        if ($action === 'update_client') {
            $id = (int)($_POST['client_id'] ?? 0);
            update_client_account($id, $_POST);
            flash('success', 'The client information has been updated.');
            redirect('client', ['id' => $id]);
        }
        if ($action === 'delete_client') {
            $id = (int)($_POST['client_id'] ?? 0);
            delete_client_account($id);
            flash('success', 'The client has been deleted.');
            redirect('clients');
        }
        if ($action === 'create_invoice') {
            $id = create_invoice($_POST);
            flash('success', 'The invoice has been created. Use Send Mail when you are ready to email the client.');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'edit_invoice') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            edit_invoice($id, $_POST);
            flash('success', 'The invoice changes have been saved.');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'collect_payment') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            collect_payment($id, (string)($_POST['amount'] ?? ''), (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['paid_at'] ?? ''));
            flash('success', 'The collection has been saved.');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'collect_invoice_collection') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            $collectionType = (string)($_POST['collection_type'] ?? 'partial');
            if (!in_array($collectionType, ['partial', 'full'], true)) throw new InvalidArgumentException('Select a collection type.');
            $invoice = invoice_rows('WHERE i.id=?', [$id], 'i.id DESC', 1)[0] ?? null;
            if (!$invoice) throw new InvalidArgumentException('Invoice not found.');
            $remaining = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
            if ($remaining < 1) throw new InvalidArgumentException('This invoice is already fully paid.');
            $amount = $collectionType === 'full' ? number_format($remaining / 100, 2, '.', '') : (string)($_POST['amount'] ?? '');
            collect_payment($id, $amount, (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['paid_at'] ?? ''));
            flash('success', $collectionType === 'full' ? 'The full payment has been collected.' : 'The partial payment has been collected.');
            redirect('collections');
        }
        if ($action === 'collect_multiple_invoices') {
            $receipt = collect_multiple_invoices((array)($_POST['invoice_ids'] ?? []), (string)($_POST['amount'] ?? ''), (string)($_POST['discount'] ?? ''), (string)($_POST['discount_scope'] ?? ''), (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['paid_at'] ?? ''));
            flash('success', 'The collection was allocated across the selected invoices.');
            redirect('receipt', ['number' => $receipt]);
        }
        if ($action === 'retry_invoice_email') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            if (!function_exists('send_invoice_email_for_invoice')) throw new RuntimeException('Run composer install on the server to enable invoice email delivery.');
            $delivery = send_invoice_email_for_invoice($id);
            flash($delivery['status'] === 'sent' ? 'success' : 'error', $delivery['message']);
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'save_service') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = money_cents((string)($_POST['price'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter a service name.');
            $id = (int)($_POST['service_id'] ?? 0);
            if ($id) {
                db()->prepare('UPDATE services SET name=?, description=?, price_cents=? WHERE id=?')->execute([$name, $description, $price, $id]);
            } else {
                db()->prepare('INSERT INTO services (name,description,price_cents) VALUES (?,?,?)')->execute([$name, $description, $price]);
            }
            flash('success', 'The service has been saved.');
            redirect('services');
        }
        if ($action === 'toggle_service') {
            db()->prepare('UPDATE services SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?')->execute([(int)($_POST['service_id'] ?? 0)]);
            flash('success', 'The service status has been changed.');
            redirect('services');
        }
        if ($action === 'toggle_recurrence') {
            $id = (int)($_POST['recurrence_id'] ?? 0);
            db()->prepare("UPDATE recurrences SET status = CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=?")->execute([$id]);
            flash('success', 'The recurring schedule has been updated.');
            redirect('recurring');
        }
        if ($action === 'update_recurrence_frequency') {
            update_recurrence_frequency((int)($_POST['recurrence_id'] ?? 0), (string)($_POST['frequency'] ?? ''));
            flash('success', 'The billing cycle and next invoice date have been updated.');
            redirect('recurring');
        }
        throw new InvalidArgumentException('Invalid request.');
    } catch (Throwable $error) {
        flash('error', $error instanceof PDOException ? 'The data could not be saved. Please try again.' : $error->getMessage());
        if ($action === 'create_invoice') {
            $_SESSION['old_invoice'] = $_POST;
            redirect('new');
        }
        if ($action === 'edit_invoice') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            $_SESSION['old_edit_invoice'] = ['id' => $id, 'values' => $_POST];
            redirect('edit', ['id' => $id]);
        }
        if ($action === 'collect_payment') redirect('invoice', ['id' => (int)($_POST['invoice_id'] ?? 0)]);
        if ($action === 'collect_invoice_collection') {
            $_SESSION['old_collection'] = $_POST;
            redirect('collections', ['id' => (int)($_POST['invoice_id'] ?? 0)]);
        }
        if ($action === 'collect_multiple_invoices') {
            $_SESSION['old_collection'] = $_POST;
            redirect('collections');
        }
        if ($action === 'retry_invoice_email') redirect('invoice', ['id' => (int)($_POST['invoice_id'] ?? 0)]);
        if ($action === 'login') redirect('login');
        if ($action === 'save_service' || $action === 'toggle_service') redirect('services');
        if ($action === 'toggle_recurrence') redirect('recurring');
        if ($action === 'save_basic_settings') redirect('settings', ['tab' => 'basic']);
        if ($action === 'save_smtp_settings') redirect('settings', ['tab' => 'smtp']);
        if ($action === 'save_payment_method' || $action === 'toggle_payment_method') redirect('payment-methods', ['edit' => (int)($_POST['method_id'] ?? 0)]);
        if ($action === 'save_client') {
            $_SESSION['old_client'] = $_POST;
            redirect('clients', ['add' => 1]);
        }
        if ($action === 'update_client') {
            $id = (int)($_POST['client_id'] ?? 0);
            $_SESSION['old_edit_client'] = ['id' => $id, 'values' => $_POST];
            redirect('client', ['id' => $id, 'edit' => 1]);
        }
        if ($action === 'delete_client') redirect('client', ['id' => (int)($_POST['client_id'] ?? 0)]);
        redirect('invoice-dashboard');
    }
}

if (empty($_SESSION['admin_id'])) $page = 'login';
elseif (!in_array($page, ['print', 'pdf', 'download', 'client-search'], true)) {
    $generatedInvoices = generate_due_invoices();
    if ($generatedInvoices > 0 && function_exists('process_invoice_email_queue')) process_invoice_email_queue(min(10, $generatedInvoices));
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'invoice' => '<path d="M7 3h10l3 3v15l-3-2-3 2-3-2-3 2-3-2V5a2 2 0 0 1 2-2Z"/><path d="M9 8h6M9 12h7"/>',
        'repeat' => '<path d="M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'box' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M3 12h18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-8M15 7h6v6"/>',
        'wallet' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M16 15h2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'print' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/>',
        'download' => '<path d="M12 3v12m-4-4 4 4 4-4M4 17v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'settings' => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.86 1.86-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.56V21h-2.6v-.04a1.7 1.7 0 0 0-1-1.56 1.7 1.7 0 0 0-1.88.34l-.06.06-1.86-1.86.06-.06A1.7 1.7 0 0 0 8 15a1.7 1.7 0 0 0-1.56-1H6.4v-2.6h.04A1.7 1.7 0 0 0 8 10.4a1.7 1.7 0 0 0-.34-1.88L7.6 8.46 9.46 6.6l.06.06A1.7 1.7 0 0 0 11.4 7a1.7 1.7 0 0 0 1-1.56V5.4H15v.04A1.7 1.7 0 0 0 16 7a1.7 1.7 0 0 0 1.88-.34l.06-.06 1.86 1.86-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.56 1H21v2.6h-.04A1.7 1.7 0 0 0 19.4 15Z"/>',
        'edit' => '<path d="M12 20h9M4 20l4.5-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.5 16 4 20Z"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function brand_mark(int $size = 23): string
{
    $logo = uploaded_asset_url(setting('logo_path'));
    return $logo !== ''
        ? '<span class="brand-symbol uploaded-brand"><img src="' . e($logo) . '" alt=""></span>'
        : '<span class="brand-symbol">' . icon('invoice', $size) . '</span>';
}

function favicon_tag(): string
{
    $favicon = uploaded_asset_url(setting('favicon_path'));
    return $favicon !== '' ? '<link rel="icon" href="' . e($favicon) . '">' : '';
}

function versioned_asset(string $path): string
{
    $modified = @filemtime(__DIR__ . '/' . $path);
    return $path . '?v=' . ($modified ?: 1);
}

function module_definitions(): array
{
    return [
        'invoice' => ['label' => 'Invoice', 'icon' => 'invoice', 'home' => 'invoice-dashboard', 'pages' => ['invoice-dashboard','invoices','new','edit','invoice','print','pdf','download','collections','receipt','recurring']],
        'clients' => ['label' => 'Clients', 'icon' => 'users', 'home' => 'clients-dashboard', 'pages' => ['clients-dashboard','clients','client','client-ledger']],
        'services' => ['label' => 'Services', 'icon' => 'box', 'home' => 'services-dashboard', 'pages' => ['services-dashboard','services']],
        'payments' => ['label' => 'Payment Methods', 'icon' => 'wallet', 'home' => 'payments-dashboard', 'pages' => ['payments-dashboard','payment-methods']],
        'settings' => ['label' => 'Settings', 'icon' => 'settings', 'home' => 'settings-dashboard', 'pages' => ['settings-dashboard','settings']],
    ];
}

function active_module(string $page): string
{
    foreach (module_definitions() as $key => $module) if (in_array($page, $module['pages'], true)) return $key;
    return 'invoice';
}

function module_sidebar_links(string $module): array
{
    return match ($module) {
        'clients' => [['clients-dashboard','Overview','grid'], ['clients','Clients','users'], ['client-ledger','Client Ledger','invoice']],
        'services' => [['services-dashboard','Overview','grid'], ['services','Service Catalog','box']],
        'payments' => [['payments-dashboard','Overview','grid'], ['payment-methods','Payment Methods','wallet']],
        'settings' => [['settings-dashboard','Overview','grid'], ['settings','Basic & SMTP Settings','settings']],
        default => [['invoice-dashboard','Overview','grid'], ['invoices','Invoices','invoice'], ['new','Create Invoice','plus'], ['collections','Collections','wallet'], ['recurring','Recurring Billing','repeat']],
    };
}

function begin_page(string $title, string $page, string $subtitle = ''): void
{
    $admin = query_one('SELECT name, email, role FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
    $modules = module_definitions();
    $moduleKey = active_module($page);
    $currentModule = $modules[$moduleKey];
    $links = module_sidebar_links($moduleKey);
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan', 'Keep every invoice and collection in one place.');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123637"><title>' . e($title) . ' · ' . e($siteTitle) . '</title>' . favicon_tag() . '<link rel="stylesheet" href="' . e(versioned_asset('assets/app.css')) . '"></head><body><div class="app-shell">';
    echo '<aside class="sidebar" id="sidebar"><a class="brand" href="' . e(url('invoice-dashboard')) . '">' . brand_mark(23) . '<span class="brand-name">' . e($siteTitle) . '<span class="brand-dot">.</span><small>BUSINESS WORKSPACE</small></span></a>';
    echo '<div class="active-module">' . icon($currentModule['icon'], 22) . '<span><small>CURRENT MODULE</small><strong>' . e($currentModule['label']) . '</strong></span></div>';
    echo '<div class="nav-caption">WORKSPACE</div><nav class="nav-links">';
    foreach ($links as [$target, $label, $symbol]) {
        $active = $page === $target || (in_array($page, ['invoice', 'edit'], true) && $target === 'invoices') || ($page === 'client' && $target === 'clients') || ($page === 'receipt' && $target === 'collections');
        echo '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(url($target)) . '">' . icon($symbol) . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="sidebar-note"><span class="note-icon">✦</span><strong>Simple billing, clear accounts</strong><p>' . e($slogan) . '</p></div><div class="profile"><span class="avatar">' . e(mb_substr($admin['name'] ?? 'A', 0, 1)) . '</span><span class="profile-copy"><strong>' . e($admin['name'] ?? 'Admin') . '</strong><small>' . (($admin['role'] ?? '') === 'super_admin' ? 'Super Admin' : 'Admin') . '</small></span><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button type="submit" title="Log Out" class="icon-button">' . icon('logout', 18) . '</button></form></div></div></aside>';
    echo '<div class="mobile-backdrop" data-close-menu></div><main class="main"><header class="topbar"><button type="button" class="mobile-menu icon-button" data-menu-toggle aria-label="Open menu">' . icon('menu') . '</button><nav class="top-modules" aria-label="Business modules">';
    foreach ($modules as $key => $module) echo '<a class="top-module' . ($key === $moduleKey ? ' active' : '') . '" href="' . e(url($module['home'])) . '">' . icon($module['icon'], 20) . '<span>' . e($module['label']) . '</span></a>';
    echo '</nav><div class="topbar-right"><span class="today-label">' . e(date('d M Y')) . '</span><a class="btn btn-primary btn-sm" href="' . e(url('new')) . '">' . icon('plus', 17) . ' New Invoice</a></div></header><div class="content">';
    echo '<div class="page-heading"><div><p class="eyebrow">BILLFLOW / ' . strtoupper(e($moduleKey)) . '</p><h1>' . e($title) . '</h1>' . ($subtitle ? '<p>' . e($subtitle) . '</p>' : '') . '</div></div>';
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function end_page(): void { echo '</div></main></div><script src="' . e(versioned_asset('assets/app.js')) . '"></script></body></html>'; }

function badge(array $invoice): string
{
    $status = invoice_status($invoice);
    return '<span class="badge badge-' . $status . '"><span class="badge-dot"></span>' . status_label($status) . '</span>';
}

function payment_method_options(?int $selectedId = null): string
{
    $options = '<option value="">All Active Payment Method (Default)</option>';
    $methods = query_all('SELECT id, type, name, active FROM payment_methods WHERE active=1 OR id=? ORDER BY active DESC, name', [$selectedId ?? 0]);
    foreach ($methods as $method) {
        $label = $method['name'] . ' · ' . ['bank' => 'Bank', 'mfs' => 'MFS', 'card' => 'Card', 'other' => 'Other'][$method['type']];
        if (!(int)$method['active']) $label .= ' (Inactive)';
        $options .= '<option value="' . (int)$method['id'] . '"' . ((int)$method['id'] === $selectedId ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $options;
}

function invoice_table(array $invoices): void
{
    if (!$invoices) { echo '<div class="empty-state">' . icon('invoice', 34) . '<h3>No invoices yet</h3><p>Your first invoice will appear here after it is created.</p><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 17) . ' Create Invoice</a></div>'; return; }
    echo '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Client</th><th>Issue / Due</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($invoices as $invoice) {
        echo '<tr><td><a class="strong-link" href="' . e(url('invoice', ['id' => $invoice['id']])) . '">' . e($invoice['number']) . '</a><small>' . ($invoice['recurrence_id'] ? '↻ ' . frequency_label($invoice['frequency']) : 'One-time') . '</small></td><td><strong>' . e($invoice['client_name']) . '</strong><small>' . e($invoice['client_phone']) . '</small></td><td>' . e(format_date($invoice['issue_date'])) . '<small>Due ' . e(format_date($invoice['due_date'])) . '</small></td><td><strong>' . format_money((int)$invoice['total_cents']) . '</strong><small>Balance Due ' . format_money(max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents'])) . '</small></td><td>' . badge($invoice) . '</td><td><div class="row-actions"><a class="row-view" href="' . e(url('pdf', ['id' => $invoice['id']])) . '" target="_blank" rel="noopener noreferrer" aria-label="Invoice PDF Open in a new tab">PDF View</a><a class="row-edit" href="' . e(url('edit', ['id' => $invoice['id']])) . '">Edit</a><a class="row-arrow" href="' . e(url('invoice', ['id' => $invoice['id']])) . '" aria-label="View Invoice">' . icon('chevron', 18) . '</a></div></td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($page === 'login') {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan', 'Invoices, clients, collections, and recurring billing in one simple workspace.');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · ' . e($siteTitle) . '</title>' . favicon_tag() . '<link rel="stylesheet" href="assets/app.css"></head><body class="auth-body"><div class="auth-art"><div class="auth-brand">' . brand_mark(24) . '<span>' . e($siteTitle) . '<span class="brand-dot">.</span></span></div><div class="auth-art-content"><span class="art-chip">✦ SMART INVOICING</span><h1>Your billing,<br><em>perfectly organized.</em></h1><p>' . e($slogan) . '</p><div class="art-lines"><div></div><div></div><div></div></div></div><small>© ' . date('Y') . ' ' . e($siteTitle) . '</small></div><div class="auth-main"><div class="auth-card"><span class="auth-kicker">WELCOME TO BILLFLOW</span><h2>Welcome Back</h2><p>Sign in to your admin account.</p>';
    if ($flash) echo '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    echo '<form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="login"><label>Email<input type="email" name="email" autocomplete="email" required placeholder="you@company.com"></label><label>Password<input type="password" name="password" autocomplete="current-password" required placeholder="Your password"></label><button class="btn btn-primary btn-wide" type="submit">Log In ' . icon('arrow', 18) . '</button></form></div></div></body></html>';
    exit;
}

switch ($page) {
case 'client-search':
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(search_clients((string)($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

case 'dashboard':
case 'invoice-dashboard':
    $invoiceStats = query_one('SELECT COUNT(*) invoice_count, COALESCE(SUM(total_cents),0) billed FROM invoices');
    $collected = query_one('SELECT COALESCE(SUM(amount_cents),0) total, COALESCE(SUM(discount_cents),0) discounts FROM payments');
    $invoices = invoice_rows('', [], 'i.id DESC', 6);
    begin_page('Invoice Overview', $page, 'Invoices, collections, and recurring billing.');
    echo '<div class="stats-grid"><div class="stat-card"><span class="stat-icon stat-icon-mint">' . icon('invoice',22) . '</span><span class="stat-label">Total Invoices</span><strong>' . number_format((int)$invoiceStats['invoice_count']) . '</strong></div><div class="stat-card"><span class="stat-icon stat-icon-blue">' . icon('wallet',22) . '</span><span class="stat-label">Collected</span><strong>' . format_money((int)$collected['total']) . '</strong></div><div class="stat-card"><span class="stat-icon stat-icon-peach">' . icon('clock',22) . '</span><span class="stat-label">Outstanding</span><strong>' . format_money(max(0,(int)$invoiceStats['billed']-(int)$collected['total']-(int)$collected['discounts'])) . '</strong></div></div><section class="panel"><div class="panel-heading"><h2>Recent Invoices</h2><a class="text-link" href="' . e(url('invoices')) . '">View All</a></div>'; invoice_table($invoices); echo '</section>';
    end_page(); break;

case 'clients-dashboard':
    $clientStats = query_one('SELECT COUNT(*) total FROM clients');
    begin_page('Clients Overview', $page, 'Client accounts and account ledgers.');
    echo '<div class="module-dashboard-grid"><a class="panel module-dashboard-card" href="' . e(url('clients')) . '"><span class="stat-icon stat-icon-mint">' . icon('users',24) . '</span><h2>' . number_format((int)$clientStats['total']) . ' Clients</h2><p>Search, add, edit, and review client profiles.</p><span class="text-link">Manage Clients</span></a><a class="panel module-dashboard-card" href="' . e(url('client-ledger')) . '"><span class="stat-icon stat-icon-blue">' . icon('invoice',24) . '</span><h2>Client Ledger</h2><p>Review invoices, collections, discounts, and balances.</p><span class="text-link">Open Ledger</span></a></div>';
    end_page(); break;

case 'services-dashboard':
case 'payments-dashboard':
case 'settings-dashboard':
    $target = $page === 'services-dashboard' ? 'services' : ($page === 'payments-dashboard' ? 'payment-methods' : 'settings');
    $label = module_definitions()[active_module($page)]['label'];
    begin_page($label . ' Overview', $page, 'Open this module to manage its controls.');
    echo '<a class="panel module-dashboard-card" href="' . e(url($target)) . '"><span class="stat-icon stat-icon-mint">' . icon(module_definitions()[active_module($page)]['icon'],24) . '</span><h2>Manage ' . e($label) . '</h2><p>Open the ' . e($label) . ' control page.</p><span class="text-link">Continue ' . icon('arrow',16) . '</span></a>';
    end_page(); break;

case 'invoices':
    $search = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['filter'] ?? 'all');
    $where = ''; $params = [];
    if ($search !== '') { $where = 'WHERE (i.number LIKE ? OR i.billing_name LIKE ? OR i.billing_phone LIKE ? OR i.billing_company_name LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)'; $params = array_fill(0, 6, '%' . $search . '%'); }
    $rows = invoice_rows($where, $params, 'i.id DESC', 500);
    if (in_array($filter, ['paid','partial','overdue','unpaid'], true)) $rows = array_values(array_filter($rows, fn($row) => invoice_status($row) === $filter));
    begin_page('Invoices', $page, 'View all invoices, payments, and outstanding balances.');
    echo '<section class="panel"><div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="page" value="invoices">' . icon('search', 18) . '<input name="q" value="' . e($search) . '" placeholder="Invoice, Name, Company or Mobile Search"><button type="submit">Search</button></form><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 18) . ' New Invoice</a></div><div class="filter-tabs">';
    foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'partial' => 'Partial', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $key => $label) echo '<a class="' . ($filter === $key ? 'selected' : '') . '" href="' . e(url('invoices', ['q' => $search, 'filter' => $key])) . '">' . $label . '</a>';
    echo '</div>'; invoice_table($rows); echo '</section>';
    end_page(); break;

case 'collections':
    $search = trim((string)($_GET['q'] ?? ''));
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE (i.number LIKE ? OR i.billing_name LIKE ? OR i.billing_phone LIKE ? OR i.billing_company_name LIKE ?)';
        $params = array_fill(0, 4, '%' . $search . '%');
    }
    $outstandingInvoices = array_values(array_filter(invoice_rows($where, $params, 'i.id DESC', 500), static fn(array $row): bool => (int)$row['paid_cents'] < (int)$row['total_cents']));
    $outstandingTotal = array_sum(array_map(static fn(array $row): int => (int)$row['total_cents'] - (int)$row['paid_cents'], $outstandingInvoices));
    $selectedId = (int)($_GET['id'] ?? 0);
    $selectedInvoice = $selectedId ? (invoice_rows('WHERE i.id=?', [$selectedId], 'i.id DESC', 1)[0] ?? null) : null;
    if ($selectedInvoice && (int)$selectedInvoice['paid_cents'] >= (int)$selectedInvoice['total_cents']) $selectedInvoice = null;
    $oldCollection = $_SESSION['old_collection'] ?? []; unset($_SESSION['old_collection']);
    $recentCollections = query_all('SELECT p.*, i.number, i.billing_name FROM payments p JOIN invoices i ON i.id=p.invoice_id ORDER BY p.id DESC LIMIT 50');
    begin_page('Invoice Collections', $page, 'Record partial or full payments and review recent transactions.');
    if ($selectedInvoice) {
        $remaining = (int)$selectedInvoice['total_cents'] - (int)$selectedInvoice['paid_cents'];
        $collectionType = (string)($oldCollection['collection_type'] ?? 'partial');
        echo '<section class="panel form-panel collection-page-form"><div class="collection-form-heading"><div><span class="eyebrow">COLLECT PAYMENT</span><h2>' . e($selectedInvoice['number']) . ' · ' . e($selectedInvoice['client_name']) . '</h2><p>Current balance: <strong>' . format_money($remaining) . '</strong></p></div><a class="btn btn-outline btn-sm" href="' . e(url('collections', ['q' => $search])) . '">Close</a></div><form method="post" class="stack-form" data-collection-form data-remaining="' . e(number_format($remaining / 100, 2, '.', '')) . '">' . csrf_field() . '<input type="hidden" name="action" value="collect_invoice_collection"><input type="hidden" name="invoice_id" value="' . (int)$selectedInvoice['id'] . '"><div class="type-choice"><label><input type="radio" name="collection_type" value="partial"' . ($collectionType === 'partial' ? ' checked' : '') . '><span class="type-card"><strong>Partial Collection</strong><small>Collect part of the outstanding balance</small></span></label><label><input type="radio" name="collection_type" value="full"' . ($collectionType === 'full' ? ' checked' : '') . '><span class="type-card"><strong>Full Payment</strong><small>Collect the entire outstanding balance</small></span></label></div><div class="form-grid"><label>Collection Amount (BDT)<input type="number" name="amount" data-collection-amount required min="0.01" max="' . e(number_format($remaining / 100, 2, '.', '')) . '" step="0.01" value="' . e($oldCollection['amount'] ?? '') . '" placeholder="0.00"></label><label>Payment Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Date<input type="date" name="paid_at" required value="' . e($oldCollection['paid_at'] ?? date('Y-m-d')) . '"></label><label>Transaction Reference <small>(Optional)</small><input name="reference" maxlength="120" value="' . e($oldCollection['reference'] ?? '') . '" placeholder="Transaction ID"></label><label class="field-wide">Notes <small>(Optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="Payment details">' . e($oldCollection['notes'] ?? '') . '</textarea></label></div><div class="client-create-actions"><button class="btn btn-primary" type="submit">Save Collection</button></div></form></section>';
    }
    echo '<form method="post" data-multi-collection>' . csrf_field() . '<input type="hidden" name="action" value="collect_multiple_invoices"><div class="panel bulk-collection-bar"><label>Collection Amount (BDT)<input type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00"></label><label>Partial Discount (BDT)<input type="number" name="discount" min="0" step="0.01" value="0.00"></label><div><span class="form-label">Confirm Discount Type</span><label class="checkbox-line"><input type="radio" name="discount_scope" value="one_time"> One-time Invoices</label><label class="checkbox-line"><input type="radio" name="discount_scope" value="recurring"> Recurring Invoices</label></div><label>Payment Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Date<input type="date" name="paid_at" required value="' . e(date('Y-m-d')) . '"></label><label>Transaction Reference <small>(Optional)</small><input name="reference" maxlength="120" placeholder="Transaction ID"></label><label class="field-wide">Notes <small>(Optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="Payment or discount details"></textarea></label><div class="bulk-actions"><button class="btn btn-primary btn-wide" type="submit">Collect Selected Invoices</button></div></div><section class="panel collection-list-panel"><div class="toolbar"><div class="search-form">' . icon('search', 18) . '<input type="search" data-invoice-filter placeholder="Filter the invoices below"></div><div class="collection-summary"><span data-selected-count>0 selected</span><strong data-selected-total>BDT 0</strong></div></div>';
    if (!$outstandingInvoices) echo '<div class="empty-state">' . icon('check', 34) . '<h3>No outstanding invoices</h3><p>All invoices have been paid in full.</p></div>';
    else {
        echo '<div class="table-wrap"><table><thead><tr><th><input class="collection-select" type="checkbox" data-select-all aria-label="Select all invoices"></th><th>Invoice</th><th>Client</th><th>Total</th><th>Collection</th><th>Discount</th><th>Balance Due</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($outstandingInvoices as $row) {
            $due = (int)$row['total_cents'] - (int)$row['paid_cents'];
            echo '<tr data-invoice-row data-search="' . e(strtolower($row['number'].' '.$row['client_name'].' '.$row['client_phone'])) . '"><td><input class="collection-select" type="checkbox" name="invoice_ids[]" value="' . (int)$row['id'] . '" data-balance="' . e((string)$due) . '" aria-label="Select ' . e($row['number']) . '"></td><td><a class="strong-link" href="' . e(url('invoice', ['id' => $row['id']])) . '">' . e($row['number']) . '</a><small>' . e(format_date($row['due_date'])) . '</small></td><td><strong>' . e($row['client_name']) . '</strong><small>' . e($row['client_phone']) . '</small></td><td><strong>' . format_money((int)$row['total_cents']) . '</strong></td><td>' . format_money(max(0,(int)$row['paid_cents']-(int)$row['discount_cents'])) . '</td><td>' . format_money((int)$row['discount_cents']) . '</td><td><strong>' . format_money($due) . '</strong></td><td>' . badge($row) . '</td><td><a class="btn btn-outline btn-sm" href="' . e(url('collections', ['id' => $row['id'], 'q' => $search])) . '">Single</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section></form><section class="panel collection-history-panel"><div class="panel-heading"><div><span class="eyebrow">RECENT COLLECTIONS</span><h2>Recent Collections</h2></div><span class="count-pill">' . count($recentCollections) . '</span></div>';
    if (!$recentCollections) echo '<div class="empty-state"><p>No collections have been recorded yet.</p></div>';
    else {
        echo '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Client</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th></tr></thead><tbody>';
        $methodLabels = ['cash'=>'Cash','bank'=>'Bank Transfer','bkash'=>'bKash','nagad'=>'Nagad','card'=>'Card','other'=>'Other'];
        foreach ($recentCollections as $payment) echo '<tr><td><a class="strong-link" href="' . e(url('invoice', ['id' => $payment['invoice_id']])) . '">' . e($payment['number']) . '</a></td><td>' . e($payment['billing_name']) . '</td><td><strong>' . format_money((int)$payment['amount_cents']) . '</strong></td><td>' . e($methodLabels[$payment['method']] ?? $payment['method']) . '</td><td>' . e(format_date($payment['paid_at'])) . '</td><td>' . e($payment['reference'] ?: '—') . '</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</section>';
    end_page(); break;

case 'new':
    $old = $_SESSION['old_invoice'] ?? []; unset($_SESSION['old_invoice']);
    $sourceClientId = (int)($_GET['client_id'] ?? 0);
    if (!$old && $sourceClientId > 0) {
        $sourceClient = query_one('SELECT * FROM clients WHERE id=?', [$sourceClientId]);
        if ($sourceClient) $old = ['client_name'=>$sourceClient['name'], 'client_phone'=>$sourceClient['phone'], 'client_email'=>$sourceClient['email'] ?? '', 'company_name'=>$sourceClient['company_name'] ?? ''];
    }
    $services = query_all('SELECT * FROM services WHERE active=1 ORDER BY name');
    $serviceOptions = '<option value="">Enter manually</option>';
    foreach ($services as $service) $serviceOptions .= '<option value="' . (int)$service['id'] . '" data-name="' . e($service['name']) . '" data-description="' . e($service['description']) . '" data-price="' . e(number_format($service['price_cents'] / 100, 2, '.', '')) . '">' . e($service['name']) . '</option>';
    $frequencyOptions = '';
    foreach (billing_frequency_options() as $value => $label) $frequencyOptions .= '<option value="' . e($value) . '"' . (($old['frequency'] ?? 'monthly') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    begin_page('New Invoice', $page, 'Create an invoice with client, service, and billing details.');
    echo '<form method="post" id="invoice-form" class="invoice-form" data-client-search-url="' . e(url('client-search')) . '">' . csrf_field() . '<input type="hidden" name="action" value="create_invoice"><input type="hidden" name="matched_client_id" value=""><div class="form-main"><section class="panel form-panel"><div class="section-title"><span class="step">01</span><div><h2>Client Information</h2><p>Enter a mobile number or email address to find an existing client.</p></div></div><div class="form-grid client-fields"><label>Mobile Number <span>*</span><input name="client_phone" inputmode="tel" autocomplete="off" required value="' . e($old['client_phone'] ?? '') . '" placeholder="01XXXXXXXXX" data-client-lookup></label><label>Email <small>(Optional)</small><input type="email" name="client_email" autocomplete="off" value="' . e($old['client_email'] ?? '') . '" placeholder="client@example.com" data-client-lookup></label><div class="client-search-results field-wide" data-client-results hidden></div><div class="client-match field-wide" data-client-match hidden></div><label>Client Name <span>*</span><input name="client_name" required maxlength="150" value="' . e($old['client_name'] ?? '') . '" placeholder="Full name"></label><label>Company Name <small>(Optional)</small><input name="company_name" maxlength="150" value="' . e($old['company_name'] ?? '') . '" placeholder="Company Name"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">02</span><div><h2>Billing Settings</h2><p>Choose a one-time or recurring invoice.</p></div></div><div class="type-choice"><label><input type="radio" name="invoice_type" value="one_time" ' . (($old['invoice_type'] ?? 'one_time') === 'one_time' ? 'checked' : '') . '><span class="type-card"><strong>One-time Payment</strong><small>Create this invoice only once</small></span></label><label><input type="radio" name="invoice_type" value="recurring" ' . (($old['invoice_type'] ?? '') === 'recurring' ? 'checked' : '') . '><span class="type-card"><strong>Recurring Payment</strong><small>Create a new invoice at the selected interval</small></span></label></div><div class="form-grid"><label>Issue Date <span>*</span><input type="date" name="issue_date" required value="' . e($old['issue_date'] ?? date('Y-m-d')) . '"></label><label>Due Date <span>*</span><input type="date" name="due_date" required value="' . e($old['due_date'] ?? add_days(date('Y-m-d'), 7)) . '"></label><label class="field-wide recurring-field">Recurring Frequency<select name="frequency">' . $frequencyOptions . '</select></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">03</span><div><h2>Services and Items</h2><p>Choose a catalog service or enter a custom item.</p></div></div><div id="invoice-items">';
    $itemCount = max(1, count($old['item_name'] ?? []));
    for ($i = 0; $i < $itemCount; $i++) {
        echo '<div class="line-item"><div class="line-item-top"><span class="item-index">Item ' . ($i + 1) . '</span><button type="button" class="remove-item" aria-label="Remove Item">Remove</button></div><div class="form-grid"><label class="field-wide">Catalog Service<select name="item_service_id[]" class="service-select">' . str_replace('value="' . e((string)($old['item_service_id'][$i] ?? '')) . '"', 'value="' . e((string)($old['item_service_id'][$i] ?? '')) . '" selected', $serviceOptions) . '</select></label><label class="field-wide">Item Name <span>*</span><input name="item_name[]" class="item-name" required value="' . e($old['item_name'][$i] ?? '') . '" placeholder="Example: Website hosting"></label><label class="field-wide">Description <small>(Optional)</small><input name="item_description[]" class="item-description" value="' . e($old['item_description'][$i] ?? '') . '" placeholder="Service description"></label><label>Quantity <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="' . e($old['item_qty'][$i] ?? '1') . '"></label><label>Price (BDT) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" value="' . e($old['item_price'][$i] ?? '') . '" placeholder="0.00"></label></div></div>';
    }
    echo '</div><button type="button" id="add-item" class="btn btn-outline">' . icon('plus', 17) . ' Add Another Item</button></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">04</span><div><h2>Payment Method</h2><p>Show all active methods by default, or choose one method.</p></div></div><label>Payment Method<select name="payment_method_id">' . payment_method_options(isset($old['payment_method_id']) ? (int)$old['payment_method_id'] : null) . '</select></label><p class="form-help">Open <a href="' . e(url('payment-methods')) . '" target="_blank" rel="noopener noreferrer">Payment Methods</a> to add or update a method.</p></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">05</span><div><h2>Additional Notes</h2><p>Add information to display on the invoice.</p></div></div><textarea name="notes" rows="3" placeholder="Payment instructions or other information">' . e($old['notes'] ?? '') . '</textarea></section></div>';
    echo '<aside class="form-aside"><div class="summary-card"><span class="eyebrow">INVOICE SUMMARY</span><h3>Invoice Summary</h3><div class="summary-row"><span>Items</span><strong id="summary-count">1</strong></div><div class="summary-total"><span>Grand Total</span><strong id="summary-total">BDT 0</strong></div><p>An invoice number will be generated after saving.</p><button class="btn btn-primary btn-wide" type="submit">Create Invoice ' . icon('arrow', 18) . '</button></div></aside></form>';
    echo '<template id="item-template"><div class="line-item"><div class="line-item-top"><span class="item-index">Item</span><button type="button" class="remove-item" aria-label="Remove Item">Remove</button></div><div class="form-grid"><label class="field-wide">Catalog Service<select name="item_service_id[]" class="service-select">' . $serviceOptions . '</select></label><label class="field-wide">Item Name <span>*</span><input name="item_name[]" class="item-name" required placeholder="Example: Website hosting"></label><label class="field-wide">Description <small>(Optional)</small><input name="item_description[]" class="item-description" placeholder="Service description"></label><label>Amount <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="1"></label><label>Price (BDT ) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" placeholder="0.00"></label></div></div></template>';
    end_page(); break;

case 'edit':
    $id = (int)($_GET['id'] ?? 0);
    $invoice = invoice_rows('WHERE i.id = ?', [$id], 'i.id DESC', 1)[0] ?? null;
    if (!$invoice) { http_response_code(404); begin_page('Invoice Not Found', 'edit'); echo '<div class="empty-state"><h2>Invoice not found</h2><a href="' . e(url('invoices')) . '">Back to Invoices</a></div>'; end_page(); break; }
    $savedItems = query_all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [$id]);
    $oldEdit = $_SESSION['old_edit_invoice'] ?? null; unset($_SESSION['old_edit_invoice']);
    $old = $oldEdit && (int)$oldEdit['id'] === $id ? $oldEdit['values'] : [
        'client_name' => $invoice['client_name'], 'client_phone' => $invoice['client_phone'],
        'client_email' => $invoice['client_email'], 'company_name' => $invoice['billing_company_name'],
        'issue_date' => $invoice['issue_date'], 'due_date' => $invoice['due_date'], 'notes' => $invoice['notes'], 'payment_method_id' => $invoice['payment_method_id'],
        'item_service_id' => array_column($savedItems, 'service_id'),
        'item_name' => array_column($savedItems, 'name'),
        'item_description' => array_column($savedItems, 'description'),
        'item_qty' => array_column($savedItems, 'quantity'),
        'item_price' => array_map(static fn($item) => number_format((int)$item['unit_price_cents'] / 100, 2, '.', ''), $savedItems),
    ];
    $services = query_all('SELECT * FROM services ORDER BY active DESC, name');
    $serviceOptions = '<option value="">Enter manually</option>';
    foreach ($services as $service) $serviceOptions .= '<option value="' . (int)$service['id'] . '" data-name="' . e($service['name']) . '" data-description="' . e($service['description']) . '" data-price="' . e(number_format($service['price_cents'] / 100, 2, '.', '')) . '">' . e($service['name']) . ($service['active'] ? '' : ' (Inactive)') . '</option>';
    begin_page('Edit Invoice', $page, $invoice['number'] . ' · Update billing details and items.');
    echo '<div class="edit-toolbar"><a class="text-link" href="' . e(url('invoice', ['id' => $id])) . '">← Back to Invoice</a><span class="count-pill">' . e($invoice['number']) . '</span></div>';
    if ($invoice['recurrence_id']) echo '<div class="edit-note">These changes apply only to invoice ' . e($invoice['number']) . '. The recurring schedule will remain unchanged.</div>';
    echo '<form method="post" id="invoice-form" class="invoice-form">' . csrf_field() . '<input type="hidden" name="action" value="edit_invoice"><input type="hidden" name="invoice_id" value="' . $id . '"><input type="hidden" name="invoice_type" value="' . ($invoice['recurrence_id'] ? 'recurring' : 'one_time') . '"><div class="form-main">';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">01</span><div><h2>Billing Client</h2><p>Changing the mobile number links this invoice to the matching client account.</p></div></div><div class="form-grid"><label>Client Name <span>*</span><input name="client_name" required maxlength="150" value="' . e($old['client_name'] ?? '') . '" placeholder="Full name"></label><label>Mobile Number <span>*</span><input name="client_phone" inputmode="tel" required value="' . e($old['client_phone'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label class="field-wide">Company Name <small>(Optional)</small><input name="company_name" maxlength="150" value="' . e($old['company_name'] ?? '') . '" placeholder="Company Name"></label><label class="field-wide">Email <small>(Optional)</small><input type="email" name="client_email" value="' . e($old['client_email'] ?? '') . '" placeholder="client@example.com"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">02</span><div><h2>Dates and Type</h2><p>The invoice number and payment history will remain unchanged.</p></div></div><div class="edit-type-badge">' . ($invoice['recurrence_id'] ? icon('repeat', 18) . ' ' . frequency_label($invoice['frequency']) . ' Recurring Invoice' : icon('invoice', 18) . ' One-time Invoice') . '</div><div class="form-grid"><label>Issue Date <span>*</span><input type="date" name="issue_date" required value="' . e($old['issue_date'] ?? '') . '"></label><label>Due Date <span>*</span><input type="date" name="due_date" required value="' . e($old['due_date'] ?? '') . '"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">03</span><div><h2>Services and Items</h2><p>Add or remove items, and update quantities or prices.</p></div></div><div id="invoice-items">';
    $itemCount = max(1, count($old['item_name'] ?? []));
    for ($i = 0; $i < $itemCount; $i++) {
        $selectedService = (string)($old['item_service_id'][$i] ?? '');
        $options = str_replace('value="' . e($selectedService) . '"', 'value="' . e($selectedService) . '" selected', $serviceOptions);
        echo '<div class="line-item"><div class="line-item-top"><span class="item-index">Item ' . ($i + 1) . '</span><button type="button" class="remove-item" aria-label="Remove Item">Remove</button></div><div class="form-grid"><label class="field-wide">Catalog Service<select name="item_service_id[]" class="service-select">' . $options . '</select></label><label class="field-wide">Item Name <span>*</span><input name="item_name[]" class="item-name" required value="' . e($old['item_name'][$i] ?? '') . '" placeholder="Example: Website hosting"></label><label class="field-wide">Description <small>(Optional)</small><input name="item_description[]" class="item-description" value="' . e($old['item_description'][$i] ?? '') . '" placeholder="Service description"></label><label>Amount <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="' . e($old['item_qty'][$i] ?? '1') . '"></label><label>Price (BDT ) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" value="' . e($old['item_price'][$i] ?? '') . '" placeholder="0.00"></label></div></div>';
    }
    echo '</div><button type="button" id="add-item" class="btn btn-outline">' . icon('plus', 17) . ' Add Another Item</button></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">04</span><div><h2>Payment Method</h2><p>Show all active methods or one selected method.</p></div></div><label>Payment Method<select name="payment_method_id">' . payment_method_options(isset($old['payment_method_id']) ? (int)$old['payment_method_id'] : null) . '</select></label></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">05</span><div><h2>Additional Notes</h2><p>Add information to display on the invoice.</p></div></div><textarea name="notes" rows="3" placeholder="Payment instructions or other information">' . e($old['notes'] ?? '') . '</textarea></section></div>';
    echo '<aside class="form-aside"><div class="summary-card"><span class="eyebrow">EDIT INVOICE</span><h3>Change Summary</h3><div class="summary-row"><span>Items</span><strong id="summary-count">' . $itemCount . '</strong></div><div class="summary-total"><span>New Total</span><strong id="summary-total">' . format_money((int)$invoice['total_cents']) . '</strong></div><p>Previously collected: <strong>' . format_money((int)$invoice['paid_cents']) . '</strong>. The new total cannot be lower than this amount.</p><button class="btn btn-primary btn-wide" type="submit">Save Changes ' . icon('arrow', 18) . '</button><a class="btn btn-outline btn-wide edit-cancel" href="' . e(url('invoice', ['id' => $id])) . '">Cancel</a></div></aside></form>';
    echo '<template id="item-template"><div class="line-item"><div class="line-item-top"><span class="item-index">Item</span><button type="button" class="remove-item" aria-label="Remove Item">Remove</button></div><div class="form-grid"><label class="field-wide">Catalog Service<select name="item_service_id[]" class="service-select">' . $serviceOptions . '</select></label><label class="field-wide">Item Name <span>*</span><input name="item_name[]" class="item-name" required placeholder="Example: Website hosting"></label><label class="field-wide">Description <small>(Optional)</small><input name="item_description[]" class="item-description" placeholder="Service description"></label><label>Amount <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="1"></label><label>Price (BDT ) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" placeholder="0.00"></label></div></div></template>';
    end_page(); break;

case 'invoice':
case 'print':
case 'pdf':
case 'download':
    $id = (int)($_GET['id'] ?? 0);
    $invoice = invoice_rows('WHERE i.id = ?', [$id], 'i.id DESC', 1)[0] ?? null;
    if (!$invoice) { http_response_code(404); begin_page('Invoice not found', 'invoices'); echo '<div class="empty-state"><h2>Invoice not found</h2><a href="' . e(url('invoices')) . '">Go Back</a></div>'; end_page(); break; }
    $items = query_all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [$id]);
    $payments = query_all('SELECT * FROM payments WHERE invoice_id=? ORDER BY paid_at DESC, id DESC', [$id]);
    $paymentMethods = invoice_payment_methods($invoice);
    if (in_array($page, ['pdf', 'download'], true)) {
        require_once __DIR__ . '/pdf_invoice.php';
        session_write_close();
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $pdf = render_invoice_pdf($invoice, $items, $paymentMethods);
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
        }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$invoice['number']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
    if ($page === 'print') {
        render_invoice_print($invoice, $items, $paymentMethods);
        break;
    }
    $remaining = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
    $siteTitle = setting('site_title', 'Billflow');
    $invoiceLogo = uploaded_asset_url(setting('logo_path'));
    $contactDetails = array_values(array_filter([setting('mobile_number'), setting('email')], static fn($value) => $value !== ''));
    $contactText = implode(' · ', array_map('e', $contactDetails));
    $emailDelivery = query_one('SELECT * FROM email_deliveries WHERE invoice_id=?', [$id]);
    begin_page($invoice['number'], 'invoice', 'Invoice details, collections, and payment history.');
    echo '<div class="detail-actions"><a class="btn btn-outline" href="' . e(url('edit', ['id' => $id])) . '">' . icon('edit', 17) . ' Edit</a><a class="btn btn-outline" target="_blank" rel="noopener noreferrer" href="' . e(url('pdf', ['id' => $id])) . '">' . icon('print', 17) . ' PDF View</a></div>';
    if ($emailDelivery) {
        $emailLabels = ['pending' => 'Email Pending', 'sending' => 'Sending Email', 'sent' => 'Email Sent', 'failed' => 'Email Delivery Failed', 'skipped' => 'Email Not Sent'];
        $emailStatus = (string)$emailDelivery['status'];
        echo '<div class="email-delivery-status email-' . e($emailStatus) . '"><div><strong>' . e($emailLabels[$emailStatus] ?? $emailStatus) . '</strong><span>' . e($emailDelivery['recipient']) . ($emailDelivery['sent_at'] ? ' · ' . e(format_date(substr($emailDelivery['sent_at'], 0, 10))) : '') . '</span></div>';
        if (in_array($emailStatus, ['pending', 'failed'], true)) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="retry_invoice_email"><input type="hidden" name="invoice_id" value="' . $id . '"><button class="plain-link" type="submit">Send Mail</button></form>';
        echo '</div>';
    }
    echo '<div class="invoice-detail-grid"><section class="panel invoice-paper"><div class="paper-head"><div><div class="paper-brand-wrap">' . ($invoiceLogo !== '' ? '<img class="paper-logo" src="' . e($invoiceLogo) . '" alt="">' : '') . '<span class="paper-brand">' . e($siteTitle) . '<span>.</span></span></div><p>INVOICE</p></div><div class="paper-status">' . badge($invoice) . '<strong>' . e($invoice['number']) . '</strong></div></div><div class="paper-meta"><div><span>Bill To</span><strong>' . e($invoice['client_name']) . '</strong>' . ($invoice['billing_company_name'] !== '' ? '<p><strong>' . e($invoice['billing_company_name']) . '</strong></p>' : '') . '<p>' . e($invoice['client_phone']) . '</p>' . ($invoice['client_email'] !== '' ? '<p>' . e($invoice['client_email']) . '</p>' : '') . '</div><div><span>Issue Date</span><strong>' . e(format_date($invoice['issue_date'])) . '</strong><span class="meta-gap">Due Date</span><strong>' . e(format_date($invoice['due_date'])) . '</strong></div></div><div class="table-wrap"><table class="items-table"><thead><tr><th>Service / Item</th><th>Quantity</th><th>Price</th><th>Total</th></tr></thead><tbody>';
    foreach ($items as $item) echo '<tr><td><strong>' . e($item['name']) . '</strong>' . ($item['description'] ? '<small>' . e($item['description']) . '</small>' : '') . '</td><td>' . e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) . '</td><td>' . format_money((int)$item['unit_price_cents']) . '</td><td><strong>' . format_money((int)$item['total_cents']) . '</strong></td></tr>';
    echo '</tbody></table></div><div class="paper-totals"><div><span>Grand Total</span><strong>' . format_money((int)$invoice['total_cents']) . '</strong></div><div><span>Collection</span><strong>' . format_money(max(0,(int)$invoice['paid_cents']-(int)$invoice['discount_cents'])) . '</strong></div><div><span>Discount</span><strong>' . format_money((int)$invoice['discount_cents']) . '</strong></div><div class="balance"><span>Balance Due</span><strong>' . format_money($remaining) . '</strong></div></div>';
    foreach ($paymentMethods as $paymentMethod) {
        $methodQr = uploaded_asset_url($paymentMethod['qr_path']);
        echo '<div class="paper-payment"><div><strong>Payment Method: ' . e($paymentMethod['name']) . '</strong><p>' . e(['bank' => 'Bank', 'mfs' => 'Mobile Banking', 'card' => 'Card', 'other' => 'Other'][$paymentMethod['type']] ?? 'Other') . '</p>';
        foreach (['account_name' => 'Account Name', 'account_number' => 'Account Number', 'mobile_number' => 'Mobile', 'branch' => 'Branch / Routing'] as $field => $label) {
            if ($paymentMethod[$field] !== '') echo '<p><b>' . $label . ':</b> ' . e($paymentMethod[$field]) . '</p>';
        }
        if ($paymentMethod['instructions'] !== '') echo '<p>' . nl2br(e($paymentMethod['instructions'])) . '</p>';
        echo '</div>' . ($methodQr ? '<img src="' . e($methodQr) . '" alt="Payment QR">' : '') . '</div>';
    }
    if ($invoice['notes']) echo '<div class="paper-notes"><strong>Notes</strong><p>' . nl2br(e($invoice['notes'])) . '</p></div>';
    echo '<div class="paper-footer">Thank you! We appreciate your business.' . ($contactText !== '' ? '<br>' . $contactText : '') . '</div></section>';
    echo '<aside class="detail-aside"><section class="panel collection-card"><div class="aside-heading"><span class="stat-icon stat-icon-mint">' . icon('wallet', 21) . '</span><div><span class="eyebrow">PAYMENT COLLECTION</span><h3>Add Collection</h3></div></div>';
        if ($remaining > 0) {
            echo '<p>Balance Due: <strong>' . format_money($remaining) . '</strong>. Collect a partial or full payment.</p><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="collect_payment"><input type="hidden" name="invoice_id" value="' . $id . '"><label>Collection Amount (BDT)<input type="number" name="amount" required min="0.01" max="' . e(number_format($remaining / 100, 2, '.', '')) . '" step="0.01" placeholder="0.00"></label><label>Payment Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Date<input type="date" name="paid_at" required value="' . e(date('Y-m-d')) . '"></label><label>Transaction Reference <small>(Optional)</small><input name="reference" placeholder="Transaction ID"></label><label>Notes <small>(Optional)</small><textarea name="notes" rows="2" placeholder="Payment details"></textarea></label><button class="btn btn-primary btn-wide" type="submit">Save Collection</button></form>';
        } else echo '<div class="fully-paid">' . icon('check', 24) . '<strong>Fully Paid</strong><span>This invoice has no outstanding balance.</span></div>';
        echo '</section><section class="panel history-card"><div class="aside-heading"><span class="stat-icon stat-icon-blue">' . icon('clock', 21) . '</span><div><span class="eyebrow">TRANSACTIONS</span><h3>Payment History</h3></div></div>';
        if (!$payments) echo '<p class="muted">No collections have been recorded yet.</p>';
        else foreach ($payments as $payment) echo '<div class="payment-row"><span class="payment-check">' . icon('check', 15) . '</span><div><strong>' . format_money((int)$payment['amount_cents']) . '</strong>' . ((int)$payment['discount_cents'] > 0 ? '<small>Discount: ' . format_money((int)$payment['discount_cents']) . '</small>' : '') . '<small>' . e(format_date($payment['paid_at'])) . ' · ' . e(['cash'=>'Cash','bank'=>'Bank','bkash'=>'bKash','nagad'=>'Nagad','card'=>'Card','other'=>'Other'][$payment['method']] ?? $payment['method']) . '</small>' . ($payment['receipt_number'] ? '<small><a href="' . e(url('receipt',['number'=>$payment['receipt_number']])) . '">' . e($payment['receipt_number']) . '</a></small>' : '') . ($payment['reference'] ? '<small>#' . e($payment['reference']) . '</small>' : '') . '</div></div>';
        echo '</section></aside>';
    echo '</div>';
    end_page();
    break;

case 'receipt':
    $receiptNumber = trim((string)($_GET['number'] ?? ''));
    $receiptRows = $receiptNumber !== '' ? query_all('SELECT p.*, i.number invoice_number, i.billing_name, i.billing_phone, i.client_id FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.receipt_number=? ORDER BY p.id', [$receiptNumber]) : [];
    if (!$receiptRows) { http_response_code(404); begin_page('Money Receipt Not Found', $page); echo '<div class="empty-state"><h2>Money receipt not found</h2><a href="' . e(url('collections')) . '">Back to Collections</a></div>'; end_page(); break; }
    $receiptCash = array_sum(array_column($receiptRows, 'amount_cents'));
    $receiptDiscount = array_sum(array_column($receiptRows, 'discount_cents'));
    begin_page('Money Receipt', $page, $receiptNumber);
    echo '<div class="detail-actions"><button class="btn btn-outline" type="button" onclick="window.print()">' . icon('print',17) . ' Print Receipt</button></div><section class="panel receipt-sheet"><div class="receipt-head"><div><span class="eyebrow">MONEY RECEIPT</span><h2>' . e($receiptNumber) . '</h2><p>' . e($receiptRows[0]['billing_name']) . ' · ' . e($receiptRows[0]['billing_phone']) . '</p></div><div><strong>' . e(format_date($receiptRows[0]['paid_at'])) . '</strong><p>' . e(ucwords(str_replace('_',' ',$receiptRows[0]['method']))) . '</p></div></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Collection</th><th>Discount</th><th>Settled</th></tr></thead><tbody>';
    foreach ($receiptRows as $row) echo '<tr><td><a class="strong-link" href="' . e(url('invoice',['id'=>$row['invoice_id']])) . '">' . e($row['invoice_number']) . '</a></td><td>' . format_money((int)$row['amount_cents']) . '</td><td>' . format_money((int)$row['discount_cents']) . '<small>' . ($row['discount_scope'] ? e(ucwords(str_replace('_',' ',$row['discount_scope']))) : '—') . '</small></td><td><strong>' . format_money((int)$row['amount_cents']+(int)$row['discount_cents']) . '</strong></td></tr>';
    echo '</tbody></table></div><div class="paper-totals"><div><span>Total Collected</span><strong>' . format_money($receiptCash) . '</strong></div><div><span>Total Discount</span><strong>' . format_money($receiptDiscount) . '</strong></div><div class="balance"><span>Total Settled</span><strong>' . format_money($receiptCash+$receiptDiscount) . '</strong></div></div></section>';
    end_page(); break;

case 'client-ledger':
    $ledgerSearch = trim((string)($_GET['q'] ?? ''));
    $ledgerClientId = (int)($_GET['id'] ?? 0);
    $ledgerClients = $ledgerSearch !== '' ? search_clients($ledgerSearch, 30) : query_all('SELECT c.id,c.name,c.company_name,c.phone,COALESCE(c.email,\'\') email, MAX(p.paid_at) last_collection FROM clients c JOIN invoices i ON i.client_id=c.id JOIN payments p ON p.invoice_id=i.id GROUP BY c.id,c.name,c.company_name,c.phone,c.email ORDER BY last_collection DESC LIMIT 30');
    begin_page('Client Ledger', $page, 'Search clients and review invoice, collection, and discount history.');
    echo '<section class="panel"><div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="page" value="client-ledger">' . icon('search',18) . '<input name="q" value="' . e($ledgerSearch) . '" placeholder="Search by client name, mobile number, or email"><button type="submit">Search</button></form></div>';
    if ($ledgerClientId > 0) {
        $ledgerClient = query_one('SELECT * FROM clients WHERE id=?', [$ledgerClientId]);
        if (!$ledgerClient) { echo '<div class="empty-state"><p>Client not found.</p></div>'; }
        else {
            $ledgerInvoices = invoice_rows('WHERE i.client_id=?', [$ledgerClientId], 'i.issue_date ASC, i.id ASC', 1000);
            $billed = array_sum(array_column($ledgerInvoices,'total_cents')); $settled = array_sum(array_column($ledgerInvoices,'paid_cents')); $discounts = array_sum(array_column($ledgerInvoices,'discount_cents'));
            echo '<div class="panel-heading"><div><span class="eyebrow">LEDGER HISTORY</span><h2>' . e($ledgerClient['name']) . '</h2><p>' . e($ledgerClient['phone']) . ' · ' . e($ledgerClient['email'] ?: 'No email') . '</p></div><a class="btn btn-primary btn-sm" href="' . e(url('new',['client_id'=>$ledgerClientId])) . '">Create Invoice</a></div><div class="ledger-summary"><div><span>Total Billed</span><strong>' . format_money($billed) . '</strong></div><div><span>Collections</span><strong>' . format_money(max(0,$settled-$discounts)) . '</strong></div><div><span>Discounts</span><strong>' . format_money($discounts) . '</strong></div><div><span>Balance</span><strong>' . format_money(max(0,$billed-$settled)) . '</strong></div></div>'; invoice_table($ledgerInvoices);
        }
    } else {
        echo '<div class="panel-heading"><div><span class="eyebrow">RECENT COLLECTION CLIENTS</span><h2>Recently Collected Clients</h2></div></div><div class="table-wrap"><table><thead><tr><th>Client</th><th>Mobile</th><th>Email</th><th>Last Collection</th><th></th></tr></thead><tbody>';
        foreach ($ledgerClients as $client) echo '<tr><td><strong>' . e($client['name']) . '</strong><small>' . e($client['company_name'] ?? '') . '</small></td><td>' . e($client['phone']) . '</td><td>' . e($client['email'] ?: '—') . '</td><td>' . e(isset($client['last_collection']) ? format_date($client['last_collection']) : '—') . '</td><td><a class="btn btn-outline btn-sm" href="' . e(url('client-ledger',['id'=>$client['id']])) . '">See Ledger</a></td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</section>'; end_page(); break;

case 'payment-methods':
    $methods = query_all('SELECT * FROM payment_methods ORDER BY active DESC, id DESC');
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId ? query_one('SELECT * FROM payment_methods WHERE id=?', [$editId]) : false;
    $types = ['bank' => 'Bank', 'mfs' => 'Mobile Banking (MFS)', 'card' => 'Card', 'other' => 'Other'];
    begin_page('Payment Methods', $page, 'Manage bank, MFS, and other payment details with QR codes.');
    echo '<div class="two-column payment-method-layout"><section class="panel payment-channel-panel"><div class="panel-heading"><div><span class="eyebrow">PAYMENT CHANNELS</span><h2>Payment Methods</h2></div><span class="count-pill">' . count($methods) . ' </span></div>';
    if (!$methods) echo '<div class="empty-state">' . icon('wallet', 34) . '<h3>No payment methods found</h3><p>Use the form to add bank or MFS details and a QR code.</p></div>';
    else {
        echo '<div class="payment-method-list"><div class="payment-method-table-head"><span></span><span>Method</span><span>Account and Status</span><span>QR</span><span>Actions</span></div>';
        foreach ($methods as $method) {
            $qr = uploaded_asset_url($method['qr_path']);
            $account = $method['account_number'] ?: ($method['mobile_number'] ?: 'Account Number was not provided');
            echo '<div class="payment-method-row"><span class="service-icon">' . icon('wallet', 20) . '</span><div class="method-identity"><strong>' . e($method['name']) . '</strong><small>' . e($types[$method['type']] ?? 'Other') . '</small></div><div class="method-account"><strong>' . e($account) . '</strong><small>' . ($method['active'] ? 'Active' : 'Inactive') . ($qr ? ' · QR attached' : '') . '</small></div><div class="method-qr-slot">' . ($qr ? '<img class="method-qr-thumb" src="' . e($qr) . '" alt="' . e($method['name']) . ' QR">' : '<span>—</span>') . '</div><div class="method-actions"><a class="plain-link" href="' . e(url('payment-methods', ['edit' => $method['id']])) . '">Edit</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_payment_method"><input type="hidden" name="method_id" value="' . (int)$method['id'] . '"><button class="plain-link" type="submit">' . ($method['active'] ? 'Disable' : 'Enable') . '</button></form></div></div>';
        }
        echo '</div>';
    }
    echo '</section><div class="column-resizer" role="separator" aria-label="Resize payment method columns" aria-orientation="vertical" tabindex="0" data-payment-resizer><span></span></div><aside class="panel side-form"><span class="eyebrow">' . ($edit ? 'EDIT PAYMENT METHOD' : 'NEW PAYMENT METHOD') . '</span><h2>' . ($edit ? 'Edit Payment Method' : 'New Payment Method') . '</h2><p>The selected payment details will appear on the invoice print and PDF views.</p><form method="post" enctype="multipart/form-data" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_payment_method"><input type="hidden" name="method_id" value="' . (int)($edit['id'] ?? 0) . '"><label>Type <span>*</span><select name="type" required>';
    foreach ($types as $value => $label) echo '<option value="' . e($value) . '"' . (($edit['type'] ?? 'bank') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    echo '</select></label><label>Bank / MFS / Method Name <span>*</span><input name="name" required maxlength="120" value="' . e($edit['name'] ?? '') . '" placeholder="Example: Dutch-Bangla Bank / bKash"></label><label>Account Name<input name="account_name" maxlength="150" value="' . e($edit['account_name'] ?? '') . '" placeholder="Account holder name"></label><label>Account Number<input name="account_number" maxlength="150" value="' . e($edit['account_number'] ?? '') . '" placeholder="Bank account or wallet number"></label><label>Mobile Number<input name="mobile_number" type="tel" maxlength="150" value="' . e($edit['mobile_number'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label>Branch / Routing Information<input name="branch" maxlength="150" value="' . e($edit['branch'] ?? '') . '" placeholder="Branch or routing number"></label><label>Payment Instructions<textarea name="instructions" rows="3" maxlength="1000" placeholder="Please include the invoice number when making a payment.">' . e($edit['instructions'] ?? '') . '</textarea></label>';
    $qr = uploaded_asset_url((string)($edit['qr_path'] ?? ''));
    echo '<div class="upload-card method-upload"><div class="upload-card-head"><strong>Upload QR Code</strong><small>PNG, JPG, or WebP · Maximum 3 MB</small></div><div class="upload-preview" data-preview-box="qr"><img id="qr-preview" alt="QR preview"' . ($qr ? ' src="' . e($qr) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($qr ? ' hidden' : '') . '>' . icon('grid', 27) . '<small>QR preview</small></span></div><input type="file" name="qr_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-preview-target="qr-preview">' . ($qr ? '<label class="checkbox-line"><input type="checkbox" name="remove_qr" value="1" data-remove-image="qr-preview"> Remove Current QR Code</label>' : '') . '</div><button class="btn btn-primary btn-wide" type="submit">' . ($edit ? 'Save Changes' : 'Add Payment Method') . '</button>' . ($edit ? '<a class="btn btn-outline btn-wide" href="' . e(url('payment-methods')) . '">New Method</a>' : '') . '</form></aside></div>';
    end_page(); break;

case 'services':
    $services = query_all('SELECT * FROM services ORDER BY active DESC, id DESC');
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId ? query_one('SELECT * FROM services WHERE id=?', [$editId]) : false;
    begin_page('Services', $page, 'Create a service catalog and quickly add services to invoices.');
    echo '<div class="two-column"><section class="panel"><div class="panel-heading"><div><span class="eyebrow">SERVICE CATALOG</span><h2>All Services</h2></div><span class="count-pill">' . count($services) . ' </span></div>';
    if (!$services) echo '<div class="empty-state">' . icon('box', 34) . '<h3>No services found</h3><p>Use the form on the right to add your first service.</p></div>';
    else {
        echo '<div class="service-list">';
        foreach ($services as $service) echo '<div class="service-row"><span class="service-icon">' . icon('box', 20) . '</span><div class="service-info"><strong>' . e($service['name']) . '</strong><small>' . e($service['description'] ?: 'No description') . '</small></div><div class="service-end"><strong>' . format_money((int)$service['price_cents']) . '</strong><small>' . ($service['active'] ? 'Active' : 'Inactive') . '</small></div><a class="plain-link" href="' . e(url('services', ['edit' => $service['id']])) . '">Edit</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="' . (int)$service['id'] . '"><button class="plain-link" type="submit">' . ($service['active'] ? 'Disable' : 'Enable') . '</button></form></div>';
        echo '</div>';
    }
    echo '</section><aside class="panel side-form"><span class="eyebrow">' . ($edit ? 'EDIT SERVICE' : 'NEW SERVICE') . '</span><h2>' . ($edit ? 'Edit Service' : 'Add New Service') . '</h2><p>Catalog services can be added to invoices.</p><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_service"><input type="hidden" name="service_id" value="' . (int)($edit['id'] ?? 0) . '"><label>Service Name<input name="name" required value="' . e($edit['name'] ?? '') . '" placeholder="Example: Website hosting"></label><label>Description<textarea name="description" rows="3" placeholder="Short service description">' . e($edit['description'] ?? '') . '</textarea></label><label>Default Price (BDT)<input type="number" name="price" min="0" step="0.01" required value="' . e(isset($edit['price_cents']) ? number_format($edit['price_cents'] / 100, 2, '.', '') : '') . '" placeholder="0.00"></label><button class="btn btn-primary btn-wide" type="submit">' . ($edit ? 'Save Changes' : 'Add Service') . '</button></form></aside></div>';
    end_page(); break;

case 'clients':
    $search = trim((string)($_GET['q'] ?? ''));
    $oldClient = $_SESSION['old_client'] ?? []; unset($_SESSION['old_client']);
    $showAddClient = isset($_GET['add']) || $oldClient !== [];
    $clients = query_all('SELECT c.*, COUNT(DISTINCT i.id) invoice_count, COALESCE(SUM(i.total_cents),0) billed FROM clients c LEFT JOIN invoices i ON i.client_id = c.id ' . ($search ? 'WHERE c.name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? ' : '') . 'GROUP BY c.id ORDER BY c.id DESC LIMIT 500', $search ? ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%'] : []);
    begin_page('Clients', $page, 'Manage client accounts linked to mobile numbers.');
    if ($showAddClient) echo '<section class="panel form-panel client-create-panel"><div class="client-create-heading"><div><span class="eyebrow">NEW CLIENT</span><h2>Add New Client</h2><p>This client can be linked to invoices later.</p></div><a class="btn btn-outline btn-sm" href="' . e(url('clients')) . '">Close</a></div><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_client"><div class="form-grid"><label>Mobile Number <span>*</span><input name="client_phone" inputmode="tel" required value="' . e($oldClient['client_phone'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label>Email <small>(Optional)</small><input type="email" name="client_email" maxlength="190" value="' . e($oldClient['client_email'] ?? '') . '" placeholder="client@example.com"></label><label>Client Name <span>*</span><input name="client_name" maxlength="150" required value="' . e($oldClient['client_name'] ?? '') . '" placeholder="Full name"></label><label>Company Name <small>(Optional)</small><input name="company_name" maxlength="150" value="' . e($oldClient['company_name'] ?? '') . '" placeholder="Company Name"></label><label class="field-wide">Address <small>(Optional)</small><textarea name="client_address" rows="2" maxlength="300" placeholder="Client address">' . e($oldClient['client_address'] ?? '') . '</textarea></label></div><div class="client-create-actions"><button class="btn btn-primary" type="submit">' . icon('plus', 17) . ' Add Client</button></div></form></section>';
    echo '<section class="panel"><div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="page" value="clients">' . icon('search', 18) . '<input name="q" value="' . e($search) . '" placeholder="Name, Company, Mobile or Email Search"><button type="submit">Search</button></form><div class="client-toolbar-actions"><span class="count-pill">' . count($clients) . '  clients</span><a class="btn btn-primary btn-sm" href="' . e(url('clients', ['add' => 1])) . '">' . icon('plus', 16) . ' New Client</a></div></div>';
    if (!$clients) echo '<div class="empty-state">' . icon('users', 34) . '<h3>No clients found</h3><p>A client account will be created automatically when an invoice is created.</p></div>';
    else { echo '<div class="table-wrap"><table><thead><tr><th>Client</th><th>Company Name</th><th>Mobile</th><th>Email</th><th>Invoice</th><th>Total Billed</th><th></th></tr></thead><tbody>'; foreach ($clients as $client) echo '<tr><td><a class="strong-link" href="' . e(url('client', ['id' => $client['id']])) . '">' . e($client['name']) . '</a><small>Account #' . (int)$client['id'] . '</small></td><td>' . e($client['company_name'] ?: '—') . '</td><td>' . e($client['phone']) . '</td><td>' . e($client['email'] ?: '—') . '</td><td>' . (int)$client['invoice_count'] . '</td><td><strong>' . format_money((int)$client['billed']) . '</strong></td><td><div class="row-actions"><a class="row-edit" href="' . e(url('client', ['id' => $client['id'], 'edit' => 1])) . '">Edit</a><a class="row-arrow" href="' . e(url('client', ['id' => $client['id']])) . '" aria-label="View Client">' . icon('chevron', 18) . '</a></div></td></tr>'; echo '</tbody></table></div>'; }
    echo '</section>'; end_page(); break;

case 'client':
    $id = (int)($_GET['id'] ?? 0);
    $client = query_one('SELECT * FROM clients WHERE id=?', [$id]);
    if (!$client) { http_response_code(404); redirect('clients'); }
    $rows = invoice_rows('WHERE i.client_id=?', [$id], 'i.id DESC', 500);
    $recurrenceCount = (int)(query_one('SELECT COUNT(*) total FROM recurrences WHERE client_id=?', [$id])['total'] ?? 0);
    $oldEditClient = $_SESSION['old_edit_client'] ?? []; unset($_SESSION['old_edit_client']);
    $editValues = ((int)($oldEditClient['id'] ?? 0) === $id) ? (array)($oldEditClient['values'] ?? []) : [];
    $showEditClient = isset($_GET['edit']) || $editValues !== [];
    begin_page($client['name'], $page, 'Client profile and invoice records.');
    if ($showEditClient) {
        $value = static fn(string $field, string $column): string => (string)($editValues[$field] ?? $client[$column] ?? '');
        echo '<section class="panel form-panel client-create-panel"><div class="client-create-heading"><div><span class="eyebrow">EDIT CLIENT</span><h2>Client Information</h2><p>Updated information will be used when creating the next invoice.</p></div><a class="btn btn-outline btn-sm" href="' . e(url('client', ['id' => $id])) . '">Close</a></div><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="update_client"><input type="hidden" name="client_id" value="' . $id . '"><div class="form-grid"><label>Mobile Number <span>*</span><input name="client_phone" inputmode="tel" required value="' . e($value('client_phone', 'phone')) . '"></label><label>Email <small>(Optional)</small><input type="email" name="client_email" maxlength="190" value="' . e($value('client_email', 'email')) . '"></label><label>Client Name <span>*</span><input name="client_name" maxlength="150" required value="' . e($value('client_name', 'name')) . '"></label><label>Company Name <small>(Optional)</small><input name="company_name" maxlength="150" value="' . e($value('company_name', 'company_name')) . '"></label><label class="field-wide">Address <small>(Optional)</small><textarea name="client_address" rows="2" maxlength="300">' . e($value('client_address', 'address')) . '</textarea></label></div><div class="client-create-actions"><button class="btn btn-primary" type="submit">Save Changes</button></div></form></section>';
    }
    echo '<div class="client-card panel"><span class="client-avatar">' . e(mb_substr($client['name'], 0, 1)) . '</span><div class="client-card-copy"><h2>' . e($client['name']) . '</h2>' . ($client['company_name'] !== '' ? '<p><strong>' . e($client['company_name']) . '</strong></p>' : '') . ($client['address'] !== '' ? '<p>' . nl2br(e($client['address'])) . '</p>' : '') . '<p>' . e($client['phone']) . ' · ' . e($client['email'] ?: 'No email provided') . '</p><small>Account created ' . e(format_date(substr($client['created_at'], 0, 10))) . '</small></div><div class="client-card-actions"><span class="count-pill">' . count($rows) . ' invoices</span><a class="btn btn-primary btn-sm" href="' . e(url('new', ['client_id' => $id])) . '">Create Invoice</a><a class="btn btn-outline btn-sm" href="' . e(url('client', ['id' => $id, 'edit' => 1])) . '">Edit</a><form method="post" onsubmit="return confirm(\'Permanently delete this client?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete_client"><input type="hidden" name="client_id" value="' . $id . '"><button class="btn btn-danger btn-sm" type="submit"' . ($rows || $recurrenceCount > 0 ? ' disabled title="Invoices or recurring schedules are linked, so this client cannot be deleted"' : '') . '>Delete</button></form></div></div><section class="panel"><div class="panel-heading"><div><span class="eyebrow">CLIENT INVOICES</span><h2>Invoice History</h2></div></div>'; invoice_table($rows); echo '</section>';
    end_page(); break;

case 'recurring':
    $schedules = query_all('SELECT r.*, c.name client_name, c.phone client_phone, COALESCE((SELECT SUM(ROUND(quantity * unit_price_cents)) FROM recurrence_items ri WHERE ri.recurrence_id=r.id),0) amount_cents, (SELECT COUNT(*) FROM invoices i WHERE i.recurrence_id=r.id) invoice_count FROM recurrences r JOIN clients c ON c.id=r.client_id ORDER BY r.id DESC');
    begin_page('Recurring', $page, 'Manage automated billing schedules and upcoming invoices.');
    echo '<section class="panel"><div class="panel-heading"><div><span class="eyebrow">AUTOMATED BILLING</span><h2>Recurring schedule</h2></div><span class="count-pill">' . count($schedules) . ' </span></div>';
    if (!$schedules) echo '<div class="empty-state">' . icon('repeat', 34) . '<h3>No recurring schedules</h3><p>Choose Recurring Payment when creating a new invoice.</p><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 17) . ' Create Invoice</a></div>';
    else { echo '<div class="table-wrap"><table><thead><tr><th>Client</th><th>Billing Cycle</th><th>Amount</th><th>Next Invoice</th><th>Status</th><th></th></tr></thead><tbody>'; foreach ($schedules as $schedule) { $cycleOptions=''; foreach (billing_frequency_options() as $value=>$label) $cycleOptions.='<option value="'.e($value).'"'.($schedule['frequency']===$value?' selected':'').'>'.e($label).'</option>'; echo '<tr><td><strong>' . e($schedule['client_name']) . '</strong><small>' . e($schedule['client_phone']) . '</small></td><td><form method="post" class="inline-cycle-form">' . csrf_field() . '<input type="hidden" name="action" value="update_recurrence_frequency"><input type="hidden" name="recurrence_id" value="' . (int)$schedule['id'] . '"><select name="frequency">'.$cycleOptions.'</select><button type="submit" class="plain-link">Update</button></form><small>' . (int)$schedule['invoice_count'] . ' invoices created</small></td><td><strong>' . format_money((int)$schedule['amount_cents']) . '</strong></td><td>' . e(format_date($schedule['next_issue_date'])) . '</td><td><span class="badge ' . ($schedule['status'] === 'active' ? 'badge-paid' : 'badge-unpaid') . '"><span class="badge-dot"></span>' . ($schedule['status'] === 'active' ? 'Active' : 'Paused') . '</span></td><td><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_recurrence"><input type="hidden" name="recurrence_id" value="' . (int)$schedule['id'] . '"><button type="submit" class="plain-link">' . ($schedule['status'] === 'active' ? 'Pause' : 'Enable') . '</button></form></td></tr>'; } echo '</tbody></table></div>'; }
    echo '<div class="panel-footnote">A new invoice is created on the next scheduled date. Keep cron.php enabled for background generation.</div></section>';
    end_page(); break;

case 'settings':
    $tab = (string)($_GET['tab'] ?? 'basic');
    $tabs = ['basic' => 'Basic Settings', 'smtp' => 'SMTP'];
    if (!isset($tabs[$tab])) $tab = 'basic';
    begin_page('Settings', $page, 'Manage site information and email server settings.');
    echo '<div class="settings-tabs" role="tablist" aria-label="Settings sections">';
    foreach ($tabs as $key => $label) {
        echo '<a role="tab" aria-selected="' . ($tab === $key ? 'true' : 'false') . '" class="' . ($tab === $key ? 'selected' : '') . '" href="' . e(url('settings', ['tab' => $key])) . '">' . e($label) . '</a>';
    }
    echo '</div><div class="settings-shell">';
    if ($tab === 'basic') {
        $logo = uploaded_asset_url(setting('logo_path'));
        $favicon = uploaded_asset_url(setting('favicon_path'));
        $signature = uploaded_asset_url(setting('signature_path'));
        echo '<section class="panel settings-panel"><div class="section-title"><span class="step">01</span><div><h2>Basic Settings</h2><p>Site identity and contact information.</p></div></div><form method="post" enctype="multipart/form-data" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_basic_settings"><div class="form-grid"><label class="field-wide">Site Title <span>*</span><input name="site_title" required maxlength="80" value="' . e(setting('site_title', 'Billflow')) . '" placeholder="Your site name"></label><label class="field-wide">Slogan<input name="slogan" maxlength="200" value="' . e(setting('slogan', 'Keep every invoice and collection in one place.')) . '" placeholder="Short slogan"></label><label>Mobile Number<input name="mobile_number" type="tel" maxlength="25" value="' . e(setting('mobile_number')) . '" placeholder="+880 1XXXXXXXXX"></label><label>Email<input name="site_email" type="email" value="' . e(setting('email')) . '" placeholder="hello@example.com"></label></div>';
        echo '<div class="form-grid settings-extra"><label class="field-wide">Office Address <small>(Optional)</small><textarea name="site_address" rows="2" maxlength="300" placeholder="Address displayed on invoices">' . e(setting('address')) . '</textarea></label><label class="field-wide">Website <small>(Optional)</small><input name="site_website" type="url" maxlength="200" value="' . e(setting('website')) . '" placeholder="https://example.com"></label></div>';
        echo '<div class="invoice-display-controls"><div><strong>PDF Invoice Header</strong><small>Choose which identity elements appear on PDF invoices.</small></div><label class="checkbox-line"><input type="checkbox" name="pdf_show_logo" value="1"' . (setting('pdf_show_logo', '1') === '1' ? ' checked' : '') . '> Show Logo</label><label class="checkbox-line"><input type="checkbox" name="pdf_show_title" value="1"' . (setting('pdf_show_title', '1') === '1' ? ' checked' : '') . '> Show Site Title</label><label class="checkbox-line"><input type="checkbox" name="pdf_show_slogan" value="1"' . (setting('pdf_show_slogan', '1') === '1' ? ' checked' : '') . '> Show Slogan</label></div>';
        echo '<div class="upload-grid"><div class="upload-card"><div class="upload-card-head"><strong>Logo</strong><small>PNG, JPG, or WebP · Maximum 3 MB</small></div><div class="upload-preview" data-preview-box="logo"><img id="logo-preview" alt="Logo preview"' . ($logo !== '' ? ' src="' . e($logo) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($logo !== '' ? ' hidden' : '') . '>' . icon('invoice', 29) . '<small>Logo preview</small></span></div><label for="logo-file">Upload Logo</label><input id="logo-file" type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-preview-target="logo-preview"><p class="upload-hint">Choose a file to preview it, then save your changes.</p>' . ($logo !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_logo" value="1" data-remove-image="logo-preview"> Remove Current Logo</label>' : '') . '</div>';
        echo '<div class="upload-card"><div class="upload-card-head"><strong>Favicon</strong><small>PNG, WebP, or ICO · Maximum 1 MB</small></div><div class="upload-preview favicon-preview" data-preview-box="favicon"><img id="favicon-preview" alt="Favicon preview"' . ($favicon !== '' ? ' src="' . e($favicon) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($favicon !== '' ? ' hidden' : '') . '>' . icon('grid', 27) . '<small>Favicon preview</small></span></div><label for="favicon-file">Upload Favicon</label><input id="favicon-file" type="file" name="favicon_file" accept=".png,.webp,.ico,image/png,image/webp,image/x-icon,image/vnd.microsoft.icon" data-preview-target="favicon-preview"><p class="upload-hint">Displayed as the browser tab icon.</p>' . ($favicon !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_favicon" value="1" data-remove-image="favicon-preview"> Remove Current Favicon</label>' : '') . '</div>';
        echo '<div class="upload-card"><div class="upload-card-head"><strong>Authorized Signature</strong><small>PNG, JPG, or WebP · Maximum 3 MB</small></div><div class="upload-preview signature-preview" data-preview-box="signature"><img id="signature-preview" alt="Signature preview"' . ($signature !== '' ? ' src="' . e($signature) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($signature !== '' ? ' hidden' : '') . '>' . icon('invoice', 27) . '<small>Signature preview</small></span></div><label for="signature-file">Upload Signature</label><input id="signature-file" type="file" name="signature_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-preview-target="signature-preview"><p class="upload-hint">The signature will appear above the signature line on invoices.</p>' . ($signature !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_signature" value="1" data-remove-image="signature-preview"> Remove Current Signature</label>' : '') . '</div></div>';
        echo '<div class="settings-actions"><button class="btn btn-primary" type="submit">Save Changes ' . icon('arrow', 17) . '</button></div></form></section>';
        echo '<aside class="settings-tip panel"><span class="settings-tip-icon">' . icon('grid', 22) . '</span><h3>Site Identity</h3><p>The site title appears in the sidebar, login page, and invoices. The slogan and contact details can also appear on invoices.</p></aside>';
    } else {
        $encryption = setting('smtp_encryption', 'tls');
        echo '<section class="panel settings-panel"><div class="section-title"><span class="step">02</span><div><h2>SMTP Settings</h2><p>These settings send invoice PDFs to clients automatically.</p></div></div><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_smtp_settings"><div class="form-grid"><label class="field-wide">SMTP Host<input name="smtp_host" maxlength="255" value="' . e(setting('smtp_host')) . '" placeholder="smtp.example.com"></label><label>Port<input name="smtp_port" type="number" min="1" max="65535" required value="' . e(setting('smtp_port', '587')) . '"></label><label>Encryption<select name="smtp_encryption"><option value="none" ' . ($encryption === 'none' ? 'selected' : '') . '>None</option><option value="tls" ' . ($encryption === 'tls' ? 'selected' : '') . '>TLS / STARTTLS</option><option value="ssl" ' . ($encryption === 'ssl' ? 'selected' : '') . '>SSL</option></select></label><label class="field-wide">Username<input name="smtp_username" maxlength="255" autocomplete="off" value="' . e(setting('smtp_username')) . '" placeholder="SMTP username"></label><label class="field-wide">Password<input name="smtp_password" type="password" autocomplete="new-password" placeholder="' . (setting('smtp_password') !== '' ? 'Saved · enter a new password to change it' : 'SMTP password') . '"></label>';
        if (setting('smtp_password') !== '') echo '<label class="checkbox-line field-wide"><input type="checkbox" name="smtp_clear_password" value="1"> Remove Saved Password</label>';
        echo '<label>From Name<input name="smtp_from_name" maxlength="150" value="' . e(setting('smtp_from_name', setting('site_title', 'Billflow'))) . '" placeholder="Sender name"></label><label>From Email<input name="smtp_from_email" type="email" value="' . e(setting('smtp_from_email', setting('email'))) . '" placeholder="billing@example.com"></label></div><div class="settings-actions"><button class="btn btn-primary" type="submit">Save SMTP Settings ' . icon('arrow', 17) . '</button></div></form></section>';
        echo '<aside class="settings-tip panel"><span class="settings-tip-icon">' . icon('settings', 22) . '</span><h3>Automatic Invoice Email</h3><p>When a client has a valid email address, new and recurring invoice PDFs are sent automatically. The scheduled task retries failed deliveries up to three times.</p></aside>';
    }
    echo '</div>';
    end_page(); break;

default:
    http_response_code(404);
    begin_page('Page not found', $page);
    echo '<div class="empty-state"><h2>Page not found</h2><a href="' . e(url('dashboard')) . '">Back to Dashboard</a></div>';
    end_page();
}
