<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');

const DEFAULT_SUPER_ADMIN_EMAIL = 'me@kbashar.com';
const DEFAULT_SUPER_ADMIN_HASH = '$2a$12$u4m/DiaBZhAO0/p26M741eYJavOi8t21FDd6AOit8IhQfMBbVOAWO';

function load_database_environment(): void
{
    $path = __DIR__ . '/.env';
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || getenv($key) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

load_database_environment();

function db(): PDO
{
    $connection = $GLOBALS['billflow_db_connection'] ?? null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $sqlitePath = getenv('INVOICE_DB_PATH');
    $driver = $sqlitePath !== false && $sqlitePath !== '' ? 'sqlite' : strtolower(getenv('DB_DRIVER') ?: 'mysql');
    if ($driver === 'sqlite') {
        $path = $sqlitePath ?: __DIR__ . '/storage/invoice.sqlite';
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
    } elseif ($driver === 'mysql') {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $database = getenv('DB_DATABASE') ?: 'invoice';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }
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
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        migrate_mysql($pdo);
        return;
    }
    migrate_sqlite($pdo);
}

function migrate_sqlite(PDO $pdo): void
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
    address TEXT NOT NULL DEFAULT '',
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
    frequency TEXT NOT NULL CHECK (frequency IN ('monthly','quarterly','half_yearly','yearly','biennial','triennial','quadrennial','quinquennial')),
    next_issue_date TEXT NOT NULL,
    due_days INTEGER NOT NULL DEFAULT 7,
    anchor_day INTEGER NOT NULL,
    anchor_month INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused')),
    notes TEXT NOT NULL DEFAULT '',
    discount_type TEXT NOT NULL DEFAULT 'none',
    discount_value INTEGER NOT NULL DEFAULT 0,
    discount_scope TEXT NOT NULL DEFAULT 'none',
    discount_cycles_remaining INTEGER NOT NULL DEFAULT 0,
    discount_note TEXT NOT NULL DEFAULT '',
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
    subtotal_cents INTEGER NOT NULL DEFAULT 0,
    invoice_discount_type TEXT NOT NULL DEFAULT 'none',
    invoice_discount_value INTEGER NOT NULL DEFAULT 0,
    invoice_discount_cents INTEGER NOT NULL DEFAULT 0,
    invoice_discount_label TEXT NOT NULL DEFAULT '',
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
    amount_cents INTEGER NOT NULL CHECK (amount_cents >= 0),
    discount_cents INTEGER NOT NULL DEFAULT 0,
    discount_scope TEXT NOT NULL DEFAULT '',
    receipt_number TEXT NOT NULL DEFAULT '',
    method TEXT NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    paid_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS email_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL UNIQUE REFERENCES invoices(id) ON DELETE CASCADE,
    recipient TEXT NOT NULL,
    auto_send INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sending','sent','failed','skipped')),
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT NOT NULL DEFAULT '',
    next_attempt_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_invoices_client ON invoices(client_id);
CREATE INDEX IF NOT EXISTS idx_invoices_due ON invoices(due_date);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id);
CREATE INDEX IF NOT EXISTS idx_recurrences_next ON recurrences(status, next_issue_date);
CREATE INDEX IF NOT EXISTS idx_payment_methods_active ON payment_methods(active, name);
CREATE INDEX IF NOT EXISTS idx_email_deliveries_queue ON email_deliveries(status, next_attempt_at);
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
    if (!in_array('address', $clientColumns, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN address TEXT NOT NULL DEFAULT ''");
    }
    $invoiceColumns = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
    $recurrenceColumns = array_column($pdo->query('PRAGMA table_info(recurrences)')->fetchAll(), 'name');
    $paymentColumns = array_column($pdo->query('PRAGMA table_info(payments)')->fetchAll(), 'name');
    $deliveryColumns = array_column($pdo->query('PRAGMA table_info(email_deliveries)')->fetchAll(), 'name');
    if (!in_array('discount_cents', $paymentColumns, true)) $pdo->exec('ALTER TABLE payments ADD COLUMN discount_cents INTEGER NOT NULL DEFAULT 0');
    if (!in_array('discount_scope', $paymentColumns, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN discount_scope TEXT NOT NULL DEFAULT ''");
    if (!in_array('receipt_number', $paymentColumns, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN receipt_number TEXT NOT NULL DEFAULT ''");
    if (!in_array('auto_send', $deliveryColumns, true)) $pdo->exec('ALTER TABLE email_deliveries ADD COLUMN auto_send INTEGER NOT NULL DEFAULT 0');
    if (!in_array('payment_method_id', $recurrenceColumns, true)) {
        $pdo->exec('ALTER TABLE recurrences ADD COLUMN payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL');
    }
    foreach ([
        'discount_type' => "TEXT NOT NULL DEFAULT 'none'",
        'discount_value' => 'INTEGER NOT NULL DEFAULT 0',
        'discount_scope' => "TEXT NOT NULL DEFAULT 'none'",
        'discount_cycles_remaining' => 'INTEGER NOT NULL DEFAULT 0',
        'discount_note' => "TEXT NOT NULL DEFAULT ''",
    ] as $field => $definition) {
        if (!in_array($field, $recurrenceColumns, true)) $pdo->exec("ALTER TABLE recurrences ADD COLUMN {$field} {$definition}");
    }
    if (!in_array('payment_method_id', $invoiceColumns, true)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN payment_method_id INTEGER REFERENCES payment_methods(id) ON DELETE SET NULL');
    }
    if (!in_array('billing_company_name', $invoiceColumns, true)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN billing_company_name TEXT NOT NULL DEFAULT ''");
    }
    foreach ([
        'subtotal_cents' => 'INTEGER NOT NULL DEFAULT 0',
        'invoice_discount_type' => "TEXT NOT NULL DEFAULT 'none'",
        'invoice_discount_value' => 'INTEGER NOT NULL DEFAULT 0',
        'invoice_discount_cents' => 'INTEGER NOT NULL DEFAULT 0',
        'invoice_discount_label' => "TEXT NOT NULL DEFAULT ''",
    ] as $field => $definition) {
        if (!in_array($field, $invoiceColumns, true)) $pdo->exec("ALTER TABLE invoices ADD COLUMN {$field} {$definition}");
    }
    $pdo->exec('UPDATE invoices SET subtotal_cents = total_cents WHERE subtotal_cents = 0 AND total_cents > 0');
    foreach (['billing_name' => 'name', 'billing_phone' => 'phone', 'billing_email' => 'email'] as $invoiceField => $clientField) {
        if (!in_array($invoiceField, $invoiceColumns, true)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN {$invoiceField} TEXT NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE invoices SET {$invoiceField} = COALESCE((SELECT {$clientField} FROM clients WHERE clients.id = invoices.client_id), '')");
        }
    }

    // Apply the requested default credential once. Later password changes remain intact.
    $seeded = $pdo->query("SELECT value FROM app_meta WHERE `key` = 'super_admin_seed_v1'")->fetchColumn();
    if ($seeded === false) {
        $pdo->beginTransaction();
        try {
            $seeded = $pdo->query("SELECT value FROM app_meta WHERE `key` = 'super_admin_seed_v1'")->fetchColumn();
            if ($seeded === false) {
                $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin') ON CONFLICT(email) DO UPDATE SET name=excluded.name, password_hash=excluded.password_hash, role=excluded.role");
                $stmt->execute(['Super Admin', DEFAULT_SUPER_ADMIN_EMAIL, DEFAULT_SUPER_ADMIN_HASH]);
                $pdo->prepare('INSERT INTO app_meta (`key`, value) VALUES (?, ?)')->execute(['super_admin_seed_v1', date('c')]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}

function migrate_mysql(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS admins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'admin',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS app_meta (
            `key` VARCHAR(190) PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(190) PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payment_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            account_name VARCHAR(150) NOT NULL DEFAULT '',
            account_number VARCHAR(150) NOT NULL DEFAULT '',
            mobile_number VARCHAR(150) NOT NULL DEFAULT '',
            branch VARCHAR(150) NOT NULL DEFAULT '',
            instructions TEXT NOT NULL,
            qr_path VARCHAR(500) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_methods_active (active, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            company_name VARCHAR(150) NOT NULL DEFAULT '',
            address VARCHAR(300) NOT NULL DEFAULT '',
            phone VARCHAR(30) NOT NULL UNIQUE,
            email VARCHAR(190) NULL,
            password_hash VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_clients_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS services (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            price_cents BIGINT UNSIGNED NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT chk_services_price CHECK (price_cents >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS recurrences (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            frequency VARCHAR(20) NOT NULL,
            next_issue_date DATE NOT NULL,
            due_days INT UNSIGNED NOT NULL DEFAULT 7,
            anchor_day TINYINT UNSIGNED NOT NULL,
            anchor_month TINYINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NOT NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
            discount_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            discount_scope VARCHAR(20) NOT NULL DEFAULT 'none',
            discount_cycles_remaining INT UNSIGNED NOT NULL DEFAULT 0,
            discount_note VARCHAR(200) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_recurrences_client FOREIGN KEY (client_id) REFERENCES clients(id),
            CONSTRAINT fk_recurrences_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL,
            CONSTRAINT chk_recurrences_frequency CHECK (frequency IN ('monthly','quarterly','half_yearly','yearly','biennial','triennial','quadrennial','quinquennial')),
            CONSTRAINT chk_recurrences_status CHECK (status IN ('active','paused')),
            KEY idx_recurrences_next (status, next_issue_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS recurrence_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recurrence_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            quantity DECIMAL(14,4) NOT NULL,
            unit_price_cents BIGINT UNSIGNED NOT NULL,
            CONSTRAINT fk_recurrence_items_recurrence FOREIGN KEY (recurrence_id) REFERENCES recurrences(id) ON DELETE CASCADE,
            CONSTRAINT fk_recurrence_items_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
            CONSTRAINT chk_recurrence_items_quantity CHECK (quantity > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            number VARCHAR(80) NOT NULL UNIQUE,
            client_id BIGINT UNSIGNED NOT NULL,
            billing_name VARCHAR(150) NOT NULL DEFAULT '',
            billing_phone VARCHAR(30) NOT NULL DEFAULT '',
            billing_email VARCHAR(190) NOT NULL DEFAULT '',
            billing_company_name VARCHAR(150) NOT NULL DEFAULT '',
            recurrence_id BIGINT UNSIGNED NULL,
            cycle_date DATE NULL,
            issue_date DATE NOT NULL,
            due_date DATE NOT NULL,
            notes TEXT NOT NULL,
            subtotal_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            invoice_discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
            invoice_discount_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            invoice_discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            invoice_discount_label VARCHAR(255) NOT NULL DEFAULT '',
            total_cents BIGINT UNSIGNED NOT NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id),
            CONSTRAINT fk_invoices_recurrence FOREIGN KEY (recurrence_id) REFERENCES recurrences(id),
            CONSTRAINT fk_invoices_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL,
            CONSTRAINT uq_invoices_recurrence_cycle UNIQUE (recurrence_id, cycle_date),
            KEY idx_invoices_client (client_id),
            KEY idx_invoices_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS invoice_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            quantity DECIMAL(14,4) NOT NULL,
            unit_price_cents BIGINT UNSIGNED NOT NULL,
            total_cents BIGINT UNSIGNED NOT NULL,
            CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            CONSTRAINT fk_invoice_items_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
            CONSTRAINT chk_invoice_items_quantity CHECK (quantity > 0),
            KEY idx_invoice_items_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            discount_scope VARCHAR(20) NOT NULL DEFAULT '',
            receipt_number VARCHAR(80) NOT NULL DEFAULT '',
            method VARCHAR(32) NOT NULL,
            reference VARCHAR(120) NOT NULL DEFAULT '',
            notes VARCHAR(500) NOT NULL DEFAULT '',
            paid_at DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            CONSTRAINT chk_payments_amount CHECK (amount_cents >= 0),
            KEY idx_payments_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS email_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
            recipient VARCHAR(190) NOT NULL,
            auto_send TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(1000) NOT NULL DEFAULT '',
            next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_email_deliveries_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            CONSTRAINT chk_email_deliveries_status CHECK (status IN ('pending','sending','sent','failed','skipped')),
            KEY idx_email_deliveries_queue (status, next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $statement) $pdo->exec($statement);

    $clientColumns = array_column($pdo->query('SHOW COLUMNS FROM clients')->fetchAll(), 'Field');
    if (!in_array('address', $clientColumns, true)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN address VARCHAR(300) NOT NULL DEFAULT '' AFTER company_name");
    }
    $recurrenceColumns = array_column($pdo->query('SHOW COLUMNS FROM recurrences')->fetchAll(), 'Field');
    foreach ([
        'discount_type' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
        'discount_value' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'discount_scope' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
        'discount_cycles_remaining' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'discount_note' => "VARCHAR(200) NOT NULL DEFAULT ''",
    ] as $field => $definition) {
        if (!in_array($field, $recurrenceColumns, true)) $pdo->exec("ALTER TABLE recurrences ADD COLUMN {$field} {$definition}");
    }
    $invoiceColumns = array_column($pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
    foreach ([
        'subtotal_cents' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'invoice_discount_type' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
        'invoice_discount_value' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'invoice_discount_cents' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'invoice_discount_label' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $field => $definition) {
        if (!in_array($field, $invoiceColumns, true)) $pdo->exec("ALTER TABLE invoices ADD COLUMN {$field} {$definition}");
    }
    $pdo->exec('UPDATE invoices SET subtotal_cents = total_cents WHERE subtotal_cents = 0 AND total_cents > 0');
    $paymentColumns = array_column($pdo->query('SHOW COLUMNS FROM payments')->fetchAll(), 'Field');
    if (!in_array('discount_cents', $paymentColumns, true)) $pdo->exec('ALTER TABLE payments ADD COLUMN discount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_cents');
    if (!in_array('discount_scope', $paymentColumns, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN discount_scope VARCHAR(20) NOT NULL DEFAULT '' AFTER discount_cents");
    if (!in_array('receipt_number', $paymentColumns, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(80) NOT NULL DEFAULT '' AFTER discount_scope");
    $deliveryColumns = array_column($pdo->query('SHOW COLUMNS FROM email_deliveries')->fetchAll(), 'Field');
    if (!in_array('auto_send', $deliveryColumns, true)) $pdo->exec('ALTER TABLE email_deliveries ADD COLUMN auto_send TINYINT(1) NOT NULL DEFAULT 0 AFTER recipient');
    $receiptMigrated = $pdo->query("SELECT value FROM app_meta WHERE `key`='collection_receipts_v1'")->fetchColumn();
    if ($receiptMigrated === false) {
        $paymentCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND CONSTRAINT_NAME='chk_payments_amount'");
        $paymentCheck->execute();
        if ((int)$paymentCheck->fetchColumn() > 0) {
            try { $pdo->exec('ALTER TABLE payments DROP CHECK chk_payments_amount'); }
            catch (Throwable) { $pdo->exec('ALTER TABLE payments DROP CONSTRAINT chk_payments_amount'); }
        }
        $pdo->exec('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_cents >= 0)');
        $pdo->exec("INSERT INTO app_meta (`key`, value) VALUES ('collection_receipts_v1', CURRENT_TIMESTAMP)");
    }
    $billingCycleMigrated = $pdo->query("SELECT value FROM app_meta WHERE `key`='billing_cycles_v2'")->fetchColumn();
    if ($billingCycleMigrated === false) {
        $frequencyCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='recurrences' AND CONSTRAINT_NAME='chk_recurrences_frequency'");
        $frequencyCheck->execute();
        if ((int)$frequencyCheck->fetchColumn() > 0) {
            try { $pdo->exec('ALTER TABLE recurrences DROP CHECK chk_recurrences_frequency'); }
            catch (Throwable) { $pdo->exec('ALTER TABLE recurrences DROP CONSTRAINT chk_recurrences_frequency'); }
        }
        $pdo->exec("ALTER TABLE recurrences ADD CONSTRAINT chk_recurrences_frequency CHECK (frequency IN ('monthly','quarterly','half_yearly','yearly','biennial','triennial','quadrennial','quinquennial'))");
        $pdo->exec("INSERT INTO app_meta (`key`, value) VALUES ('billing_cycles_v2', CURRENT_TIMESTAMP)");
    }

    $seeded = $pdo->query("SELECT value FROM app_meta WHERE `key` = 'super_admin_seed_v1'")->fetchColumn();
    if ($seeded === false) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin') ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role=VALUES(role)");
            $stmt->execute(['Super Admin', DEFAULT_SUPER_ADMIN_EMAIL, DEFAULT_SUPER_ADMIN_HASH]);
            $pdo->prepare('INSERT INTO app_meta (`key`, value) VALUES (?, ?)')->execute(['super_admin_seed_v1', date('c')]);
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
    $row = query_one('SELECT value FROM app_settings WHERE `key` = ?', [$key]);
    return $row ? (string)$row['value'] : $default;
}

function save_settings(array $values): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)'
            : 'INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value';
        $stmt = $pdo->prepare($sql);
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
        throw new InvalidArgumentException('Enter a valid amount.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    if ((float)$whole > 1000000000) {
        throw new InvalidArgumentException('The amount is too large.');
    }
    return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
}

function format_money(int $cents): string
{
    return 'BDT ' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
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
        throw new InvalidArgumentException('Enter a valid 11-digit Bangladeshi mobile number.');
    }
    return $digits;
}

function search_clients(string $term, int $limit = 8): array
{
    $term = trim($term);
    $digits = preg_replace('/\D+/', '', $term);
    if (str_starts_with($digits, '880')) $digits = '0' . substr($digits, 3);
    $emailTerm = mb_strtolower($term);
    if (strlen($digits) < 3 && mb_strlen($emailTerm) < 2) return [];
    $phoneLike = $digits !== '' ? '%' . $digits . '%' : '__no_phone_match__';
    $emailLike = '%' . $emailTerm . '%';
    return query_all(
        'SELECT id, name, company_name, address, phone, COALESCE(email, \'\') email
         FROM clients
         WHERE phone LIKE ? OR lower(COALESCE(email, \'\')) LIKE ? OR lower(name) LIKE ?
         ORDER BY CASE WHEN phone = ? OR lower(COALESCE(email, \'\')) = ? OR lower(name) = ? THEN 0 ELSE 1 END, name
         LIMIT ' . max(1, min(20, $limit)),
        [$phoneLike, $emailLike, $emailLike, $digits, $emailTerm, $emailTerm]
    );
}

function valid_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Select a valid date.');
    }
    return $date;
}

function add_days(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
}

function billing_frequency_options(): array
{
    return [
        'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half Yearly',
        'yearly' => 'Yearly', 'biennial' => 'Biennial', 'triennial' => 'Triennial',
        'quadrennial' => 'Quadrennial', 'quinquennial' => 'Quinquennial',
    ];
}

function next_cycle_date(string $current, string $frequency, int $anchorDay, int $anchorMonth): string
{
    $date = new DateTimeImmutable($current);
    $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12, 'biennial' => 24, 'triennial' => 36, 'quadrennial' => 48, 'quinquennial' => 60][$frequency] ?? 1;
    $first = $date->modify('first day of this month')->modify('+' . $months . ' months');
    $day = min($anchorDay, (int)$first->format('t'));
    // For yearly schedules, the original month remains the billing month.
    if ($months >= 12 && (int)$first->format('n') !== $anchorMonth) {
        $first = $first->setDate((int)$first->format('Y'), $anchorMonth, 1);
        $day = min($anchorDay, (int)$first->format('t'));
    }
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day)->format('Y-m-d');
}

function invoice_rows(string $where = '', array $params = [], string $order = 'i.id DESC', int $limit = 100): array
{
    $sql = 'SELECT i.*, i.billing_name AS client_name, i.billing_phone AS client_phone,
            i.billing_email AS client_email, r.frequency,
            COALESCE((SELECT SUM(p.amount_cents + p.discount_cents) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid_cents,
            COALESCE((SELECT SUM(p.discount_cents) FROM payments p WHERE p.invoice_id = i.id), 0) AS discount_cents
            FROM invoices i JOIN clients c ON c.id = i.client_id
            LEFT JOIN recurrences r ON r.id = i.recurrence_id ' . $where .
            ' ORDER BY ' . $order . ' LIMIT ' . (int)$limit;
    return query_all($sql, $params);
}

function client_ledger_statement(int $clientId): array
{
    $client = query_one('SELECT * FROM clients WHERE id=?', [$clientId]);
    if (!$client) throw new InvalidArgumentException('Client not found.');
    $events = [];
    foreach (query_all('SELECT id, number, issue_date, total_cents FROM invoices WHERE client_id=? ORDER BY issue_date, id', [$clientId]) as $invoice) {
        $events[] = [
            'date' => $invoice['issue_date'], 'invoice_id' => (int)$invoice['id'], 'invoice_number' => $invoice['number'],
            'debit_cents' => (int)$invoice['total_cents'], 'credit_cents' => 0,
            'cash_cents' => 0, 'discount_cents' => 0, 'kind' => 'invoice',
            'sort_order' => 0, 'source_id' => (int)$invoice['id'],
        ];
    }
    foreach (query_all('SELECT p.id, p.paid_at, p.amount_cents, p.discount_cents, i.id invoice_id, i.number invoice_number FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.client_id=? ORDER BY p.paid_at, p.id', [$clientId]) as $payment) {
        $cash = (int)$payment['amount_cents'];
        $discount = (int)$payment['discount_cents'];
        $events[] = [
            'date' => $payment['paid_at'], 'invoice_id' => (int)$payment['invoice_id'], 'invoice_number' => $payment['invoice_number'],
            'debit_cents' => 0, 'credit_cents' => $cash + $discount,
            'cash_cents' => $cash, 'discount_cents' => $discount, 'kind' => 'collection',
            'sort_order' => 1, 'source_id' => (int)$payment['id'],
        ];
    }
    usort($events, static fn(array $a, array $b): int => [$a['date'], $a['sort_order'], $a['source_id']] <=> [$b['date'], $b['sort_order'], $b['source_id']]);
    $balance = 0;
    $totalDebit = 0;
    $totalCredit = 0;
    $totalCash = 0;
    $totalDiscount = 0;
    foreach ($events as $index => &$event) {
        $totalDebit += $event['debit_cents'];
        $totalCredit += $event['credit_cents'];
        $totalCash += $event['cash_cents'];
        $totalDiscount += $event['discount_cents'];
        $balance += $event['debit_cents'] - $event['credit_cents'];
        $event['serial'] = $index + 1;
        $event['balance_cents'] = $balance;
    }
    unset($event);
    return [
        'client' => $client, 'entries' => $events,
        'total_debit_cents' => $totalDebit, 'total_credit_cents' => $totalCredit,
        'total_cash_cents' => $totalCash, 'total_discount_cents' => $totalDiscount,
        'balance_cents' => $balance,
    ];
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
        throw new InvalidArgumentException('Select an active payment method.');
    }
    return $id;
}

function get_or_create_client(PDO $pdo, string $name, string $phone, string $email, string $companyName): int
{
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter the client name.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
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

function create_client_account(array $input): int
{
    $name = trim((string)($input['client_name'] ?? ''));
    $companyName = trim((string)($input['company_name'] ?? ''));
    $address = trim((string)($input['client_address'] ?? ''));
    $phone = normalize_phone((string)($input['client_phone'] ?? ''));
    $email = mb_strtolower(trim((string)($input['client_email'] ?? '')));
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter a client name of up to 150 characters.');
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Enter a company name of up to 150 characters.');
    if (mb_strlen($address) > 300) throw new InvalidArgumentException('Enter an address of up to 300 characters.');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) throw new InvalidArgumentException('Enter a valid email address.');
    if (query_one('SELECT id FROM clients WHERE phone = ?', [$phone])) throw new InvalidArgumentException('A client already exists with this mobile number.');
    if ($email !== '' && query_one("SELECT id FROM clients WHERE lower(COALESCE(email, '')) = ?", [$email])) throw new InvalidArgumentException('A client already exists with this email address.');
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO clients (name, company_name, address, phone, email) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $companyName, $address, $phone, $email !== '' ? $email : null]);
    return (int)$pdo->lastInsertId();
}

function update_client_account(int $id, array $input): void
{
    $client = query_one('SELECT id FROM clients WHERE id=?', [$id]);
    if (!$client) throw new InvalidArgumentException('Client not found.');
    $name = trim((string)($input['client_name'] ?? ''));
    $companyName = trim((string)($input['company_name'] ?? ''));
    $address = trim((string)($input['client_address'] ?? ''));
    $phone = normalize_phone((string)($input['client_phone'] ?? ''));
    $email = mb_strtolower(trim((string)($input['client_email'] ?? '')));
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter a client name of up to 150 characters.');
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Enter a company name of up to 150 characters.');
    if (mb_strlen($address) > 300) throw new InvalidArgumentException('Enter an address of up to 300 characters.');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) throw new InvalidArgumentException('Enter a valid email address.');
    if (query_one('SELECT id FROM clients WHERE phone=? AND id<>?', [$phone, $id])) throw new InvalidArgumentException('This mobile number belongs to another client.');
    if ($email !== '' && query_one("SELECT id FROM clients WHERE lower(COALESCE(email, ''))=? AND id<>?", [$email, $id])) throw new InvalidArgumentException('This email address belongs to another client.');
    db()->prepare('UPDATE clients SET name=?, company_name=?, address=?, phone=?, email=? WHERE id=?')->execute([$name, $companyName, $address, $phone, $email !== '' ? $email : null, $id]);
}

function delete_client_account(int $id): void
{
    if (!query_one('SELECT id FROM clients WHERE id=?', [$id])) throw new InvalidArgumentException('Client not found.');
    $invoiceCount = (int)(query_one('SELECT COUNT(*) total FROM invoices WHERE client_id=?', [$id])['total'] ?? 0);
    $recurrenceCount = (int)(query_one('SELECT COUNT(*) total FROM recurrences WHERE client_id=?', [$id])['total'] ?? 0);
    if ($invoiceCount > 0 || $recurrenceCount > 0) {
        throw new InvalidArgumentException('This client has invoices or recurring schedules and cannot be deleted.');
    }
    db()->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
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
        throw new InvalidArgumentException('Add at least one service or custom item.');
    }
    $items = [];
    foreach ($names as $i => $rawName) {
        $serviceId = (int)($serviceIds[$i] ?? 0);
        $service = $serviceId ? ($services[$serviceId] ?? null) : null;
        if ($serviceId && !$service) throw new InvalidArgumentException('The selected service was not found.');
        $name = trim((string)$rawName);
        if ($name === '' && $service) $name = $service['name'];
        if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter a name for each item.');
        $quantity = filter_var($quantities[$i] ?? null, FILTER_VALIDATE_FLOAT);
        if ($quantity === false || $quantity <= 0 || $quantity > 100000) {
            throw new InvalidArgumentException('Enter a valid quantity.');
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

function parse_recurrence_discount(array $input, int $subtotalCents): array
{
    if (!isset($input['discount_enabled'])) {
        return ['type' => 'none', 'value' => 0, 'scope' => 'none', 'cycles_remaining' => 0, 'note' => ''];
    }
    $type = (string)($input['discount_type'] ?? '');
    $scope = (string)($input['discount_scope'] ?? '');
    if (!in_array($type, ['fixed', 'percent'], true)) throw new InvalidArgumentException('Select a valid recurring discount type.');
    if (!in_array($scope, ['next_invoice', 'every_cycle', 'limited'], true)) throw new InvalidArgumentException('Select when the recurring discount should apply.');
    $rawValue = trim((string)($input['discount_value'] ?? ''));
    if ($rawValue === '' || !is_numeric($rawValue) || (float)$rawValue <= 0) throw new InvalidArgumentException('Enter a recurring discount greater than zero.');
    if ($type === 'fixed') {
        $value = money_cents($rawValue);
        if ($value > $subtotalCents) throw new InvalidArgumentException('The recurring discount cannot exceed the invoice subtotal.');
    } else {
        $percent = (float)$rawValue;
        if ($percent > 100) throw new InvalidArgumentException('The percentage discount cannot exceed 100%.');
        $value = (int)round($percent * 100);
    }
    $cycles = $scope === 'limited' ? (int)($input['discount_cycles'] ?? 0) : 0;
    if ($scope === 'limited' && ($cycles < 1 || $cycles > 120)) throw new InvalidArgumentException('Limited discounts must apply for 1 to 120 invoices.');
    return [
        'type' => $type,
        'value' => $value,
        'scope' => $scope,
        'cycles_remaining' => $cycles,
        'note' => mb_substr(trim((string)($input['discount_note'] ?? '')), 0, 200),
    ];
}

function recurrence_discount_from_row(array $row): array
{
    return [
        'type' => (string)($row['discount_type'] ?? 'none'),
        'value' => (int)($row['discount_value'] ?? 0),
        'scope' => (string)($row['discount_scope'] ?? 'none'),
        'cycles_remaining' => (int)($row['discount_cycles_remaining'] ?? 0),
        'note' => (string)($row['discount_note'] ?? ''),
    ];
}

function calculate_invoice_discount(int $subtotalCents, array $rule): array
{
    $type = (string)($rule['type'] ?? 'none');
    $value = (int)($rule['value'] ?? 0);
    $scope = (string)($rule['scope'] ?? 'none');
    $active = in_array($type, ['fixed', 'percent'], true) && in_array($scope, ['next_invoice', 'every_cycle', 'limited'], true) && $value > 0;
    if ($scope === 'limited' && (int)($rule['cycles_remaining'] ?? 0) < 1) $active = false;
    if (!$active) return ['type' => 'none', 'value' => 0, 'cents' => 0, 'label' => ''];
    $cents = $type === 'fixed' ? $value : (int)round($subtotalCents * $value / 10000);
    $cents = min($subtotalCents, max(0, $cents));
    $label = trim((string)($rule['note'] ?? ''));
    if ($label === '') {
        $label = $type === 'percent'
            ? rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.') . '% recurring discount'
            : 'Recurring discount';
    }
    return ['type' => $type, 'value' => $value, 'cents' => $cents, 'label' => $label];
}

function parse_invoice_discount(array $input, int $subtotalCents): array
{
    $mapped = $input;
    $mapped['discount_scope'] = 'every_cycle';
    $rule = parse_recurrence_discount($mapped, $subtotalCents);
    $discount = calculate_invoice_discount($subtotalCents, $rule);
    if ($discount['cents'] > 0 && trim((string)($input['discount_note'] ?? '')) === '') {
        $discount['label'] = $discount['type'] === 'percent'
            ? rtrim(rtrim(number_format($discount['value'] / 100, 2, '.', ''), '0'), '.') . '% invoice discount'
            : 'Invoice discount';
    }
    return $discount;
}

function advance_recurrence_discount(PDO $pdo, int $recurrenceId, array $rule): array
{
    if (($rule['scope'] ?? 'none') === 'next_invoice') {
        $rule = ['type' => 'none', 'value' => 0, 'scope' => 'none', 'cycles_remaining' => 0, 'note' => ''];
    } elseif (($rule['scope'] ?? 'none') === 'limited') {
        $rule['cycles_remaining'] = max(0, (int)($rule['cycles_remaining'] ?? 0) - 1);
        if ($rule['cycles_remaining'] < 1) $rule = ['type' => 'none', 'value' => 0, 'scope' => 'none', 'cycles_remaining' => 0, 'note' => ''];
    }
    $pdo->prepare('UPDATE recurrences SET discount_type=?, discount_value=?, discount_scope=?, discount_cycles_remaining=?, discount_note=? WHERE id=?')
        ->execute([$rule['type'], $rule['value'], $rule['scope'], $rule['cycles_remaining'], $rule['note'], $recurrenceId]);
    return $rule;
}

function insert_invoice(PDO $pdo, int $clientId, array $items, string $issueDate, string $dueDate, string $notes, array $billing, ?int $recurrenceId = null, ?int $paymentMethodId = null, array $discountRule = []): int
{
    $subtotal = array_sum(array_column($items, 'total_cents'));
    $discount = calculate_invoice_discount($subtotal, $discountRule);
    $total = $subtotal - $discount['cents'];
    $stmt = $pdo->prepare('INSERT INTO invoices (number, client_id, billing_name, billing_phone, billing_email, billing_company_name, recurrence_id, cycle_date, issue_date, due_date, notes, subtotal_cents, invoice_discount_type, invoice_discount_value, invoice_discount_cents, invoice_discount_label, total_cents, payment_method_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(['PENDING-' . bin2hex(random_bytes(8)), $clientId, $billing['name'], $billing['phone'], $billing['email'], $billing['company_name'], $recurrenceId, $recurrenceId ? $issueDate : null, $issueDate, $dueDate, $notes, $subtotal, $discount['type'], $discount['value'], $discount['cents'], $discount['label'], $total, $paymentMethodId]);
    $id = (int)$pdo->lastInsertId();
    $number = 'INV-' . date('Y', strtotime($issueDate)) . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE invoices SET number = ? WHERE id = ?')->execute([$number, $id]);
    $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, service_id, name, description, quantity, unit_price_cents, total_cents) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $item) {
        $itemStmt->execute([$id, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents'], $item['total_cents']]);
    }
    return $id;
}

function queue_invoice_email(PDO $pdo, int $invoiceId, string $recipient, bool $autoSend = false): void
{
    $recipient = mb_strtolower(trim($recipient));
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) return;
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'INSERT IGNORE INTO email_deliveries (invoice_id, recipient, auto_send) VALUES (?, ?, ?)'
        : 'INSERT OR IGNORE INTO email_deliveries (invoice_id, recipient, auto_send) VALUES (?, ?, ?)';
    $pdo->prepare($sql)->execute([$invoiceId, $recipient, $autoSend ? 1 : 0]);
}

function reset_invoice_email_delivery(PDO $pdo, int $invoiceId, string $recipient): void
{
    $recipient = mb_strtolower(trim($recipient));
    $delivery = query_one('SELECT id FROM email_deliveries WHERE invoice_id=?', [$invoiceId]);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        if ($delivery) {
            $pdo->prepare("UPDATE email_deliveries SET recipient='', auto_send=0, status='skipped', attempts=0, last_error='A valid client email address was not provided.', sent_at=NULL, updated_at=? WHERE invoice_id=?")
                ->execute([date('Y-m-d H:i:s'), $invoiceId]);
        }
        return;
    }
    if ($delivery) {
        $pdo->prepare("UPDATE email_deliveries SET recipient=?, auto_send=0, status='pending', attempts=0, last_error='', next_attempt_at=?, sent_at=NULL, updated_at=? WHERE invoice_id=?")
            ->execute([$recipient, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $invoiceId]);
    } else {
        queue_invoice_email($pdo, $invoiceId, $recipient);
    }
}

function create_invoice(array $input): int
{
    $pdo = db();
    $issueDate = valid_date((string)($input['issue_date'] ?? ''));
    $dueDate = valid_date((string)($input['due_date'] ?? ''));
    if ($dueDate < $issueDate) throw new InvalidArgumentException('The due date cannot be earlier than the issue date.');
    $type = (string)($input['invoice_type'] ?? 'one_time');
    if (!in_array($type, ['one_time', 'recurring'], true)) throw new InvalidArgumentException('Select an invoice type.');
    $items = parse_items($input);
    $subtotal = array_sum(array_column($items, 'total_cents'));
    $discountRule = $type === 'recurring' ? parse_recurrence_discount($input, $subtotal) : [];
    $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 2000);
    $paymentMethodId = selected_payment_method_id($input);
    $companyName = trim((string)($input['company_name'] ?? ''));
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Enter a company name of up to 150 characters.');
    $pdo->beginTransaction();
    try {
        $clientId = get_or_create_client($pdo, trim((string)($input['client_name'] ?? '')), (string)($input['client_phone'] ?? ''), trim((string)($input['client_email'] ?? '')), $companyName);
        $client = query_one('SELECT name, phone, email, company_name FROM clients WHERE id = ?', [$clientId]);
        $billingCompanyName = $companyName !== '' ? $companyName : (string)$client['company_name'];
        $billing = ['name' => $client['name'], 'phone' => $client['phone'], 'email' => $client['email'] ?? '', 'company_name' => $billingCompanyName];
        $recurrenceId = null;
        if ($type === 'recurring') {
            $frequency = (string)($input['frequency'] ?? 'monthly');
            if (!array_key_exists($frequency, billing_frequency_options())) throw new InvalidArgumentException('Select a valid recurring frequency.');
            $dueDays = (new DateTimeImmutable($issueDate))->diff(new DateTimeImmutable($dueDate))->days;
            $nextDate = next_cycle_date($issueDate, $frequency, (int)date('j', strtotime($issueDate)), (int)date('n', strtotime($issueDate)));
            $stmt = $pdo->prepare('INSERT INTO recurrences (client_id, frequency, next_issue_date, due_days, anchor_day, anchor_month, notes, payment_method_id, discount_type, discount_value, discount_scope, discount_cycles_remaining, discount_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$clientId, $frequency, $nextDate, $dueDays, (int)date('j', strtotime($issueDate)), (int)date('n', strtotime($issueDate)), $notes, $paymentMethodId, $discountRule['type'], $discountRule['value'], $discountRule['scope'], $discountRule['cycles_remaining'], $discountRule['note']]);
            $recurrenceId = (int)$pdo->lastInsertId();
            $itemStmt = $pdo->prepare('INSERT INTO recurrence_items (recurrence_id, service_id, name, description, quantity, unit_price_cents) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $itemStmt->execute([$recurrenceId, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents']]);
            }
        }
        $id = insert_invoice($pdo, $clientId, $items, $issueDate, $dueDate, $notes, $billing, $recurrenceId, $paymentMethodId, $discountRule);
        if ($recurrenceId !== null && ($discountRule['type'] ?? 'none') !== 'none') advance_recurrence_discount($pdo, $recurrenceId, $discountRule);
        queue_invoice_email($pdo, $id, (string)$billing['email']);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function edit_invoice(int $invoiceId, array $input): void
{
    if ($invoiceId < 1) throw new InvalidArgumentException('Invoice not found.');
    $issueDate = valid_date((string)($input['issue_date'] ?? ''));
    $dueDate = valid_date((string)($input['due_date'] ?? ''));
    if ($dueDate < $issueDate) throw new InvalidArgumentException('The due date cannot be earlier than the issue date.');
    $name = trim((string)($input['client_name'] ?? ''));
    $phone = normalize_phone((string)($input['client_phone'] ?? ''));
    $email = trim((string)($input['client_email'] ?? ''));
    $companyName = trim((string)($input['company_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) throw new InvalidArgumentException('Enter the client name.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (mb_strlen($companyName) > 150) throw new InvalidArgumentException('Enter a company name of up to 150 characters.');
    $items = parse_items($input, true);
    $subtotal = array_sum(array_column($items, 'total_cents'));
    $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 2000);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $invoice = query_one('SELECT i.id, i.total_cents, i.payment_method_id, i.invoice_discount_type, i.invoice_discount_value, i.invoice_discount_label, COALESCE(SUM(p.amount_cents + p.discount_cents), 0) AS paid_cents FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id WHERE i.id = ? GROUP BY i.id', [$invoiceId]);
        if (!$invoice) throw new InvalidArgumentException('Invoice not found.');
        $discount = parse_invoice_discount($input, $subtotal);
        $total = $subtotal - $discount['cents'];
        if ($total < (int)$invoice['paid_cents']) throw new InvalidArgumentException('The new invoice total cannot be less than the amount already collected.');
        $clientId = get_or_create_client($pdo, $name, $phone, $email, $companyName);
        $paymentMethodId = selected_payment_method_id($input, (int)$invoice['payment_method_id']);
        $stmt = $pdo->prepare('UPDATE invoices SET client_id=?, billing_name=?, billing_phone=?, billing_email=?, billing_company_name=?, issue_date=?, due_date=?, notes=?, subtotal_cents=?, invoice_discount_type=?, invoice_discount_value=?, invoice_discount_cents=?, invoice_discount_label=?, total_cents=?, payment_method_id=? WHERE id=?');
        $stmt->execute([$clientId, $name, $phone, $email, $companyName, $issueDate, $dueDate, $notes, $subtotal, $discount['type'], $discount['value'], $discount['cents'], $discount['label'], $total, $paymentMethodId, $invoiceId]);
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
        $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, service_id, name, description, quantity, unit_price_cents, total_cents) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $itemStmt->execute([$invoiceId, $item['service_id'], $item['name'], $item['description'], $item['quantity'], $item['unit_price_cents'], $item['total_cents']]);
        }
        reset_invoice_email_delivery($pdo, $invoiceId, $email);
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
    if ($cents <= 0) throw new InvalidArgumentException('The collection amount must be greater than zero.');
    if (!in_array($method, ['cash', 'bank', 'bkash', 'nagad', 'card', 'other'], true)) throw new InvalidArgumentException('Select a payment method.');
    $paidAt = valid_date($paidAt);
    $pdo->beginTransaction();
    try {
        $invoice = query_one('SELECT i.total_cents, COALESCE(SUM(p.amount_cents + p.discount_cents),0) paid_cents FROM invoices i LEFT JOIN payments p ON p.invoice_id = i.id WHERE i.id = ? GROUP BY i.id', [$invoiceId]);
        if (!$invoice) throw new InvalidArgumentException('Invoice not found.');
        $remaining = (int)$invoice['total_cents'] - (int)$invoice['paid_cents'];
        if ($cents > $remaining) throw new InvalidArgumentException('The collection amount cannot exceed the balance due.');
        $stmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount_cents, method, reference, notes, paid_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $cents, $method, mb_substr(trim($reference), 0, 120), mb_substr(trim($notes), 0, 500), $paidAt]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_recurrence_frequency(int $recurrenceId, string $frequency): void
{
    if (!array_key_exists($frequency, billing_frequency_options())) throw new InvalidArgumentException('Select a valid billing cycle.');
    $schedule = query_one('SELECT * FROM recurrences WHERE id=?', [$recurrenceId]);
    if (!$schedule) throw new InvalidArgumentException('Recurring schedule not found.');
    $base = max(date('Y-m-d'), (string)$schedule['next_issue_date']);
    $next = next_cycle_date($base, $frequency, (int)$schedule['anchor_day'], (int)$schedule['anchor_month']);
    db()->prepare('UPDATE recurrences SET frequency=?, next_issue_date=? WHERE id=?')->execute([$frequency, $next, $recurrenceId]);
}

function update_recurrence_discount(int $recurrenceId, array $input): void
{
    $schedule = query_one('SELECT id FROM recurrences WHERE id=?', [$recurrenceId]);
    if (!$schedule) throw new InvalidArgumentException('Recurring schedule not found.');
    $items = query_all('SELECT quantity, unit_price_cents FROM recurrence_items WHERE recurrence_id=?', [$recurrenceId]);
    $subtotal = array_sum(array_map(static fn(array $item): int => (int)round((float)$item['quantity'] * (int)$item['unit_price_cents']), $items));
    $rule = parse_recurrence_discount($input, $subtotal);
    db()->prepare('UPDATE recurrences SET discount_type=?, discount_value=?, discount_scope=?, discount_cycles_remaining=?, discount_note=? WHERE id=?')
        ->execute([$rule['type'], $rule['value'], $rule['scope'], $rule['cycles_remaining'], $rule['note'], $recurrenceId]);
}

function collect_multiple_invoices(array $invoiceIds, string $amount, string $discount, string $discountScope, string $method, string $reference, string $notes, string $paidAt): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) throw new InvalidArgumentException('Select at least one invoice.');
    $amountCents = money_cents($amount);
    $discountCents = trim($discount) === '' ? 0 : money_cents($discount);
    if ($amountCents <= 0) throw new InvalidArgumentException('The collection amount must be greater than zero.');
    if ($discountCents > 0 && !in_array($discountScope, ['one_time', 'recurring'], true)) throw new InvalidArgumentException('Confirm whether the discount is for one-time or recurring invoices.');
    if (!in_array($method, ['cash', 'bank', 'bkash', 'nagad', 'card', 'other'], true)) throw new InvalidArgumentException('Select a payment method.');
    $paidAt = valid_date($paidAt);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = invoice_rows("WHERE i.id IN ({$placeholders})", $ids, 'i.issue_date ASC, i.id ASC', count($ids));
        if (count($rows) !== count($ids)) throw new InvalidArgumentException('One or more selected invoices were not found.');
        if (count(array_unique(array_column($rows, 'client_id'))) !== 1) throw new InvalidArgumentException('All selected invoices must belong to the same client.');
        if ($discountCents > 0) {
            foreach ($rows as $row) {
                $type = $row['recurrence_id'] ? 'recurring' : 'one_time';
                if ($type !== $discountScope) throw new InvalidArgumentException('The selected invoices do not match the confirmed discount type.');
            }
        }
        $outstanding = array_sum(array_map(static fn(array $row): int => max(0, (int)$row['total_cents'] - (int)$row['paid_cents']), $rows));
        if ($amountCents + $discountCents > $outstanding) throw new InvalidArgumentException('Collection and discount cannot exceed the selected balance.');
        $receipt = 'MR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $cashLeft = $amountCents;
        $discountLeft = $discountCents;
        $stmt = $pdo->prepare('INSERT INTO payments (invoice_id, amount_cents, discount_cents, discount_scope, receipt_number, method, reference, notes, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $due = max(0, (int)$row['total_cents'] - (int)$row['paid_cents']);
            if ($due < 1 || ($cashLeft < 1 && $discountLeft < 1)) continue;
            $cashPart = min($cashLeft, $due);
            $cashLeft -= $cashPart;
            $due -= $cashPart;
            $discountPart = min($discountLeft, $due);
            $discountLeft -= $discountPart;
            if ($cashPart + $discountPart > 0) {
                $stmt->execute([(int)$row['id'], $cashPart, $discountPart, $discountPart > 0 ? $discountScope : '', $receipt, $method, mb_substr(trim($reference), 0, 120), mb_substr(trim($notes), 0, 500), $paidAt]);
            }
        }
        if ($cashLeft > 0 || $discountLeft > 0) throw new InvalidArgumentException('The selected invoices cannot absorb the full collection amount.');
        $pdo->commit();
        return $receipt;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
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
            $discountRule = recurrence_discount_from_row($recurrence);
            $items = query_all('SELECT * FROM recurrence_items WHERE recurrence_id = ? ORDER BY id', [(int)$recurrence['id']]);
            for ($n = 0; $next <= $today && $n < 120; $n++) {
                $invoiceItems = array_map(static function ($item) {
                    $item['total_cents'] = (int)round((int)$item['unit_price_cents'] * (float)$item['quantity']);
                    return $item;
                }, $items);
                $billing = ['name' => $recurrence['billing_name'], 'phone' => $recurrence['billing_phone'], 'email' => $recurrence['billing_email'] ?? '', 'company_name' => $recurrence['billing_company_name']];
                $invoiceId = insert_invoice($pdo, (int)$recurrence['client_id'], $invoiceItems, $next, add_days($next, (int)$recurrence['due_days']), $recurrence['notes'], $billing, (int)$recurrence['id'], $recurrence['payment_method_id'] !== null ? (int)$recurrence['payment_method_id'] : null, $discountRule);
                if (($discountRule['type'] ?? 'none') !== 'none') $discountRule = advance_recurrence_discount($pdo, (int)$recurrence['id'], $discountRule);
                 queue_invoice_email($pdo, $invoiceId, (string)($billing['email'] ?? ''), true);
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
