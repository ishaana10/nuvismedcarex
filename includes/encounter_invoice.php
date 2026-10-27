<?php
/**
 * Builds the invoice raised when a clinical encounter is finalised.
 *
 * Both finalise paths (an open visit and a past one) used to carry their own
 * copy of this, which is how the same two faults lived in both: the payment
 * line recorded the whole bill even when an insurer covered part of it, and
 * nothing ever raised the insurance claim.
 */

use ClinicFlow\Services\InsuranceProviders;
use ClinicFlow\Services\InsuranceService;
use ClinicFlow\Shared\Container;

if (!function_exists('nxCreateEncounterInvoice')) {

    /**
     * @param array $patient  The patient row, already checked against this clinic.
     * @param array $post     The submitted form.
     * @return array{invoice_id:string, invoice_number:string, total:float,
     *               insurance:float, patient_owed:float, claim_action:string,
     *               provider:string}
     */
    function nxCreateEncounterInvoice(PDO $pdo, array $patient, string $visitId, array $post): array
    {
        $container = Container::getInstance();
        $vmsService = $container->get(\ClinicFlow\Services\VMSService::class);
        $inventoryService = $container->get(\ClinicFlow\Services\InventoryService::class);
        $tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();

        $invoiceType = trim((string) ($post['invoice_type'] ?? 'Normal'));
        $transactionType = trim((string) ($post['transaction_type'] ?? 'Sale'));
        $paymentType = trim((string) ($post['payment_type'] ?? 'Cash'));
        $insuranceCovered = round(max(0.0, (float) ($post['insurance_covered'] ?? 0.0)), 2);

        $itemNames = (array) ($post['item_name'] ?? ['Clinical Consultation & Examination']);
        $inventoryIds = (array) ($post['inventory_id'] ?? []);
        $quantities = (array) ($post['quantity'] ?? [1]);
        $unitPrices = (array) ($post['unit_price'] ?? [150.00]);
        $taxLabels = (array) ($post['tax_label'] ?? ['A']);

        $invoiceId = 'inv-' . uniqid();
        $invNumber = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);

        $totalAmount = 0.00;
        $totalTax = 0.00;
        $items = [];

        foreach ($itemNames as $idx => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $qty = (float) ($quantities[$idx] ?? 1.0);
            $price = (float) ($unitPrices[$idx] ?? 0.00);
            $lineTotal = round($qty * $price, 2);
            $totalAmount += $lineTotal;

            $taxLabel = $taxLabels[$idx] ?? 'A';
            $taxCalc = $vmsService->calculateItemTax($lineTotal, $taxLabel);
            $totalTax += round($taxCalc['tax_amount'], 2);

            $invItemId = $inventoryIds[$idx] ?? null;
            if (!empty($invItemId)) {
                try {
                    $inventoryService->deductStock(
                        $invItemId,
                        (int) ceil($qty),
                        "Invoiced on encounter {$visitId}",
                        $_SESSION['user_name'] ?? 'System'
                    );
                } catch (\Throwable $e) {
                    error_log('[encounter invoice] stock: ' . $e->getMessage());
                }
            }

            $items[] = [
                'id' => 'item-' . uniqid(),
                'invoice_id' => $invoiceId,
                'name' => $name,
                'gtin' => '10009812',
                'unit_price' => $price,
                'quantity' => $qty,
                'total_price' => $lineTotal,
                'tax_label' => $taxLabel,
                'tax_rate' => $taxCalc['tax_rate'],
                'tax_amount' => round($taxCalc['tax_amount'], 2),
            ];
        }

        $totalAmount = round($totalAmount, 2);
        // An insurer cannot cover more than the bill
        $insuranceCovered = min($insuranceCovered, $totalAmount);
        $patientOwed = round(max(0.00, $totalAmount - $insuranceCovered), 2);

        $pdo->prepare("
            INSERT INTO invoices (
                id, tenant_id, ref_no, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date,
                amount, status, insurance_covered, patient_owed, services, invoice_type, transaction_type,
                total_tax, payment_methods
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            $tenantId,
            $visitId,
            $invNumber,
            $patient['id'],
            trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')),
            $patient['mrn'] ?? '',
            date('Y-m-d'),
            date('Y-m-d', strtotime('+30 days')),
            $totalAmount,
            $insuranceCovered,
            $patientOwed,
            json_encode(array_column($items, 'name')),
            $invoiceType,
            $transactionType,
            round($totalTax, 2),
            // Only the patient's share was handed over at the desk
            json_encode([['type' => $paymentType, 'amount' => $patientOwed]]),
        ]);

        $itemStmt = $pdo->prepare("
            INSERT INTO invoice_items (id, tenant_id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $itemStmt->execute([
                $item['id'], $tenantId, $item['invoice_id'], $item['name'], $item['gtin'],
                $item['unit_price'], $item['quantity'], $item['total_price'],
                $item['tax_label'], $item['tax_rate'], $item['tax_amount'],
            ]);
        }

        // ---- the insurance claim, raised from the same screen as the invoice
        $provider = InsuranceProviders::cleanProviderName((string) ($post['insurance_provider'] ?? ''));
        if ($provider === '__other__') {
            $provider = InsuranceProviders::cleanProviderName((string) ($post['insurance_provider_other'] ?? ''));
        }
        if ($provider === '') {
            $provider = trim((string) ($patient['insurance_provider'] ?? ''));
        }
        $policy = trim((string) ($post['insurance_policy_number'] ?? ''));
        if ($policy === '') {
            $policy = trim((string) ($patient['insurance_policy_number'] ?? ''));
        }

        $claimAction = 'skipped';
        if ($insuranceCovered > 0) {
            try {
                $insurance = $container->get(InsuranceService::class);
                $result = $insurance->syncFromInvoice(
                    $invoiceId,
                    (string) $patient['id'],
                    $insuranceCovered,
                    $provider,
                    $policy,
                    (string) ($post['claim_signature'] ?? '') ?: null,
                    (string) ($post['claim_signed_by'] ?? '') ?: trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')),
                    $tenantId
                );
                $claimAction = $result['action'];
            } catch (\Throwable $e) {
                error_log('[encounter invoice] claim: ' . $e->getMessage());
                $claimAction = 'failed';
            }
        }

        // Fill in the patient's insurer if the clinic learned it at the desk
        if ($provider !== '' && trim((string) ($patient['insurance_provider'] ?? '')) === '' && !empty($post['save_insurance_to_patient'])) {
            try {
                $pdo->prepare("UPDATE patients SET insurance_provider = ?, insurance_policy_number = COALESCE(NULLIF(?, ''), insurance_policy_number) WHERE id = ? AND tenant_id = ?")
                    ->execute([$provider, $policy, $patient['id'], $tenantId]);
            } catch (\Throwable $e) {
                error_log('[encounter invoice] patient insurer: ' . $e->getMessage());
            }
        }

        return [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invNumber,
            'total' => $totalAmount,
            'insurance' => $insuranceCovered,
            'patient_owed' => $patientOwed,
            'claim_action' => $claimAction,
            'provider' => $provider,
        ];
    }
}
