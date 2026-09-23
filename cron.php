<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/invoice_mailer.php';
try {
    $count = generate_due_invoices();
    $mail = process_invoice_email_queue(20);
    echo date('Y-m-d H:i:s') . " - Generated {$count} invoice(s); email sent {$mail['sent']}, failed {$mail['failed']}, pending {$mail['pending']}.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
