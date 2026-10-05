<?php

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;
use ClinicFlow\Utils\Encryption;
use ClinicFlow\Shared\TenantContext;

class EncounterService {
    private PDO $db;
    private AuditService $audit;

    public function __construct(PDO $db, ?AuditService $audit = null) {
        $this->db = $db;
        $this->audit = $audit ?? new AuditService($db);
    }

    public function getEncountersByPatient(string $patientId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM past_visits WHERE patient_id = :pid AND tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $patientId, 'tid' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            if (!empty($row['soap_notes'])) {
                $decryptedSoap = Encryption::decrypt($row['soap_notes']);
                $jsonSoap = json_decode($decryptedSoap, true);
                $row['soap_notes_parsed'] = $jsonSoap ?: $decryptedSoap;
            }
            if (!empty($row['vitals'])) {
                $decryptedVitals = Encryption::decrypt($row['vitals']);
                $row['vitals_parsed'] = json_decode($decryptedVitals, true) ?: $decryptedVitals;
            }
        }

        return $rows;
    }

    public function saveEncounter(
        string $patientId,
        array $vitalsData,
        array $soapData,
        array $prescriptions = [],
        bool $finalize = false,
        ?string $visitId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $visitId = $visitId ?: Uuid::uuidv7();
        $doctorName = $_SESSION['user_name'] ?? $_SESSION['user']['name'] ?? 'Attending Physician';

        // 1. Update / Insert Vitals
        $vStmt = $this->db->prepare("DELETE FROM vitals WHERE patient_id = ? AND tenant_id = ?");
        $vStmt->execute([$patientId, $tenantId]);

        $vInsert = $this->db->prepare(
            "INSERT INTO vitals (id, tenant_id, patient_id, blood_pressure, heart_rate, temperature, oxygen_sat, weight, height, bmi) " .
            "VALUES (:id, :tid, :pid, :bp, :hr, :temp, :spo2, :wt, :ht, :bmi)"
        );
        $vInsert->execute([
            'id' => Uuid::uuidv7(),
            'tid' => $tenantId,
            'pid' => $patientId,
            'bp' => $vitalsData['blood_pressure'] ?? '120/80',
            'hr' => (int)($vitalsData['heart_rate'] ?? 72),
            'temp' => (float)($vitalsData['temperature'] ?? 98.6),
            'spo2' => (int)($vitalsData['oxygen_sat'] ?? 99),
            'wt' => (int)($vitalsData['weight'] ?? 145),
            'ht' => (int)($vitalsData['height'] ?? 66),
            'bmi' => (float)($vitalsData['bmi'] ?? 23.4)
        ]);

        // 2. Encrypt and save SOAP Notes
        $sStmt = $this->db->prepare("DELETE FROM soap_notes WHERE patient_id = ? AND tenant_id = ?");
        $sStmt->execute([$patientId, $tenantId]);

        $rawSoapJson = json_encode($soapData);
        $encryptedSoap = Encryption::encrypt($rawSoapJson);

        $sInsert = $this->db->prepare(
            "INSERT INTO soap_notes (id, tenant_id, patient_id, subjective, objective, assessment_codes, plan) " .
            "VALUES (:id, :tid, :pid, :sub, :obj, :codes, :plan)"
        );
        $sInsert->execute([
            'id' => Uuid::uuidv7(),
            'tid' => $tenantId,
            'pid' => $patientId,
            'sub' => Encryption::encrypt($soapData['subjective'] ?? ''),
            'obj' => Encryption::encrypt($soapData['objective'] ?? ''),
            'codes' => json_encode([['code' => $soapData['icd_code'] ?? 'J01.90', 'label' => $soapData['icd_code'] ?? 'J01.90']]),
            'plan' => Encryption::encrypt($soapData['plan'] ?? '')
        ]);

        $pastVisitId = null;

        if ($finalize) {
            $pastVisitId = Uuid::uuidv7();
            $icdCode = $soapData['icd_code'] ?? 'J01.90';
            $title = "Clinical Encounter ({$icdCode})";
            $planText = $soapData['plan'] ?? '';
            $summary = !empty($planText) ? (substr($planText, 0, 120) . (strlen($planText) > 120 ? '...' : '')) : "Clinical encounter completed.";

            $pvInsert = $this->db->prepare(
                "INSERT INTO past_visits (id, tenant_id, patient_id, visit_id, visit_date, title, summary, doctor_name, vitals, soap_notes, prescriptions) " .
                "VALUES (:id, :tid, :pid, :vid, :vdate, :title, :summary, :doc, :vitals, :soaps, :rxs)"
            );
            $pvInsert->execute([
                'id' => $pastVisitId,
                'tid' => $tenantId,
                'pid' => $patientId,
                'vid' => $visitId,
                'vdate' => date('M d, Y'),
                'title' => $title,
                'summary' => $summary,
                'doc' => $doctorName,
                'vitals' => Encryption::encrypt(json_encode($vitalsData)),
                'soaps' => $encryptedSoap,
                'rxs' => json_encode($prescriptions)
            ]);

            // Remove patient from queue
            $qStmt = $this->db->prepare("DELETE FROM queue WHERE patient_id = ? AND tenant_id = ?");
            $qStmt->execute([$patientId, $tenantId]);

            // Update appointment status to Completed if appointment_id is provided or matched
            $appointmentId = $_REQUEST['appointment_id'] ?? null;
            if ($appointmentId) {
                $apptStmt = $this->db->prepare("UPDATE appointments SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?");
                $apptStmt->execute([$appointmentId, $tenantId]);
            } else {
                // Otherwise update any active or in-progress appointment for this patient today/recent
                $apptStmt = $this->db->prepare("UPDATE appointments SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE patient_id = ? AND tenant_id = ? AND (status != 'Completed' OR status IS NULL)");
                $apptStmt->execute([$patientId, $tenantId]);
            }
        }

        $this->audit->log($finalize ? 'FINALIZE_ENCOUNTER' : 'SAVE_ENCOUNTER', "Patient $patientId encounter saved", null, null, null, $tenantId);

        return [
            'visit_id' => $visitId,
            'past_visit_id' => $pastVisitId,
            'finalized' => $finalize,
            'vitals' => $vitalsData,
            'soap' => $soapData
        ];
    }

    public function saveEncounterData(string $patientId, array $vitalsData, array $soapData, ?string $visitId = null, ?string $tenantId = null): array {
        return $this->saveEncounter($patientId, $vitalsData, $soapData, [], false, $visitId, $tenantId);
    }

    public function finalizeEncounter(string $patientId, ?string $visitId = null, array $finalizeOptions = [], ?string $tenantId = null): array {
        // Fetch current prescriptions for visit
        $tenantId = $tenantId ?? TenantContext::getTenantId();
        $rxStmt = $this->db->prepare("SELECT medication_name, dosage, frequency, duration, instructions FROM prescriptions WHERE patient_id = ? AND tenant_id = ?");
        $rxStmt->execute([$patientId, $tenantId]);
        $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch vitals
        $vStmt = $this->db->prepare("SELECT * FROM vitals WHERE patient_id = ? AND tenant_id = ? LIMIT 1");
        $vStmt->execute([$patientId, $tenantId]);
        $vitalsRow = $vStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Fetch soap
        $sStmt = $this->db->prepare("SELECT * FROM soap_notes WHERE patient_id = ? AND tenant_id = ? LIMIT 1");
        $sStmt->execute([$patientId, $tenantId]);
        $soapRow = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $vitalsData = [
            'blood_pressure' => $vitalsRow['blood_pressure'] ?? '120/80',
            'heart_rate' => $vitalsRow['heart_rate'] ?? 72,
            'temperature' => $vitalsRow['temperature'] ?? 98.6,
            'oxygen_sat' => $vitalsRow['oxygen_sat'] ?? 99
        ];

        $subjective = !empty($soapRow['subjective']) ? Encryption::decrypt($soapRow['subjective']) : '';
        $objective = !empty($soapRow['objective']) ? Encryption::decrypt($soapRow['objective']) : '';
        $plan = !empty($soapRow['plan']) ? Encryption::decrypt($soapRow['plan']) : '';
        $codes = json_decode($soapRow['assessment_codes'] ?? '[]', true) ?: [];
        $icdCode = $codes[0]['code'] ?? 'J01.90';

        $soapData = [
            'subjective' => $subjective,
            'objective' => $objective,
            'icd_code' => $icdCode,
            'plan' => $plan
        ];

        $res = $this->saveEncounter($patientId, $vitalsData, $soapData, $prescriptions, true, $visitId, $tenantId);

        if (!empty($finalizeOptions['create_invoice'])) {
            $pStmt = $this->db->prepare("SELECT * FROM patients WHERE id = ?");
            $pStmt->execute([$patientId]);
            $patient = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($patient) {
                $itemNames = $finalizeOptions['item_name'] ?? [];
                if (!empty($itemNames) && is_array($itemNames)) {
                    $vmsService = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\VMSService::class);
                    $invoiceId = 'inv-' . uniqid();
                    $invoiceNumber = 'INV-' . date('Y') . '-' . rand(10000, 99999);

                    $invoiceType = $finalizeOptions['invoice_type'] ?? 'Normal';
                    $transactionType = $finalizeOptions['transaction_type'] ?? 'Sale';
                    $buyerTin = $finalizeOptions['buyer_tin'] ?? '';
                    $buyerCostCenter = $finalizeOptions['buyer_cost_center'] ?? '';

                    $inventoryIds = $finalizeOptions['inventory_id'] ?? [];
                    $gtins = $finalizeOptions['gtin'] ?? [];
                    $quantities = $finalizeOptions['quantity'] ?? [];
                    $unitPrices = $finalizeOptions['unit_price'] ?? [];
                    $taxLabels = $finalizeOptions['tax_label'] ?? [];

                    $paymentTypes = $finalizeOptions['payment_type'] ?? [];
                    $paymentAmounts = $finalizeOptions['payment_amount'] ?? [];

                    $totalAmount = 0.00;
                    $totalTax = 0.00;
                    $itemsToInsert = [];

                    for ($i = 0; $i < count($itemNames); $i++) {
                        $name = trim($itemNames[$i]);
                        if (empty($name)) continue;

                        $qty = (float)($quantities[$i] ?? 1.0);
                        $price = (float)($unitPrices[$i] ?? 0.0);
                        $label = $taxLabels[$i] ?? 'A';
                        $gtin = trim($gtins[$i] ?? '');

                        $lineTotal = round($qty * $price, 2);
                        $taxCalc = $vmsService->calculateItemTax($lineTotal, $label);

                        $totalAmount += $lineTotal;
                        $totalTax += round($taxCalc['tax_amount'], 2);

                        $invItemId = $inventoryIds[$i] ?? '';

                        $itemsToInsert[] = [
                            'id' => 'item-' . uniqid(),
                            'inventory_id' => $invItemId,
                            'name' => $name,
                            'gtin' => $gtin,
                            'unit_price' => $price,
                            'quantity' => $qty,
                            'total_price' => $lineTotal,
                            'tax_label' => $label,
                            'tax_rate' => $taxCalc['tax_rate'],
                            'tax_amount' => round($taxCalc['tax_amount'], 2)
                        ];

                        if (!empty($invItemId)) {
                            $stmtInvCheck = $this->db->prepare("SELECT id, current_stock, name FROM inventory WHERE id = ? FOR UPDATE");
                            $stmtInvCheck->execute([$invItemId]);
                            $invRow = $stmtInvCheck->fetch(PDO::FETCH_ASSOC);

                            if ($invRow) {
                                $prevStock = (int)$invRow['current_stock'];
                                $deductQty = (int)ceil($qty);
                                $newStock = max(0, $prevStock - $deductQty);
                                $stockStatus = ($newStock <= 0) ? 'Out of Stock' : (($newStock <= 10) ? 'Low Stock' : 'In Stock');

                                $stmtStockUpd = $this->db->prepare("UPDATE inventory SET current_stock = ?, status = ? WHERE id = ?");
                                $stmtStockUpd->execute([$newStock, $stockStatus, $invItemId]);

                                $logStmt = $this->db->prepare("INSERT INTO inventory_logs (id, tenant_id, inventory_id, change_amount, previous_stock, new_stock, type, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $logStmt->execute([
                                    'log-' . uniqid(),
                                    $tenantId,
                                    $invItemId,
                                    -$deductQty,
                                    $prevStock,
                                    $newStock,
                                    'Invoice Sale',
                                    'Deducted via Encounter Finalize VMS Invoice: ' . $invoiceNumber,
                                    $_SESSION['user_name'] ?? 'Attending Physician'
                                ]);
                            }
                        }
                    }

                    $paymentMethods = [];
                    $totalPaid = 0.00;
                    for ($j = 0; $j < count($paymentTypes); $j++) {
                        $pType = trim($paymentTypes[$j]);
                        $pAmt = (float)($paymentAmounts[$j] ?? 0.0);
                        if ($pAmt > 0) {
                            $paymentMethods[] = ['type' => $pType, 'amount' => $pAmt];
                            $totalPaid += $pAmt;
                        }
                    }

                    if (empty($paymentMethods)) {
                        $paymentMethods = [['type' => 'Cash', 'amount' => $totalAmount]];
                        $totalPaid = $totalAmount;
                    }

                    $status = ($totalPaid >= $totalAmount) ? 'Paid' : 'Pending';
                    $patientOwed = max(0.00, $totalAmount - $totalPaid);
                    $servicesJson = json_encode(array_column($itemsToInsert, 'name'));

                    $stmtSettings = $this->db->query("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'vms_%'");
                    $settings = $stmtSettings ? $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR) : [];

                    $sellerTin = $settings['vms_seller_tin'] ?? '502579006';
                    $businessLoc = $settings['vms_business_location'] ?? 'Suva Central Clinic, 2 Woodstand Road, Suva';
                    $posNum = $settings['vms_pos_number'] ?? 'ASDF238/1.2';
                    $cashierName = $_SESSION['user_name'] ?? 'Attending Physician';

                    $stmtInv = $this->db->prepare("
                        INSERT INTO invoices (
                            id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date,
                            amount, status, insurance_covered, patient_owed, services,
                            invoice_type, transaction_type, seller_tin, business_location, cashier,
                            buyer_tin, buyer_cost_center, pos_number, pos_time, ref_no, ref_time,
                            total_tax, payment_methods
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, 0.00, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, '', '',
                            ?, ?
                        )
                    ");

                    $stmtInv->execute([
                        $invoiceId, $invoiceNumber, $patientId, $patient['first_name'] . ' ' . $patient['last_name'], $patient['mrn'], date('Y-m-d'), date('Y-m-d', strtotime('+30 days')),
                        $totalAmount, $status, $patientOwed, $servicesJson,
                        $invoiceType, $transactionType, $sellerTin, $businessLoc, $cashierName,
                        $buyerTin, $buyerCostCenter, $posNum, date('Y-m-d H:i:s'),
                        $totalTax, json_encode($paymentMethods)
                    ]);

                    $stmtItem = $this->db->prepare("
                        INSERT INTO invoice_items (id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($itemsToInsert as $item) {
                        $stmtItem->execute([
                            $item['id'], $invoiceId, $item['name'], $item['gtin'], $item['unit_price'], $item['quantity'],
                            $item['total_price'], $item['tax_label'], $item['tax_rate'], $item['tax_amount']
                        ]);
                    }

                    $vmsService->fiscalizeInvoice($invoiceId);
                } else {
                    $billingService = new BillingService($this->db);
                    $billingService->createInvoice([
                        'patient_id' => $patientId,
                        'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
                        'patient_mrn' => $patient['mrn'],
                        'service_date' => date('Y-m-d'),
                        'due_date' => date('Y-m-d', strtotime('+30 days')),
                        'amount' => $finalizeOptions['amount'] ?? 150.00,
                        'insurance_covered' => $finalizeOptions['insurance_covered'] ?? 0.00,
                        'services' => [$finalizeOptions['service_description'] ?? 'Clinical Consultation & Examination']
                    ]);
                }
            }
        }

        return $res;
    }
}
