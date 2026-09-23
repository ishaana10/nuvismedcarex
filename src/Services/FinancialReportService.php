<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class FinancialReportService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Generate daily or date-range financial report summarized by payment method & status
     */
    public function getDailySummary(string $startDate, ?string $endDate = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $endDate = $endDate ?? $startDate;

        $stmt = $this->db->prepare("
            SELECT * FROM invoices
            WHERE tenant_id = :tid AND service_date BETWEEN :sdate AND :edate
            ORDER BY service_date ASC
        ");
        $stmt->execute(['tid' => $tenantId, 'sdate' => $startDate, 'edate' => $endDate]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_invoices' => count($invoices),
            'gross_revenue' => 0.0,
            'insurance_covered' => 0.0,
            'patient_owed' => 0.0,
            'total_tax' => 0.0,
            'fiscalized_count' => 0,
            'by_payment_method' => [],
            'by_status' => []
        ];

        foreach ($invoices as $inv) {
            $amt = (float)$inv['amount'];
            $ins = (float)($inv['insurance_covered'] ?? 0.0);
            $owed = (float)($inv['patient_owed'] ?? 0.0);
            $tax = (float)($inv['total_tax'] ?? 0.0);

            $summary['gross_revenue'] += $amt;
            $summary['insurance_covered'] += $ins;
            $summary['patient_owed'] += $owed;
            $summary['total_tax'] += $tax;

            if (!empty($inv['is_fiscalized'])) {
                $summary['fiscalized_count']++;
            }

            $status = $inv['status'] ?? 'Pending';
            $summary['by_status'][$status] = ($summary['by_status'][$status] ?? 0) + 1;

            $methods = json_decode($inv['payment_methods'] ?? '[]', true);
            if (is_array($methods)) {
                foreach ($methods as $m) {
                    $type = $m['type'] ?? 'Cash';
                    $pAmt = (float)($m['amount'] ?? 0.0);
                    $summary['by_payment_method'][$type] = ($summary['by_payment_method'][$type] ?? 0.0) + $pAmt;
                }
            }
        }

        return $summary;
    }
}
