<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/pdf_invoice.php';

if (!function_exists('e')) {
    function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('format_date')) {
    function format_date(?string $date): string { return $date ? date('d M Y', strtotime($date)) : '—'; }
}
if (!function_exists('status_label')) {
    function status_label(string $value): string { return ['paid' => 'পরিশোধিত', 'partial' => 'আংশিক', 'overdue' => 'মেয়াদোত্তীর্ণ', 'unpaid' => 'অপরিশোধিত'][$value] ?? $value; }
}

function invoice_email_configuration(): array
{
    return [
        'host' => trim(setting('smtp_host')),
        'port' => (int)setting('smtp_port', '587'),
        'username' => trim(setting('smtp_username')),
        'password' => setting('smtp_password'),
        'encryption' => setting('smtp_encryption', 'tls'),
        'from_name' => trim(setting('smtp_from_name', setting('site_title', 'Billflow'))),
        'from_email' => trim(setting('smtp_from_email', setting('email'))),
    ];
}

function send_invoice_email_for_invoice(int $invoiceId): array
{
    $delivery = query_one('SELECT * FROM email_deliveries WHERE invoice_id=?', [$invoiceId]);
    if (!$delivery) return ['status' => 'skipped', 'message' => 'ক্লায়েন্টের বৈধ ইমেইল দেওয়া নেই।'];
    return deliver_invoice_email($delivery, true);
}

function deliver_invoice_email(array $delivery, bool $force = false): array
{
    $pdo = db();
    $deliveryId = (int)$delivery['id'];
    if ($delivery['status'] === 'sent') return ['status' => 'sent', 'message' => 'ইনভয়েস ইমেইল আগে পাঠানো হয়েছে।'];
    $config = invoice_email_configuration();
    if ($config['host'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
        $message = 'SMTP Host এবং From Email সম্পূর্ণভাবে কনফিগার করা হয়নি।';
        $pdo->prepare("UPDATE email_deliveries SET status='pending', last_error=?, next_attempt_at=?, updated_at=? WHERE id=?")
            ->execute([$message, date('Y-m-d H:i:s', strtotime('+15 minutes')), date('Y-m-d H:i:s'), $deliveryId]);
        return ['status' => 'pending', 'message' => $message];
    }
    if (!$force && (int)$delivery['attempts'] >= 3) return ['status' => 'failed', 'message' => (string)$delivery['last_error']];
    $claim = $pdo->prepare("UPDATE email_deliveries SET status='sending', attempts=attempts+1, updated_at=? WHERE id=? AND status IN ('pending','failed')");
    $claim->execute([date('Y-m-d H:i:s'), $deliveryId]);
    if (!$claim->rowCount()) return ['status' => (string)$delivery['status'], 'message' => 'Delivery ইতোমধ্যে process হচ্ছে।'];

    try {
        $invoice = invoice_rows('WHERE i.id=?', [(int)$delivery['invoice_id']], 'i.id DESC', 1)[0] ?? null;
        if (!$invoice) throw new RuntimeException('ইনভয়েস পাওয়া যায়নি।');
        $items = query_all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [(int)$invoice['id']]);
        $methods = invoice_payment_methods($invoice);
        $pdf = render_invoice_pdf($invoice, $items, $methods);

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->SMTPAuth = $config['username'] !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $config['username'];
            $mail->Password = $config['password'] !== '' ? decrypt_smtp_password($config['password']) : '';
        }
        if ($config['encryption'] === 'tls') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($config['encryption'] === 'ssl') $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->setFrom($config['from_email'], $config['from_name'] !== '' ? $config['from_name'] : setting('site_title', 'Billflow'));
        $mail->addAddress((string)$delivery['recipient'], (string)$invoice['client_name']);
        $mail->isHTML(true);
        $siteTitle = setting('site_title', 'Billflow');
        $mail->Subject = 'Invoice ' . $invoice['number'] . ' - ' . $siteTitle;
        $mail->Body = '<div style="font-family:Arial,sans-serif;color:#243d34;line-height:1.6"><h2 style="color:#0c826e">Invoice / Bill ' . e($invoice['number']) . '</h2><p>প্রিয় ' . e($invoice['client_name']) . ',</p><p>আপনার ইনভয়েস তৈরি হয়েছে। PDF কপি এই ইমেইলের সঙ্গে সংযুক্ত করা হলো।</p><p><strong>সর্বমোট:</strong> ' . e(format_money((int)$invoice['total_cents'])) . '<br><strong>পরিশোধের শেষ তারিখ:</strong> ' . e(format_date($invoice['due_date'])) . '</p><p>ধন্যবাদ,<br>' . e($siteTitle) . '</p></div>';
        $mail->AltBody = "Invoice {$invoice['number']}\nTotal: " . format_money((int)$invoice['total_cents']) . "\nDue date: " . format_date($invoice['due_date']) . "\n\n{$siteTitle}";
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$invoice['number']) . '.pdf';
        $mail->addStringAttachment($pdf, $filename, PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/pdf');
        $mail->send();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE email_deliveries SET status='sent', last_error='', sent_at=?, updated_at=? WHERE id=?")->execute([$now, $now, $deliveryId]);
        return ['status' => 'sent', 'message' => 'Invoice PDF ইমেইলে পাঠানো হয়েছে।'];
    } catch (Throwable $error) {
        $message = mb_substr($error->getMessage(), 0, 1000);
        $pdo->prepare("UPDATE email_deliveries SET status='failed', last_error=?, next_attempt_at=?, updated_at=? WHERE id=?")
            ->execute([$message, date('Y-m-d H:i:s', strtotime('+15 minutes')), date('Y-m-d H:i:s'), $deliveryId]);
        return ['status' => 'failed', 'message' => $message];
    }
}

function process_invoice_email_queue(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    db()->prepare("UPDATE email_deliveries SET status='failed', last_error='Delivery process interrupted; queued for retry.', next_attempt_at=?, updated_at=? WHERE status='sending' AND updated_at < ?")
        ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('-15 minutes'))]);
    $deliveries = query_all("SELECT * FROM email_deliveries WHERE status IN ('pending','failed') AND attempts < 3 AND next_attempt_at <= ? ORDER BY id LIMIT {$limit}", [date('Y-m-d H:i:s')]);
    $result = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
    foreach ($deliveries as $delivery) {
        $status = deliver_invoice_email($delivery)['status'];
        $result[$status] = ($result[$status] ?? 0) + 1;
    }
    return $result;
}
