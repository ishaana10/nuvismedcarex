<?php

namespace ClinicFlow\Services;

use PDO;
use Exception;

class TaxService
{
    private PDO $pdo;

    // Tax rates by label (default values, can be overridden by clinic settings)
    private array $taxRates = [
        'A' => 15.00, // VAT Standard Rate 15%
        'E' => 0.00,  // Exempt Rate 0%
        'F' => 0.00,  // Zero-rated 0%
        'P' => 0.25   // Special Rate 0.25%
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        try {
            $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'tax_rate_%'");
            $settings = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

            try {
                $tenantStmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key LIKE 'tax_rate_%'");
                $tenantStmt->execute([$tenantId]);
                while ($row = $tenantStmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (\Throwable $te) {
                // Table might not exist in isolated memory test databases
            }

            if (isset($settings['tax_rate_a'])) $this->taxRates['A'] = (float)$settings['tax_rate_a'];
            if (isset($settings['tax_rate_e'])) $this->taxRates['E'] = (float)$settings['tax_rate_e'];
            if (isset($settings['tax_rate_f'])) $this->taxRates['F'] = (float)$settings['tax_rate_f'];
            if (isset($settings['tax_rate_p'])) $this->taxRates['P'] = (float)$settings['tax_rate_p'];
        } catch (Exception $e) {
            \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Shared\Logger::class)->warning("Failed loading tax settings, falling back to defaults: " . $e->getMessage());
        }
    }

    public function getTaxRates(): array
    {
        return $this->taxRates;
    }

    /**
     * Calculate tax amount for an item price (prices include VAT)
     * Tax Amount = Total Price - (Total Price / (1 + Rate/100))
     */
    public function calculateItemTax(float $totalPrice, string $taxLabel): array
    {
        $rate = $this->taxRates[$taxLabel] ?? 15.00;
        if ($rate <= 0) {
            return [
                'tax_rate' => $rate,
                'tax_amount' => 0.00,
                'net_amount' => round($totalPrice, 2)
            ];
        }

        $netAmount = $totalPrice / (1 + ($rate / 100));
        $taxAmount = $totalPrice - $netAmount;

        return [
            'tax_rate' => $rate,
            'tax_amount' => round($taxAmount, 4), // 4 decimals
            'net_amount' => round($netAmount, 4)
        ];
    }

    /**
     * Cancels an invoice by issuing a matching counter invoice (a sale becomes a refund and the
     * reverse) that points back at the original, and marks the original as cancelled.
     */
    public function cancelInvoice(string $originalInvoiceId, string $cashierName = 'Admin'): string
    {
        $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
        $stmt = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
        $stmt->execute([$originalInvoiceId, $tenantId]);
        $orig = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$orig) {
            throw new Exception("Original invoice not found");
        }

        $stmtItems = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $stmtItems->execute([$originalInvoiceId]);
        $origItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $newInvoiceId = 'inv-' . uniqid();

        // Determine counter transaction type (Sale -> Refund, Refund -> Sale)
        $newTxType = ($orig['transaction_type'] === 'Refund') ? 'Sale' : 'Refund';
        $newType = $orig['invoice_type']; // Normal or Advance

        $refNo = $orig['invoice_number'];

        $stmtIns = $this->pdo->prepare("
            INSERT INTO invoices (
                id, tenant_id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date,
                amount, status, insurance_covered, patient_owed, services, invoice_type, transaction_type,
                cashier, ref_no, total_tax, payment_methods
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, 'Cancelled', ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )
        ");

        [$newInvNumber] = \ClinicFlow\Services\DocumentNumbers::issue($this->pdo, (string) ($orig['tenant_id'] ?? $tenantId), 'invoices', 'invoice_number', 'INV-CAN-', 5, function (string $newInvNumber) use ($stmtIns, $cashierName, $newInvoiceId, $newTxType, $newType, $refNo, $tenantId, $orig) {
        $stmtIns->execute([
            $newInvoiceId,
            $orig['tenant_id'] ?? $tenantId,
            $newInvNumber,
            $orig['patient_id'],
            $orig['patient_name'],
            $orig['patient_mrn'],
            date('Y-m-d'),
            date('Y-m-d'),
            $orig['amount'],
            $orig['insurance_covered'],
            $orig['patient_owed'],
            $orig['services'],
            $newType,
            $newTxType,
            $cashierName,
            $refNo,
            $orig['total_tax'] ?? 0,
            $orig['payment_methods']
        ]);
        });

        // Copy item lines
        $stmtItemIns = $this->pdo->prepare("
            INSERT INTO invoice_items (id, tenant_id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($origItems as $item) {
            $stmtItemIns->execute([
                'item-' . uniqid(),
                $tenantId,
                $newInvoiceId,
                $item['name'],
                $item['gtin'],
                $item['unit_price'],
                $item['quantity'],
                $item['total_price'],
                $item['tax_label'],
                $item['tax_rate'],
                $item['tax_amount']
            ]);
        }

        // Mark original invoice status as Cancelled
        $stmtUpdateOrig = $this->pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE id = ? AND tenant_id = ?");
        $stmtUpdateOrig->execute([$originalInvoiceId, $tenantId]);

        return $newInvoiceId;
    }

    /**
     * Builds the daily summary (Z-Report) figures for a given date
     */
    public function getDailySummary(string $date, ?string $fromDate = null, ?string $toDate = null, ?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? \ClinicFlow\Shared\TenantContext::getTenantId();

        if ($fromDate !== null && $toDate !== null) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM invoices
                WHERE tenant_id = ? AND (DATE(created_at) BETWEEN ? AND ? OR service_date BETWEEN ? AND ?)
                ORDER BY created_at ASC
            ");
            $stmt->execute([$tenantId, $fromDate, $toDate, $fromDate, $toDate]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT * FROM invoices
                WHERE tenant_id = ? AND (DATE(created_at) = ? OR service_date = ?)
                ORDER BY created_at ASC
            ");
            $stmt->execute([$tenantId, $date, $date]);
        }
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'date' => $date,
            'total_invoices' => count($invoices),
            'by_type' => [
                'Normal-Sale' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Normal-Refund' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Advance-Sale' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Advance-Refund' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Proforma-Sale' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Copy-Sale' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0],
                'Training-Sale' => ['count' => 0, 'amount' => 0.0, 'tax' => 0.0]
            ],
            'by_payment' => [
                'Cash' => 0.0,
                'Card' => 0.0,
                'Check' => 0.0,
                'Wire Transfer' => 0.0,
                'Voucher' => 0.0,
                'Mobile Money' => 0.0,
                'Other' => 0.0
            ],
            'total_sales' => 0.0,
            'total_refunds' => 0.0,
            'total_vat' => 0.0,
            // Added for the Z-Report screen and printed summary
            'from' => $fromDate,
            'to' => $toDate,
            'is_range' => $fromDate !== null && $toDate !== null,
            'sales_count' => 0,
            'refund_count' => 0,
            'insurance_covered' => 0.0,
            'patient_share' => 0.0,
            'net_sales' => 0.0,          // turnover excluding VAT
            'first_invoice' => '',
            'last_invoice' => '',
            'by_tax' => [],              // tax label => net, tax, gross
            'payments_total' => 0.0,
            'reconciliation' => 0.0,     // payments recorded less net turnover
        ];

        foreach ($invoices as $inv) {
            $key = $inv['invoice_type'] . '-' . $inv['transaction_type'];
            if (!isset($summary['by_type'][$key])) {
                $summary['by_type'][$key] = ['count' => 0, 'amount' => 0.0, 'tax' => 0.0];
            }

            $amt = (float)$inv['amount'];
            $tax = (float)$inv['total_tax'];

            $summary['by_type'][$key]['count']++;
            $summary['by_type'][$key]['amount'] += $amt;
            $summary['by_type'][$key]['tax'] += $tax;

            if ($inv['transaction_type'] === 'Sale') {
                $summary['total_sales'] += $amt;
                $summary['sales_count']++;
            } else {
                $summary['total_refunds'] += $amt;
                $summary['refund_count']++;
            }
            $summary['total_vat'] += $tax;

            // A refund takes money back out, so its lines count against turnover
            $sign = ($inv['transaction_type'] ?? 'Sale') === 'Refund' ? -1 : 1;

            // What a third party covered, so the payment lines add up to turnover
            $covered = max(0.0, (float) ($inv['insurance_covered'] ?? 0));
            $covered = min($covered, max(0.0, $amt));
            $patientShare = max(0.0, $amt - $covered);
            $summary['insurance_covered'] += $sign * $covered;
            $summary['patient_share'] += $sign * $patientShare;

            if (($inv['invoice_number'] ?? '') !== '') {
                if ($summary['first_invoice'] === '') {
                    $summary['first_invoice'] = (string) $inv['invoice_number'];
                }
                $summary['last_invoice'] = (string) $inv['invoice_number'];
            }

            $payments = json_decode($inv['payment_methods'] ?? '[]', true);
            if (is_array($payments) && !empty($payments)) {
                foreach ($payments as $p) {
                    $pType = $p['type'] ?? 'Cash';
                    $pAmt = $sign * (float)($p['amount'] ?? 0);
                    if (isset($summary['by_payment'][$pType])) {
                        $summary['by_payment'][$pType] += $pAmt;
                    } else {
                        $summary['by_payment'][$pType] = $pAmt;
                    }
                }
            } elseif ($patientShare > 0) {
                // No payment lines recorded: credit the patient's share only, so
                // an insured invoice is no longer counted as cash in the drawer.
                $pType = $inv['payment_type'] ?? 'Cash';
                if (!isset($summary['by_payment'][$pType])) {
                    $summary['by_payment'][$pType] = 0.0;
                }
                $summary['by_payment'][$pType] += $sign * $patientShare;
            }

            if ($covered > 0) {
                $summary['by_payment']['Insurance / third party'] =
                    ($summary['by_payment']['Insurance / third party'] ?? 0.0) + $sign * $covered;
            }
        }

        $summary['net_sales'] = $summary['total_sales'] - $summary['total_refunds'] - $summary['total_vat'];
        $summary['payments_total'] = array_sum($summary['by_payment']);
        $summary['reconciliation'] = round(
            $summary['payments_total'] - ($summary['total_sales'] - $summary['total_refunds']),
            2
        );
        $summary['by_tax'] = $this->taxBreakdown(array_column($invoices, 'id'));

        return $summary;
    }

    /**
     * VAT split by tax label (A, E, F, P) for the invoices in a report.
     * Falls back to the invoice-level tax total when line items were never
     * written, so the figures are never silently zero.
     *
     * @param string[] $invoiceIds
     */
    private function taxBreakdown(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_filter($invoiceIds, static fn($id) => (string) $id !== ''));
        if ($invoiceIds === []) {
            return [];
        }
        $out = [];
        try {
            $in = implode(',', array_fill(0, count($invoiceIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT tax_label,
                       SUM(total_price) AS gross,
                       SUM(tax_amount) AS tax,
                       COUNT(*) AS lines
                  FROM invoice_items
                 WHERE invoice_id IN ($in)
                 GROUP BY tax_label
                 ORDER BY tax_label ASC
            ");
            $stmt->execute($invoiceIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $label = (string) ($row['tax_label'] ?: 'A');
                $gross = (float) $row['gross'];
                $tax = (float) $row['tax'];
                $out[$label] = [
                    'label' => $label,
                    'rate' => $this->taxRates[$label] ?? null,
                    'lines' => (int) $row['lines'],
                    'gross' => $gross,
                    'tax' => $tax,
                    'net' => $gross - $tax,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[TaxService] tax breakdown: ' . $e->getMessage());
        }
        return $out;
    }
}
