<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';
$mailerAvailable = is_file(__DIR__ . '/vendor/autoload.php');
if ($mailerAvailable) require __DIR__ . '/invoice_mailer.php';
try {
    $count = generate_due_invoices();
    $mail = $mailerAvailable ? process_invoice_email_queue(20) : ['sent' => 0, 'failed' => 0, 'pending' => 0];
    echo date('Y-m-d H:i:s') . " - Generated {$count} invoice(s); email sent {$mail['sent']}, failed {$mail['failed']}, pending {$mail['pending']}.\n";
    if (!$mailerAvailable) fwrite(STDERR, "Email delivery skipped: run composer install to create vendor/autoload.php.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
