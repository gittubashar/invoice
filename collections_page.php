<?php
declare(strict_types=1);

$search = trim((string)($_GET['q'] ?? ''));
$selectedClientId = (int)($_GET['client_id'] ?? 0);
$selectedClient = $selectedClientId > 0 ? query_one('SELECT * FROM clients WHERE id=?', [$selectedClientId]) : null;
if (!$selectedClient) $selectedClientId = 0;
$clientMatches = $search !== '' ? search_clients($search, 20) : [];
$outstandingInvoices = $selectedClientId > 0
    ? array_values(array_filter(invoice_rows('WHERE i.client_id=?', [$selectedClientId], 'i.issue_date ASC, i.id ASC', 500), static fn(array $row): bool => (int)$row['paid_cents'] < (int)$row['total_cents']))
    : [];
$outstandingTotal = array_sum(array_map(static fn(array $row): int => max(0, (int)$row['total_cents'] - (int)$row['paid_cents']), $outstandingInvoices));
$selectedId = (int)($_GET['id'] ?? 0);
$selectedInvoice = $selectedId && $selectedClientId ? (invoice_rows('WHERE i.id=? AND i.client_id=?', [$selectedId, $selectedClientId], 'i.id DESC', 1)[0] ?? null) : null;
if ($selectedInvoice && (int)$selectedInvoice['paid_cents'] >= (int)$selectedInvoice['total_cents']) $selectedInvoice = null;
$oldCollection = $_SESSION['old_collection'] ?? [];
unset($_SESSION['old_collection']);
$recentCollections = query_all('SELECT p.*, i.number, i.billing_name FROM payments p JOIN invoices i ON i.id=p.invoice_id ORDER BY p.id DESC LIMIT 50');
$methodLabels = ['cash'=>'Cash','bank'=>'Bank Transfer','bkash'=>'bKash','nagad'=>'Nagad','card'=>'Card','other'=>'Other'];

begin_page('Invoice Collections', 'collections', 'Find a client, select invoices, and record partial or full payments.');

echo '<section class="panel collection-client-search"><div class="collection-search-copy"><span class="eyebrow">FIND CLIENT</span><h2>Search Client</h2><p>Start typing a client name, mobile number, or email address.</p></div><form method="get" class="collection-client-search-form" data-collection-client-search data-search-url="' . e(url('client-search')) . '" data-select-url="' . e(url('collections')) . '"><input type="hidden" name="page" value="collections"><span>' . icon('search', 19) . '</span><input type="search" name="q" value="' . e($search) . '" placeholder="Name, mobile number, or email" autocomplete="off" required data-collection-client-input><button class="btn btn-primary" type="submit">Search</button></form><div class="collection-client-results collection-live-results" data-collection-client-results hidden></div>';

if ($selectedClient) {
    echo '<div class="selected-collection-client"><span class="client-avatar">' . e(mb_substr($selectedClient['name'], 0, 1)) . '</span><span><small>SELECTED CLIENT</small><strong>' . e($selectedClient['name']) . '</strong><em>' . e($selectedClient['phone']) . ($selectedClient['email'] ? ' · ' . e($selectedClient['email']) : '') . '</em></span><a class="btn btn-outline btn-sm" href="' . e(url('collections')) . '">Change Client</a></div>';
} elseif ($search !== '') {
    echo '<div class="collection-client-results">';
    if (!$clientMatches) echo '<div class="collection-client-empty">No client matched “' . e($search) . '”.</div>';
    foreach ($clientMatches as $client) {
        echo '<a href="' . e(url('collections', ['client_id'=>$client['id'], 'q'=>$search])) . '"><span class="client-avatar">' . e(mb_substr($client['name'], 0, 1)) . '</span><span><strong>' . e($client['name']) . '</strong><small>' . e($client['company_name'] ?: 'No company name') . '</small></span><span class="collection-client-contact">' . e($client['phone']) . ($client['email'] ? '<small>' . e($client['email']) . '</small>' : '') . '</span>' . icon('chevron',17) . '</a>';
    }
    echo '</div>';
}
echo '</section>';

if (!$selectedClient) {
    echo '<section class="panel collection-client-prompt"><span class="stat-icon stat-icon-mint">' . icon('users',24) . '</span><h2>Select a client to view invoices</h2><p>Outstanding invoices will appear after you choose a client from the search results.</p></section>';
} else {
    echo '<section class="panel collection-list-panel" data-collection-workspace><div class="panel-heading"><div><span class="eyebrow">OUTSTANDING INVOICES</span><h2>' . e($selectedClient['name']) . '</h2><small>' . count($outstandingInvoices) . ' invoice(s) · Total due ' . format_money($outstandingTotal) . '</small></div><div class="collection-summary"><span data-selected-count>0 selected</span><strong data-selected-total>BDT 0</strong></div></div>';
    if (!$outstandingInvoices) {
        echo '<div class="empty-state">' . icon('check',34) . '<h3>No outstanding invoices</h3><p>This client has no balance due.</p></div>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th><input class="collection-select" type="checkbox" data-select-all aria-label="Select all invoices"></th><th>Invoice</th><th>Issue / Due</th><th>Total</th><th>Collection</th><th>Discount</th><th>Balance Due</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($outstandingInvoices as $row) {
            $due = max(0, (int)$row['total_cents'] - (int)$row['paid_cents']);
            echo '<tr><td><input class="collection-select" type="checkbox" form="bulk-collection-form" name="invoice_ids[]" value="' . (int)$row['id'] . '" data-balance="' . $due . '" aria-label="Select ' . e($row['number']) . '"></td><td><a class="strong-link" href="' . e(url('invoice', ['id'=>$row['id']])) . '">' . e($row['number']) . '</a><small>' . ($row['recurrence_id'] ? frequency_label($row['frequency']) : 'One-time') . '</small></td><td>' . e(format_date($row['issue_date'])) . '<small>Due ' . e(format_date($row['due_date'])) . '</small></td><td><strong>' . format_money((int)$row['total_cents']) . '</strong></td><td>' . format_money(max(0, (int)$row['paid_cents'] - (int)$row['discount_cents'])) . '</td><td>' . format_money((int)$row['discount_cents']) . '</td><td><strong>' . format_money($due) . '</strong></td><td>' . badge($row) . '</td><td><a class="btn btn-outline btn-sm" href="' . e(url('collections', ['client_id'=>$selectedClientId, 'id'=>$row['id'], 'q'=>$search])) . '">Single</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';

    if ($selectedInvoice) {
        $remaining = max(0, (int)$selectedInvoice['total_cents'] - (int)$selectedInvoice['paid_cents']);
        $collectionType = (string)($oldCollection['collection_type'] ?? 'partial');
        echo '<section class="panel form-panel collection-page-form collection-form-below"><div class="collection-form-heading"><div><span class="eyebrow">SINGLE INVOICE COLLECTION</span><h2>' . e($selectedInvoice['number']) . '</h2><p>Current balance: <strong>' . format_money($remaining) . '</strong></p></div><a class="btn btn-outline btn-sm" href="' . e(url('collections', ['client_id'=>$selectedClientId, 'q'=>$search])) . '">Close</a></div><form method="post" class="stack-form" data-collection-form data-remaining="' . e(number_format($remaining / 100, 2, '.', '')) . '">' . csrf_field() . '<input type="hidden" name="action" value="collect_invoice_collection"><input type="hidden" name="invoice_id" value="' . (int)$selectedInvoice['id'] . '"><input type="hidden" name="client_id" value="' . $selectedClientId . '"><div class="type-choice"><label><input type="radio" name="collection_type" value="partial"' . ($collectionType === 'partial' ? ' checked' : '') . '><span class="type-card"><strong>Partial Collection</strong><small>Collect part of this invoice balance</small></span></label><label><input type="radio" name="collection_type" value="full"' . ($collectionType === 'full' ? ' checked' : '') . '><span class="type-card"><strong>Full Payment</strong><small>Collect the entire invoice balance</small></span></label></div><div class="form-grid"><label>Collection Amount (BDT)<input type="number" name="amount" data-collection-amount required min="0.01" max="' . e(number_format($remaining / 100, 2, '.', '')) . '" step="0.01" value="' . e($oldCollection['amount'] ?? '') . '" placeholder="0.00"></label><label>Payment Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Date<input type="date" name="paid_at" required value="' . e($oldCollection['paid_at'] ?? date('Y-m-d')) . '"></label><label>Transaction Reference <small>(Optional)</small><input name="reference" maxlength="120" value="' . e($oldCollection['reference'] ?? '') . '" placeholder="Transaction ID"></label><label class="field-wide">Notes <small>(Optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="Payment details">' . e($oldCollection['notes'] ?? '') . '</textarea></label></div><div class="client-create-actions"><button class="btn btn-primary" type="submit">Save Collection</button></div></form></section>';
    }

    if ($outstandingInvoices) {
        echo '<form method="post" id="bulk-collection-form" class="panel form-panel bulk-collection-form-below" data-multi-collection>' . csrf_field() . '<input type="hidden" name="action" value="collect_multiple_invoices"><input type="hidden" name="client_id" value="' . $selectedClientId . '"><div class="collection-form-heading"><div><span class="eyebrow">MULTIPLE INVOICE COLLECTION</span><h2>Collect Selected Invoices</h2><p>Payment is allocated to the oldest selected invoice first.</p></div><div class="collection-summary"><span data-selected-count>0 selected</span><strong data-selected-total>BDT 0</strong></div></div><div class="bulk-collection-fields"><label>Collection Amount (BDT)<input type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00"></label><label>Partial Discount (BDT)<input type="number" name="discount" min="0" step="0.01" value="0.00"></label><div><span class="form-label">Confirm Discount Type</span><label class="checkbox-line"><input type="radio" name="discount_scope" value="one_time"> One-time Invoices</label><label class="checkbox-line"><input type="radio" name="discount_scope" value="recurring"> Recurring Invoices</label></div><label>Payment Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="card">Card</option><option value="other">Other</option></select></label><label>Date<input type="date" name="paid_at" required value="' . e(date('Y-m-d')) . '"></label><label>Transaction Reference <small>(Optional)</small><input name="reference" maxlength="120" placeholder="Transaction ID"></label><label class="field-wide">Notes <small>(Optional)</small><textarea name="notes" rows="2" maxlength="500" placeholder="Payment or discount details"></textarea></label><div class="bulk-actions"><button class="btn btn-primary btn-wide" type="submit">Collect Selected Invoices</button></div></div></form>';
    }
}

echo '<section class="panel collection-history-panel"><div class="panel-heading"><div><span class="eyebrow">RECENT COLLECTIONS</span><h2>Recent Collections</h2></div><span class="count-pill">' . count($recentCollections) . '</span></div>';
if (!$recentCollections) {
    echo '<div class="empty-state"><p>No collections have been recorded yet.</p></div>';
} else {
    echo '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Client</th><th>Amount</th><th>Method</th><th>Date</th><th>Reference</th></tr></thead><tbody>';
    foreach ($recentCollections as $payment) echo '<tr><td><a class="strong-link" href="' . e(url('invoice', ['id'=>$payment['invoice_id']])) . '">' . e($payment['number']) . '</a></td><td>' . e($payment['billing_name']) . '</td><td><strong>' . format_money((int)$payment['amount_cents']) . '</strong></td><td>' . e($methodLabels[$payment['method']] ?? $payment['method']) . '</td><td>' . e(format_date($payment['paid_at'])) . '</td><td>' . e($payment['reference'] ?: '—') . '</td></tr>';
    echo '</tbody></table></div>';
}
echo '</section>';
end_page();
