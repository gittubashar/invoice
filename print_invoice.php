<?php
declare(strict_types=1);

function render_invoice_print(array $invoice, array $items, array $methods, array $collections = []): void
{
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan');
    $logo = uploaded_asset_url(setting('logo_path'));
    $signature = uploaded_asset_url(setting('signature_path'));
    $address = setting('address');
    $mobile = setting('mobile_number');
    $email = setting('email');
    $website = setting('website');
    $showLogo = setting('pdf_show_logo', '1') === '1';
    $showTitle = setting('pdf_show_title', '1') === '1';
    $showSlogan = setting('pdf_show_slogan', '1') === '1';
    $hasCompanyHeading = ($showLogo && $logo !== '') || $showTitle || ($showSlogan && $slogan !== '');
    $collectionDiscount = (int)($invoice['discount_cents'] ?? 0);
    $invoiceDiscount = (int)($invoice['invoice_discount_cents'] ?? 0);
    $subtotal = (int)($invoice['subtotal_cents'] ?? 0) ?: (int)$invoice['total_cents'];
    $collected = max(0, (int)$invoice['paid_cents'] - $collectionDiscount);
    $due = max(0, (int)$invoice['total_cents'] - (int)$invoice['paid_cents']);
    $status = invoice_status($invoice);
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($invoice['number']) ?> · <?= e($siteTitle) ?></title>
    <?= favicon_tag() ?>
    <link rel="stylesheet" href="assets/print.css">
</head>
<body>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <a href="<?= e(url('invoice', ['id' => $invoice['id']])) ?>">Back to Invoice</a>
</div>
<main class="invoice-sheet">
    <header class="sheet-header">
        <div class="company-block<?= $showLogo && $logo !== '' ? ' has-logo' : '' ?>">
            <?php if ($hasCompanyHeading): ?><div class="company-heading">
                <?php if ($showLogo && $logo !== ''): ?><img class="company-logo" src="<?= e($logo) ?>" alt="<?= e($siteTitle) ?> logo"><?php endif; ?>
                <?php if ($showTitle || ($showSlogan && $slogan !== '')): ?><div><?php if ($showTitle): ?><h1><?= e($siteTitle) ?></h1><?php endif; ?><?php if ($showSlogan && $slogan !== ''): ?><p><?= e($slogan) ?></p><?php endif; ?></div><?php endif; ?>
            </div><?php endif; ?>
            <div class="company-contact">
                <?php if ($address !== ''): ?><p><span>⌖</span><?= nl2br(e($address)) ?></p><?php endif; ?>
                <?php if ($mobile !== ''): ?><p><span>☎</span><?= e($mobile) ?></p><?php endif; ?>
                <?php if ($email !== ''): ?><p><span>✉</span><?= e($email) ?></p><?php endif; ?>
                <?php if ($website !== ''): ?><p><span>◎</span><?= e($website) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="invoice-heading">
            <div class="topline">TECHNOLOGY &nbsp; | &nbsp; SERVICE &nbsp; | &nbsp; GROWTH</div>
            <div class="invoice-title"><span>Invoice / Bill</span><strong>INVOICE</strong></div>
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
                <p><strong>Client:</strong> <?= e($invoice['client_name']) ?></p>
                <?php if ($invoice['billing_company_name'] !== ''): ?><p><strong>Company:</strong> <?= e($invoice['billing_company_name']) ?></p><?php endif; ?>
                <p><strong>Mobile:</strong> <?= e($invoice['client_phone']) ?></p>
                <?php if ($invoice['client_email'] !== ''): ?><p><strong>Email:</strong> <?= e($invoice['client_email']) ?></p><?php endif; ?>
            </div></div>
        </div>
        <div class="bill-message">Built on trust<br>Through technology<br>Always by your side</div>
    </section>

    <table class="line-table">
        <thead><tr><th>#</th><th>Description</th><th>Quantity</th><th>Unit Price (BDT)</th><th>Total (BDT)</th></tr></thead>
        <tbody>
        <?php foreach ($items as $position => $item): ?>
            <tr><td><?= $position + 1 ?></td><td><strong><?= e($item['name']) ?></strong><?php if ($item['description'] !== ''): ?><small><?= e($item['description']) ?></small><?php endif; ?></td><td><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 2, '.', ''), '0'), '.')) ?></td><td><?= e(number_format((int)$item['unit_price_cents'] / 100, 2)) ?></td><td><?= e(number_format((int)$item['total_cents'] / 100, 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-area">
        <div class="thanks-message">Your trust is<br>our inspiration.<span></span></div>
        <div class="totals-card">
            <div><span>Subtotal</span><strong><?= e(format_money($subtotal)) ?></strong></div>
            <?php if ($invoiceDiscount > 0): ?><div><span><?= e($invoice['invoice_discount_label'] ?: 'Invoice Discount') ?></span><strong>− <?= e(format_money($invoiceDiscount)) ?></strong></div><?php endif; ?>
            <div><span>Grand Total</span><strong><?= e(format_money((int)$invoice['total_cents'])) ?></strong></div>
            <div><span>Paid</span><strong><?= e(format_money($collected)) ?></strong></div>
            <?php if ($collectionDiscount > 0): ?><div><span>Collection Discount</span><strong><?= e(format_money($collectionDiscount)) ?></strong></div><?php endif; ?>
            <div class="total-due"><span>Balance Due</span><strong><?= e(format_money($due)) ?></strong></div>
        </div>
    </div>

    <?php if ($collections): ?>
    <section class="sheet-collections">
        <h2>Collection History</h2>
        <div class="collection-history-grid">
            <?php foreach ($collections as $collection): ?>
                <div><span><?= e(format_date($collection['paid_at'])) ?></span><strong><?= e(format_money((int)$collection['amount_cents'])) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="sheet-payment<?= count($methods) > 1 ? ' multiple-methods' : '' ?>">
        <h2 class="payment-section-title"><span>▣</span> Payment Information</h2>
        <?php if (!$methods): ?><p>No payment methods have been added.</p><?php endif; ?>
        <?php foreach ($methods as $method): $qr = uploaded_asset_url($method['qr_path']); $methodType = ['bank' => 'Bank', 'mfs' => 'Mobile Banking', 'card' => 'Card', 'other' => 'Other'][$method['type']] ?? 'Other'; ?>
            <div class="payment-method-entry<?= $qr === '' ? ' no-qr' : '' ?>">
                <div class="payment-data">
                    <h3><?= e($method['name']) ?> <small>· <?= e($methodType) ?></small></h3>
                    <?php if ($method['account_name'] !== ''): ?><p><strong>Account Name:</strong> <?= e($method['account_name']) ?></p><?php endif; ?>
                    <?php if ($method['account_number'] !== ''): ?><p><strong>Account Number:</strong> <?= e($method['account_number']) ?></p><?php endif; ?>
                    <?php if ($method['mobile_number'] !== ''): ?><p><strong>Mobile Number:</strong> <?= e($method['mobile_number']) ?></p><?php endif; ?>
                    <?php if ($method['branch'] !== ''): ?><p><strong>Branch / Routing:</strong> <?= e($method['branch']) ?></p><?php endif; ?>
                    <?php if ($method['instructions'] !== ''): ?><p class="method-instructions"><?= nl2br(e($method['instructions'])) ?></p><?php endif; ?>
                </div>
                <?php if ($qr !== ''): ?><div class="payment-qr"><h3>Pay with QR Code</h3><img src="<?= e($qr) ?>" alt="<?= e($method['name']) ?> Payment QR"></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="sheet-bottom">
        <div class="invoice-note"><h2>Notes</h2><ol><li>Please include the invoice number when making a payment.</li><?php if ($invoice['notes'] !== ''): ?><li><?= nl2br(e($invoice['notes'])) ?></li><?php endif; ?></ol></div>
        <div class="signature"><?php if ($signature !== ''): ?><img class="signature-image" src="<?= e($signature) ?>" alt="Authorized Signature"><?php endif; ?><span class="signature-line"></span><div class="signature-caption">Authorized Signature</div><div class="signature-site"><?= e($siteTitle) ?></div></div>
    </section>
    <footer class="sheet-footer"><?= e($slogan !== '' ? $slogan : 'Built on your trust') ?><?php if ($website !== ''): ?> &nbsp; | &nbsp; <?= e($website) ?><?php endif; ?></footer>
</main>
</body>
</html>
<?php
}
