<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'invoice-email-');
if ($path === false) throw new RuntimeException('Temporary path unavailable');
unlink($path);
putenv('INVOICE_DB_PATH=' . $path);
putenv('INVOICE_SMTP_KEY_PATH=' . $path . '.key');
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/invoice_mailer.php';

try {
    $id = create_invoice([
        'client_name' => 'Email Client',
        'client_phone' => '01712345678',
        'client_email' => 'client@example.test',
        'invoice_type' => 'one_time',
        'issue_date' => date('Y-m-d'),
        'due_date' => add_days(date('Y-m-d'), 7),
        'item_service_id' => [''],
        'item_name' => ['Email test'],
        'item_description' => [''],
        'item_qty' => ['1'],
        'item_price' => ['100'],
    ]);
    $queued = query_one('SELECT * FROM email_deliveries WHERE invoice_id=?', [$id]);
    if (!$queued || $queued['recipient'] !== 'client@example.test' || $queued['status'] !== 'pending') throw new RuntimeException('Invoice email was not queued');
    $delivery = send_invoice_email_for_invoice($id);
    if ($delivery['status'] !== 'pending') throw new RuntimeException('Missing SMTP configuration must keep delivery pending');
    $queued = query_one('SELECT status, attempts, last_error FROM email_deliveries WHERE invoice_id=?', [$id]);
    if ($queued['status'] !== 'pending' || (int)$queued['attempts'] !== 0 || $queued['last_error'] === '') throw new RuntimeException('Pending delivery state is invalid');
    echo "Email delivery passed: invoice queued and missing SMTP handled safely.\n";
} finally {
    close_db();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '.key'] as $file) if (is_file($file)) unlink($file);
}
