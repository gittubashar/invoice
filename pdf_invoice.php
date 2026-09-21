<?php
declare(strict_types=1);

function invoice_pdf_html(array $invoice, array $items, array $methods, string $fontFamily = 'hindsiliguri'): string
{
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan');
    $address = setting('address');
    $mobile = setting('mobile_number');
    $email = setting('email');
    $website = setting('website');
    $logo = uploaded_asset_url(setting('logo_path'));
    $logoFile = $logo !== '' ? str_replace('\\', '/', __DIR__ . '/' . $logo) : '';
    $due = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
    $status = invoice_status($invoice);
    $methodTypes = ['bank' => 'ব্যাংক', 'mfs' => 'মোবাইল ব্যাংকিং', 'card' => 'কার্ড', 'other' => 'অন্যান্য'];
    ob_start();
    ?>
<html lang="bn"><head><meta charset="utf-8"><style>
body{font-family:<?= e($fontFamily) ?>;font-size:8pt;color:#1b2c44}
h1,h2,h3,p{margin:0}
table{border-collapse:collapse;width:100%}
.header{margin-bottom:4mm}.header td{vertical-align:top}.header .invoice-cell{width:42%;padding-left:6mm;text-align:right}.header .invoice-cell .invoice-box{text-align:left}
.brand-logo{max-width:19mm;max-height:19mm}
.brand-title{font-size:19pt;font-weight:bold;color:#183e73;line-height:1.1}
.brand-slogan{font-size:8pt;color:#315a81}
.contact{margin-top:2mm;font-size:7.5pt;line-height:1.45;color:#31516e}.contact-icon{display:inline-block;width:3.8mm;font-family:dejavusans;font-size:7pt;color:#0d6a9f}
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
.items small{display:block;color:#69849a;font-size:7pt}
.totals-wrap{margin:1.5mm 0 2mm}.totals-wrap td{vertical-align:bottom}
.thanks{color:#0d5489;font-size:10pt;font-style:italic}
.totals{background:#eef8fd}.totals td{padding:1mm 2mm;border-bottom:0.2mm solid #b7d9e9;font-size:8pt}.totals td:last-child{text-align:right;font-weight:bold}
.totals .due-row td{background:#beece9;color:#123c52;font-size:9pt;font-weight:bold;border:0}
.payment-area{background:#edf7fd;padding:2mm 3mm;border-radius:2mm;margin-top:.5mm;page-break-inside:avoid}
.payment-area h2{font-size:10pt;color:#0d5489;margin-bottom:1mm}
.payment-grid .method-cell{width:33.33%;padding:0 1.2mm;vertical-align:top;border-left:.2mm solid #c2dce9}
.payment-card h3{font-size:8pt;color:#0d5489;margin-bottom:.5mm}
.payment-card h3 small{font-size:6.5pt;color:#5d798e;font-weight:normal}
.payment-card p{font-size:6.8pt;line-height:1.18;margin-bottom:.25mm;word-wrap:break-word}
.payment-card .qr-cell{text-align:right;width:17mm;padding-left:1mm;border:0}
.payment-card img{width:14mm;height:14mm}
.note-signature{margin-top:9mm}.note-signature td{vertical-align:bottom}
.note h2{font-size:9pt;color:#0d5489}.note p{font-size:7pt}
.signature{border-top:0.3mm solid #234a7a;text-align:center;font-size:7pt;padding-top:.5mm}
.footer{margin-top:2mm;border-top:0.3mm solid #478ab9;padding-top:1mm;text-align:center;color:#376086;font-size:6.5pt}
</style></head><body>
<table class="header"><tr>
    <td style="width:58%">
        <table style="width:auto"><tr>
            <?php if ($logoFile !== ''): ?><td style="width:26mm;padding-right:3mm"><img class="brand-logo" src="<?= e($logoFile) ?>"></td><?php endif; ?>
            <td><div class="brand-title"><?= e($siteTitle) ?></div><?php if ($slogan !== ''): ?><div class="brand-slogan"><?= e($slogan) ?></div><?php endif; ?></td>
        </tr></table>
        <div class="contact">
            <?php if ($address !== ''): ?><?= nl2br(e($address)) ?><br><?php endif; ?>
            <?php if ($mobile !== ''): ?><span class="contact-icon">&#9742;</span><?= e($mobile) ?><br><?php endif; ?>
            <?php if ($email !== ''): ?><span class="contact-icon">&#9993;</span><?= e($email) ?><br><?php endif; ?>
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
    <p><strong>গ্রাহক:</strong> <?= e($invoice['client_name']) ?></p>
    <?php if ($invoice['billing_company_name'] !== ''): ?><p><strong>কোম্পানি:</strong> <?= e($invoice['billing_company_name']) ?></p><?php endif; ?>
    <p><strong>মোবাইল:</strong> <?= e($invoice['client_phone']) ?></p>
    <?php if ($invoice['client_email'] !== ''): ?><p><strong>ইমেইল:</strong> <?= e($invoice['client_email']) ?></p><?php endif; ?>
</div>
<table class="items"><thead><tr><th class="num">#</th><th>বিবরণ</th><th class="qty">পরিমাণ</th><th class="money">একক মূল্য (টাকা)</th><th class="money">মোট (টাকা)</th></tr></thead><tbody>
<?php foreach ($items as $index => $item): ?>
    <tr class="<?= $index % 2 ? 'stripe' : '' ?>"><td class="num"><?= $index + 1 ?></td><td><strong><?= e($item['name']) ?></strong><?php if ($item['description'] !== ''): ?><small><?= e($item['description']) ?></small><?php endif; ?></td><td class="qty"><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) ?></td><td class="money"><?= e(number_format((int)$item['unit_price_cents'] / 100, 2)) ?></td><td class="money"><?= e(number_format((int)$item['total_cents'] / 100, 2)) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<table class="totals-wrap"><tr><td style="width:54%"><div class="thanks">আপনার আস্থাই<br>আমাদের অনুপ্রেরণা।</div></td><td style="width:46%">
    <table class="totals"><tr><td>সাবটোটাল</td><td><?= e(format_money((int)$invoice['total_cents'])) ?></td></tr><tr><td>পরিশোধিত</td><td><?= e(format_money((int)$invoice['paid_cents'])) ?></td></tr><tr class="due-row"><td>বকেয়া</td><td><?= e(format_money($due)) ?></td></tr></table>
</td></tr></table>
<div class="payment-area"><h2>পেমেন্ট তথ্য</h2>
    <?php if (!$methods): ?><p>পেমেন্ট তথ্যের জন্য আমাদের সাথে যোগাযোগ করুন।</p><?php endif; ?>
    <?php if ($methods): ?><table class="payment-grid"><tr><?php endif; ?>
    <?php foreach ($methods as $index => $method): $qr = uploaded_asset_url((string)$method['qr_path']); $qrFile = $qr !== '' ? str_replace('\\', '/', __DIR__ . '/' . $qr) : ''; ?>
    <?php if ($index > 0 && $index % 3 === 0): ?></tr><tr><?php endif; ?>
    <td class="method-cell"><table class="payment-card"><tr><td>
        <h3><?= e($method['name']) ?> <small>· <?= e($methodTypes[$method['type']] ?? 'অন্যান্য') ?></small></h3>
        <?php if ($method['account_name'] !== ''): ?><p><strong>অ্যাকাউন্টের নাম:</strong> <?= e($method['account_name']) ?></p><?php endif; ?>
        <?php if ($method['account_number'] !== ''): ?><p><strong>অ্যাকাউন্ট নম্বর:</strong> <?= e($method['account_number']) ?></p><?php endif; ?>
        <?php if ($method['mobile_number'] !== ''): ?><p><strong>মোবাইল নম্বর:</strong> <?= e($method['mobile_number']) ?></p><?php endif; ?>
        <?php if ($method['branch'] !== ''): ?><p><strong>শাখা / রাউটিং:</strong> <?= e($method['branch']) ?></p><?php endif; ?>
        <?php if ($method['instructions'] !== ''): ?><p><?= nl2br(e($method['instructions'])) ?></p><?php endif; ?>
    </td><?php if ($qrFile !== ''): ?><td class="qr-cell"><img src="<?= e($qrFile) ?>" width="53" height="53"><div style="font-size:5.5pt;color:#315a81">QR পেমেন্ট</div></td><?php endif; ?></tr></table></td>
    <?php endforeach; ?>
    <?php if ($methods): ?><?php $remainder = count($methods) % 3; for ($blank = $remainder; $remainder !== 0 && $blank < 3; $blank++): ?><td></td><?php endfor; ?></tr></table><?php endif; ?>
</div>
<table class="note-signature"><tr><td style="width:70%"><div class="note"><h2>নোট</h2><p><?= $invoice['notes'] !== '' ? nl2br(e($invoice['notes'])) : 'ধন্যবাদ। সময়মতো পেমেন্ট করার জন্য অনুরোধ করা হলো।' ?></p></div></td><td style="width:30%"><div class="signature">Authorized Signature<br><?= e($siteTitle) ?></div></td></tr></table>
<div class="footer"><?= e($slogan !== '' ? $slogan : 'আপনার আস্থায় আমাদের পথচলা') ?><?php if ($website !== ''): ?> &nbsp; | &nbsp; <?= e($website) ?><?php endif; ?></div>
</body></html>
<?php
    return (string)ob_get_clean();
}

function render_invoice_pdf(array $invoice, array $items, array $methods): string
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
        'margin_bottom' => 7,
        'tempDir' => $tempDir,
        'fontDir' => $fontDirs,
        'fontdata' => $fontData,
        'default_font' => 'hindsiliguri',
    ]);
    $mpdf->SetTitle((string)$invoice['number']);
    $mpdf->SetAuthor(setting('site_title', 'Billflow'));
    $mpdf->WriteHTML(invoice_pdf_html($invoice, $items, $methods));
    return $mpdf->OutputBinaryData();
}
