<?php
/**
 * Database Initialization & Seed Script (Multi-Tenant Enabled)
 */

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

echo "Initializing database tables...\n";

executeAutoSchemaMigrations($pdo);

echo "Seeding default tenant data...\n";

// Clear existing tables
$tables = ['user_tenants', 'tenant_settings', 'doctors', 'patients', 'appointments', 'queue', 'vitals', 'soap_notes', 'prescriptions', 'past_visits', 'activities', 'invoices', 'invoice_items', 'vms_logs', 'inventory', 'inventory_logs', 'medical_certificates', 'audit_logs', 'tenants'];
foreach ($tables as $t) {
    $pdo->exec("DELETE FROM $t");
}

// Seed Primary Default Tenant
$defaultTenantId = 'default-clinic';
$tenantStmt = $pdo->prepare("INSERT INTO tenants (id, name, code, status, plan, address, phone, email, timezone, locale, currency, branding, vms_credentials, feature_flags, custom_fields, billing_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$tenantStmt->execute([
    $defaultTenantId,
    'Nuvis Medico Healthcare',
    'default-clinic',
    'active',
    'enterprise',
    'Level 2, Nuvis House, 12 Gordon Street, Suva',
    '+679 331 2233',
    'contact@nuvistechnologies.com.fj',
    'Pacific/Fiji',
    'en_FJ',
    'FJD',
    json_encode(['primary_color' => '#0f2b5c', 'logo_url' => 'assets/images/nuvis_medico_logo.png']),
    json_encode([
        'vms_enabled' => true,
        'seller_tin' => '502579006',
        'location' => 'Suva Central Clinic, 2 Woodstand Road, Suva',
        'pos_number' => 'CF-POS-V3/1.0'
    ]),
    json_encode(['vms_fiscalization' => true, 'inventory_module' => true, 'telehealth' => false]),
    json_encode(['tax_region' => 'Fiji Central']),
    json_encode(['payment_terms' => 'Net 30 Days'])
]);

// Default Developer & Doctors with Passwords (Password: "password")
$defaultPasswordHash = password_hash('password', PASSWORD_DEFAULT);

$sampleSignature = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='250' height='70' viewBox='0 0 250 70'><path d='M10,45 C30,10 60,60 90,25 C110,5 140,55 170,20 C190,5 220,40 240,30' fill='none' stroke='%230f2b5c' stroke-width='3' stroke-linecap='round'/><text x='15' y='62' font-family='cursive, sans-serif' font-size='14' fill='%231e3a8a'>Dr. Sereana Baleiwai, MD</text></svg>";
$sampleStamp = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'><circle cx='60' cy='60' r='54' fill='none' stroke='%231e3a8a' stroke-width='3' stroke-dasharray='4,2'/><circle cx='60' cy='60' r='48' fill='none' stroke='%231e3a8a' stroke-width='1.5'/><text x='60' y='42' font-family='sans-serif' font-size='9' font-weight='bold' fill='%231e3a8a' text-anchor='middle'>OFFICIAL CLINICAL STAMP</text><text x='60' y='62' font-family='sans-serif' font-size='10' font-weight='bold' fill='%230f2b5c' text-anchor='middle'>NUVIS MEDICO</text><text x='60' y='78' font-family='sans-serif' font-size='8' fill='%231e3a8a' text-anchor='middle'>LIC: PRC-0098412</text></svg>";

$doctors = [
    ['doc-1', $defaultTenantId, 'System Developer', 'Developer / IT Administrator', 'medico@nuvistechnologies.com.fj', $defaultPasswordHash, 'Developer', '#10B981', 'bg-emerald-500', 'assets/images/nuvis_medico_logo.png', 'PRC-DEV-001', 'PTR-DEV-001', $sampleSignature, $sampleStamp],
    ['doc-2', $defaultTenantId, 'Dr. Sereana Baleiwai', 'Internal Medicine', 'sbaleiwai@nuvismedcare.com.fj', $defaultPasswordHash, 'Doctor', '#10B981', 'bg-emerald-500', 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?auto=format&fit=crop&q=80&w=200', 'PRC-0098412', 'PTR-8842109', $sampleSignature, $sampleStamp],
    ['doc-3', $defaultTenantId, 'Dr. Rajesh Naidu', 'Cardiology & Family Practice', 'rnaidu@nuvismedcare.com.fj', $defaultPasswordHash, 'Doctor', '#F59E0B', 'bg-amber-500', 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?auto=format&fit=crop&q=80&w=200', 'PRC-0087123', 'PTR-7721092', $sampleSignature, $sampleStamp],
    ['doc-4', $defaultTenantId, 'Dr. Mere Tuisawau', 'Paediatrics', 'mtuisawau@nuvismedcare.com.fj', $defaultPasswordHash, 'Doctor', '#6366F1', 'bg-indigo-500', 'https://images.unsplash.com/photo-1594824813581-22e6900f68ff?auto=format&fit=crop&q=80&w=200', 'PRC-0076210', 'PTR-6610923', $sampleSignature, $sampleStamp],
    ['doc-5', $defaultTenantId, 'Dr. Anil Singh', 'General Practice', 'asingh@nuvismedcare.com.fj', $defaultPasswordHash, 'Doctor', '#3B82F6', 'bg-blue-500', 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?auto=format&fit=crop&q=80&w=200', 'PRC-0065109', 'PTR-5509812', $sampleSignature, $sampleStamp]
];
$stmt = $pdo->prepare("INSERT INTO doctors (id, tenant_id, name, specialty, email, password_hash, role, color, dot_color_class, avatar, prc_number, ptr_number, esignature, digital_stamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($doctors as $doc) {
    $stmt->execute($doc);
}

// User Tenants Mapping
$userTenantStmt = $pdo->prepare("INSERT INTO user_tenants (id, user_id, tenant_id, role, is_default) VALUES (?, ?, ?, ?, ?)");
foreach ($doctors as $idx => $doc) {
    $userTenantStmt->execute(['ut-' . ($idx + 1), $doc[0], $defaultTenantId, $doc[6], 1]);
}

// Default Tenant Settings
$defaultSettings = [
    'clinic_name' => 'Nuvis Medico Healthcare',
    'clinic_subtitle' => 'Integrated Primary & Specialist Healthcare Platform',
    'clinic_address' => 'Level 2, Nuvis House, 12 Gordon Street, Suva',
    'clinic_phone' => '+679 331 2233',
    'clinic_email' => 'contact@nuvistechnologies.com.fj',
    'clinic_dea' => 'FC9823019',
    'clinic_npi' => '1092830192',
    'dev_environment' => 'Production - A2 Hosting',
    'rx_header_title' => 'OFFICIAL MEDICAL PRESCRIPTION',
    'rx_disclaimer' => 'Notice: This prescription is valid for 30 days from date of issue unless specified otherwise.',
    'rx_footer_note' => 'Substitution Permitted unless DAW (Dispense As Written) is indicated.',
    'invoice_header_title' => 'MEDICAL SERVICES INVOICE',
    'invoice_tax_id' => '93-1029384',
    'invoice_payment_terms' => 'Net 30 Days. Please remit payment promptly.',
    'invoice_footer_note' => 'Thank you for choosing Nuvis Medico Healthcare for your care.',
    'receipt_header_title' => 'OFFICIAL PAYMENT RECEIPT',
    'receipt_thank_you_msg' => 'Thank you for your payment. Your account balance for this invoice is cleared.',
    'doc_prc_no' => 'PRC-0098412',
    'doc_ptr_no' => 'PTR-8842109',
    'vms_enabled' => '1',
    'vms_seller_tin' => '502579006',
    'vms_business_location' => 'Suva Central Clinic, 2 Woodstand Road, Suva',
    'vms_pos_number' => 'ASDF238/1.2',
    'vms_sdc_url' => 'https://tap.sandbox.vms.frcs.org.fj',
    'vms_tax_rate_a' => '15.00',
    'vms_tax_rate_e' => '0.00',
    'vms_tax_rate_f' => '0.00',
    'vms_tax_rate_p' => '0.25'
];
$stmt = $pdo->prepare("INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value) VALUES (?, ?, ?, ?)");
$sIdx = 1;
foreach ($defaultSettings as $key => $val) {
    $stmt->execute(['ts-' . ($sIdx++), $defaultTenantId, $key, $val]);
}

// Patients
$patients = [
    ['pat-1', $defaultTenantId, '#98234-A', 'Sereana', 'Vakacegu', '1985-04-12', 38, 'Female', '+679 992 4478', 'sereana.vakacegu@example.fj', '14 Waimanu Road, Suva', 'Josefa Vakacegu', 'Spouse', '+679 992 4479', 'FijiCare Insurance', 'FC-9842109', 'GRP-44120', 'Penicillin Allergy, Peanuts', 'O+', 'Mild Hypertension (controlled), Seasonal Rhinitis', 'Patient experiences urticaria and mild bronchospasm with beta-lactams.', 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&q=80&w=200', 'SV', '2023-01-15'],
    ['pat-2', $defaultTenantId, '#48291', 'Rajesh', 'Prasad', '1976-08-22', 47, 'Male', '+679 993 5560', 'rajesh.prasad@example.fj', 'Lot 8 Nasese Road, Suva', 'Anita Prasad', 'Spouse', '+679 993 5561', 'BSP Life Health', 'BSP-771928', 'GRP-99381', 'Sulfa Drugs', 'A+', 'Type 2 Diabetes', 'Monitors blood glucose daily.', null, 'RP', '2022-11-04'],
    ['pat-3', $defaultTenantId, '#55102', 'Litiana', 'Tuilagi', '1992-11-30', 31, 'Female', '+679 994 6671', 'litiana.tuilagi@example.fj', '22 Vomo Street, Lautoka', 'Apisai Tuilagi', 'Brother', '+679 994 6672', 'Sun Insurance', 'SUN-339182', 'GRP-10492', 'None reported', 'B+', 'None', 'Routine health maintenance.', 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&q=80&w=200', 'LT', '2023-05-18'],
    ['pat-4', $defaultTenantId, '#22941', 'Mohammed', 'Khan', '1965-03-14', 58, 'Male', '+679 995 7782', 'mohammed.khan@example.fj', '6 Queens Road, Nadi', 'Fatima Khan', 'Daughter', '+679 995 7783', 'Tower Insurance Fiji', 'TWR-904128', 'GRP-88192', 'Aspirin, NSAIDs', 'AB+', 'Asthma', 'Requires salbutamol rescue inhaler.', null, 'MK', '2021-09-12'],
    ['pat-5', $defaultTenantId, '#88210', 'Apisai', 'Rokotuibau', '1989-07-19', 34, 'Male', '+679 996 8893', 'apisai.rokotuibau@example.fj', '31 Kings Road, Nausori', 'Mereani Rokotuibau', 'Spouse', '+679 996 8894', 'FNPF Medical Scheme', 'FNPF-662810', 'GRP-33182', 'Latex', 'O-', 'None', 'No chronic issues.', null, 'AR', '2023-08-01'],
    ['pat-6', $defaultTenantId, '#10293', 'Shalini', 'Kumar', '1959-12-05', 64, 'Female', '+679 997 9904', 'shalini.kumar@example.fj', '4 Tavewa Avenue, Lautoka', 'Vijay Kumar', 'Son', '+679 997 9905', 'Dominion Insurance', 'DOM-1129384', 'GRP-10029', 'Codeine', 'A-', 'Osteoarthritis, Hyperlipidemia', 'Routine lipid panel review scheduled.', null, 'SK', '2020-04-10'],
    ['pat-7', $defaultTenantId, '884920', 'Mereani', 'Qoro', '1990-06-15', 33, 'Female', '+679 998 1125', 'mereani.qoro@example.fj', '9 Milverton Road, Raiwaqa, Suva', 'Inoke Qoro', 'Spouse', '+679 998 1126', 'FijiCare Insurance', 'FC-882910', 'GRP-55421', 'None', 'B-', 'Migraines', 'Follow-up for episodic migraine prevention.', 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&q=80&w=200', 'MQ', '2023-03-21'],
    ['pat-8', $defaultTenantId, '112934', 'Peter', 'Whippy', '1982-09-28', 41, 'Male', '+679 999 2236', 'peter.whippy@example.fj', '17 Beach Street, Savusavu', 'Elenoa Whippy', 'Sister', '+679 999 2237', 'BSP Life Health', 'BSP-112938', 'GRP-44120', 'Penicillin', 'O+', 'Hypertension', 'In Room 2 for follow up.', null, 'PW', '2022-02-14'],
    ['pat-9', $defaultTenantId, '440219', 'Anjali', 'Chand', '1974-02-10', 49, 'Female', '+679 991 3347', 'anjali.chand@example.fj', '25 Naviti Street, Lautoka', 'Sanjay Chand', 'Spouse', '+679 991 3348', 'Sun Insurance', 'SUN-440219', 'GRP-99381', 'Iodine contrast dye', 'AB-', 'Acute sinusitis, recurrent', 'Urgent care visit for facial pressure and fever.', null, 'AC', '2023-09-05'],
    ['pat-10', $defaultTenantId, '#66190', 'Ilisapeci', 'Naulu', '1987-10-11', 36, 'Female', '+679 992 4458', 'ilisapeci.naulu@example.fj', '3 Bau Street, Labasa', 'Semi Naulu', 'Spouse', '+679 992 4459', 'Tower Insurance Fiji', 'TWR-661902', 'GRP-88192', 'None', 'A+', 'None', 'Annual routine wellness checkup.', null, 'IN', '2023-06-11']
];
$stmt = $pdo->prepare("INSERT INTO patients (id, tenant_id, mrn, first_name, last_name, dob, age, gender, phone, email, address, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, insurance_provider, insurance_policy_number, insurance_group_number, known_allergies, blood_group, chronic_conditions, clinical_notes, avatar, initials, registration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($patients as $p) {
    $stmt->execute($p);
}

// Appointments
$appointments = [
    ['apt-1', $defaultTenantId, 'pat-2', 'Rajesh Prasad', '#48291', null, 'RP', 'doc-2', 'Dr. S. Baleiwai', '2023-10-24', '09:00 AM', null, '09:00 - 09:45', 'Follow-up', 'Arrived', 'Room 1', null, 0],
    ['apt-2', $defaultTenantId, 'pat-3', 'Litiana Tuilagi', '#55102', 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&q=80&w=200', 'LT', 'doc-3', 'Dr. R. Naidu', '2023-10-24', '09:30 AM', null, '09:30 - 10:15', 'Consultation', 'In Progress', 'Room 3', null, 0],
    ['apt-3', $defaultTenantId, 'pat-4', 'Mohammed Khan', '#22941', null, 'MK', 'doc-2', 'Dr. S. Baleiwai', '2023-10-24', '10:00 AM', null, '10:00 - 10:45', 'Urgent Care', 'Waiting', null, null, 1],
    ['apt-4', $defaultTenantId, 'pat-5', 'Apisai Rokotuibau', '#88210', null, 'AR', 'doc-5', 'Dr. A. Singh', '2023-10-24', '10:45 AM', null, '10:45 - 11:30', 'Routine Check', 'Scheduled', null, null, 0],
    ['apt-5', $defaultTenantId, 'pat-6', 'Shalini Kumar', '#10293', null, 'SK', 'doc-2', 'Dr. S. Baleiwai', '2023-10-24', '11:30 AM', null, '11:30 - 12:00', 'Lab Results', 'Scheduled', null, null, 0]
];
$stmt = $pdo->prepare("INSERT INTO appointments (id, tenant_id, patient_id, patient_name, patient_mrn, patient_avatar, patient_initials, doctor_id, doctor_name, appointment_date, time, end_time, time_slot, type, status, room, notes, is_urgent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($appointments as $apt) {
    $stmt->execute($apt);
}

// Queue
$queue = [
    ['q-1', $defaultTenantId, 'pat-7', 'Mereani Qoro', '884920', '8:30 AM', 'Dr. Naidu', 'Waiting', null, '08:22 AM'],
    ['q-2', $defaultTenantId, 'pat-8', 'Peter Whippy', '112934', '9:15 AM', 'Dr. Baleiwai', 'In Room', 'Room 2', '09:05 AM'],
    ['q-3', $defaultTenantId, 'pat-9', 'Anjali Chand', '440219', '10:00 AM', 'Dr. Baleiwai', 'Waiting', null, '09:48 AM'],
    ['q-4', $defaultTenantId, 'pat-2', 'Rajesh Prasad', '#48291', '10:30 AM', 'Dr. Baleiwai', 'Waiting', null, '10:15 AM']
];
$stmt = $pdo->prepare("INSERT INTO queue (id, tenant_id, patient_id, patient_name, mrn, time, doctor_name, status, room, check_in_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($queue as $q) {
    $stmt->execute($q);
}

// Vitals & SOAP
$stmt = $pdo->prepare("INSERT INTO vitals (id, tenant_id, patient_id, blood_pressure, heart_rate, temperature, weight, height, bmi, oxygen_sat) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['v-1', $defaultTenantId, 'pat-1', '120/80', 72, 98.6, 145, 66, 23.4, 99]);

$stmt = $pdo->prepare("INSERT INTO soap_notes (id, tenant_id, patient_id, subjective, objective, assessment_codes, plan) VALUES (?, ?, ?, ?, ?, ?, ?)");
$assessmentCodes = json_encode([['code' => 'J01.90', 'label' => 'Acute sinusitis, unspecified']]);
$stmt->execute([
    's-1',
    $defaultTenantId,
    'pat-1',
    'Patient reports 4-day history of worsening nasal congestion, facial pressure over maxillary sinuses, and purulent nasal discharge.',
    'Physical exam reveals erythema of bilateral nasal mucosa with mucopurulent drainage.',
    $assessmentCodes,
    'Advised rest and hydration. Prescribed Amoxicillin 500mg PO BID for 7 days.'
]);

// Prescriptions
$prescriptions = [
    ['rx-1', $defaultTenantId, 'pat-1', 'Amoxicillin', '500mg', 'BID (Twice a day)', '7 days', 'Take with food and full glass of water.']
];
$stmt = $pdo->prepare("INSERT INTO prescriptions (id, tenant_id, patient_id, medication_name, dosage, frequency, duration, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($prescriptions as $rx) {
    $stmt->execute($rx);
}

// Invoices
$invoices = [
    ['inv-1', $defaultTenantId, 'INV-2023-8821', 'pat-2', 'Rajesh Prasad', '#48291', '2023-10-24', '2023-11-24', 250.00, 'Pending', 200.00, 50.00, json_encode(['Follow-up Consultation']), 'Normal', 'Sale', '502579006', 'Suva Central Clinic, 2 Woodstand Road, Suva', 'Admin', null, null, 'ASDF238/1.2', '2023-10-24 09:15:00', null, null, 1, '7AF234D9-E377B30A-150493', '2023-10-24 09:15:02', '143027/150493NS', 'https://tap.sandbox.vms.frcs.org.fj/verify?id=7AF234D9-E377B30A-150493', 'MEYCIQDx812...SIG', 32.61, json_encode([['type' => 'Cash', 'amount' => 50.00]])],
    ['inv-2', $defaultTenantId, 'INV-2023-8819', 'pat-3', 'Litiana Tuilagi', '#55102', '2023-10-24', '2023-11-24', 320.00, 'Pending', 270.00, 50.00, json_encode(['Specialist Consultation']), 'Normal', 'Sale', '502579006', 'Suva Central Clinic, 2 Woodstand Road, Suva', 'Admin', null, null, 'ASDF238/1.2', '2023-10-24 09:45:00', null, null, 1, '7AF234D9-E377B30A-150494', '2023-10-24 09:45:02', '143028/150494NS', 'https://tap.sandbox.vms.frcs.org.fj/verify?id=7AF234D9-E377B30A-150494', 'MEYCIQDx813...SIG', 41.74, json_encode([['type' => 'Card', 'amount' => 50.00]])]
];
$stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, invoice_number, patient_id, patient_name, patient_mrn, service_date, due_date, amount, status, insurance_covered, patient_owed, services, invoice_type, transaction_type, seller_tin, business_location, cashier, buyer_tin, buyer_cost_center, pos_number, pos_time, ref_no, ref_time, is_fiscalized, sdc_invoice_no, sdc_time, invoice_counter, verification_url, digital_signature, total_tax, payment_methods) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($invoices as $inv) {
    $stmt->execute($inv);
}

// Sample Invoice Items
$items = [
    ['item-1', $defaultTenantId, 'inv-1', 'Follow-up Consultation', '10009812', 250.00, 1.00, 250.00, 'A', 15.00, 32.61],
    ['item-2', $defaultTenantId, 'inv-2', 'Specialist Consultation', '10009815', 320.00, 1.00, 320.00, 'A', 15.00, 41.74]
];
$stmt = $pdo->prepare("INSERT INTO invoice_items (id, tenant_id, invoice_id, name, gtin, unit_price, quantity, total_price, tax_label, tax_rate, tax_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($items as $item) {
    $stmt->execute($item);
}

// Inventory
$inventory = [
    ['inv-item-1', $defaultTenantId, 'Amoxicillin 500mg Capsules', 'MED-AMOX-500', 'Pharmaceuticals', 14, 50, 'Bottles (100ct)', 'Low Stock', '2023-09-15'],
    ['inv-item-2', $defaultTenantId, 'Sterile Syringes 5ml (Luer Lock)', 'SUP-SYR-005', 'Supplies', 25, 100, 'Boxes (100ct)', 'Low Stock', '2023-09-20']
];
$stmt = $pdo->prepare("INSERT INTO inventory (id, tenant_id, name, sku, category, current_stock, min_threshold, unit, status, last_restocked) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($inventory as $item) {
    $stmt->execute($item);
}

// Medical Certificates
$medCerts = [
    ['mc-1', $defaultTenantId, 'MC-20231024-001', 'pat-1', 'v-1', '2023-10-24', 'Acute Sinusitis (J01.90)', 'Fit for Work / School with Light Duty', '3 days medical leave recommended.', 'Rest and hydration advised. Avoid strenuous physical activity.', 'Dr. Sereana Baleiwai', 'PRC-0098412', 'PTR-8842109']
];
$stmt = $pdo->prepare("INSERT INTO medical_certificates (id, tenant_id, certificate_number, patient_id, visit_id, issue_date, diagnosis, fitness_status, fit_status_details, recommendations, doctor_name, prc_number, ptr_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($medCerts as $mc) {
    $stmt->execute($mc);
}

echo "Database successfully initialized and seeded with multi-tenancy support!\n";
