<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Repositories\PatientRepository;

class ClinicalAlertService {
    private PatientRepository $patientRepo;

    public function __construct(PDO $db) {
        $this->patientRepo = new PatientRepository($db);
    }

    /**
     * Check medication against patient's known allergies
     */
    public function checkMedicationAllergies(string $patientId, string $medicationName): array {
        $patient = $this->patientRepo->findById($patientId);
        if (!$patient) {
            return [];
        }

        $allergies = strtolower($patient['known_allergies'] ?? '');
        if ($allergies === '' || $allergies === 'none' || $allergies === 'none reported') {
            return [];
        }

        $med = strtolower(trim($medicationName));
        $alerts = [];

        // Basic allergen group mapping
        $allergenMap = [
            'penicillin' => ['amoxicillin', 'ampicillin', 'penicillin', 'augmentin', 'cloxacillin'],
            'sulfa' => ['bactrim', 'septra', 'sulfamethoxazole', 'sulfasalazine'],
            'aspirin' => ['aspirin', 'ibuprofen', 'naproxen', 'nsaid', 'ketorolac'],
            'codeine' => ['codeine', 'morphine', 'oxycodone', 'hydrocodone', 'tramadol']
        ];

        foreach ($allergenMap as $allergenGroup => $drugs) {
            if (str_contains($allergies, $allergenGroup)) {
                foreach ($drugs as $drug) {
                    if (str_contains($med, $drug)) {
                        $alerts[] = [
                            'type' => 'ALLERGY_WARNING',
                            'severity' => 'HIGH',
                            'message' => "ALLERGY WARNING: Patient has a documented '{$allergenGroup}' allergy which conflicts with prescribed '{$medicationName}'."
                        ];
                        break;
                    }
                }
            }
        }

        // Direct string match check
        if (empty($alerts)) {
            $allergyList = array_map('trim', explode(',', $allergies));
            foreach ($allergyList as $allergy) {
                if ($allergy !== '' && (str_contains($med, $allergy) || str_contains($allergy, $med))) {
                    $alerts[] = [
                        'type' => 'ALLERGY_WARNING',
                        'severity' => 'MEDIUM',
                        'message' => "ALLERGY WARNING: Prescribed '{$medicationName}' may match patient allergy '{$allergy}'."
                    ];
                }
            }
        }

        return $alerts;
    }
}
