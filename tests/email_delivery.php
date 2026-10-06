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

    $reminder = send_invoice_reminder_for_invoice($id);
    if ($reminder['status'] !== 'pending') throw new RuntimeException('Missing SMTP configuration must keep reminder pending');
    $reminderLog = query_one('SELECT recipient,status,attempts,last_error FROM invoice_reminders WHERE invoice_id=? ORDER BY id DESC LIMIT 1', [$id]);
    if (!$reminderLog || $reminderLog['recipient'] !== 'client@example.test' || $reminderLog['status'] !== 'pending' || (int)$reminderLog['attempts'] !== 0 || $reminderLog['last_error'] === '') throw new RuntimeException('Reminder delivery log is invalid');

    collect_payment($id, '100', 'cash', 'PAID-FOR-REMINDER-TEST', '', date('Y-m-d'));
    try {
        send_invoice_reminder_for_invoice($id);
        throw new RuntimeException('A paid invoice accepted a payment reminder');
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'fully paid')) throw $error;
    }
    if ((int)query_one('SELECT COUNT(*) total FROM invoice_reminders WHERE invoice_id=?', [$id])['total'] !== 1) throw new RuntimeException('Paid invoice reminder attempt must not create a log');

    $fallbackId = create_invoice([
        'client_name' => 'Updated Email Client',
        'client_phone' => '01812345678',
        'client_email' => '',
        'invoice_type' => 'one_time',
        'issue_date' => date('Y-m-d'),
        'due_date' => add_days(date('Y-m-d'), 7),
        'item_service_id' => [''],
        'item_name' => ['Email fallback test'],
        'item_description' => [''],
        'item_qty' => ['1'],
        'item_price' => ['200'],
    ]);
    $fallbackInvoice = invoice_rows('WHERE i.id=?', [$fallbackId], 'i.id DESC', 1)[0];
    update_client_account((int)$fallbackInvoice['client_id'], ['client_name'=>'Updated Email Client','company_name'=>'','client_address'=>'','client_phone'=>'01812345678','client_email'=>'updated@example.test']);
    $fallbackInvoice = invoice_rows('WHERE i.id=?', [$fallbackId], 'i.id DESC', 1)[0];
    if (invoice_recipient_email($fallbackInvoice) !== 'updated@example.test') throw new RuntimeException('Current client email was not used as the invoice recipient fallback');
    if (send_invoice_email_for_invoice($fallbackId)['status'] !== 'pending') throw new RuntimeException('Fallback invoice mail did not reach the SMTP configuration check');
    $fallbackDelivery = query_one('SELECT recipient,status FROM email_deliveries WHERE invoice_id=?', [$fallbackId]);
    if (!$fallbackDelivery || $fallbackDelivery['recipient'] !== 'updated@example.test' || $fallbackDelivery['status'] !== 'pending') throw new RuntimeException('Skipped invoice delivery was not repaired with the current client email');
    if (send_invoice_reminder_for_invoice($fallbackId)['status'] !== 'pending') throw new RuntimeException('Fallback reminder did not reach the SMTP configuration check');
    $fallbackReminder = query_one('SELECT recipient FROM invoice_reminders WHERE invoice_id=? ORDER BY id DESC LIMIT 1', [$fallbackId]);
    if (!$fallbackReminder || $fallbackReminder['recipient'] !== 'updated@example.test') throw new RuntimeException('Reminder did not use the current client email');
    echo "Email delivery passed: invoice and reminder queues handle missing SMTP and paid invoices safely.\n";
} finally {
    close_db();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '.key'] as $file) if (is_file($file)) unlink($file);
}
