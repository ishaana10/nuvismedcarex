<?php
/**
 * Payment receipt template, shared by print_receipt.php (real receipts) and
 * receipt_settings.php (live preview). Layout and content follow the
 * clinic's ReceiptSettings; styles are self-contained so printing doesn't
 * depend on the app stylesheet.
 */

if (!function_exists('nxReceiptCss')) {

    function nxReceiptCss(array $cfg): string
    {
        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $cfg['accent']) ? $cfg['accent'] : '#0E7C63';
        $thermal = str_starts_with((string) $cfg['paper'], 'thermal');
        $width = ['a4' => '720px', 'letter' => '720px', 'thermal80' => '72mm', 'thermal58' => '48mm'][$cfg['paper']] ?? '720px';
        $page = ['a4' => 'A4', 'letter' => 'letter', 'thermal80' => '80mm auto', 'thermal58' => '58mm auto'][$cfg['paper']] ?? 'A4';
        $scale = ['compact' => 0.9, 'standard' => 1, 'large' => 1.12][$cfg['size']] ?? 1;
        $base = ($thermal ? ($cfg['paper'] === 'thermal58' ? 10 : 11.5) : 13) * $scale;
        $margin = $thermal ? '3mm 4mm' : '14mm';

        return <<<CSS
.rc{--accent:{$accent};width:{$width};max-width:100%;margin:0 auto;background:#fff;color:#1b2430;font:400 {$base}px/1.45 "IBM Plex Sans",Inter,"Segoe UI",Roboto,Arial,sans-serif;box-sizing:border-box}
.rc *{box-sizing:border-box}
.rc .num{font-variant-numeric:tabular-nums;white-space:nowrap}
.rc .muted{color:#5b6878}
.rc-sheet{padding:{$margin}}
.rc-head{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;padding-bottom:12px;border-bottom:2px solid var(--accent)}
.rc-head.center{flex-direction:column;align-items:center;text-align:center}
.rc-brand{display:flex;gap:12px;align-items:center}
.rc-head.center .rc-brand{flex-direction:column;gap:6px}
.rc-logo{max-height:56px;max-width:160px;object-fit:contain}
.rc-clinic{font-weight:700;font-size:1.25em;line-height:1.2}
.rc-sub{font-size:.85em}
.rc-contact{font-size:.82em;margin-top:2px}
.rc-meta{text-align:right;font-size:.85em}
.rc-head.center .rc-meta{text-align:center}
.rc-title{color:var(--accent);font-weight:700;font-size:1.05em}
.rc-no{font-weight:700;font-size:1.05em;margin:2px 0}
.rc-paid{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:14px 0;padding:12px 14px;border-radius:10px;background:color-mix(in srgb,var(--accent) 8%,#fff);border:1px solid color-mix(in srgb,var(--accent) 30%,#fff)}
.rc-paid strong{display:block;font-size:.8em;font-weight:600;color:#5b6878}
.rc-paid .amt{font-size:1.6em;font-weight:700;color:var(--accent)}
.rc-paid p{margin:0;max-width:62%}
.rc-parties{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px}
.rc-label{font-size:.75em;font-weight:600;color:#5b6878;margin-bottom:2px}
.rc-strong{font-weight:600}
.rc table{width:100%;border-collapse:collapse}
.rc th{font-size:.78em;font-weight:600;color:#5b6878;text-align:left;padding:6px 0;border-bottom:1px solid #d6dde6}
.rc td{padding:7px 0;border-bottom:1px solid #eef1f5;vertical-align:top}
.rc .r{text-align:right}.rc .c{text-align:center}
.rc-totals{margin-left:auto;width:min(280px,100%);margin-top:8px}
.rc-totals div{display:flex;justify-content:space-between;padding:3px 0}
.rc-totals .grand{border-top:2px solid #1b2430;margin-top:4px;padding-top:6px;font-weight:700;font-size:1.1em}
.rc-thanks{margin:16px 0 0;font-size:.95em}
.rc-foot{margin-top:18px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px}
.rc-sign{text-align:right;min-width:180px}
.rc-sign img{max-height:52px;max-width:170px;object-fit:contain;display:block;margin-left:auto}
.rc-sign .line{border-top:1px solid #8a96a6;margin-top:4px;padding-top:3px;font-weight:600}
.rc-stamp{max-height:84px;max-width:120px;object-fit:contain;opacity:.9}
.rc-qr{display:flex;gap:10px;align-items:center;font-size:.78em}
.rc-qr img{width:84px;height:84px}
.rc-qr-fallback{display:none;word-break:break-all;font-size:.9em;margin-top:2px}
.rc-note{margin-top:14px;padding-top:10px;border-top:1px dashed #c7d0db;font-size:.8em;color:#5b6878;white-space:pre-line}
.rc-copy{font-size:.75em;font-weight:600;letter-spacing:.02em;color:var(--accent);text-align:right;margin-bottom:6px}
.rc-cut{border:0;border-top:1px dashed #8a96a6;margin:18px 0}
/* Receipt-printer rolls: one column, no panels */
.rc.thermal .rc-head,.rc.thermal .rc-brand{flex-direction:column;align-items:center;text-align:center;gap:4px}
.rc.thermal .rc-meta{text-align:center}
.rc.thermal .rc-logo{max-height:40px}
.rc.thermal .rc-paid{flex-direction:column;align-items:stretch;text-align:center;background:none;border:0;border-top:1px dashed #8a96a6;border-bottom:1px dashed #8a96a6;border-radius:0;padding:8px 0}
.rc.thermal .rc-paid p{max-width:none}
.rc.thermal .rc-parties{grid-template-columns:1fr;gap:6px}
.rc.thermal .rc-totals{width:100%}
.rc.thermal .rc-foot{flex-direction:column;align-items:center;text-align:center}
.rc.thermal .rc-sign{text-align:center}
.rc.thermal .rc-sign img{margin:0 auto}
@page{size:{$page};margin:0}
@media print{.no-print{display:none!important}body{background:#fff!important;margin:0!important;padding:0!important}.rc-screen-frame{box-shadow:none!important;border:0!important;margin:0!important}.rc-cut{break-after:page}}
CSS;
    }

    /**
     * @param array $cfg ReceiptSettings::load() result
     * @param array $d   receipt data: clinic, receipt, patient, items, payments, totals, signer
     */
    function nxRenderReceipt(array $cfg, array $d): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cur = (string) $d['currency'];
        $money = fn($v) => $e($cur . number_format((float) $v, 2));
        $date = fn($v) => $v ? $e(date((string) $cfg['date_format'], is_numeric($v) ? (int) $v : (strtotime((string) $v) ?: time()))) : '';
        $thermal = str_starts_with((string) $cfg['paper'], 'thermal');
        $c = $d['clinic']; $r = $d['receipt']; $p = $d['patient']; $s = $d['signer'];

        $contact = [];
        if ($cfg['show_address'] && $c['address'] !== '') $contact[] = $e($c['address']);
        $line2 = [];
        if ($cfg['show_phone'] && $c['phone'] !== '') $line2[] = $e($c['phone']);
        if ($cfg['show_email'] && $c['email'] !== '') $line2[] = $e($c['email']);
        if ($line2) $contact[] = implode(' · ', $line2);
        $ids = [];
        if ($cfg['show_tin'] && $r['tin'] !== '') $ids[] = 'TIN ' . $e($r['tin']);
        if ($cfg['show_license'] && $c['license'] !== '') $ids[] = 'Licence ' . $e($c['license']);
        if ($ids) $contact[] = implode(' · ', $ids);

        $copies = $cfg['copies'] == 2 ? ['Patient copy', 'Clinic copy'] : [''];
        $out = '';
        foreach ($copies as $i => $copyLabel) {
            if ($i > 0) $out .= '<hr class="rc-cut">';
            $out .= '<div class="rc-sheet">';
            if ($copyLabel) $out .= '<div class="rc-copy">' . $e($copyLabel) . '</div>';

            // Header
            $out .= '<header class="rc-head ' . ($cfg['logo_align'] === 'center' ? 'center' : '') . '"><div class="rc-brand">';
            if ($cfg['show_logo'] && $cfg['logo'] !== '') $out .= '<img class="rc-logo" src="' . $e($cfg['logo']) . '" alt="">';
            $out .= '<div><div class="rc-clinic">' . $e($c['name']) . '</div>';
            if ($c['subtitle'] !== '') $out .= '<div class="rc-sub muted">' . $e($c['subtitle']) . '</div>';
            foreach ($contact as $line) $out .= '<div class="rc-contact muted">' . $line . '</div>';
            $out .= '</div></div><div class="rc-meta">';
            $out .= '<div class="rc-title">' . $e($cfg['title']) . '</div>';
            $out .= '<div class="rc-no num">' . $e($cfg['number_prefix'] . $r['number']) . '</div>';
            $out .= '<div class="muted">Paid ' . $date($r['paid_at']) . '</div>';
            $out .= '<div class="muted">Invoice ' . $e($r['invoice_number']) . '</div>';
            $out .= '</div></header>';

            // Amount paid
            $out .= '<section class="rc-paid"><p>' . nl2br($e($cfg['thank_you'])) . '</p><div><strong>Amount paid</strong><span class="amt num">' . $money($d['total_paid']) . '</span></div></section>';

            // Parties
            $out .= '<section class="rc-parties"><div><div class="rc-label">Received from</div><div class="rc-strong">' . $e($p['name']) . '</div>';
            if ($cfg['show_mrn'] && $p['mrn'] !== '') $out .= '<div class="muted">MRN <span class="num">' . $e($p['mrn']) . '</span></div>';
            if ($cfg['show_service_date'] && $r['service_date']) $out .= '<div class="muted">Service date ' . $date($r['service_date']) . '</div>';
            if ($cfg['show_encounter'] && $r['ref'] !== '') $out .= '<div class="muted">Encounter ' . $e($r['ref']) . '</div>';
            $out .= '</div>';
            if ($cfg['show_payments'] || $cfg['show_cashier']) {
                $out .= '<div' . ($thermal ? '' : ' style="text-align:right"') . '><div class="rc-label">Payment</div>';
                if ($cfg['show_payments']) {
                    foreach ($d['payments'] as $pm) {
                        $out .= '<div>' . $e($pm['type']) . ' <span class="num rc-strong">' . $money($pm['amount']) . '</span></div>';
                    }
                }
                if ($cfg['show_cashier'] && $r['cashier'] !== '') $out .= '<div class="muted">Received by ' . $e($r['cashier']) . '</div>';
                $out .= '</div>';
            }
            $out .= '</section>';

            // Items
            $out .= '<table><thead><tr><th>Item</th>';
            if ($cfg['show_qty']) $out .= '<th class="c">Qty</th>';
            if ($cfg['show_unit_price'] && !$thermal) $out .= '<th class="r">Unit price</th>';
            $out .= '<th class="r">Amount</th></tr></thead><tbody>';
            foreach ($d['items'] as $it) {
                $out .= '<tr><td>' . $e($it['name']) . '</td>';
                if ($cfg['show_qty']) $out .= '<td class="c num">' . $e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')) . '</td>';
                if ($cfg['show_unit_price'] && !$thermal) $out .= '<td class="r num">' . $money($it['unit_price']) . '</td>';
                $out .= '<td class="r num">' . $money($it['total_price']) . '</td></tr>';
            }
            $out .= '</tbody></table>';

            // Totals
            $out .= '<div class="rc-totals">';
            if ($cfg['show_tax'] && $d['tax_total'] > 0) {
                $out .= '<div><span class="muted">Subtotal before VAT</span><span class="num">' . $money($d['total'] - $d['tax_total']) . '</span></div>';
                $out .= '<div><span class="muted">VAT included</span><span class="num">' . $money($d['tax_total']) . '</span></div>';
            }
            $out .= '<div class="grand"><span>Total</span><span class="num">' . $money($d['total']) . '</span></div>';
            $out .= '<div><span class="muted">Paid</span><span class="num">' . $money($d['total_paid']) . '</span></div>';
            if ($cfg['show_balance']) $out .= '<div><span class="muted">Balance remaining</span><span class="num rc-strong">' . $money($d['balance']) . '</span></div>';
            $out .= '</div>';

            // Sign-off
            $left = '';
            if ($cfg['show_qr'] && !empty($r['verification_url'])) {
                // The QR image comes from an external service. If the front desk is
                // offline the receipt still has to carry the verification link, so the
                // image hides itself on failure and the URL prints instead.
                $url = (string) $r['verification_url'];
                $left .= '<div class="rc-qr">'
                    . '<img src="https://api.qrserver.com/v1/create-qr-code/?size=168x168&amp;data=' . $e(rawurlencode($url)) . '"'
                    . ' alt="" referrerpolicy="no-referrer"'
                    . ' onerror="this.style.display=\'none\';var f=this.parentNode.querySelector(\'.rc-qr-fallback\');if(f)f.style.display=\'block\'">'
                    . '<div>Scan to verify this<br>fiscal invoice with FRCS'
                    . '<span class="rc-qr-fallback">' . $e($url) . '</span></div></div>';
            }
            if ($cfg['show_stamp'] && $s['stamp'] !== '') $left .= '<img class="rc-stamp" src="' . $e($s['stamp']) . '" alt="Clinic stamp">';
            $right = '';
            if ($cfg['show_signature']) {
                $right .= '<div class="rc-sign">';
                if ($s['signature'] !== '') $right .= '<img src="' . $e($s['signature']) . '" alt="Signature">';
                $right .= '<div class="line">' . $e($s['name'] ?: 'Authorised signature') . '</div>';
                if ($s['title'] !== '') $right .= '<div class="muted">' . $e($s['title']) . '</div>';
                $right .= '</div>';
            }
            if ($left !== '' || $right !== '') {
                $out .= '<footer class="rc-foot"><div style="display:flex;gap:12px;align-items:center">' . $left . '</div>' . $right . '</footer>';
            }
            if ($cfg['footer_note'] !== '') $out .= '<div class="rc-note">' . $e($cfg['footer_note']) . '</div>';
            $out .= '</div>';
        }

        return '<article class="rc ' . ($thermal ? 'thermal' : '') . '">' . $out . '</article>';
    }

    /** Sample data for the settings preview. */
    function nxSampleReceiptData(array $clinic, string $currency, array $signer): array
    {
        return [
            'currency' => $currency,
            'clinic' => $clinic,
            'receipt' => [
                'number' => '2026-0142', 'invoice_number' => 'INV-2026-0142', 'paid_at' => time(),
                'service_date' => date('Y-m-d'), 'ref' => 'ENC-88213', 'cashier' => 'Front desk',
                'tin' => $clinic['tin'] ?? '', 'verification_url' => 'https://vms.frcs.org.fj/verify?sample=1',
            ],
            'patient' => ['name' => 'Mereani Waqa', 'mrn' => 'MRN-104233'],
            'items' => [
                ['name' => 'General consultation', 'quantity' => 1, 'unit_price' => 45.00, 'total_price' => 45.00],
                ['name' => 'Amoxicillin 500 mg capsules', 'quantity' => 21, 'unit_price' => 0.60, 'total_price' => 12.60],
                ['name' => 'Blood glucose test', 'quantity' => 1, 'unit_price' => 15.00, 'total_price' => 15.00],
            ],
            'payments' => [['type' => 'EFTPOS', 'amount' => 72.60]],
            'total' => 72.60, 'tax_total' => 9.47, 'total_paid' => 72.60, 'balance' => 0.00,
            'signer' => $signer,
        ];
    }
}
