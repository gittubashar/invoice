<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');

const DEFAULT_SUPER_ADMIN_EMAIL = 'me@kbashar.com';
const DEFAULT_SUPER_ADMIN_HASH = '$2a$12$u4m/DiaBZhAO0/p26M741eYJavOi8t21FDd6AOit8IhQfMBbVOAWO';

function db(): PDO
{
    $connection = $GLOBALS['billflow_db_connection'] ?? null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $path = getenv('INVOICE_DB_PATH') ?: __DIR__ . '/storage/invoice.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Database directory could not be created.');
    }
    $connection = new PDO('sqlite:' . $path);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA journal_mode = WAL');
    migrate($connection);
    $GLOBALS['billflow_db_connection'] = $connection;
    return $connection;
}

function close_db(): void
{
    unset($GLOBALS['billflow_db_connection']);
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS app_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS payment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    account_name TEXT NOT NULL DEFAULT '',
    account_number TEXT NOT NULL DEFAULT '',
    mobile_number TEXT NOT NULL DEFAULT '',
    branch TEXT NOT NULL DEFAULT '',
    instructions TEXT NOT NULL DEFAULT '',
    qr_path TEXT NOT NULL DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    company_name TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL UNIQUE,
    email TEXT,
    password_hash TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS recurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL REFERENCES clients(id),
    frequency TEXT NOT NULL CHECK (frequency IN ('monthly','quarterly','yearly')),
    next_issue_date TEXT NOT NULL,
    due_days INTEGER NOT NULL DEFAULT 7,
    anchor_day INTEGER NOT NULL,
    anchor_month INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused')),
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS recurrence_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recurrence_id INTEGER NOT NULL REFERENCES recurrences(id) ON DELETE CASCADE,
    service_id INTEGER REFERENCES services(id) ON DELETE SET NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    quantity REAL NOT NULL CHECK (quantity > 0),
    unit_price_cents INTEGER NOT NULL CHECK (unit_price_cents >= 0)
);
CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT NOT NULL UNIQUE,
    client_id INTEGER NOT NULL REFERENCES clients(id),
    billing_name TEXT NOT NULL DEFAULT '',
    billing_phone TEXT NOT NULL DEFAULT '',
    billing_email TEXT NOT NULL DEFAULT '',
    billing_company_name TEXT NOT NULL DEFAULT '',
    recurrence_id INTEGER REFERENCES recurrences(id),
    cycle_date TEXT,
    issue_date TEXT NOT NULL,
    due_date TEXT NOT NULL,
    notes TEXT NOT NULL DEFAULT '',
    total_cents INTEGER NOT NULL CHECK (total_cents >= 0),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (recurrence_id, cycle_date)
);
CREATE TABLE IF NOT EXISTS invoice_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    service_id INTEGER REFERENCES services(id) ON DELETE SET NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    quantity REAL NOT NULL CHECK (quantity > 0),
    unit_price_cents INTEGER NOT NULL CHECK (unit_price_cents >= 0),
    total_cents INTEGER NOT NULL CHECK (total_cents >= 0)
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
    method TEXT NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    paid_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_invoices_client ON invoices(client_id);
CREATE INDEX IF NOT EXISTS idx_invoices_due ON invoices(due_date);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id);
CREATE INDEX IF NOT EXISTS idx_recurrences_next ON recurrences(status, next_issue_date);
CREATE INDEX IF NOT EXISTS idx_payment_methods_active ON payment_methods(active, name);
SQL);

    // Add fields introduced after the first release without replacing saved records.
    $columns = array_column($pdo->query('PRAGMA table_info(admins)')->fetchAll(), 'name');
    if (!in_array('role', $columns, true)) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
    }
    $clientColumns = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
    if (!in_array('company_name', $clientColumns, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN company_name TEXT NOT NULL DEFAULT ''");
    }
    $invoiceColumns = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
    $recurrenceColumns = array_column($pdo->query('PRAGMA table_info(recurrences)')->fetchAll(), 'name');
    if (!in_array('payment_method_id', $recurrenceColumns, true)) {
        $pdo->exec('ALTER TABLE recurrences ADD COLUMN payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL');
    }
    if (!in_array('payment_method_id', $invoiceColumns, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL');
    }
    if (!in_array('billing_company_name', $invoiceColumns, true)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN billing_company_name TEXT NOT NULL DEFAULT ''");
    }
    foreach (['billing_name' => 'name', 'billing_phone' => 'phone', 'billing_email' => 'email'] as $invoiceField => $clientField) {
        if (!in_array($invoiceField, $invoiceColumns, true)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN {$invoiceField} TEXT NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE invoices SET {$invoiceField} = COALESCE((SELECT {$clientField} FROM clients WHERE clients.id = invoices.client_id), '')");
        }
    }

    // Apply the requested default credential once. Later password changes remain intact.
    $seeded = $pdo->query("SELECT value FROM app_meta WHERE key = 'super_admin_seed_v1'")->fetchColumn();
    if ($seeded === false) {
        $pdo->beginTransaction();
        try {
            $seeded = $pdo->query("SELECT value FROM app_meta WHERE key = 'super_admin_seed_v1'")->fetchColumn();
            if ($seeded === false) {
                $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin') ON CONFLICT(email) DO UPDATE SET name=excluded.name, password_hash=excluded.password_hash, role=excluded.role");
                $stmt->execute(['Super Admin', DEFAULT_SUPER_ADMIN_EMAIL, DEFAULT_SUPER_ADMIN_HASH]);
                $pdo->prepare('INSERT INTO app_meta (key, value) VALUES (?, ?)')->execute(['super_admin_seed_v1', date('c')]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): array|false
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function setting(string $key, string $default = ''): string
{
    $row = query_one('SELECT value FROM app_settings WHERE key = ?', [$key]);
    return $row ? (string)$row['value'] : $default;
}

function save_settings(array $values): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function smtp_secret_key(): string
{
    $path = getenv('INVOICE_SMTP_KEY_PATH') ?: __DIR__ . '/storage/smtp.key';
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            try {
                $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                if (fwrite($handle, $key) !== strlen($key)) {
                    throw new RuntimeException('SMTP key could not be saved.');
                }
            } finally {
                fclose($handle);
            }
        }
    }
    $key = @file_get_contents($path);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('SMTP key is unavailable.');
    }
    return $key;
}

function encrypt_smtp_password(string $password): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($password, $nonce, smtp_secret_key()));
}

function decrypt_smtp_password(string $encrypted): string
{
    $payload = base64_decode($encrypted, true);
    if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Saved SMTP password is invalid.');
    }
    $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $password = sodium_crypto_secretbox_open($ciphertext, $nonce, smtp_secret_key());
    if ($password === false) throw new RuntimeException('Saved SMTP password could not be decrypted.');
    return $password;
}

function money_cents(string|int|float $value): int
{
    $value = trim((string)$value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('সঠিক টাকার পরিমাণ লিখুন।');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    if ((float)$whole > 1000000000) {
        throw new InvalidArgumentException('টাকার পরিমাণ অনেক বড়।');
    }
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}

function format_money(int $cents): string
{
    return '৳' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($digits, '880')) {
        $digits = '0' . substr($digits, 3);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
        $digits = '0' . $digits;
    }
    if (!preg_match('/^01[3-9]\d{8}$/', $digits)) {
        throw new InvalidArgumentException('১১ সংখ্যার একটি সঠিক বাংলাদেশি মোবাইল নম্বর লিখুন।');
    }
    return $digits;
}

function valid_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('সঠিক তারিখ নির্বাচন করুন।');
    }
    return $date;
}

function add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
}

function next_cycle_date(string $current, string $frequency, int $anchorDay, int $anchorMonth): string
{
    $date = new DateTimeImmutable($current);
    $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$frequency] ?? 1;
    $first = $date->modify('first day of this month')->modify('+' . $months . ' months');
    $day = min($anchorDay, (int)$first->format('t'));
    // For yearly schedules, the original month remains the billing month.
    if ($frequency === 'yearly' && (int)$first->format('n') !== $anchorMonth) {
        $first = $first->setDate((int)$first->format('Y'), $anchorMonth, 1);
        $day = min($anchorDay, (int)$first->format('t'));
    }
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day)->format('Y-m-d');
}

function invoice_rows(string $where = '', array $params = [], string $order = 'i.id DESC', int $limit = 100): array
{
    $sql = 'SELECT i.*, i.billing_name AS client_name, i.billing_phone AS client_phone,
            i.billing_email AS client_email, r.frequency,
            COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_cents
            FROM invoices i JOIN clients c ON c.id = i.client_id
            LEFT JOIN recurrences r ON r.id = i.recurrence_id ' . $where .
            ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit;
    return query_all($sql, $params);
}

function invoice_status(array $invoice): string
{
    if ((int)$invoice['paid_cents'] >= (int)$invoice['total_cents']) return 'paid';
    if ((int)$invoice['paid_cents'] > 0) return 'partial';
    if ($invoice['due_date'] < date('Y-m-d')) return 'overdue';
    return 'unpaid';
}

function invoice_payment_methods(array $invoice): array
{
    $id = (int)($invoice['payment_method_id'] ?? 0);
    if ($id > 0) return query_all('SELECT * FROM payment_methods WHERE id=?', [$id]);
    return query_all('SELECT * FROM payment_methods WHERE active=1 ORDER BY id');
}

function selected_payment_method_id(array $input, ?int $currentId = null): ?int
{
    $id = (int)($input['payment_method_id'] ?? 0);
    if ($id === 0) return null;
    $method = query_one('SELECT id, active FROM payment_methods WHERE id = ?', [$id]);
    if (!$method || (!(int)$method['active'] && $id !== $currentId)) {
        throw new InvalidArgumentException('সক্রিয় পেমেন্ট মেথড নির্বাচন করুন।');
    }
    return $id;
}

function get_or_create_client(PDO $pdo, string $name, string $phone, string $email, string $companyName): int
{
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('ক্লায়েন্টের নাম লিখুন।');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('সঠিক ইমেইল লিখুন।');
    $phone = normalize_phone($phone);
    $existing = query_one('SELECT id FROM clients WHERE phone = ?', [$phone]);
    if ($existing) {
        if ($companyName !== '') {
            $pdo->prepare('UPDATE clients SET company_name = ? WHERE id = ?')->execute([$companyName, $existing['id']]);
        }
        return (int)$existing['id'];
    }
    $stmt = $pdo->prepare('INSERT INTO clients (name, company_name, phone, email) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $companyName, $phone, $email ?: null]);
    return (int)$pdo->lastInsertId();
}

function parse_items(array $input, bool $allowInactiveServices = false): array
{
    $services = array_column(query_all('SELECT * FROM services' . ($allowInactiveServices ? '' : ' WHERE active = 1')), null, 'id');
    $serviceIds = $input['item_service_id'] ?? [];
    $names = $input['item_name'] ?? [];
    $descriptions = $input['item_description'] ?? [];
    $quantities = $input['item_qty'] ?? [];
    $prices = $input['item_price'] ?? [];
    if (!is_array($names) || count($names) < 1 || count($names) > 30) {
        throw new InvalidArgumentException('অন্তত একটি সার্ভিস বা আইটেম যোগ করুন।');
    }
    $items = [];
    foreach ($names as $i => $rawName) {
        $serviceId = (int)($serviceIds[$i] ?? 0);
        $service = $serviceId ? ($services[$serviceId] ?? null) : null;
        if ($serviceId && !$service) throw new InvalidArgumentException('নির্বাচিত সার্ভিসটি পাওয়া যায়নি।');
        $name = trim((string)$rawName);
        if ($name === '' && $service) $name = $service['name'];
        if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('প্রতিটি আইটেমের নাম লিখুন।');
        $quantity = filter_var($quantities[$i] ?? null, FILTER_VALIDATE_FLOAT);
        if ($quantity === false || $quantity <= 0 || $quantity > 100000) {
            throw new InvalidArgumentException('সঠিক পরিমাণ লিখুন।');
        }
        $price = money_cents((string)($prices[$i] ?? ''));
        $items[] = [
            'service_id' => $serviceId ?: null,
            'name' => $name,
            'description' => mb_substr(trim((string)($descriptions[$i] ?? '')), 0, 500),
            'quantity' => $quantity,
            'unit_price_cents' => $price,
            'total_cents' => (int)round($price * $quantity),
        ];
    }
    return $items;
}

function insert_invoice(PDO $pdo, int $clientId, array $items, string $issueDate, string $dueDate, string $notes, array $billing, ?int $recurrenceId = null, ?int $paymentMethodId = null): int
{
    $total = array_sum(array_column($items, 'total_cents'));
    $stmt = $pdo->prepare('INSERT INTO invoices (number, client_id, billing_name, billing_phone, billing_email, billing_company_name, recurrence_id, cycle_date, issue_date, due_date, notes, total_cents, payment_method_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(['PENDING-' . bin2hex(random_bytes(8)), $clientId, $billing['name'], $billing['phone'], $billing['email'], $billing['company_name'], $recurrenceId, $recurrenceId ? $issueDate : null, $issueDate, $dueDate, $notes, $total, $paymentMethodId]);
    $id = (int)$pdo->lastInsertId();
    $number = 'INV-' . date('Y', strtotime($issueDate)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE invoices SET number = ? WHERE id = ?')->execute([$number, $id]);
    $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, service_id, name, description, quantity, unit_price_cents, total_cents) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $itemStmt->execute([$id, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents'], $item['total_cents']]);
    }
    return $id;
}

function create_invoice(array $input): int
{
    $pdo = db();
    $issueDate = valid_date((string)($input['issue_date'] ?? ''));
    $dueDate = valid_date((string)($input['due_date'] ?? ''));
    if ($dueDate < $issueDate) throw new InvalidArgumentException('পরিশোধের শেষ তারিখ ইস্যুর তারিখের আগে হতে পারে না।');
    $type = (string)($input['invoice_type'] ?? 'one_time');
    if (!in_array($type, ['one_time', 'recurring'], true)) throw new InvalidArgumentException('ইনভয়েসের ধরন নির্বাচন করুন।');
    $items = parse_items($input);
    $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 2000);
    $paymentMethodId = selected_payment_method_id($input);
    $companyName = trim((string)($input['company_name'] ?? ''));
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Company Name ১৫০ অক্ষরের মধ্যে লিখুন।');
    $pdo->beginTransaction();
    try {
        $clientId = get_or_create_client($pdo, trim((string)($input['client_name'] ?? '')), (string)($input['client_phone'] ?? ''), trim((string)($input['client_email'] ?? '')), $companyName);
        $client = query_one('SELECT name, phone, email, company_name FROM clients WHERE id = ?', [$clientId]);
        $billingCompanyName = $companyName !== '' ? $companyName : (string)$client['company_name'];
        $billing = ['name' => $client['name'], 'phone' => $client['phone'], 'email' => $client['email'] ?? '', 'company_name' => $billingCompanyName];
        $recurrenceId = null;
        if ($type === 'recurring') {
            $frequency = (string)($input['frequency'] ?? 'monthly');
            if (!in_array($frequency, ['monthly', 'quarterly', 'yearly'], true)) throw new InvalidArgumentException('সঠিক recurring সময়কাল নির্বাচন করুন।');
            $dueDays = (new DateTimeImmutable($issueDate))->diff(new DateTimeImmutable($dueDate))->days;
            $nextDate = next_cycle_date($issueDate, $frequency, (int)date('j', strtotime($issueDate)), (int)date('n', strtotime($issueDate)));
            $stmt = $pdo->prepare('INSERT INTO recurrences (client_id, frequency, next_issue_date, due_days, anchor_day, anchor_month, notes, payment_method_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$clientId, $frequency, $nextDate, $dueDays, (int)date('j', strtotime($issueDate)), (int)date('n', strtotime($issueDate)), $notes, $paymentMethodId]);
            $recurrenceId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO recurrence_items (recurrence_id, service_id, name, description, quantity, unit_price_cents) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $itemStmt->execute([$recurrenceId, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents']]);
            }
        }
        $id = insert_invoice($pdo, $clientId, $items, $issueDate, $dueDate, $notes, $billing, $recurrenceId, $paymentMethodId);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function edit_invoice(int $invoiceId, array $input): void
{
    if ($invoiceId < 1) throw new InvalidArgumentException('ইনভয়েসটি পাওয়া যায়নি।');
    $issueDate = valid_date((string)($input['issue_date'] ?? ''));
    $dueDate = valid_date((string)($input['due_date'] ?? ''));
    if ($dueDate < $issueDate) throw new InvalidArgumentException('পরিশোধের শেষ তারিখ ইস্যুর তারিখের আগে হতে পারে না।');
    $name = trim((string)($input['client_name'] ?? ''));
    $phone = normalize_phone((string)($input['client_phone'] ?? ''));
    $email = trim((string)($input['client_email'] ?? ''));
    $companyName = trim((string)($input['company_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('ক্লায়েন্টের নাম লিখুন।');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('সঠিক ইমেইল লিখুন।');
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Company Name ১৫০ অক্ষরের মধ্যে লিখুন।');
    $items = parse_items($input, true);
    $total = array_sum(array_column($items, 'total_cents'));
    $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 2000);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $invoice = query_one('SELECT i.id, i.total_cents, i.payment_method_id, COALESCE(SUM(p.amount_cents), 0) AS paid_cents FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id WHERE i.id = ? GROUP BY i.id', [$invoiceId]);
        if (!$invoice) throw new InvalidArgumentException('ইনভয়েসটি পাওয়া যায়নি।');
        if ($total < (int)$invoice['paid_cents']) throw new InvalidArgumentException('নতুন মোট বিল আগে সংগ্রহ করা টাকার চেয়ে কম হতে পারে না।');
        $clientId = get_or_create_client($pdo, $name, $phone, $email, $companyName);
        $paymentMethodId = selected_payment_method_id($input, (int)$invoice['payment_method_id']);
        $stmt = $pdo->prepare('UPDATE invoices SET client_id=?, billing_name=?, billing_phone=?, billing_email=?, billing_company_name=?, issue_date=?, due_date=?, notes=?, total_cents=?, payment_method_id=? WHERE id=?');
        $stmt->execute([$clientId, $name, $phone, $email, $companyName, $issueDate, $dueDate, $notes, $total, $paymentMethodId, $invoiceId]);
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
        $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, service_id, name, description, quantity, unit_price_cents, total_cents) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $itemStmt->execute([$invoiceId, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents'], $item['total_cents']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function collect_payment(int $invoiceId, string $amount, string $method, string $reference, string $notes, string $paidAt): void
{
    $pdo = db();
    $cents = money_cents($amount);
    if ($cents <= 0) throw new InvalidArgumentException('কালেকশনের পরিমাণ শূন্যের বেশি হতে হবে।');
    if (!in_array($method, ['cash', 'bank', 'bkash', 'nagad', 'card', 'other'], true)) throw new InvalidArgumentException('পেমেন্ট পদ্ধতি নির্বাচন করুন।');
    $paidAt = valid_date($paidAt);
    $pdo->beginTransaction();
    try {
        $invoice = query_one('SELECT i.total_cents, COALESCE(SUM(p.amount_cents),0) paid_cents FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id WHERE i.id = ? GROUP BY i.id', [$invoiceId]);
        if (!$invoice) throw new InvalidArgumentException('ইনভয়েসটি পাওয়া যায়নি।');
        $remaining = (int)$invoice['total_cents'] - (int)$invoice['paid_cents'];
        if ($cents > $remaining) throw new InvalidArgumentException('বকেয়া টাকার বেশি কালেকশন করা যাবে না।');
        $stmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount_cents, method, reference, notes, paid_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $cents, $method, mb_substr(trim($reference), 0, 120), mb_substr(trim($notes), 0, 500), $paidAt]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function generate_due_invoices(): int
{
    $pdo = db();
    $today = date('Y-m-d');
    $generated = 0;
    foreach (query_all("SELECT r.*, c.name AS billing_name, c.phone AS billing_phone, c.email AS billing_email, c.company_name AS billing_company_name FROM recurrences r JOIN clients c ON c.id = r.client_id WHERE r.status = 'active' AND r.next_issue_date <= ? ORDER BY r.next_issue_date LIMIT 100", [$today]) as $recurrence) {
        $pdo->beginTransaction();
        try {
            $next = $recurrence['next_issue_date'];
            $items = query_all('SELECT * FROM recurrence_items WHERE recurrence_id = ? ORDER BY id', [(int)$recurrence['id']]);
            for ($n = 0; $next <= $today && $n < 120; $n++) {
                $invoiceItems = array_map(static function ($item) {
                    $item['total_cents'] = (int)round((int)$item['unit_price_cents'] * (float)$item['quantity']);
                    return $item;
                }, $items);
                $billing = ['name' => $recurrence['billing_name'], 'phone' => $recurrence['billing_phone'], 'email' => $recurrence['billing_email'] ?? '', 'company_name' => $recurrence['billing_company_name']];
                insert_invoice($pdo, (int)$recurrence['client_id'], $invoiceItems, $next, add_days($next, (int)$recurrence['due_days']), $recurrence['notes'], $billing, (int)$recurrence['id'], $recurrence['payment_method_id'] !== null ? (int)$recurrence['payment_method_id'] : null);
                $generated++;
                $next = next_cycle_date($next, $recurrence['frequency'], (int)$recurrence['anchor_day'], (int)$recurrence['anchor_month']);
            }
            $pdo->prepare('UPDATE recurrences SET next_issue_date = ? WHERE id = ?')->execute([$next, $recurrence['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    return $generated;
}
