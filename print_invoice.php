<?php
declare(strict_types=1);

function render_invoice_print(array $invoice, array $items, array $methods): void
{
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan');
    $logo = uploaded_asset_url(setting('logo_path'));
    $address = setting('address');
    $mobile = setting('mobile_number');
    $email = setting('email');
    $website = setting('website');
    $due = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
    $status = invoice_status($invoice);
    ?>
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($invoice['number']) ?> · <?= e($siteTitle) ?></title>
    <?= favicon_tag() ?>
    <link rel="stylesheet" href="assets/print.css">
</head>
<body>
<div class="print-actions">
    <button type="button" onclick="window.print()">প্রিন্ট / PDF সেভ করুন</button>
    <a href="<?= e(url('invoice', ['id' => $invoice['id']])) ?>">ইনভয়েসে ফিরুন</a>
</div>
<main class="invoice-sheet">
    <header class="sheet-header">
        <div class="company-block<?= $logo !== '' ? ' has-logo' : '' ?>">
            <div class="company-heading">
                <?php if ($logo !== ''): ?><img class="company-logo" src="<?= e($logo) ?>" alt="<?= e($siteTitle) ?> logo"><?php endif; ?>
                <div><h1><?= e($siteTitle) ?></h1><?php if ($slogan !== ''): ?><p><?= e($slogan) ?></p><?php endif; ?></div>
            </div>
            <div class="company-contact">
                <?php if ($address !== ''): ?><p><span>⌖</span><?= nl2br(e($address)) ?></p><?php endif; ?>
                <?php if ($mobile !== ''): ?><p><span>☎</span><?= e($mobile) ?></p><?php endif; ?>
                <?php if ($email !== ''): ?><p><span>✉</span><?= e($email) ?></p><?php endif; ?>
                <?php if ($website !== ''): ?><p><span>◎</span><?= e($website) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="invoice-heading">
            <div class="topline">TECHNOLOGY &nbsp; | &nbsp; SERVICE &nbsp; | &nbsp; GROWTH</div>
            <div class="invoice-title"><span>স্মার্ট ইনভয়েস</span><strong>INVOICE</strong></div>
            <div class="invoice-facts">
                <div><span>Invoice No</span><b>:</b><strong><?= e($invoice['number']) ?></strong></div>
                <div><span>Date</span><b>:</b><strong><?= e(format_date($invoice['issue_date'])) ?></strong></div>
                <div><span>Due Date</span><b>:</b><strong><?= e(format_date($invoice['due_date'])) ?></strong></div>
                <div><span>Status</span><b>:</b><strong class="status-pill status-<?= e($status) ?>"><?= e(status_label($status)) ?></strong></div>
            </div>
        </div>
    </header>

    <section class="bill-card">
        <div class="bill-recipient">
            <h2>Bill To</h2>
            <div class="recipient-content"><span class="recipient-avatar">●</span><div>
                <p><strong>গ্রাহক:</strong> <?= e($invoice['client_name']) ?></p>
                <?php if ($invoice['billing_company_name'] !== ''): ?><p><strong>কোম্পানি:</strong> <?= e($invoice['billing_company_name']) ?></p><?php endif; ?>
                <p><strong>মোবাইল:</strong> <?= e($invoice['client_phone']) ?></p>
                <?php if ($invoice['client_email'] !== ''): ?><p><strong>ইমেইল:</strong> <?= e($invoice['client_email']) ?></p><?php endif; ?>
            </div></div>
        </div>
        <div class="bill-message">বিশ্বাসে<br>প্রযুক্তিতে<br>আপনার পাশে সবসময়</div>
    </section>

    <table class="line-table">
        <thead><tr><th>#</th><th>বিবরণ</th><th>পরিমাণ</th><th>একক মূল্য (টাকা)</th><th>মোট (টাকা)</th></tr></thead>
        <tbody>
        <?php foreach ($items as $position => $item): ?>
            <tr><td><?= $position + 1 ?></td><td><strong><?= e($item['name']) ?></strong><?php if ($item['description'] !== ''): ?><small><?= e($item['description']) ?></small><?php endif; ?></td><td><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) ?></td><td><?= e(number_format((int)$item['unit_price_cents'] / 100, 2)) ?></td><td><?= e(number_format((int)$item['total_cents'] / 100, 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-area">
        <div class="thanks-message">আপনার আস্থাই<br>আমাদের অনুপ্রেরণা।<span></span></div>
        <div class="totals-card">
            <div><span>সাবটোটাল</span><strong><?= e(format_money((int)$invoice['total_cents'])) ?></strong></div>
            <div><span>পরিশোধিত</span><strong><?= e(format_money((int)$invoice['paid_cents'])) ?></strong></div>
            <div class="total-due"><span>বকেয়া</span><strong><?= e(format_money($due)) ?></strong></div>
        </div>
    </div>

    <section class="sheet-payment<?= count($methods) > 1 ? ' multiple-methods' : '' ?>">
        <h2 class="payment-section-title"><span>▣</span> পেমেন্ট তথ্য</h2>
        <?php if (!$methods): ?><p>পেমেন্ট তথ্যের জন্য আমাদের সাথে যোগাযোগ করুন।</p><?php endif; ?>
        <?php foreach ($methods as $method): $qr = uploaded_asset_url($method['qr_path']); $methodType = ['bank' => 'ব্যাংক', 'mfs' => 'মোবাইল ব্যাংকিং', 'card' => 'কার্ড', 'other' => 'অন্যান্য'][$method['type']] ?? 'অন্যান্য'; ?>
            <div class="payment-method-entry<?= $qr === '' ? ' no-qr' : '' ?>">
                <div class="payment-data">
                    <h3><?= e($method['name']) ?> <small>· <?= e($methodType) ?></small></h3>
                    <?php if ($method['account_name'] !== ''): ?><p><strong>অ্যাকাউন্টের নাম:</strong> <?= e($method['account_name']) ?></p><?php endif; ?>
                    <?php if ($method['account_number'] !== ''): ?><p><strong>অ্যাকাউন্ট নম্বর:</strong> <?= e($method['account_number']) ?></p><?php endif; ?>
                    <?php if ($method['mobile_number'] !== ''): ?><p><strong>মোবাইল নম্বর:</strong> <?= e($method['mobile_number']) ?></p><?php endif; ?>
                    <?php if ($method['branch'] !== ''): ?><p><strong>শাখা / রাউটিং:</strong> <?= e($method['branch']) ?></p><?php endif; ?>
                    <?php if ($method['instructions'] !== ''): ?><p class="method-instructions"><?= nl2br(e($method['instructions'])) ?></p><?php endif; ?>
                </div>
                <?php if ($qr !== ''): ?><div class="payment-qr"><h3>QR কোডে পেমেন্ট করুন</h3><img src="<?= e($qr) ?>" alt="<?= e($method['name']) ?> পেমেন্ট QR"></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="sheet-bottom">
        <div class="invoice-note"><h2>নোট</h2><p><?= $invoice['notes'] !== '' ? nl2br(e($invoice['notes'])) : 'ধন্যবাদ। সময়মতো পেমেন্ট করার জন্য অনুরোধ করা হলো।' ?></p></div>
        <div class="signature"><span></span><strong>Authorized Signature</strong><small><?= e($siteTitle) ?></small></div>
    </section>
    <footer class="sheet-footer"><?= e($slogan !== '' ? $slogan : 'আপনার আস্থায় আমাদের পথচলা') ?><?php if ($website !== ''): ?> &nbsp; | &nbsp; <?= e($website) ?><?php endif; ?></footer>
</main>
</body>
</html>
<?php
}
