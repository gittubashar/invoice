<?php
declare(strict_types=1);

function client_ledger_pdf_html(array $statement): string
{
    $client = $statement['client'];
    $entries = $statement['entries'];
    $siteTitle = setting('site_title', 'Billflow');
    $slogan = setting('slogan');
    $logo = uploaded_asset_url(setting('logo_path'));
    $logoFile = $logo !== '' ? str_replace('\\', '/', __DIR__ . '/' . $logo) : '';
    ob_start();
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><style>
body{font-family:hindsiliguri;color:#1e3346;font-size:9pt}
h1,h2,p{margin:0}.header{width:100%;border-bottom:.35mm solid #187c70;padding-bottom:4mm;margin-bottom:5mm}.header td{vertical-align:middle}.brand-logo{display:block;max-width:38mm;max-height:18mm;margin-bottom:1mm}.brand-title{font-size:19pt;font-weight:bold;color:#163f69}.brand-slogan{font-size:8pt;color:#668092}.statement-title{text-align:right}.statement-title h1{font-size:18pt;color:#116f64}.statement-title p{margin-top:1mm;color:#718694;font-size:8pt}
.client-card{width:100%;background:#eef8f5;border:0.2mm solid #d3e8e1;border-radius:2mm;margin-bottom:5mm}.client-card td{padding:2.5mm 3mm;vertical-align:top}.client-card .label{display:inline;color:#789086;font-size:7pt;text-transform:uppercase;letter-spacing:.08em}.client-card strong{display:inline;font-size:10pt;color:#203e34}.client-card p{margin-top:1mm;color:#587166;font-size:8pt}
.ledger{width:100%;border-collapse:collapse}.ledger thead{display:table-header-group}.ledger th{background:#116f64;color:#fff;padding:2.2mm 2mm;font-size:8pt;text-align:left}.ledger td{padding:2mm;border-bottom:.2mm solid #dce8e3;font-size:8pt;vertical-align:top}.ledger tbody tr:nth-child(even) td{background:#f7faf9}.ledger .sn{width:8%;text-align:center}.ledger .date{width:17%}.ledger .invoice{width:25%}.ledger .money{width:16.66%;text-align:right;white-space:nowrap}.ledger small{display:block;margin-top:.5mm;color:#789087;font-size:6.5pt}.ledger tfoot td{padding:2.4mm 2mm;background:#e5f3ee;border-top:.4mm solid #85bcae;border-bottom:0;font-weight:bold}.ledger tfoot .closing td{background:#ccebe2;color:#133f36;font-size:9pt}
.explanation{margin-top:4mm;padding:2.5mm 3mm;background:#f5f8fa;border-left:.8mm solid #478ab9;color:#5d7483;font-size:7.5pt}.summary{width:100%;margin-top:4mm;border-collapse:separate;border-spacing:2mm 0}.summary td{width:25%;padding:2.5mm;border:.2mm solid #d8e6e1;border-radius:1.5mm;background:#fafcfb}.summary span{display:inline;color:#769087;font-size:7pt}.summary strong{display:inline;color:#1f4238;font-size:10pt}.summary .due{background:#e1f4ed;border-color:#b9dfd2}.summary .due strong{color:#08735f}
</style></head><body>
<table class="header"><tr><td style="width:58%">
    <?php if ($logoFile !== ''): ?><img class="brand-logo" src="<?= e($logoFile) ?>"><?php endif; ?>
    <div class="brand-title"><?= e($siteTitle) ?></div>
    <?php if ($slogan !== ''): ?><div class="brand-slogan"><?= e($slogan) ?></div><?php endif; ?>
</td><td class="statement-title" style="width:42%"><h1>Client Statement</h1><p>Statement Date: <?= e(format_date(date('Y-m-d'))) ?></p></td></tr></table>

<table class="client-card"><tr><td style="width:38%"><span class="label">Client:</span>&nbsp;&nbsp;<strong><?= e($client['name']) ?></strong><?php if ($client['company_name'] !== ''): ?><p><?= e($client['company_name']) ?></p><?php endif; ?></td><td style="width:31%"><span class="label">Mobile:</span>&nbsp;&nbsp;<strong><?= e($client['phone']) ?></strong></td><td style="width:31%"><span class="label">Email:</span>&nbsp;&nbsp;<strong><?= e($client['email'] ?: '—') ?></strong></td></tr></table>

<table class="ledger"><thead><tr><th class="sn">S.N.</th><th class="date">Date</th><th class="invoice">Invoice No.</th><th class="money">Debit</th><th class="money">Credit</th><th class="money">Balance</th></tr></thead><tbody>
<?php if (!$entries): ?><tr><td colspan="6" style="text-align:center;padding:8mm;color:#80948c">No ledger entries found.</td></tr><?php endif; ?>
<?php foreach ($entries as $entry): ?><tr>
    <td class="sn"><?= (int)$entry['serial'] ?></td>
    <td class="date"><?= e(format_date($entry['date'])) ?></td>
    <td class="invoice"><?= e($entry['invoice_number']) ?><br><small><?= $entry['kind'] === 'invoice' ? 'Invoice issued' : 'Collection received' ?><?php if ($entry['discount_cents'] > 0): ?> &nbsp;·&nbsp; Includes <?= e(format_money((int)$entry['discount_cents'])) ?> discount<?php endif; ?></small></td>
    <td class="money"><?= $entry['debit_cents'] > 0 ? e(format_money((int)$entry['debit_cents'])) : '—' ?></td>
    <td class="money"><?= $entry['credit_cents'] > 0 ? e(format_money((int)$entry['credit_cents'])) : '—' ?></td>
    <td class="money"><?= e(format_money((int)$entry['balance_cents'])) ?></td>
</tr><?php endforeach; ?>
</tbody><tfoot><tr><td colspan="3">Total</td><td class="money"><?= e(format_money((int)$statement['total_debit_cents'])) ?></td><td class="money"><?= e(format_money((int)$statement['total_credit_cents'])) ?></td><td class="money"><?= e(format_money((int)$statement['balance_cents'])) ?></td></tr><tr class="closing"><td colspan="5">Closing Balance</td><td class="money"><?= e(format_money((int)$statement['balance_cents'])) ?></td></tr></tfoot></table>

<table class="summary"><tr><td><span>Total Invoiced:</span>&nbsp;&nbsp;<strong><?= e(format_money((int)$statement['total_debit_cents'])) ?></strong></td><td><span>Cash Collected:</span>&nbsp;&nbsp;<strong><?= e(format_money((int)$statement['total_cash_cents'])) ?></strong></td><td><span>Discounts:</span>&nbsp;&nbsp;<strong><?= e(format_money((int)$statement['total_discount_cents'])) ?></strong></td><td class="due"><span>Balance Due:</span>&nbsp;&nbsp;<strong><?= e(format_money((int)$statement['balance_cents'])) ?></strong></td></tr></table>
<div class="explanation"><strong>How to read this statement:</strong> Debit is the invoice amount charged to the client. Credit is the amount settled through collection and collection discount. Balance is the amount still payable after each transaction.</div>
</body></html>
<?php
    return (string)ob_get_clean();
}

function render_client_ledger_pdf(array $statement): string
{
    require_once __DIR__ . '/vendor/autoload.php';
    $tempDir = __DIR__ . '/storage/mpdf';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0770, true) && !is_dir($tempDir)) throw new RuntimeException('PDF temporary directory could not be created.');
    $fontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $fontConfig['fontdata'];
    $fontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $fontDirs[] = __DIR__ . '/assets/fonts';
    $fontData['hindsiliguri'] = ['R'=>'HindSiliguri-Regular.ttf','B'=>'HindSiliguri-Bold.ttf','useOTL'=>0xFF];
    $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','margin_left'=>10,'margin_right'=>10,'margin_top'=>9,'margin_bottom'=>13,'margin_footer'=>4,'tempDir'=>$tempDir,'fontDir'=>$fontDirs,'fontdata'=>$fontData,'default_font'=>'hindsiliguri']);
    $clientName = (string)$statement['client']['name'];
    $mpdf->SetTitle($clientName . ' - Client Statement');
    $mpdf->SetAuthor(setting('site_title', 'Billflow'));
    $mpdf->SetHTMLFooter('<div style="border-top:0.3mm solid #6ba99a;padding-top:1.3mm;text-align:center;color:#5e7b72;font-family:hindsiliguri;font-size:6.5pt">' . e(setting('site_title', 'Billflow')) . ' · Client Statement · Page {PAGENO} of {nbpg}</div>');
    $mpdf->WriteHTML(client_ledger_pdf_html($statement));
    return $mpdf->OutputBinaryData();
}
