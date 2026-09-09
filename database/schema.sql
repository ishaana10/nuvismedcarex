-- ClinicFlow Unified Database Schema (Multi-Tenant Architecture)

CREATE TABLE IF NOT EXISTS tenants (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    plan VARCHAR(50) NOT NULL DEFAULT 'standard',
    address TEXT,
    phone VARCHAR(50),
    email VARCHAR(255),
    timezone VARCHAR(50) DEFAULT 'Pacific/Fiji',
    locale VARCHAR(10) DEFAULT 'en_FJ',
    currency VARCHAR(10) DEFAULT 'FJD',
    branding TEXT,
    vms_credentials TEXT,
    feature_flags TEXT,
    custom_fields TEXT,
    billing_info TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS doctors (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    specialty VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    password_hash VARCHAR(255),
    role VARCHAR(50) DEFAULT 'Doctor',
    color VARCHAR(20) DEFAULT '#10B981',
    dot_color_class VARCHAR(50) DEFAULT 'bg-emerald-500',
    avatar TEXT,
    prc_number VARCHAR(100),
    ptr_number VARCHAR(100),
    esignature TEXT,
    digital_stamp TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_tenants (
    id VARCHAR(50) PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    tenant_id VARCHAR(50) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'practitioner',
    permissions TEXT,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES doctors(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT uk_user_tenant UNIQUE (user_id, tenant_id)
);

CREATE TABLE IF NOT EXISTS tenant_settings (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT uk_tenant_setting UNIQUE (tenant_id, setting_key)
);

CREATE TABLE IF NOT EXISTS patients (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    mrn VARCHAR(50) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    age INT NOT NULL,
    gender VARCHAR(20) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(255),
    address TEXT,
    emergency_contact_name VARCHAR(255),
    emergency_contact_relationship VARCHAR(100),
    emergency_contact_phone VARCHAR(50),
    insurance_provider VARCHAR(255),
    insurance_policy_number VARCHAR(100),
    insurance_group_number VARCHAR(100),
    known_allergies TEXT,
    blood_group VARCHAR(10),
    chronic_conditions TEXT,
    clinical_notes TEXT,
    avatar TEXT,
    initials VARCHAR(10),
    registration_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT uk_tenant_mrn UNIQUE (tenant_id, mrn)
);

CREATE INDEX idx_patients_tenant ON patients(tenant_id);

CREATE TABLE IF NOT EXISTS appointments (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    patient_mrn VARCHAR(50) NOT NULL,
    patient_avatar TEXT,
    patient_initials VARCHAR(10),
    doctor_id VARCHAR(50) NOT NULL,
    doctor_name VARCHAR(255) NOT NULL,
    appointment_date DATE NOT NULL,
    time VARCHAR(50) NOT NULL,
    end_time VARCHAR(50),
    time_slot VARCHAR(100) NOT NULL,
    type VARCHAR(100) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Scheduled',
    room VARCHAR(50),
    notes TEXT,
    is_urgent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
);

CREATE INDEX idx_appointments_tenant ON appointments(tenant_id);
CREATE INDEX idx_appointments_date ON appointments(tenant_id, appointment_date);
CREATE INDEX idx_appointments_patient ON appointments(tenant_id, patient_id);
CREATE INDEX idx_appointments_doctor ON appointments(tenant_id, doctor_id);
CREATE INDEX idx_appointments_status ON appointments(tenant_id, status);

CREATE TABLE IF NOT EXISTS queue (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    mrn VARCHAR(50) NOT NULL,
    time VARCHAR(50) NOT NULL,
    doctor_name VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Waiting',
    room VARCHAR(50),
    check_in_time VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX idx_queue_tenant ON queue(tenant_id);
CREATE INDEX idx_queue_status ON queue(tenant_id, status);
CREATE INDEX idx_queue_patient ON queue(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS vitals (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    blood_pressure VARCHAR(50) DEFAULT '120/80',
    heart_rate INT DEFAULT 72,
    temperature DECIMAL(4,1) DEFAULT 98.6,
    weight INT DEFAULT 145,
    height INT DEFAULT 66,
    bmi DECIMAL(4,1) DEFAULT 23.4,
    oxygen_sat INT DEFAULT 99,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX idx_vitals_tenant_patient ON vitals(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS soap_notes (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    subjective TEXT,
    objective TEXT,
    assessment_codes TEXT,
    plan TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX idx_soap_tenant_patient ON soap_notes(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS prescriptions (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    visit_id VARCHAR(100),
    medication_name VARCHAR(255) NOT NULL,
    dosage VARCHAR(100) NOT NULL,
    frequency VARCHAR(100) NOT NULL,
    duration VARCHAR(100),
    instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX idx_prescriptions_tenant_patient ON prescriptions(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS past_visits (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    visit_id VARCHAR(100),
    visit_date VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    summary TEXT,
    doctor_name VARCHAR(255) NOT NULL,
    vitals TEXT,
    prescriptions TEXT,
    soap_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE INDEX idx_past_visits_tenant_patient ON past_visits(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS activities (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    detail VARCHAR(255) NOT NULL,
    timestamp VARCHAR(50) NOT NULL,
    badge_type VARCHAR(20) DEFAULT 'blue',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50),
    patient_name VARCHAR(255) NOT NULL,
    patient_mrn VARCHAR(50) NOT NULL,
    service_date DATE NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    insurance_covered DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    patient_owed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    services TEXT,

    -- VMS Phase 3 Specification Fields
    invoice_type VARCHAR(20) NOT NULL DEFAULT 'Normal',
    transaction_type VARCHAR(20) NOT NULL DEFAULT 'Sale',
    seller_tin VARCHAR(50) DEFAULT '502579006',
    business_location VARCHAR(255) DEFAULT 'Suva Central Clinic, 2 Woodstand Road, Suva',
    cashier VARCHAR(100) DEFAULT 'Admin',
    buyer_tin VARCHAR(50),
    buyer_cost_center VARCHAR(100),
    pos_number VARCHAR(50) DEFAULT 'CF-POS-V3/1.0',
    pos_time VARCHAR(50),
    ref_no VARCHAR(100),
    ref_time VARCHAR(50),

    -- SDC Fiscalization Response Data
    is_fiscalized TINYINT(1) DEFAULT 0,
    sdc_invoice_no VARCHAR(100),
    sdc_time VARCHAR(50),
    invoice_counter VARCHAR(100),
    verification_url TEXT,
    digital_signature TEXT,
    total_tax DECIMAL(10,2) DEFAULT 0.00,
    payment_methods TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT uk_tenant_invoice_num UNIQUE (tenant_id, invoice_number)
);

CREATE INDEX idx_invoices_tenant_mrn ON invoices(tenant_id, patient_mrn);
CREATE INDEX idx_invoices_tenant_status ON invoices(tenant_id, status);
CREATE INDEX idx_invoices_tenant_fiscalized ON invoices(tenant_id, is_fiscalized);
CREATE INDEX idx_invoices_tenant_type ON invoices(tenant_id, invoice_type, transaction_type);

CREATE TABLE IF NOT EXISTS invoice_items (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    invoice_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    gtin VARCHAR(50),
    unit_price DECIMAL(10,2) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    total_price DECIMAL(10,2) NOT NULL,
    tax_label VARCHAR(10) NOT NULL DEFAULT 'A',
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);

CREATE INDEX idx_invoice_items_tenant_inv ON invoice_items(tenant_id, invoice_id);

CREATE TABLE IF NOT EXISTS vms_logs (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    invoice_id VARCHAR(50),
    event_type VARCHAR(50) NOT NULL,
    request_payload TEXT,
    response_payload TEXT,
    status_code INT DEFAULT 200,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX idx_vms_logs_tenant_inv ON vms_logs(tenant_id, invoice_id);

CREATE TABLE IF NOT EXISTS inventory (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    category VARCHAR(100) NOT NULL,
    current_stock INT NOT NULL DEFAULT 0,
    min_threshold INT NOT NULL DEFAULT 10,
    unit VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'In Stock',
    last_restocked DATE NOT NULL,
    cost_price DECIMAL(10,2) DEFAULT 0.00,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    batch_number VARCHAR(100),
    expiry_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    vms_tax_code VARCHAR(10) DEFAULT 'A',
    custom_fields TEXT,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT uk_tenant_sku UNIQUE (tenant_id, sku)
);

CREATE INDEX idx_inventory_tenant_sku ON inventory(tenant_id, sku);
CREATE INDEX idx_inventory_tenant_active ON inventory(tenant_id, is_active);

CREATE TABLE IF NOT EXISTS inventory_logs (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    inventory_id VARCHAR(50) NOT NULL,
    change_amount INT NOT NULL,
    previous_stock INT NOT NULL,
    new_stock INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    supplier VARCHAR(255),
    unit_cost DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_by VARCHAR(255) DEFAULT 'System',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
);

CREATE INDEX idx_inventory_logs_tenant_item ON inventory_logs(tenant_id, inventory_id);

CREATE TABLE IF NOT EXISTS medical_certificates (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    certificate_number VARCHAR(50) NOT NULL,
    patient_id VARCHAR(50) NOT NULL,
    visit_id VARCHAR(50),
    issue_date DATE NOT NULL,
    diagnosis TEXT NOT NULL,
    fitness_status VARCHAR(100) NOT NULL,
    fit_status_details TEXT,
    recommendations TEXT,
    doctor_name VARCHAR(255) NOT NULL,
    prc_number VARCHAR(100),
    ptr_number VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT uk_tenant_cert_num UNIQUE (tenant_id, certificate_number)
);

CREATE INDEX idx_medcert_tenant_patient ON medical_certificates(tenant_id, patient_id);

CREATE TABLE IF NOT EXISTS audit_logs (
    id VARCHAR(50) PRIMARY KEY,
    tenant_id VARCHAR(50) NOT NULL,
    user_id VARCHAR(50),
    user_name VARCHAR(100),
    user_role VARCHAR(50),
    action VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX idx_audit_logs_tenant ON audit_logs(tenant_id, created_at);
