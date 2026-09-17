<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db.php';
try {
    $count = generate_due_invoices();
    echo date('Y-m-d H:i:s') . " - Generated {$count} invoice(s).\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
