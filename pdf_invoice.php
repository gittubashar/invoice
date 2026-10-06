<?php
declare(strict_types=1);

function invoice_pdf_html(array $invoice, array $items, array $methods, array $collections = [], string $fontFamily = 'hindsiliguri'): string
{
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan');
    $address = setting('address');
    $mobile = setting('mobile_number');
    $email = setting('email');
    $website = setting('website');
    $logo = uploaded_asset_url(setting('logo_path'));
    $logoFile = $logo !== '' ? str_replace('\\', '/', __DIR__ . '/' . $logo) : '';
    $showLogo = setting('pdf_show_logo', '1') === '1';
    $showTitle = setting('pdf_show_title', '1') === '1';
    $showSlogan = setting('pdf_show_slogan', '1') === '1';
    $signature = uploaded_asset_url(setting('signature_path'));
    $signatureFile = $signature !== '' ? str_replace('\\', '/', __DIR__ . '/' . $signature) : '';
    $collectionDiscount = (int)($invoice['discount_cents'] ?? 0);
    $invoiceDiscount = (int)($invoice['invoice_discount_cents'] ?? 0);
    $subtotal = (int)($invoice['subtotal_cents'] ?? 0) ?: (int)$invoice['total_cents'];
    $collected = max(0, (int)$invoice['paid_cents'] - $collectionDiscount);
    $due = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
    $status = invoice_status($invoice);
    $methodTypes = ['bank' => 'Bank', 'mfs' => 'Mobile Banking', 'card' => 'Card', 'other' => 'Other'];
    ob_start();
    ?>
<html lang="en"><head><meta charset="utf-8"><style>
body{font-family:<?= e($fontFamily) ?>;font-size:8pt;color:#1b2c44}
h1,h2,h3,p{margin:0}
table{border-collapse:collapse;width:100%}
.header{margin-bottom:4mm}.header td{vertical-align:top}.header .invoice-cell{width:42%;padding-left:6mm;text-align:right}.header .invoice-cell .invoice-box{text-align:left}
.brand-stack{text-align:left}.brand-logo{display:block;width:auto;height:60px;max-width:220px;margin:0 0 1.2mm}
.brand-title{font-size:19pt;font-weight:bold;color:#183e73;line-height:1.1}
.brand-slogan{margin-top:.7mm;font-size:8pt;color:#315a81}
.contact{margin-top:2mm;font-size:7.5pt;line-height:1.45;color:#31516e}.contact-row{white-space:nowrap}.contact-item{display:inline-block;margin-right:4mm}.contact-icon{display:inline-block;width:3.8mm;font-family:dejavusans;font-size:7pt;color:#0d6a9f}
.invoice-box{background:#eaf6fc;border-radius:2mm;padding:3mm}
.invoice-box h1{font-size:16pt;color:#154575;text-align:center;border-bottom:1px solid #3e8abd;padding-bottom:1mm;margin-bottom:1mm}
.facts td{padding:.5mm 0;font-size:8pt}.facts .label{width:32%}.facts .colon{width:5%}
.status{font-weight:bold;color:#b73b43}.status.status-paid{color:#168753}
.bill-to{background:#edf7fd;padding:2mm 3mm;margin-bottom:2mm;border-radius:2mm}
.bill-to h2{font-size:11pt;color:#0d5489;margin-bottom:.5mm}
.bill-to p{line-height:1.25}
.items th{background:#135c90;color:white;padding:1.5mm 1.5mm;font-size:8pt}
.items td{padding:1mm 1.5mm;border:0.2mm solid #cddfeb;vertical-align:top;font-size:8pt}
.items .stripe td{background:#edf7fd}.items .num{text-align:center;width:7%}.items .qty{text-align:center;width:12%}.items .money{text-align:right;width:18%}
.item-name{display:block;font-weight:bold}.item-description{display:block;margin-top:.5mm;color:#69849a;font-size:7pt;line-height:1.25}
.totals-wrap{margin:1.5mm 0 2mm}.totals-wrap td{vertical-align:bottom}
.thanks{color:#0d5489;font-size:10pt;font-style:italic}
.totals{background:#eef8fd}.totals td{padding:1mm 2mm;border-bottom:0.2mm solid #b7d9e9;font-size:8pt}.totals td:last-child{text-align:right;font-weight:bold}
.totals .due-row td{background:#beece9;color:#123c52;font-size:9pt;font-weight:bold;border:0}
.collection-history{background:#f4f9fc;border:0.2mm solid #c9dfea;border-radius:2mm;padding:1.5mm 2.5mm;margin:0 0 2mm;page-break-inside:avoid}.collection-history h2{font-size:8.5pt;color:#0d5489;margin-bottom:.7mm}.collection-history-table td{padding:.45mm 1mm;border-top:.15mm solid #d9e8ef;font-size:7.2pt}.collection-history-table td:last-child{text-align:right;font-weight:bold}.collection-history-table tr:first-child td{border-top:0}
.payment-area{background:#edf7fd;padding:2mm 3mm;border-radius:2mm;margin-top:.5mm;page-break-inside:avoid}
.payment-area h2{font-size:10pt;color:#0d5489;margin-bottom:1mm}
.payment-grid .method-cell{width:33.33%;padding:0 1.2mm;vertical-align:top;border-left:.2mm solid #c2dce9}
.payment-card h3{font-size:8pt;color:#0d5489;margin-bottom:.5mm}
.payment-card h3 small{font-size:6.5pt;color:#5d798e;font-weight:normal}
.payment-card p{font-size:6.8pt;line-height:1.18;margin-bottom:.25mm;word-wrap:break-word}
.payment-card .qr-cell{text-align:right;width:17mm;padding-left:1mm;border:0}
.payment-card img{width:14mm;height:14mm}
.note-signature{margin-top:9mm}.note-signature td{vertical-align:bottom}
.note h2{font-size:9pt;color:#0d5489}.note ol{margin:.5mm 0 0;padding-left:5mm}.note li{font-size:7pt;line-height:1.3}
.signature{text-align:center;font-size:7pt}.signature-image{display:block;max-width:35mm;max-height:13mm;margin:0 auto .5mm}.signature-line{width:100%;border-top:0.3mm solid #234a7a;margin:0 auto 1mm}.signature-caption,.signature-site{display:block;width:100%;text-align:center;line-height:1.35}.signature-caption{font-weight:bold}.signature-site{margin-top:.4mm;color:#466385;font-size:6.5pt}
</style></head><body>
<table class="header"><tr>
    <td style="width:58%">
        <div class="brand-stack">
            <?php if ($showLogo && $logoFile !== ''): ?><img class="brand-logo" src="<?= e($logoFile) ?>"><?php endif; ?>
            <?php if ($showTitle): ?><div class="brand-title"><?= e($siteTitle) ?></div><?php endif; ?>
            <?php if ($showSlogan && $slogan !== ''): ?><div class="brand-slogan"><?= e($slogan) ?></div><?php endif; ?>
        </div>
        <div class="contact">
            <?php if ($address !== ''): ?><?= nl2br(e($address)) ?><br><?php endif; ?>
            <?php if ($mobile !== '' || $email !== ''): ?><div class="contact-row">
                <?php if ($mobile !== ''): ?><span class="contact-item"><span class="contact-icon">&#9742;</span><?= e($mobile) ?></span><?php endif; ?>
                <?php if ($email !== ''): ?><span class="contact-item"><span class="contact-icon">&#9993;</span><?= e($email) ?></span><?php endif; ?>
            </div><?php endif; ?>
            <?php if ($website !== ''): ?><?= e($website) ?><?php endif; ?>
        </div>
    </td>
    <td class="invoice-cell"><div class="invoice-box">
        <h1>Invoice / Bill</h1>
        <table class="facts">
            <tr><td class="label">Invoice No</td><td class="colon">:</td><td><?= e($invoice['number']) ?></td></tr>
            <tr><td>Date</td><td>:</td><td><?= e(format_date($invoice['issue_date'])) ?></td></tr>
            <tr><td>Due Date</td><td>:</td><td><?= e(format_date($invoice['due_date'])) ?></td></tr>
            <tr><td>Status</td><td>:</td><td class="status status-<?= e($status) ?>"><?= e(status_label($status)) ?></td></tr>
        </table>
    </div></td>
</tr></table>
<div class="bill-to">
    <h2>Bill To</h2>
    <p><strong>Client:</strong> <?= e($invoice['client_name']) ?></p>
    <?php if ($invoice['billing_company_name'] !== ''): ?><p><strong>Company:</strong> <?= e($invoice['billing_company_name']) ?></p><?php endif; ?>
    <p><strong>Mobile:</strong> <?= e($invoice['client_phone']) ?></p>
    <?php if ($invoice['client_email'] !== ''): ?><p><strong>Email:</strong> <?= e($invoice['client_email']) ?></p><?php endif; ?>
</div>
<table class="items"><thead><tr><th class="num">#</th><th>Description</th><th class="qty">Quantity</th><th class="money">Unit Price (BDT)</th><th class="money">Total (BDT)</th></tr></thead><tbody>
<?php foreach ($items as $index => $item): ?>
    <tr class="<?= $index % 2 ? 'stripe' : '' ?>"><td class="num"><?= $index + 1 ?></td><td><div class="item-name"><?= e($item['name']) ?></div><?php if ($item['description'] !== ''): ?><div class="item-description"><?= e($item['description']) ?></div><?php endif; ?></td><td class="qty"><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) ?></td><td class="money"><?= e(number_format((int)$item['unit_price_cents'] / 100, 2)) ?></td><td class="money"><?= e(number_format((int)$item['total_cents'] / 100, 2)) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<table class="totals-wrap"><tr><td style="width:54%"><div class="thanks">Your trust is<br>our inspiration.</div></td><td style="width:46%">
    <table class="totals"><tr><td>Subtotal</td><td><?= e(format_money($subtotal)) ?></td></tr><?php if ($invoiceDiscount > 0): ?><tr><td><?= e($invoice['invoice_discount_label'] ?: 'Invoice Discount') ?></td><td>− <?= e(format_money($invoiceDiscount)) ?></td></tr><?php endif; ?><tr><td>Grand Total</td><td><?= e(format_money((int)$invoice['total_cents'])) ?></td></tr><tr><td>Paid</td><td><?= e(format_money($collected)) ?></td></tr><?php if ($collectionDiscount > 0): ?><tr><td>Collection Discount</td><td><?= e(format_money($collectionDiscount)) ?></td></tr><?php endif; ?><tr class="due-row"><td>Balance Due</td><td><?= e(format_money($due)) ?></td></tr></table>
</td></tr></table>
<?php if ($collections): ?><div class="collection-history"><h2>Collection History</h2><table class="collection-history-table"><?php foreach ($collections as $collection): ?><tr><td><?= e(format_date($collection['paid_at'])) ?></td><td><?= e(format_money((int)$collection['amount_cents'])) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<div class="payment-area"><h2>Payment Information</h2>
    <?php if (!$methods): ?><p>No payment methods have been added.</p><?php endif; ?>
    <?php if ($methods): ?><table class="payment-grid"><tr><?php endif; ?>
    <?php foreach ($methods as $index => $method): $qr = uploaded_asset_url((string)$method['qr_path']); $qrFile = $qr !== '' ? str_replace('\\', '/', __DIR__ . '/' . $qr) : ''; ?>
    <?php if ($index > 0 && $index % 3 === 0): ?></tr><tr><?php endif; ?>
    <td class="method-cell"><table class="payment-card"><tr><td>
        <h3><?= e($method['name']) ?> <small>· <?= e($methodTypes[$method['type']] ?? 'Other') ?></small></h3>
        <?php if ($method['account_name'] !== ''): ?><p><strong>Account Name:</strong> <?= e($method['account_name']) ?></p><?php endif; ?>
        <?php if ($method['account_number'] !== ''): ?><p><strong>Account Number:</strong> <?= e($method['account_number']) ?></p><?php endif; ?>
        <?php if ($method['mobile_number'] !== ''): ?><p><strong>Mobile Number:</strong> <?= e($method['mobile_number']) ?></p><?php endif; ?>
        <?php if ($method['branch'] !== ''): ?><p><strong>Branch / Routing:</strong> <?= e($method['branch']) ?></p><?php endif; ?>
        <?php if ($method['instructions'] !== ''): ?><p><?= nl2br(e($method['instructions'])) ?></p><?php endif; ?>
    </td><?php if ($qrFile !== ''): ?><td class="qr-cell"><img src="<?= e($qrFile) ?>" width="53" height="53"><div style="font-size:5.5pt;color:#315a81">QR Payment</div></td><?php endif; ?></tr></table></td>
    <?php endforeach; ?>
    <?php if ($methods): ?><?php $remainder = count($methods) % 3; for ($blank = $remainder; $remainder !== 0 && $blank < 3; $blank++): ?><td></td><?php endfor; ?></tr></table><?php endif; ?>
</div>
<table class="note-signature"><tr><td style="width:70%"><div class="note"><h2>Notes</h2><ol><li>Please include the invoice number when making a payment.</li><?php if ($invoice['notes'] !== ''): ?><li><?= nl2br(e($invoice['notes'])) ?></li><?php endif; ?></ol></div></td><td style="width:30%;text-align:center"><div class="signature"><?php if ($signatureFile !== ''): ?><img class="signature-image" src="<?= e($signatureFile) ?>"><?php endif; ?><div class="signature-line"></div><div class="signature-caption">Authorized Signature</div><div class="signature-site"><?= e($siteTitle) ?></div></div></td></tr></table>
</body></html>
<?php
    return (string)ob_get_clean();
}

function render_invoice_pdf(array $invoice, array $items, array $methods, array $collections = []): string
{
    require_once __DIR__ . '/vendor/autoload.php';
    $tempDir = __DIR__ . '/storage/mpdf';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir)) {
        throw new RuntimeException('PDF temporary directory could not be created.');
    }
    $fontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $fontConfig['fontdata'];
    $fontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $fontDirs[] = __DIR__ . '/assets/fonts';
    $fontData['hindsiliguri'] = [
        'R' => 'HindSiliguri-Regular.ttf',
        'B' => 'HindSiliguri-Bold.ttf',
        'useOTL' => 0xFF,
    ];
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 7,
        'margin_bottom' => 14,
        'margin_footer' => 4,
        'tempDir' => $tempDir,
        'fontDir' => $fontDirs,
        'fontdata' => $fontData,
        'default_font' => 'hindsiliguri',
    ]);
    $mpdf->SetTitle((string)$invoice['number']);
    $mpdf->SetAuthor(setting('site_title', 'Billflow'));
    $footerSlogan = setting('slogan') !== '' ? setting('slogan') : 'Built on your trust';
    $footerWebsite = setting('website');
    $footerText = e($footerSlogan) . ($footerWebsite !== '' ? ' &nbsp; | &nbsp; ' . e($footerWebsite) : '');
    $mpdf->SetHTMLFooter('<div style="border-top:0.3mm solid #478ab9;padding-top:1.4mm;text-align:center;color:#376086;font-family:hindsiliguri;font-size:6.5pt;letter-spacing:.03em">' . $footerText . '</div>');
    $mpdf->WriteHTML(invoice_pdf_html($invoice, $items, $methods, $collections));
    return $mpdf->OutputBinaryData();
}
