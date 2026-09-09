<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Domain\ValueObjects\Money;

class BillingService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    public function getInvoices(?string $status = null, ?string $patientId = null, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $sql = "SELECT * FROM invoices WHERE tenant_id = :tid";
        $params = ['tid' => $tenantId];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        if ($patientId) {
            $sql .= " AND patient_id = :pid";
            $params['pid'] = $patientId;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInvoiceById(string $id, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            return null;
        }

        $itemStmt = $this->db->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id AND tenant_id = :tid");
        $itemStmt->execute(['id' => $id, 'tid' => $tenantId]);
        $inv['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return $inv;
    }

    public function createInvoice(array $data, array $items = [], ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $id = Uuid::uuidv7();
        $invNumber = 'INV-' . date('Y') . '-' . sprintf('%04d', rand(1, 9999));

        $totalMoney = Money::zero('FJD');
        foreach ($items as $item) {
            $itemMoney = Money::fromFloat((float)($item['total_price'] ?? (($item['unit_price'] ?? 0) * ($item['quantity'] ?? 1))));
            $totalMoney = $totalMoney->add($itemMoney);
        }
        if ($totalMoney->getAmountFloat() <= 0.0 && isset($data['amount'])) {
            $totalMoney = Money::fromFloat((float)$data['amount']);
        }

        $insuranceCovered = Money::fromFloat((float)($data['insurance_covered'] ?? 0.0));
        $patientOwedFloat = max(0.0, $totalMoney->getAmountFloat() - $insuranceCovered->getAmountFloat());
        $patientOwed = Money::fromFloat($patientOwedFloat);

        $stmt = $this->db->prepare(
            "INSERT INTO invoices (id, tenant_id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date, amount, status, insurance_covered, patient_owed, services, invoice_type, transaction_type, seller_tin, business_location, cashier) " .
            "VALUES (:id, :tid, :inv_num, :pid, :pname, :pmrn, :sdate, :ddate, :amt, :status, :ins, :owed, :services, :inv_type, :txn_type, :tin, :loc, :cashier)"
        );

        $stmt->execute([
            'id' => $id,
            'tid' => $tenantId,
            'inv_num' => $invNumber,
            'pid' => $data['patient_id'] ?? null,
            'pname' => $data['patient_name'] ?? 'Walk-in Patient',
            'pmrn' => $data['patient_mrn'] ?? 'N/A',
            'sdate' => $data['service_date'] ?? date('Y-m-d'),
            'ddate' => $data['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
            'amt' => $totalMoney->getAmountFloat(),
            'status' => 'Pending',
            'ins' => $insuranceCovered->getAmountFloat(),
            'owed' => $patientOwed->getAmountFloat(),
            'services' => json_encode($data['services'] ?? []),
            'inv_type' => $data['invoice_type'] ?? 'Normal',
            'txn_type' => $data['transaction_type'] ?? 'Sale',
            'tin' => $data['seller_tin'] ?? '502579006',
            'loc' => $data['business_location'] ?? 'Suva Central Clinic',
            'cashier' => $_SESSION['user_name'] ?? 'Admin'
        ]);

        foreach ($items as $item) {
            $itemId = Uuid::uuidv7();
            $itemStmt = $this->db->prepare(
                "INSERT INTO invoice_items (id, tenant_id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount) " .
                "VALUES (:id, :tid, :inv_id, :name, :gtin, :uprice, :qty, :tprice, :label, :rate, :tamt)"
            );
            $qty = (float)($item['quantity'] ?? 1.0);
            $uPriceMoney = Money::fromFloat((float)($item['unit_price'] ?? 0.0));
            $tPriceMoney = $uPriceMoney->multiply($qty);
            $taxRate = (float)($item['tax_rate'] ?? 15.0);
            $taxAmtMoney = $tPriceMoney->multiply($taxRate / 100.0);

            $itemStmt->execute([
                'id' => $itemId,
                'tid' => $tenantId,
                'inv_id' => $id,
                'name' => $item['name'] ?? 'Service',
                'gtin' => $item['gtin'] ?? null,
                'uprice' => $uPriceMoney->getAmountFloat(),
                'qty' => $qty,
                'tprice' => $tPriceMoney->getAmountFloat(),
                'label' => $item['tax_label'] ?? 'A',
                'rate' => $taxRate,
                'tamt' => $taxAmtMoney->getAmountFloat()
            ]);
        }

        $this->audit->log("CREATE_INVOICE", "Created invoice $invNumber for " . $totalMoney->format(), null, null, null, $tenantId);

        return $this->getInvoiceById($id, $tenantId);
    }

    public function recordPayment(string $invoiceId, float $amountPaid, string $paymentMethod = 'Cash', ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $inv = $this->getInvoiceById($invoiceId, $tenantId);
        if (!$inv) {
            throw new \RuntimeException("Invoice not found.");
        }

        $paidMoney = Money::fromFloat($amountPaid);
        $currentOwedMoney = Money::fromFloat((float)$inv['patient_owed']);
        $newOwedFloat = max(0.0, $currentOwedMoney->getAmountFloat() - $paidMoney->getAmountFloat());
        $newStatus = $newOwedFloat <= 0 ? 'Paid' : 'Partial';

        $stmt = $this->db->prepare("UPDATE invoices SET patient_owed = :owed, status = :status WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['owed' => $newOwedFloat, 'status' => $newStatus, 'id' => $invoiceId, 'tid' => $tenantId]);

        $this->audit->log("RECORD_PAYMENT", "Payment of " . $paidMoney->format() . " via $paymentMethod for invoice {$inv['invoice_number']}", null, null, null, $tenantId);

        return $this->getInvoiceById($invoiceId, $tenantId);
    }
}
