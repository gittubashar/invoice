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
function frequency_label(?string $value): string { return ['monthly' => 'মাসিক', 'quarterly' => 'ত্রৈমাসিক', 'yearly' => 'বার্ষিক'][$value ?? ''] ?? 'এককালীন'; }
function status_label(string $value): string { return ['paid' => 'পরিশোধিত', 'partial' => 'আংশিক', 'overdue' => 'মেয়াদোত্তীর্ণ', 'unpaid' => 'অপরিশোধিত'][$value] ?? $value; }
function format_date(?string $date): string { return $date ? date('d M Y', strtotime($date)) : '—'; }
function plural_count(int $number, string $label): string { return number_format($number) . ' ' . $label; }

$hasAdmin = (bool)query_one('SELECT id FROM admins LIMIT 1');
$page = (string)($_GET['page'] ?? 'dashboard');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('সেশন শেষ হয়েছে। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'login' && $hasAdmin) {
            $admin = query_one('SELECT * FROM admins WHERE email = ?', [strtolower(trim((string)($_POST['email'] ?? '')))]);
            if (!$admin || !password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
                throw new InvalidArgumentException('ইমেইল বা পাসওয়ার্ড সঠিক নয়।');
            }
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            redirect('dashboard');
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
            if ($siteTitle === '' || mb_strlen($siteTitle) > 80) throw new InvalidArgumentException('Site Title ১ থেকে ৮০ অক্ষরের মধ্যে লিখুন।');
            if (mb_strlen($slogan) > 200) throw new InvalidArgumentException('Slogan ২০০ অক্ষরের মধ্যে লিখুন।');
            if ($mobile !== '' && (mb_strlen($mobile) > 25 || !preg_match('/^[+0-9()\-\s]+$/', $mobile))) throw new InvalidArgumentException('সঠিক মোবাইল নম্বর লিখুন।');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('সঠিক ইমেইল লিখুন।');
            if (mb_strlen($address) > 300) throw new InvalidArgumentException('ঠিকানা ৩০০ অক্ষরের মধ্যে লিখুন।');
            if ($website !== '' && (mb_strlen($website) > 200 || !filter_var($website, FILTER_VALIDATE_URL) || !in_array(parse_url($website, PHP_URL_SCHEME), ['http', 'https'], true))) throw new InvalidArgumentException('Website এর সম্পূর্ণ http বা https লিংক লিখুন।');
            $oldLogo = setting('logo_path');
            $oldFavicon = setting('favicon_path');
            $created = [];
            try {
                $newLogo = store_uploaded_image($_FILES['logo_file'] ?? null, 'logo');
                if ($newLogo !== null) $created[] = $newLogo;
                $newFavicon = store_uploaded_image($_FILES['favicon_file'] ?? null, 'favicon');
                if ($newFavicon !== null) $created[] = $newFavicon;
                $logoPath = $newLogo ?? (!empty($_POST['remove_logo']) ? '' : $oldLogo);
                $faviconPath = $newFavicon ?? (!empty($_POST['remove_favicon']) ? '' : $oldFavicon);
                save_settings(['site_title' => $siteTitle, 'slogan' => $slogan, 'mobile_number' => $mobile, 'email' => $email, 'address' => $address, 'website' => $website, 'logo_path' => $logoPath, 'favicon_path' => $faviconPath]);
                if ($oldLogo !== $logoPath) delete_uploaded_asset($oldLogo);
                if ($oldFavicon !== $faviconPath) delete_uploaded_asset($oldFavicon);
            } catch (Throwable $error) {
                foreach ($created as $path) delete_uploaded_asset($path);
                throw $error;
            }
            flash('success', 'Basic Settings সংরক্ষণ করা হয়েছে।');
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
            if (mb_strlen($host) > 255 || preg_match('/[\r\n]/', $host)) throw new InvalidArgumentException('সঠিক SMTP Host লিখুন।');
            if ($port === false || $port < 1 || $port > 65535) throw new InvalidArgumentException('SMTP Port ১ থেকে ৬৫৫৩৫ এর মধ্যে লিখুন।');
            if (mb_strlen($username) > 255 || mb_strlen($password) > 500 || mb_strlen($fromName) > 150) throw new InvalidArgumentException('SMTP তথ্য অনেক বড়।');
            if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) throw new InvalidArgumentException('সঠিক Encryption নির্বাচন করুন।');
            if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('সঠিক From Email লিখুন।');
            $values = ['smtp_host' => $host, 'smtp_port' => (string)$port, 'smtp_username' => $username, 'smtp_encryption' => $encryption, 'smtp_from_name' => $fromName, 'smtp_from_email' => $fromEmail];
            if (!empty($_POST['smtp_clear_password'])) $values['smtp_password'] = '';
            elseif ($password !== '') $values['smtp_password'] = encrypt_smtp_password($password);
            save_settings($values);
            flash('success', 'SMTP Settings সংরক্ষণ করা হয়েছে।');
            redirect('settings', ['tab' => 'smtp']);
        }
        if ($action === 'save_payment_method') {
            $id = (int)($_POST['method_id'] ?? 0);
            $existing = $id ? query_one('SELECT * FROM payment_methods WHERE id=?', [$id]) : false;
            if ($id && !$existing) throw new InvalidArgumentException('পেমেন্ট মেথড পাওয়া যায়নি।');
            $type = (string)($_POST['type'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $accountName = trim((string)($_POST['account_name'] ?? ''));
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            $mobile = trim((string)($_POST['mobile_number'] ?? ''));
            $branch = trim((string)($_POST['branch'] ?? ''));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            if (!in_array($type, ['bank', 'mfs', 'card', 'other'], true)) throw new InvalidArgumentException('পেমেন্ট মেথডের ধরন নির্বাচন করুন।');
            if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('পেমেন্ট মেথডের নাম ১ থেকে ১২০ অক্ষরের মধ্যে লিখুন।');
            foreach ([$accountName, $accountNumber, $mobile, $branch] as $value) {
                if (mb_strlen($value) > 150) throw new InvalidArgumentException('পেমেন্ট তথ্য ১৫০ অক্ষরের মধ্যে লিখুন।');
            }
            if (mb_strlen($instructions) > 1000) throw new InvalidArgumentException('পেমেন্ট নির্দেশনা ১০০০ অক্ষরের মধ্যে লিখুন।');
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
            flash('success', 'পেমেন্ট মেথড সংরক্ষণ করা হয়েছে।');
            redirect('payment-methods');
        }
        if ($action === 'toggle_payment_method') {
            $id = (int)($_POST['method_id'] ?? 0);
            $stmt = db()->prepare('UPDATE payment_methods SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?');
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) throw new InvalidArgumentException('পেমেন্ট মেথড পাওয়া যায়নি।');
            flash('success', 'পেমেন্ট মেথডের অবস্থা পরিবর্তন হয়েছে।');
            redirect('payment-methods');
        }
        if ($action === 'create_invoice') {
            $id = create_invoice($_POST);
            flash('success', 'ইনভয়েস তৈরি এবং সংরক্ষণ করা হয়েছে।');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'edit_invoice') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            edit_invoice($id, $_POST);
            flash('success', 'ইনভয়েসের পরিবর্তন সংরক্ষণ করা হয়েছে।');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'collect_payment') {
            $id = (int)($_POST['invoice_id'] ?? 0);
            collect_payment($id, (string)($_POST['amount'] ?? ''), (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['notes'] ?? ''), (string)($_POST['paid_at'] ?? ''));
            flash('success', 'কালেকশন সংরক্ষণ করা হয়েছে।');
            redirect('invoice', ['id' => $id]);
        }
        if ($action === 'save_service') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = money_cents((string)($_POST['price'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('সার্ভিসের নাম লিখুন।');
            $id = (int)($_POST['service_id'] ?? 0);
            if ($id) {
                db()->prepare('UPDATE services SET name=?, description=?, price_cents=? WHERE id=?')->execute([$name, $description, $price, $id]);
            } else {
                db()->prepare('INSERT INTO services (name,description,price_cents) VALUES (?,?,?)')->execute([$name, $description, $price]);
            }
            flash('success', 'সার্ভিস সংরক্ষণ করা হয়েছে।');
            redirect('services');
        }
        if ($action === 'toggle_service') {
            db()->prepare('UPDATE services SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?')->execute([(int)($_POST['service_id'] ?? 0)]);
            flash('success', 'সার্ভিসের অবস্থা পরিবর্তন হয়েছে।');
            redirect('services');
        }
        if ($action === 'toggle_recurrence') {
            $id = (int)($_POST['recurrence_id'] ?? 0);
            db()->prepare("UPDATE recurrences SET status = CASE status WHEN 'active' THEN 'paused' ELSE 'active' END WHERE id=?")->execute([$id]);
            flash('success', 'Recurring schedule আপডেট হয়েছে।');
            redirect('recurring');
        }
        throw new InvalidArgumentException('অনুরোধটি সঠিক নয়।');
    } catch (Throwable $error) {
        flash('error', $error instanceof PDOException ? 'ডেটা সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।' : $error->getMessage());
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
        if ($action === 'login') redirect('login');
        if ($action === 'save_service' || $action === 'toggle_service') redirect('services');
        if ($action === 'toggle_recurrence') redirect('recurring');
        if ($action === 'save_basic_settings') redirect('settings', ['tab' => 'basic']);
        if ($action === 'save_smtp_settings') redirect('settings', ['tab' => 'smtp']);
        if ($action === 'save_payment_method' || $action === 'toggle_payment_method') redirect('payment-methods', ['edit' => (int)($_POST['method_id'] ?? 0)]);
        redirect('dashboard');
    }
}

if (empty($_SESSION['admin_id'])) $page = 'login';
elseif (!in_array($page, ['print', 'download'], true)) generate_due_invoices();

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

function begin_page(string $title, string $page, string $subtitle = ''): void
{
    $admin = query_one('SELECT name, email, role FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
    $links = [
        ['dashboard', 'ওভারভিউ', 'grid'], ['invoices', 'ইনভয়েস', 'invoice'],
        ['recurring', 'রিকারিং', 'repeat'], ['clients', 'ক্লায়েন্ট', 'users'], ['services', 'সার্ভিস', 'box'],
        ['payment-methods', 'Payment Method', 'wallet'],
        ['settings', 'Dashboard Settings', 'settings'],
    ];
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan', 'সব ইনভয়েস ও কালেকশন এক জায়গায় রাখুন।');
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123637"><title>' . e($title) . ' · ' . e($siteTitle) . '</title>' . favicon_tag() . '<link rel="stylesheet" href="assets/app.css"></head><body><div class="app-shell">';
    echo '<aside class="sidebar" id="sidebar"><div class="brand">' . brand_mark(23) . '<span class="brand-name">' . e($siteTitle) . '<span class="brand-dot">.</span><small>INVOICE STUDIO</small></span></div>';
    echo '<div class="nav-caption">WORKSPACE</div><nav class="nav-links">';
    foreach ($links as [$target, $label, $symbol]) {
        $active = $page === $target || (in_array($page, ['invoice', 'new', 'edit'], true) && $target === 'invoices') || ($page === 'client' && $target === 'clients');
        echo '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(url($target)) . '">' . icon($symbol) . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="sidebar-note"><span class="note-icon">✦</span><strong>সহজ বিলিং, পরিষ্কার হিসাব</strong><p>' . e($slogan) . '</p></div><div class="profile"><span class="avatar">' . e(mb_substr($admin['name'] ?? 'A', 0, 1)) . '</span><span class="profile-copy"><strong>' . e($admin['name'] ?? 'Admin') . '</strong><small>' . (($admin['role'] ?? '') === 'super_admin' ? 'সুপার অ্যাডমিন' : 'অ্যাডমিন') . '</small></span><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="logout"><button type="submit" title="লগআউট" class="icon-button">' . icon('logout', 18) . '</button></form></div></div></aside>';
    echo '<div class="mobile-backdrop" data-close-menu></div><main class="main"><header class="topbar"><button type="button" class="mobile-menu icon-button" data-menu-toggle aria-label="মেনু খুলুন">' . icon('menu') . '</button><span class="topbar-path">Workspace <span>/</span> <strong>' . e($title) . '</strong></span><div class="topbar-right"><span class="today-label">' . e(date('d M Y')) . '</span><a class="btn btn-primary btn-sm" href="' . e(url('new')) . '">' . icon('plus', 17) . ' নতুন ইনভয়েস</a></div></header><div class="content">';
    if ($page !== 'dashboard') echo '<div class="page-heading"><div><p class="eyebrow">BILLFLOW / ' . strtoupper(e($page)) . '</p><h1>' . e($title) . '</h1>' . ($subtitle ? '<p>' . e($subtitle) . '</p>' : '') . '</div></div>';
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
}

function end_page(): void { echo '</div></main></div><script src="assets/app.js"></script></body></html>'; }

function badge(array $invoice): string
{
    $status = invoice_status($invoice);
    return '<span class="badge badge-' . $status . '"><span class="badge-dot"></span>' . status_label($status) . '</span>';
}

function payment_method_options(?int $selectedId = null): string
{
    $options = '<option value="">সব সক্রিয় পেমেন্ট মেথড (ডিফল্ট)</option>';
    $methods = query_all('SELECT id, type, name, active FROM payment_methods WHERE active=1 OR id=? ORDER BY active DESC, name', [$selectedId ?? 0]);
    foreach ($methods as $method) {
        $label = $method['name'] . ' · ' . ['bank' => 'ব্যাংক', 'mfs' => 'MFS', 'card' => 'কার্ড', 'other' => 'অন্যান্য'][$method['type']];
        if (!(int)$method['active']) $label .= ' (নিষ্ক্রিয়)';
        $options .= '<option value="' . (int)$method['id'] . '"' . ((int)$method['id'] === $selectedId ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $options;
}

function invoice_table(array $invoices): void
{
    if (!$invoices) { echo '<div class="empty-state">' . icon('invoice', 34) . '<h3>এখনো কোনো ইনভয়েস নেই</h3><p>প্রথম ইনভয়েস তৈরি করলে এখানে দেখা যাবে।</p><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 17) . ' ইনভয়েস তৈরি করুন</a></div>'; return; }
    echo '<div class="table-wrap"><table><thead><tr><th>ইনভয়েস</th><th>ক্লায়েন্ট</th><th>ইস্যু / ডিউ</th><th>পরিমাণ</th><th>অবস্থা</th><th></th></tr></thead><tbody>';
    foreach ($invoices as $invoice) {
        echo '<tr><td><a class="strong-link" href="' . e(url('invoice', ['id' => $invoice['id']])) . '">' . e($invoice['number']) . '</a><small>' . ($invoice['recurrence_id'] ? '↻ ' . frequency_label($invoice['frequency']) : 'এককালীন') . '</small></td><td><strong>' . e($invoice['client_name']) . '</strong><small>' . e($invoice['client_phone']) . '</small></td><td>' . e(format_date($invoice['issue_date'])) . '<small>ডিউ ' . e(format_date($invoice['due_date'])) . '</small></td><td><strong>' . format_money((int)$invoice['total_cents']) . '</strong><small>বকেয়া ' . format_money(max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents'])) . '</small></td><td>' . badge($invoice) . '</td><td><div class="row-actions"><a class="row-download" href="' . e(url('download', ['id' => $invoice['id']])) . '" aria-label="ইনভয়েস PDF ডাউনলোড করুন">PDF ডাউনলোড</a><a class="row-edit" href="' . e(url('edit', ['id' => $invoice['id']])) . '">এডিট</a><a class="row-arrow" href="' . e(url('invoice', ['id' => $invoice['id']])) . '" aria-label="ইনভয়েস দেখুন">' . icon('chevron', 18) . '</a></div></td></tr>';
    }
    echo '</tbody></table></div>';
}

if ($page === 'login') {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan', 'ইনভয়েস, ক্লায়েন্ট, কালেকশন আর নিয়মিত বিল—সবকিছু একটি সহজ জায়গায়।');
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>লগইন · ' . e($siteTitle) . '</title>' . favicon_tag() . '<link rel="stylesheet" href="assets/app.css"></head><body class="auth-body"><div class="auth-art"><div class="auth-brand">' . brand_mark(24) . '<span>' . e($siteTitle) . '<span class="brand-dot">.</span></span></div><div class="auth-art-content"><span class="art-chip">✦ SMART INVOICING</span><h1>আপনার বিলিং,<br><em>একদম গুছিয়ে।</em></h1><p>' . e($slogan) . '</p><div class="art-lines"><div></div><div></div><div></div></div></div><small>© ' . date('Y') . ' ' . e($siteTitle) . '</small></div><div class="auth-main"><div class="auth-card"><span class="auth-kicker">WELCOME TO BILLFLOW</span><h2>আবার স্বাগতম</h2><p>আপনার অ্যাডমিন অ্যাকাউন্টে লগইন করুন।</p>';
    if ($flash) echo '<div class="alert alert-' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    echo '<form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="login"><label>ইমেইল<input type="email" name="email" autocomplete="email" required placeholder="you@company.com"></label><label>পাসওয়ার্ড<input type="password" name="password" autocomplete="current-password" required placeholder="আপনার পাসওয়ার্ড"></label><button class="btn btn-primary btn-wide" type="submit">লগইন করুন ' . icon('arrow', 18) . '</button></form></div></div></body></html>';
    exit;
}

switch ($page) {
case 'dashboard':
    $invoices = invoice_rows('', [], 'i.id DESC', 6);
    $stats = query_one('SELECT COUNT(*) invoice_count, COALESCE(SUM(total_cents),0) billed FROM invoices');
    $payments = query_one('SELECT COALESCE(SUM(amount_cents),0) collected FROM payments');
    $outstanding = (int)$stats['billed'] - (int)$payments['collected'];
    $activeSchedules = query_one("SELECT COUNT(*) total FROM recurrences WHERE status='active'");
    $monthlyCounts = array_column(query_all("SELECT substr(issue_date, 1, 7) month, COUNT(*) total FROM invoices GROUP BY substr(issue_date, 1, 7)"), 'total', 'month');
    $chartMonths = [];
    for ($offset = 8; $offset >= 0; $offset--) {
        $month = (new DateTimeImmutable('first day of this month'))->modify('-' . $offset . ' months')->format('Y-m');
        $chartMonths[$month] = (int)($monthlyCounts[$month] ?? 0);
    }
    $chartMax = max(1, ...array_values($chartMonths));
    $chart = '';
    foreach ($chartMonths as $month => $count) {
        $chart .= '<i title="' . e($month . ': ' . $count . ' invoice(s)') . '" style="height:' . max(4, (int)round($count / $chartMax * 40)) . 'px"></i>';
    }
    begin_page('ওভারভিউ', $page);
    echo '<section class="hero"><div><span class="hero-kicker">✦ YOUR BILLING WORKSPACE</span><h1>সব হিসাব, <em>এক নজরে।</em></h1><p>আজ ' . e(format_date(date('Y-m-d'))) . ' · আপনার ব্যবসার বিলিং আপডেট এখানে।</p><a class="btn btn-light" href="' . e(url('new')) . '">' . icon('plus', 18) . ' নতুন ইনভয়েস তৈরি করুন</a></div><div class="hero-art"><div class="hero-art-card"><span>মোট ইনভয়েস</span><strong>' . e(plural_count((int)$stats['invoice_count'], 'ইনভয়েস')) . '</strong><div class="fake-chart">' . $chart . '</div><small>গত ৯ মাসের ইনভয়েস</small></div><div class="hero-orbit"></div></div></section>';
    echo '<div class="stats-grid"><div class="stat-card"><span class="stat-icon stat-icon-mint">' . icon('invoice', 22) . '</span><span class="stat-label">মোট ইনভয়েস</span><strong>' . number_format((int)$stats['invoice_count']) . '</strong><small>সব সময়ের হিসাব</small></div><div class="stat-card"><span class="stat-icon stat-icon-blue">' . icon('wallet', 22) . '</span><span class="stat-label">মোট কালেকশন</span><strong>' . format_money((int)$payments['collected']) . '</strong><small>পরিশোধিত অর্থ</small></div><div class="stat-card"><span class="stat-icon stat-icon-peach">' . icon('clock', 22) . '</span><span class="stat-label">বকেয়া</span><strong>' . format_money($outstanding) . '</strong><small>কালেকশন বাকি</small></div><div class="stat-card"><span class="stat-icon stat-icon-purple">' . icon('repeat', 22) . '</span><span class="stat-label">সক্রিয় রিকারিং</span><strong>' . number_format((int)$activeSchedules['total']) . '</strong><small>চলমান schedule</small></div></div>';
    echo '<section class="panel"><div class="panel-heading"><div><span class="eyebrow">RECENT ACTIVITY</span><h2>সাম্প্রতিক ইনভয়েস</h2></div><a class="text-link" href="' . e(url('invoices')) . '">সব ইনভয়েস দেখুন ' . icon('arrow', 17) . '</a></div>';
    invoice_table($invoices);
    echo '</section>';
    end_page(); break;

case 'invoices':
    $search = trim((string)($_GET['q'] ?? ''));
    $filter = (string)($_GET['filter'] ?? 'all');
    $where = ''; $params = [];
    if ($search !== '') { $where = 'WHERE (i.number LIKE ? OR i.billing_name LIKE ? OR i.billing_phone LIKE ? OR i.billing_company_name LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)'; $params = array_fill(0, 6, '%' . $search . '%'); }
    $rows = invoice_rows($where, $params, 'i.id DESC', 500);
    if (in_array($filter, ['paid','partial','overdue','unpaid'], true)) $rows = array_values(array_filter($rows, fn($row) => invoice_status($row) === $filter));
    begin_page('ইনভয়েস', $page, 'সব ইনভয়েস, পেমেন্ট ও বকেয়ার হিসাব।');
    echo '<section class="panel"><div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="page" value="invoices">' . icon('search', 18) . '<input name="q" value="' . e($search) . '" placeholder="ইনভয়েস, নাম, কোম্পানি বা মোবাইল খুঁজুন"><button type="submit">খুঁজুন</button></form><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 18) . ' নতুন ইনভয়েস</a></div><div class="filter-tabs">';
    foreach (['all' => 'সব', 'unpaid' => 'অপরিশোধিত', 'partial' => 'আংশিক', 'overdue' => 'মেয়াদোত্তীর্ণ', 'paid' => 'পরিশোধিত'] as $key => $label) echo '<a class="' . ($filter === $key ? 'selected' : '') . '" href="' . e(url('invoices', ['q' => $search, 'filter' => $key])) . '">' . $label . '</a>';
    echo '</div>'; invoice_table($rows); echo '</section>';
    end_page(); break;

case 'new':
    $old = $_SESSION['old_invoice'] ?? []; unset($_SESSION['old_invoice']);
    $services = query_all('SELECT * FROM services WHERE active=1 ORDER BY name');
    $serviceOptions = '<option value="">ম্যানুয়ালি লিখুন</option>';
    foreach ($services as $service) $serviceOptions .= '<option value="' . (int)$service['id'] . '" data-name="' . e($service['name']) . '" data-description="' . e($service['description']) . '" data-price="' . e(number_format($service['price_cents'] / 100, 2, '.', '')) . '">' . e($service['name']) . '</option>';
    begin_page('নতুন ইনভয়েস', $page, 'ক্লায়েন্ট, সার্ভিস ও বিলিং সময়কাল দিয়ে ইনভয়েস তৈরি করুন।');
    echo '<form method="post" id="invoice-form" class="invoice-form">' . csrf_field() . '<input type="hidden" name="action" value="create_invoice"><div class="form-main"><section class="panel form-panel"><div class="section-title"><span class="step">01</span><div><h2>ক্লায়েন্ট তথ্য</h2><p>মোবাইল নম্বর দিয়ে ক্লায়েন্ট অ্যাকাউন্ট তৈরি বা খুঁজে নেয়া হবে।</p></div></div><div class="form-grid"><label>ক্লায়েন্টের নাম <span>*</span><input name="client_name" required value="' . e($old['client_name'] ?? '') . '" placeholder="পূর্ণ নাম"></label><label>মোবাইল নম্বর <span>*</span><input name="client_phone" inputmode="tel" required value="' . e($old['client_phone'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label class="field-wide">Company Name <small>(ঐচ্ছিক)</small><input name="company_name" maxlength="150" value="' . e($old['company_name'] ?? '') . '" placeholder="কোম্পানির নাম"></label><label class="field-wide">ইমেইল <small>(ঐচ্ছিক)</small><input type="email" name="client_email" value="' . e($old['client_email'] ?? '') . '" placeholder="client@example.com"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">02</span><div><h2>বিলিং সেটিংস</h2><p>এককালীন বা স্বয়ংক্রিয় recurring ইনভয়েস নির্বাচন করুন।</p></div></div><div class="type-choice"><label><input type="radio" name="invoice_type" value="one_time" ' . (($old['invoice_type'] ?? 'one_time') === 'one_time' ? 'checked' : '') . '><span class="type-card"><strong>এককালীন পেমেন্ট</strong><small>এই ইনভয়েস শুধু একবার তৈরি হবে</small></span></label><label><input type="radio" name="invoice_type" value="recurring" ' . (($old['invoice_type'] ?? '') === 'recurring' ? 'checked' : '') . '><span class="type-card"><strong>রিকারিং পেমেন্ট</strong><small>নির্ধারিত সময় পর আবার তৈরি হবে</small></span></label></div><div class="form-grid"><label>ইস্যুর তারিখ <span>*</span><input type="date" name="issue_date" required value="' . e($old['issue_date'] ?? date('Y-m-d')) . '"></label><label>পরিশোধের শেষ তারিখ <span>*</span><input type="date" name="due_date" required value="' . e($old['due_date'] ?? add_days(date('Y-m-d'), 7)) . '"></label><label class="field-wide recurring-field">রিকারিং সময়কাল<select name="frequency"><option value="monthly" ' . (($old['frequency'] ?? 'monthly') === 'monthly' ? 'selected' : '') . '>প্রতি মাসে</option><option value="quarterly" ' . (($old['frequency'] ?? '') === 'quarterly' ? 'selected' : '') . '>প্রতি ৩ মাসে</option><option value="yearly" ' . (($old['frequency'] ?? '') === 'yearly' ? 'selected' : '') . '>প্রতি বছরে</option></select></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">03</span><div><h2>সার্ভিস ও আইটেম</h2><p>ক্যাটালগ থেকে বাছুন অথবা ম্যানুয়ালি লিখুন।</p></div></div><div id="invoice-items">';
    $itemCount = max(1, count($old['item_name'] ?? []));
    for ($i = 0; $i < $itemCount; $i++) {
        echo '<div class="line-item"><div class="line-item-top"><span class="item-index">আইটেম ' . ($i + 1) . '</span><button type="button" class="remove-item" aria-label="আইটেম সরান">সরান</button></div><div class="form-grid"><label class="field-wide">ক্যাটালগ সার্ভিস<select name="item_service_id[]" class="service-select">' . str_replace('value="' . e((string)($old['item_service_id'][$i] ?? '')) . '"', 'value="' . e((string)($old['item_service_id'][$i] ?? '')) . '" selected', $serviceOptions) . '</select></label><label class="field-wide">আইটেমের নাম <span>*</span><input name="item_name[]" class="item-name" required value="' . e($old['item_name'][$i] ?? '') . '" placeholder="যেমন: Website hosting"></label><label class="field-wide">বিবরণ <small>(ঐচ্ছিক)</small><input name="item_description[]" class="item-description" value="' . e($old['item_description'][$i] ?? '') . '" placeholder="সার্ভিসের বিবরণ"></label><label>পরিমাণ <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="' . e($old['item_qty'][$i] ?? '1') . '"></label><label>দর (৳) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" value="' . e($old['item_price'][$i] ?? '') . '" placeholder="0.00"></label></div></div>';
    }
    echo '</div><button type="button" id="add-item" class="btn btn-outline">' . icon('plus', 17) . ' আরেকটি আইটেম</button></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">04</span><div><h2>পেমেন্ট মেথড</h2><p>ডিফল্টভাবে সব সক্রিয় মেথড দেখাবে; চাইলে শুধু একটি বাছুন।</p></div></div><label>পেমেন্ট মেথড<select name="payment_method_id">' . payment_method_options(isset($old['payment_method_id']) ? (int)$old['payment_method_id'] : null) . '</select></label><p class="form-help">মেথড যোগ বা পরিবর্তন করতে <a href="' . e(url('payment-methods')) . '" target="_blank" rel="noopener noreferrer">Payment Method</a> খুলুন।</p></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">05</span><div><h2>অতিরিক্ত নোট</h2><p>ইনভয়েসে দেখানোর জন্য প্রয়োজনীয় তথ্য।</p></div></div><textarea name="notes" rows="3" placeholder="পেমেন্ট নির্দেশনা বা অন্যান্য তথ্য">' . e($old['notes'] ?? '') . '</textarea></section></div>';
    echo '<aside class="form-aside"><div class="summary-card"><span class="eyebrow">INVOICE SUMMARY</span><h3>ইনভয়েস সারাংশ</h3><div class="summary-row"><span>আইটেম</span><strong id="summary-count">1</strong></div><div class="summary-total"><span>সর্বমোট</span><strong id="summary-total">৳0</strong></div><p>সেভ করার পর ইনভয়েস নম্বর তৈরি হবে।</p><button class="btn btn-primary btn-wide" type="submit">ইনভয়েস তৈরি করুন ' . icon('arrow', 18) . '</button></div></aside></form>';
    echo '<template id="item-template"><div class="line-item"><div class="line-item-top"><span class="item-index">আইটেম</span><button type="button" class="remove-item" aria-label="আইটেম সরান">সরান</button></div><div class="form-grid"><label class="field-wide">ক্যাটালগ সার্ভিস<select name="item_service_id[]" class="service-select">' . $serviceOptions . '</select></label><label class="field-wide">আইটেমের নাম <span>*</span><input name="item_name[]" class="item-name" required placeholder="যেমন: Website hosting"></label><label class="field-wide">বিবরণ <small>(ঐচ্ছিক)</small><input name="item_description[]" class="item-description" placeholder="সার্ভিসের বিবরণ"></label><label>পরিমাণ <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="1"></label><label>দর (৳) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" placeholder="0.00"></label></div></div></template>';
    end_page(); break;

case 'edit':
    $id = (int)($_GET['id'] ?? 0);
    $invoice = invoice_rows('WHERE i.id = ?', [$id], 'i.id DESC', 1)[0] ?? null;
    if (!$invoice) { http_response_code(404); begin_page('ইনভয়েস পাওয়া যায়নি', 'edit'); echo '<div class="empty-state"><h2>ইনভয়েস পাওয়া যায়নি</h2><a href="' . e(url('invoices')) . '">ইনভয়েস তালিকায় ফিরুন</a></div>'; end_page(); break; }
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
    $serviceOptions = '<option value="">ম্যানুয়ালি লিখুন</option>';
    foreach ($services as $service) $serviceOptions .= '<option value="' . (int)$service['id'] . '" data-name="' . e($service['name']) . '" data-description="' . e($service['description']) . '" data-price="' . e(number_format($service['price_cents'] / 100, 2, '.', '')) . '">' . e($service['name']) . ($service['active'] ? '' : ' (নিষ্ক্রিয়)') . '</option>';
    begin_page('ইনভয়েস এডিট', $page, $invoice['number'] . ' · বিলিং তথ্য ও আইটেম পরিবর্তন করুন।');
    echo '<div class="edit-toolbar"><a class="text-link" href="' . e(url('invoice', ['id' => $id])) . '">← ইনভয়েসে ফিরুন</a><span class="count-pill">' . e($invoice['number']) . '</span></div>';
    if ($invoice['recurrence_id']) echo '<div class="edit-note">এই পরিবর্তন শুধু ' . e($invoice['number']) . ' ইনভয়েসে প্রযোজ্য হবে। পরবর্তী recurring schedule অপরিবর্তিত থাকবে।</div>';
    echo '<form method="post" id="invoice-form" class="invoice-form">' . csrf_field() . '<input type="hidden" name="action" value="edit_invoice"><input type="hidden" name="invoice_id" value="' . $id . '"><input type="hidden" name="invoice_type" value="' . ($invoice['recurrence_id'] ? 'recurring' : 'one_time') . '"><div class="form-main">';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">01</span><div><h2>বিলিং ক্লায়েন্ট</h2><p>মোবাইল নম্বর বদলালে ইনভয়েসটি সেই ক্লায়েন্ট অ্যাকাউন্টে যুক্ত হবে।</p></div></div><div class="form-grid"><label>ক্লায়েন্টের নাম <span>*</span><input name="client_name" required maxlength="150" value="' . e($old['client_name'] ?? '') . '" placeholder="পূর্ণ নাম"></label><label>মোবাইল নম্বর <span>*</span><input name="client_phone" inputmode="tel" required value="' . e($old['client_phone'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label class="field-wide">Company Name <small>(ঐচ্ছিক)</small><input name="company_name" maxlength="150" value="' . e($old['company_name'] ?? '') . '" placeholder="কোম্পানির নাম"></label><label class="field-wide">ইমেইল <small>(ঐচ্ছিক)</small><input type="email" name="client_email" value="' . e($old['client_email'] ?? '') . '" placeholder="client@example.com"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">02</span><div><h2>তারিখ ও ধরন</h2><p>ইনভয়েস নম্বর ও পেমেন্ট ইতিহাস অপরিবর্তিত থাকবে।</p></div></div><div class="edit-type-badge">' . ($invoice['recurrence_id'] ? icon('repeat', 18) . ' ' . frequency_label($invoice['frequency']) . ' রিকারিং ইনভয়েস' : icon('invoice', 18) . ' এককালীন ইনভয়েস') . '</div><div class="form-grid"><label>ইস্যুর তারিখ <span>*</span><input type="date" name="issue_date" required value="' . e($old['issue_date'] ?? '') . '"></label><label>পরিশোধের শেষ তারিখ <span>*</span><input type="date" name="due_date" required value="' . e($old['due_date'] ?? '') . '"></label></div></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">03</span><div><h2>সার্ভিস ও আইটেম</h2><p>আইটেম যোগ, সরানো বা পরিমাণ ও দর পরিবর্তন করুন।</p></div></div><div id="invoice-items">';
    $itemCount = max(1, count($old['item_name'] ?? []));
    for ($i = 0; $i < $itemCount; $i++) {
        $selectedService = (string)($old['item_service_id'][$i] ?? '');
        $options = str_replace('value="' . e($selectedService) . '"', 'value="' . e($selectedService) . '" selected', $serviceOptions);
        echo '<div class="line-item"><div class="line-item-top"><span class="item-index">আইটেম ' . ($i + 1) . '</span><button type="button" class="remove-item" aria-label="আইটেম সরান">সরান</button></div><div class="form-grid"><label class="field-wide">ক্যাটালগ সার্ভিস<select name="item_service_id[]" class="service-select">' . $options . '</select></label><label class="field-wide">আইটেমের নাম <span>*</span><input name="item_name[]" class="item-name" required value="' . e($old['item_name'][$i] ?? '') . '" placeholder="যেমন: Website hosting"></label><label class="field-wide">বিবরণ <small>(ঐচ্ছিক)</small><input name="item_description[]" class="item-description" value="' . e($old['item_description'][$i] ?? '') . '" placeholder="সার্ভিসের বিবরণ"></label><label>পরিমাণ <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="' . e($old['item_qty'][$i] ?? '1') . '"></label><label>দর (৳) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" value="' . e($old['item_price'][$i] ?? '') . '" placeholder="0.00"></label></div></div>';
    }
    echo '</div><button type="button" id="add-item" class="btn btn-outline">' . icon('plus', 17) . ' আরেকটি আইটেম</button></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">04</span><div><h2>পেমেন্ট মেথড</h2><p>সব সক্রিয় মেথড অথবা একটি নির্দিষ্ট মেথড দেখান।</p></div></div><label>পেমেন্ট মেথড<select name="payment_method_id">' . payment_method_options(isset($old['payment_method_id']) ? (int)$old['payment_method_id'] : null) . '</select></label></section>';
    echo '<section class="panel form-panel"><div class="section-title"><span class="step">05</span><div><h2>অতিরিক্ত নোট</h2><p>ইনভয়েসে দেখানোর তথ্য পরিবর্তন করুন।</p></div></div><textarea name="notes" rows="3" placeholder="পেমেন্ট নির্দেশনা বা অন্যান্য তথ্য">' . e($old['notes'] ?? '') . '</textarea></section></div>';
    echo '<aside class="form-aside"><div class="summary-card"><span class="eyebrow">EDIT INVOICE</span><h3>পরিবর্তনের সারাংশ</h3><div class="summary-row"><span>আইটেম</span><strong id="summary-count">' . $itemCount . '</strong></div><div class="summary-total"><span>নতুন মোট</span><strong id="summary-total">' . format_money((int)$invoice['total_cents']) . '</strong></div><p>আগে কালেকশন: <strong>' . format_money((int)$invoice['paid_cents']) . '</strong>। নতুন মোট এর কম হতে পারবে না।</p><button class="btn btn-primary btn-wide" type="submit">পরিবর্তন সেভ করুন ' . icon('arrow', 18) . '</button><a class="btn btn-outline btn-wide edit-cancel" href="' . e(url('invoice', ['id' => $id])) . '">বাতিল করুন</a></div></aside></form>';
    echo '<template id="item-template"><div class="line-item"><div class="line-item-top"><span class="item-index">আইটেম</span><button type="button" class="remove-item" aria-label="আইটেম সরান">সরান</button></div><div class="form-grid"><label class="field-wide">ক্যাটালগ সার্ভিস<select name="item_service_id[]" class="service-select">' . $serviceOptions . '</select></label><label class="field-wide">আইটেমের নাম <span>*</span><input name="item_name[]" class="item-name" required placeholder="যেমন: Website hosting"></label><label class="field-wide">বিবরণ <small>(ঐচ্ছিক)</small><input name="item_description[]" class="item-description" placeholder="সার্ভিসের বিবরণ"></label><label>পরিমাণ <span>*</span><input type="number" name="item_qty[]" class="item-qty" required min="0.01" step="0.01" value="1"></label><label>দর (৳) <span>*</span><input type="number" name="item_price[]" class="item-price" required min="0" step="0.01" placeholder="0.00"></label></div></div></template>';
    end_page(); break;

case 'invoice':
case 'print':
case 'download':
    $id = (int)($_GET['id'] ?? 0);
    $invoice = invoice_rows('WHERE i.id = ?', [$id], 'i.id DESC', 1)[0] ?? null;
    if (!$invoice) { http_response_code(404); begin_page('ইনভয়েস পাওয়া যায়নি', 'invoices'); echo '<div class="empty-state"><h2>ইনভয়েস পাওয়া যায়নি</h2><a href="' . e(url('invoices')) . '">ফিরে যান</a></div>'; end_page(); break; }
    $items = query_all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [$id]);
    $payments = query_all('SELECT * FROM payments WHERE invoice_id=? ORDER BY paid_at DESC, id DESC', [$id]);
    $paymentMethods = invoice_payment_methods($invoice);
    if ($page === 'download') {
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
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
    begin_page($invoice['number'], 'invoice', 'ইনভয়েস বিস্তারিত, কালেকশন ও পেমেন্ট ইতিহাস।');
    echo '<div class="detail-actions"><a class="btn btn-outline" href="' . e(url('edit', ['id' => $id])) . '">' . icon('edit', 17) . ' এডিট করুন</a><a class="btn btn-outline" href="' . e(url('download', ['id' => $id])) . '">' . icon('download', 17) . ' PDF ডাউনলোড</a></div>';
    echo '<div class="invoice-detail-grid"><section class="panel invoice-paper"><div class="paper-head"><div><div class="paper-brand-wrap">' . ($invoiceLogo !== '' ? '<img class="paper-logo" src="' . e($invoiceLogo) . '" alt="">' : '') . '<span class="paper-brand">' . e($siteTitle) . '<span>.</span></span></div><p>INVOICE</p></div><div class="paper-status">' . badge($invoice) . '<strong>' . e($invoice['number']) . '</strong></div></div><div class="paper-meta"><div><span>বিল করা হয়েছে</span><strong>' . e($invoice['client_name']) . '</strong>' . ($invoice['billing_company_name'] !== '' ? '<p><strong>' . e($invoice['billing_company_name']) . '</strong></p>' : '') . '<p>' . e($invoice['client_phone']) . '</p>' . ($invoice['client_email'] !== '' ? '<p>' . e($invoice['client_email']) . '</p>' : '') . '</div><div><span>ইস্যুর তারিখ</span><strong>' . e(format_date($invoice['issue_date'])) . '</strong><span class="meta-gap">পরিশোধের শেষ তারিখ</span><strong>' . e(format_date($invoice['due_date'])) . '</strong></div></div><div class="table-wrap"><table class="items-table"><thead><tr><th>সার্ভিস / আইটেম</th><th>পরিমাণ</th><th>দর</th><th>মোট</th></tr></thead><tbody>';
    foreach ($items as $item) echo '<tr><td><strong>' . e($item['name']) . '</strong>' . ($item['description'] ? '<small>' . e($item['description']) . '</small>' : '') . '</td><td>' . e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) . '</td><td>' . format_money((int)$item['unit_price_cents']) . '</td><td><strong>' . format_money((int)$item['total_cents']) . '</strong></td></tr>';
    echo '</tbody></table></div><div class="paper-totals"><div><span>সর্বমোট</span><strong>' . format_money((int)$invoice['total_cents']) . '</strong></div><div><span>কালেকশন</span><strong>' . format_money((int)$invoice['paid_cents']) . '</strong></div><div class="balance"><span>বকেয়া</span><strong>' . format_money($remaining) . '</strong></div></div>';
    foreach ($paymentMethods as $paymentMethod) {
        $methodQr = uploaded_asset_url($paymentMethod['qr_path']);
        echo '<div class="paper-payment"><div><strong>পেমেন্ট মেথড: ' . e($paymentMethod['name']) . '</strong><p>' . e(['bank' => 'ব্যাংক', 'mfs' => 'মোবাইল ব্যাংকিং', 'card' => 'কার্ড', 'other' => 'অন্যান্য'][$paymentMethod['type']] ?? 'অন্যান্য') . '</p>';
        foreach (['account_name' => 'অ্যাকাউন্টের নাম', 'account_number' => 'অ্যাকাউন্ট নম্বর', 'mobile_number' => 'মোবাইল', 'branch' => 'শাখা / রাউটিং'] as $field => $label) {
            if ($paymentMethod[$field] !== '') echo '<p><b>' . $label . ':</b> ' . e($paymentMethod[$field]) . '</p>';
        }
        if ($paymentMethod['instructions'] !== '') echo '<p>' . nl2br(e($paymentMethod['instructions'])) . '</p>';
        echo '</div>' . ($methodQr ? '<img src="' . e($methodQr) . '" alt="পেমেন্ট QR">' : '') . '</div>';
    }
    if ($invoice['notes']) echo '<div class="paper-notes"><strong>নোট</strong><p>' . nl2br(e($invoice['notes'])) . '</p></div>';
    echo '<div class="paper-footer">ধন্যবাদ! আপনার সাথে কাজ করতে পেরে আমরা আনন্দিত।' . ($contactText !== '' ? '<br>' . $contactText : '') . '</div></section>';
    echo '<aside class="detail-aside"><section class="panel collection-card"><div class="aside-heading"><span class="stat-icon stat-icon-mint">' . icon('wallet', 21) . '</span><div><span class="eyebrow">PAYMENT COLLECTION</span><h3>কালেকশন যোগ করুন</h3></div></div>';
        if ($remaining > 0) {
            echo '<p>বকেয়া <strong>' . format_money($remaining) . '</strong>। আংশিক বা সম্পূর্ণ পরিমাণ সংগ্রহ করুন।</p><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="collect_payment"><input type="hidden" name="invoice_id" value="' . $id . '"><label>কালেকশনের পরিমাণ (৳)<input type="number" name="amount" required min="0.01" max="' . e(number_format($remaining / 100, 2, '.', '')) . '" step="0.01" placeholder="0.00"></label><label>পেমেন্ট পদ্ধতি<select name="method" required><option value="cash">ক্যাশ</option><option value="bank">ব্যাংক ট্রান্সফার</option><option value="bkash">বিকাশ</option><option value="nagad">নগদ</option><option value="card">কার্ড</option><option value="other">অন্যান্য</option></select></label><label>তারিখ<input type="date" name="paid_at" required value="' . e(date('Y-m-d')) . '"></label><label>ট্রানজেকশন রেফারেন্স <small>(ঐচ্ছিক)</small><input name="reference" placeholder="Transaction ID"></label><label>নোট <small>(ঐচ্ছিক)</small><textarea name="notes" rows="2" placeholder="পেমেন্টের বিবরণ"></textarea></label><button class="btn btn-primary btn-wide" type="submit">কালেকশন সেভ করুন</button></form>';
        } else echo '<div class="fully-paid">' . icon('check', 24) . '<strong>সম্পূর্ণ পরিশোধিত</strong><span>এই ইনভয়েসের কোনো বকেয়া নেই।</span></div>';
        echo '</section><section class="panel history-card"><div class="aside-heading"><span class="stat-icon stat-icon-blue">' . icon('clock', 21) . '</span><div><span class="eyebrow">TRANSACTIONS</span><h3>পেমেন্ট ইতিহাস</h3></div></div>';
        if (!$payments) echo '<p class="muted">এখনো কোনো কালেকশন নেই।</p>';
        else foreach ($payments as $payment) echo '<div class="payment-row"><span class="payment-check">' . icon('check', 15) . '</span><div><strong>' . format_money((int)$payment['amount_cents']) . '</strong><small>' . e(format_date($payment['paid_at'])) . ' · ' . e(['cash'=>'ক্যাশ','bank'=>'ব্যাংক','bkash'=>'বিকাশ','nagad'=>'নগদ','card'=>'কার্ড','other'=>'অন্যান্য'][$payment['method']] ?? $payment['method']) . '</small>' . ($payment['reference'] ? '<small>#' . e($payment['reference']) . '</small>' : '') . '</div></div>';
        echo '</section></aside>';
    echo '</div>';
    end_page();
    break;

case 'payment-methods':
    $methods = query_all('SELECT * FROM payment_methods ORDER BY active DESC, id DESC');
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId ? query_one('SELECT * FROM payment_methods WHERE id=?', [$editId]) : false;
    $types = ['bank' => 'ব্যাংক', 'mfs' => 'মোবাইল ব্যাংকিং (MFS)', 'card' => 'কার্ড', 'other' => 'অন্যান্য'];
    begin_page('Payment Method', $page, 'ব্যাংক, MFS এবং অন্যান্য পেমেন্ট তথ্য ও QR কোড পরিচালনা করুন।');
    echo '<div class="two-column payment-method-layout"><section class="panel"><div class="panel-heading"><div><span class="eyebrow">PAYMENT CHANNELS</span><h2>পেমেন্ট মেথডসমূহ</h2></div><span class="count-pill">' . count($methods) . ' টি</span></div>';
    if (!$methods) echo '<div class="empty-state">' . icon('wallet', 34) . '<h3>পেমেন্ট মেথড নেই</h3><p>ডান পাশের ফর্মে ব্যাংক বা MFS তথ্য এবং QR কোড যোগ করুন।</p></div>';
    else {
        echo '<div class="payment-method-list">';
        foreach ($methods as $method) {
            $qr = uploaded_asset_url($method['qr_path']);
            echo '<div class="payment-method-row"><span class="service-icon">' . icon('wallet', 20) . '</span><div class="service-info"><strong>' . e($method['name']) . '</strong><small>' . e($types[$method['type']] ?? 'অন্যান্য') . ' · ' . e($method['account_number'] ?: ($method['mobile_number'] ?: 'অ্যাকাউন্ট নম্বর দেওয়া হয়নি')) . '</small><small>' . ($method['active'] ? 'সক্রিয়' : 'নিষ্ক্রিয়') . ($qr ? ' · QR সংযুক্ত' : '') . '</small></div>' . ($qr ? '<img class="method-qr-thumb" src="' . e($qr) . '" alt="' . e($method['name']) . ' QR">' : '') . '<div class="method-actions"><a class="plain-link" href="' . e(url('payment-methods', ['edit' => $method['id']])) . '">এডিট</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_payment_method"><input type="hidden" name="method_id" value="' . (int)$method['id'] . '"><button class="plain-link" type="submit">' . ($method['active'] ? 'বন্ধ' : 'চালু') . '</button></form></div></div>';
        }
        echo '</div>';
    }
    echo '</section><aside class="panel side-form"><span class="eyebrow">' . ($edit ? 'EDIT PAYMENT METHOD' : 'NEW PAYMENT METHOD') . '</span><h2>' . ($edit ? 'পেমেন্ট মেথড এডিট' : 'নতুন পেমেন্ট মেথড') . '</h2><p>যে তথ্য দেবেন, নির্বাচিত ইনভয়েসের প্রিন্ট/PDF ভিউতে তা দেখাবে।</p><form method="post" enctype="multipart/form-data" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_payment_method"><input type="hidden" name="method_id" value="' . (int)($edit['id'] ?? 0) . '"><label>ধরন <span>*</span><select name="type" required>';
    foreach ($types as $value => $label) echo '<option value="' . e($value) . '"' . (($edit['type'] ?? 'bank') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    echo '</select></label><label>ব্যাংক / MFS / মেথডের নাম <span>*</span><input name="name" required maxlength="120" value="' . e($edit['name'] ?? '') . '" placeholder="যেমন: Dutch-Bangla Bank / bKash"></label><label>অ্যাকাউন্টের নাম<input name="account_name" maxlength="150" value="' . e($edit['account_name'] ?? '') . '" placeholder="হিসাবধারীর নাম"></label><label>অ্যাকাউন্ট নম্বর<input name="account_number" maxlength="150" value="' . e($edit['account_number'] ?? '') . '" placeholder="ব্যাংক হিসাব বা ওয়ালেট নম্বর"></label><label>মোবাইল নম্বর<input name="mobile_number" type="tel" maxlength="150" value="' . e($edit['mobile_number'] ?? '') . '" placeholder="01XXXXXXXXX"></label><label>শাখা / রাউটিং তথ্য<input name="branch" maxlength="150" value="' . e($edit['branch'] ?? '') . '" placeholder="শাখা বা রাউটিং নম্বর"></label><label>পেমেন্ট নির্দেশনা<textarea name="instructions" rows="3" maxlength="1000" placeholder="পেমেন্ট করার সময় ইনভয়েস নম্বর উল্লেখ করুন">' . e($edit['instructions'] ?? '') . '</textarea></label>';
    $qr = uploaded_asset_url((string)($edit['qr_path'] ?? ''));
    echo '<div class="upload-card method-upload"><div class="upload-card-head"><strong>QR কোড আপলোড</strong><small>PNG, JPG বা WebP · সর্বোচ্চ ৩ MB</small></div><div class="upload-preview" data-preview-box="qr"><img id="qr-preview" alt="QR preview"' . ($qr ? ' src="' . e($qr) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($qr ? ' hidden' : '') . '>' . icon('grid', 27) . '<small>QR preview</small></span></div><input type="file" name="qr_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-preview-target="qr-preview">' . ($qr ? '<label class="checkbox-line"><input type="checkbox" name="remove_qr" value="1" data-remove-image="qr-preview"> বর্তমান QR সরান</label>' : '') . '</div><button class="btn btn-primary btn-wide" type="submit">' . ($edit ? 'পরিবর্তন সেভ করুন' : 'পেমেন্ট মেথড যোগ করুন') . '</button>' . ($edit ? '<a class="btn btn-outline btn-wide" href="' . e(url('payment-methods')) . '">নতুন মেথড</a>' : '') . '</form></aside></div>';
    end_page(); break;

case 'services':
    $services = query_all('SELECT * FROM services ORDER BY active DESC, id DESC');
    $editId = (int)($_GET['edit'] ?? 0);
    $edit = $editId ? query_one('SELECT * FROM services WHERE id=?', [$editId]) : false;
    begin_page('সার্ভিস', $page, 'সার্ভিস ক্যাটালগ তৈরি করে ইনভয়েসে দ্রুত যোগ করুন।');
    echo '<div class="two-column"><section class="panel"><div class="panel-heading"><div><span class="eyebrow">SERVICE CATALOG</span><h2>সব সার্ভিস</h2></div><span class="count-pill">' . count($services) . ' টি</span></div>';
    if (!$services) echo '<div class="empty-state">' . icon('box', 34) . '<h3>কোনো সার্ভিস নেই</h3><p>ডান পাশের ফর্মে আপনার প্রথম সার্ভিস যোগ করুন।</p></div>';
    else {
        echo '<div class="service-list">';
        foreach ($services as $service) echo '<div class="service-row"><span class="service-icon">' . icon('box', 20) . '</span><div class="service-info"><strong>' . e($service['name']) . '</strong><small>' . e($service['description'] ?: 'কোনো বিবরণ নেই') . '</small></div><div class="service-end"><strong>' . format_money((int)$service['price_cents']) . '</strong><small>' . ($service['active'] ? 'সক্রিয়' : 'নিষ্ক্রিয়') . '</small></div><a class="plain-link" href="' . e(url('services', ['edit' => $service['id']])) . '">এডিট</a><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="' . (int)$service['id'] . '"><button class="plain-link" type="submit">' . ($service['active'] ? 'বন্ধ' : 'চালু') . '</button></form></div>';
        echo '</div>';
    }
    echo '</section><aside class="panel side-form"><span class="eyebrow">' . ($edit ? 'EDIT SERVICE' : 'NEW SERVICE') . '</span><h2>' . ($edit ? 'সার্ভিস এডিট করুন' : 'নতুন সার্ভিস যোগ করুন') . '</h2><p>ক্যাটালগ সার্ভিস ইনভয়েসে ব্যবহার করা যাবে।</p><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_service"><input type="hidden" name="service_id" value="' . (int)($edit['id'] ?? 0) . '"><label>সার্ভিসের নাম<input name="name" required value="' . e($edit['name'] ?? '') . '" placeholder="যেমন: Website hosting"></label><label>বিবরণ<textarea name="description" rows="3" placeholder="সার্ভিসের সংক্ষিপ্ত বিবরণ">' . e($edit['description'] ?? '') . '</textarea></label><label>ডিফল্ট দর (৳)<input type="number" name="price" min="0" step="0.01" required value="' . e(isset($edit['price_cents']) ? number_format($edit['price_cents'] / 100, 2, '.', '') : '') . '" placeholder="0.00"></label><button class="btn btn-primary btn-wide" type="submit">' . ($edit ? 'পরিবর্তন সেভ করুন' : 'সার্ভিস যোগ করুন') . '</button></form></aside></div>';
    end_page(); break;

case 'clients':
    $search = trim((string)($_GET['q'] ?? ''));
    $clients = query_all('SELECT c.*, COUNT(DISTINCT i.id) invoice_count, COALESCE(SUM(i.total_cents),0) billed FROM clients c LEFT JOIN invoices i ON i.client_id = c.id ' . ($search ? 'WHERE c.name LIKE ? OR c.company_name LIKE ? OR c.phone LIKE ? ' : '') . 'GROUP BY c.id ORDER BY c.id DESC LIMIT 500', $search ? ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'] : []);
    begin_page('ক্লায়েন্ট', $page, 'মোবাইল নম্বরের বিপরীতে তৈরি ক্লায়েন্ট অ্যাকাউন্ট।');
    echo '<section class="panel"><div class="toolbar"><form method="get" class="search-form"><input type="hidden" name="page" value="clients">' . icon('search', 18) . '<input name="q" value="' . e($search) . '" placeholder="নাম, কোম্পানি বা মোবাইল খুঁজুন"><button type="submit">খুঁজুন</button></form><span class="count-pill">' . count($clients) . ' জন ক্লায়েন্ট</span></div>';
    if (!$clients) echo '<div class="empty-state">' . icon('users', 34) . '<h3>কোনো ক্লায়েন্ট নেই</h3><p>ইনভয়েস তৈরি করলে ক্লায়েন্ট অ্যাকাউন্ট স্বয়ংক্রিয়ভাবে তৈরি হবে।</p></div>';
    else { echo '<div class="table-wrap"><table><thead><tr><th>ক্লায়েন্ট</th><th>Company Name</th><th>মোবাইল</th><th>ইমেইল</th><th>ইনভয়েস</th><th>মোট বিল</th><th></th></tr></thead><tbody>'; foreach ($clients as $client) echo '<tr><td><a class="strong-link" href="' . e(url('client', ['id' => $client['id']])) . '">' . e($client['name']) . '</a><small>অ্যাকাউন্ট #' . (int)$client['id'] . '</small></td><td>' . e($client['company_name'] ?: '—') . '</td><td>' . e($client['phone']) . '</td><td>' . e($client['email'] ?: '—') . '</td><td>' . (int)$client['invoice_count'] . '</td><td><strong>' . format_money((int)$client['billed']) . '</strong></td><td><a class="row-arrow" href="' . e(url('client', ['id' => $client['id']])) . '">' . icon('chevron', 18) . '</a></td></tr>'; echo '</tbody></table></div>'; }
    echo '</section>'; end_page(); break;

case 'client':
    $id = (int)($_GET['id'] ?? 0);
    $client = query_one('SELECT * FROM clients WHERE id=?', [$id]);
    if (!$client) { http_response_code(404); redirect('clients'); }
    $rows = invoice_rows('WHERE i.client_id=?', [$id], 'i.id DESC', 500);
    begin_page($client['name'], $page, 'ক্লায়েন্ট প্রোফাইল ও ইনভয়েসের হিসাব।');
    echo '<div class="client-card panel"><span class="client-avatar">' . e(mb_substr($client['name'], 0, 1)) . '</span><div><h2>' . e($client['name']) . '</h2>' . ($client['company_name'] !== '' ? '<p><strong>' . e($client['company_name']) . '</strong></p>' : '') . '<p>' . e($client['phone']) . ' · ' . e($client['email'] ?: 'ইমেইল দেওয়া হয়নি') . '</p><small>অ্যাকাউন্ট তৈরি ' . e(format_date(substr($client['created_at'], 0, 10))) . '</small></div><span class="count-pill">' . count($rows) . ' টি ইনভয়েস</span></div><section class="panel"><div class="panel-heading"><div><span class="eyebrow">CLIENT INVOICES</span><h2>ইনভয়েস ইতিহাস</h2></div></div>'; invoice_table($rows); echo '</section>';
    end_page(); break;

case 'recurring':
    $schedules = query_all('SELECT r.*, c.name client_name, c.phone client_phone, COALESCE((SELECT SUM(ROUND(quantity * unit_price_cents)) FROM recurrence_items ri WHERE ri.recurrence_id=r.id),0) amount_cents, (SELECT COUNT(*) FROM invoices i WHERE i.recurrence_id=r.id) invoice_count FROM recurrences r JOIN clients c ON c.id=r.client_id ORDER BY r.id DESC');
    begin_page('রিকারিং', $page, 'নিয়মিত বিলিং schedule এবং পরবর্তী ইনভয়েসের তারিখ।');
    echo '<section class="panel"><div class="panel-heading"><div><span class="eyebrow">AUTOMATED BILLING</span><h2>Recurring schedule</h2></div><span class="count-pill">' . count($schedules) . ' টি</span></div>';
    if (!$schedules) echo '<div class="empty-state">' . icon('repeat', 34) . '<h3>কোনো schedule নেই</h3><p>নতুন ইনভয়েসে রিকারিং পেমেন্ট বেছে নিন।</p><a class="btn btn-primary" href="' . e(url('new')) . '">' . icon('plus', 17) . ' ইনভয়েস তৈরি করুন</a></div>';
    else { echo '<div class="table-wrap"><table><thead><tr><th>ক্লায়েন্ট</th><th>সময়কাল</th><th>পরিমাণ</th><th>পরবর্তী ইনভয়েস</th><th>অবস্থা</th><th></th></tr></thead><tbody>'; foreach ($schedules as $schedule) echo '<tr><td><strong>' . e($schedule['client_name']) . '</strong><small>' . e($schedule['client_phone']) . '</small></td><td>' . frequency_label($schedule['frequency']) . '<small>' . (int)$schedule['invoice_count'] . ' টি ইনভয়েস তৈরি</small></td><td><strong>' . format_money((int)$schedule['amount_cents']) . '</strong></td><td>' . e(format_date($schedule['next_issue_date'])) . '</td><td><span class="badge ' . ($schedule['status'] === 'active' ? 'badge-paid' : 'badge-unpaid') . '"><span class="badge-dot"></span>' . ($schedule['status'] === 'active' ? 'সক্রিয়' : 'বিরতিতে') . '</span></td><td><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="toggle_recurrence"><input type="hidden" name="recurrence_id" value="' . (int)$schedule['id'] . '"><button type="submit" class="plain-link">' . ($schedule['status'] === 'active' ? 'বিরতি' : 'চালু') . '</button></form></td></tr>'; echo '</tbody></table></div>'; }
    echo '<div class="panel-footnote">পরবর্তী তারিখ এলে নতুন ইনভয়েস তৈরি হবে। নিয়মিত background generation-এর জন্য cron.php চালু রাখুন।</div></section>';
    end_page(); break;

case 'settings':
    $tab = (string)($_GET['tab'] ?? 'basic');
    $tabs = ['basic' => 'Basic Settings', 'smtp' => 'SMTP'];
    if (!isset($tabs[$tab])) $tab = 'basic';
    begin_page('Dashboard Settings', $page, 'সাইটের সাধারণ তথ্য ও ইমেইল সার্ভারের সেটিংস পরিচালনা করুন।');
    echo '<div class="settings-tabs" role="tablist" aria-label="Settings sections">';
    foreach ($tabs as $key => $label) {
        echo '<a role="tab" aria-selected="' . ($tab === $key ? 'true' : 'false') . '" class="' . ($tab === $key ? 'selected' : '') . '" href="' . e(url('settings', ['tab' => $key])) . '">' . e($label) . '</a>';
    }
    echo '</div><div class="settings-shell">';
    if ($tab === 'basic') {
        $logo = uploaded_asset_url(setting('logo_path'));
        $favicon = uploaded_asset_url(setting('favicon_path'));
        echo '<section class="panel settings-panel"><div class="section-title"><span class="step">01</span><div><h2>Basic Settings</h2><p>সাইটের পরিচিতি ও যোগাযোগের তথ্য।</p></div></div><form method="post" enctype="multipart/form-data" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_basic_settings"><div class="form-grid"><label class="field-wide">Site Title <span>*</span><input name="site_title" required maxlength="80" value="' . e(setting('site_title', 'Billflow')) . '" placeholder="আপনার সাইটের নাম"></label><label class="field-wide">Slogan<input name="slogan" maxlength="200" value="' . e(setting('slogan', 'সব ইনভয়েস ও কালেকশন এক জায়গায় রাখুন।')) . '" placeholder="সংক্ষিপ্ত স্লোগান"></label><label>Mobile Number<input name="mobile_number" type="tel" maxlength="25" value="' . e(setting('mobile_number')) . '" placeholder="+880 1XXXXXXXXX"></label><label>Email<input name="site_email" type="email" value="' . e(setting('email')) . '" placeholder="hello@example.com"></label></div>';
        echo '<div class="form-grid settings-extra"><label class="field-wide">অফিসের ঠিকানা <small>(ঐচ্ছিক)</small><textarea name="site_address" rows="2" maxlength="300" placeholder="ইনভয়েসে দেখানোর ঠিকানা">' . e(setting('address')) . '</textarea></label><label class="field-wide">Website <small>(ঐচ্ছিক)</small><input name="site_website" type="url" maxlength="200" value="' . e(setting('website')) . '" placeholder="https://example.com"></label></div>';
        echo '<div class="upload-grid"><div class="upload-card"><div class="upload-card-head"><strong>Logo</strong><small>PNG, JPG বা WebP · সর্বোচ্চ ৩ MB</small></div><div class="upload-preview" data-preview-box="logo"><img id="logo-preview" alt="Logo preview"' . ($logo !== '' ? ' src="' . e($logo) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($logo !== '' ? ' hidden' : '') . '>' . icon('invoice', 29) . '<small>Logo preview</small></span></div><label for="logo-file">Logo আপলোড করুন</label><input id="logo-file" type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" data-preview-target="logo-preview"><p class="upload-hint">পরিবর্তন দেখতে ফাইল বাছুন, তারপর সেভ করুন।</p>' . ($logo !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_logo" value="1" data-remove-image="logo-preview"> বর্তমান Logo সরান</label>' : '') . '</div>';
        echo '<div class="upload-card"><div class="upload-card-head"><strong>Favicon</strong><small>PNG, WebP বা ICO · সর্বোচ্চ ১ MB</small></div><div class="upload-preview favicon-preview" data-preview-box="favicon"><img id="favicon-preview" alt="Favicon preview"' . ($favicon !== '' ? ' src="' . e($favicon) . '"' : ' hidden') . '><span class="upload-placeholder"' . ($favicon !== '' ? ' hidden' : '') . '>' . icon('grid', 27) . '<small>Favicon preview</small></span></div><label for="favicon-file">Favicon আপলোড করুন</label><input id="favicon-file" type="file" name="favicon_file" accept=".png,.webp,.ico,image/png,image/webp,image/x-icon,image/vnd.microsoft.icon" data-preview-target="favicon-preview"><p class="upload-hint">ব্রাউজার ট্যাবে ছোট আইকন হিসেবে দেখা যাবে।</p>' . ($favicon !== '' ? '<label class="checkbox-line"><input type="checkbox" name="remove_favicon" value="1" data-remove-image="favicon-preview"> বর্তমান Favicon সরান</label>' : '') . '</div></div>';
        echo '<div class="settings-actions"><button class="btn btn-primary" type="submit">পরিবর্তন সেভ করুন ' . icon('arrow', 17) . '</button></div></form></section>';
        echo '<aside class="settings-tip panel"><span class="settings-tip-icon">' . icon('grid', 22) . '</span><h3>সাইটের পরিচিতি</h3><p>Site Title সাইডবার, লগইন পেজ ও ইনভয়েসে দেখা যাবে। Slogan সাইডবারে এবং যোগাযোগের তথ্য ইনভয়েসে থাকবে।</p></aside>';
    } else {
        $encryption = setting('smtp_encryption', 'tls');
        echo '<section class="panel settings-panel"><div class="section-title"><span class="step">02</span><div><h2>SMTP Settings</h2><p>ভবিষ্যতের ইমেইল পাঠানোর জন্য সার্ভারের তথ্য সংরক্ষণ করুন।</p></div></div><form method="post" class="stack-form">' . csrf_field() . '<input type="hidden" name="action" value="save_smtp_settings"><div class="form-grid"><label class="field-wide">SMTP Host<input name="smtp_host" maxlength="255" value="' . e(setting('smtp_host')) . '" placeholder="smtp.example.com"></label><label>Port<input name="smtp_port" type="number" min="1" max="65535" required value="' . e(setting('smtp_port', '587')) . '"></label><label>Encryption<select name="smtp_encryption"><option value="none" ' . ($encryption === 'none' ? 'selected' : '') . '>None</option><option value="tls" ' . ($encryption === 'tls' ? 'selected' : '') . '>TLS / STARTTLS</option><option value="ssl" ' . ($encryption === 'ssl' ? 'selected' : '') . '>SSL</option></select></label><label class="field-wide">Username<input name="smtp_username" maxlength="255" autocomplete="off" value="' . e(setting('smtp_username')) . '" placeholder="SMTP username"></label><label class="field-wide">Password<input name="smtp_password" type="password" autocomplete="new-password" placeholder="' . (setting('smtp_password') !== '' ? 'সংরক্ষিত আছে · পরিবর্তন করতে নতুন পাসওয়ার্ড লিখুন' : 'SMTP password') . '"></label>';
        if (setting('smtp_password') !== '') echo '<label class="checkbox-line field-wide"><input type="checkbox" name="smtp_clear_password" value="1"> সংরক্ষিত পাসওয়ার্ড সরান</label>';
        echo '<label>From Name<input name="smtp_from_name" maxlength="150" value="' . e(setting('smtp_from_name', setting('site_title', 'Billflow'))) . '" placeholder="প্রেরকের নাম"></label><label>From Email<input name="smtp_from_email" type="email" value="' . e(setting('smtp_from_email', setting('email'))) . '" placeholder="billing@example.com"></label></div><div class="settings-actions"><button class="btn btn-primary" type="submit">SMTP Settings সেভ করুন ' . icon('arrow', 17) . '</button></div></form></section>';
        echo '<aside class="settings-tip panel"><span class="settings-tip-icon">' . icon('settings', 22) . '</span><h3>ইমেইল সেটআপ</h3><p>SMTP তথ্য এখন নিরাপদে সংরক্ষিত হবে। ইনভয়েস ইমেইল পাঠানোর ব্যবস্থা যুক্ত হলে এই সেটিংস ব্যবহার করা যাবে।</p></aside>';
    }
    echo '</div>';
    end_page(); break;

default:
    http_response_code(404);
    begin_page('পৃষ্ঠা পাওয়া যায়নি', $page);
    echo '<div class="empty-state"><h2>পৃষ্ঠা পাওয়া যায়নি</h2><a href="' . e(url('dashboard')) . '">ড্যাশবোর্ডে ফিরুন</a></div>';
    end_page();
}
