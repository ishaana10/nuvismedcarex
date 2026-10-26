# Nuvis Medcare X - Industrial Multi-Tenant EHR & VMS Platform

**Current Version:** `v2.1.31` (Build 31)
**Target Environment:** PHP 8.1+ (PDO / MySQL 8.0+) / Redis 6.0+ / Composer / Tailwind CSS

---

## 📋 Overview

**Nuvis Medcare X** is an enterprise-grade, multi-tenant Electronic Health Records (EHR) and VAT Monitoring System (VMS) platform. Built with a modular, layered PSR-4 domain-driven architecture, it delivers end-to-end clinical workflow management, FRCS Fiji Phase 3 fiscal billing, FEFO inventory tracking, multi-tenant isolation, real-time notifications, cloud document integrations, and extensive security compliance.

---

## 🚀 Core Features & Architectural Capabilities

### 1. Multi-Tenancy & Request-Scoped Isolation
- **Non-Nullable Schema Scoping:** All core database entities (`patients`, `appointments`, `clinical_visits`, `vitals`, `invoices`, `inventory`, `inventory_logs`, `lab_orders`, `uploaded_files`, `audit_logs`, `vms_transactions`, `tenant_settings`) strictly include `tenant_id` foreign keys to enforce tenant boundary isolation.
- **Request-Scoped Context Resolver (`src/Shared/TenantContext.php`):** Deterministically resolves active tenant context via:
  1. `HTTP_X_TENANT_ID` request header (API requests).
  2. Authenticated user session (`$_SESSION['tenant_id']`).
  3. Host subdomain extraction (e.g., `clinic1.domain.com`).
  4. Fallback default tenant context (`default-clinic`).
- **Multi-Clinic User Assignment & Switching (`user_tenants`):** Doctors and staff assigned to multiple practice locations can seamlessly switch active clinic views in real-time.

### 2. PSR-4 Domain-Driven Layered Architecture
Refactored into clean separation of concerns following PSR-4 standard:
```text
src/
├── Domain/          # Domain Entities, Value Objects (Money VO, Patient Entity)
├── Infrastructure/  # Repositories (PatientRepository, InvoiceRepository, InventoryRepository, AuditRepository)
├── Application/     # Use Cases & Application Services (TenantService, RbacService)
├── Http/            # Middleware (AuthMiddleware, TenantContextMiddleware) & Route Handlers
├── Services/        # Domain Services (VMSService, EncounterService, FileUploadService, EmailService, WebhookService)
└── Shared/          # System Kernel, Container DI, Database PDO helper, & TenantContext
```

### 3. FRCS Fiji VMS Phase 3 Fiscalization Engine
- **SDC Integration & mTLS Compliance (`src/Services/VMSService.php`):** Certified integration with Fiji Revenue and Customs Service (FRCS) SDC API v3:
  - **Tax Labels:** Label A (15% VAT), Label E (Exempt 0%), Label F (Zero-rated 0%), Label P (0.25% Levy).
  - **Invoice Types:** Normal Sales, Advance Invoices, Proforma Invoices, Copy Invoices, Training Invoices, and Refund Transactions.
  - **Digital Certificates:** Supports PKCS#12 (`.pfx` / `.p12`) and X.509 client certificates with OpenSSL passphrase encryption for mTLS authentication.
  - **Fiscal Deliverables:** QR code verification generation, SDC counters, Buyer TIN validation, Z-Report / Daily Fiscal Summaries (`print_zreport.php`), and fiscalized printouts (`print_invoice.php`, `print_receipt.php`).

### 4. FEFO Inventory & Point of Sale (POS) Invoicing
- **First-Expired-First-Out Batch Management:** Tracks inventory items by lot/batch numbers and expiration dates, automatically reserving and deducting stock based on FEFO hierarchy.
- **Live Calculation Engine:** Invoicing modals compute pre-tax subtotals, configurable VAT/tax rates, gross totals, and net patient balance in real-time. Automatically records immutable batch stock entries in `inventory_logs`.

### 5. Electronic Health Records (EHR) & Clinical Encounters
- **Comprehensive Encounter Lifecycle (`clinical_visit.php` / `EncounterService.php`):** Manages appointment launch (`In Progress`), clinical note taking, ICD-10 diagnostic coding, prescriptions, and encounter finalization (`Completed`).
- **Extended Vital Signs:** Blood Pressure, Heart Rate, Heart Rhythm (`heart_rhythm`), Body Temperature, Weight, Height, Respiratory Rate (`respiratory_rate`), Oxygen Saturation (`oxygen_sat`), and Blood Sugar (`sugar`).
- **Digital Signatures & Stamps:** Doctors can upload e-signatures and digital stamps for automated rendering on printed prescriptions and medical certificates (`print_prescription.php`, `print_medical_certificate.php`).

### 6. Lab Order Management & Document File Center
- **Laboratory Workflow (`LabOrderService.php` / `actions/lab_order_save.php`):** Tracks requisition statuses, test item checklists, clinical notes, text results, and abnormal flags.
- **Document Attachments & Storage:** Supports uploading PDF reports, diagnostic images, and clinical files to local storage or cloud storage (Google Drive / OneDrive) via `FileUploadService`.

### 7. Real-Time SSE Notifications & Redis Caching
- **Server-Sent Events (SSE) Stream (`api/notifications/stream.php`):** Pushes real-time unread alerts for newly registered patients and booked appointments directly to the navigation header without blocking session state (`session_write_close()`).
- **NuCache Redis Adapter (`core/Cache.php`):** Native `phpredis` extension integration with Unix socket (`/home/.../.redis/redis.sock`) and TCP support, key prefixing (`nuvis:`), TTL caching, and graceful fallback when Redis is offline.
- **Redis Session Handler (`core/RedisSessionHandler.php`):** Fast, scalable session persistence via `SessionBootstrap.php`.

### 8. Email Dispatcher & Security RBAC
- **Tenant-Scoped SMTP (`EmailService.php`):** Custom SMTP server configuration per tenant with STARTTLS/SSL, fallback to PHP `mail()`, live connection diagnostics (`actions/test_email.php`), and audit logging (`email_logs`).
- **Role-Based Access Control (RBAC):** Granular user permissions (`can_manage_inventory`, `can_view_all_patients_in_clinic`, `can_fiscalize`, `can_manage_tenants`).
- **Two-Factor Authentication (MFA / OTP):** Supports One-Time Passwords via email for password resets and login verification.
- **Client & Server JS/PHP Diagnostics:** Automatic capture and persistence of PHP exceptions and JavaScript runtime errors (`window.onerror`) into `system_error_logs`.

### 9. External Integrations & Webhooks
- **REST API Keys & HMAC Webhook Subscriptions (`WebhookService.php`):** Dispatches real-time HMAC-signed webhooks for clinical events (e.g. `PATIENT_REGISTERED`, `APPOINTMENT_BOOKED`).
- **Tenant Subscription & ERP Billing Integration:** Tenant management supporting pricing plans, billing cycles (Monthly/Annual), renewal dates, and Nuvis ERP Client ID sync (`nuvis_erp_client_id`).

---

## 🛠️ Installation & Environment Setup

### Prerequisites
- **PHP:** 8.1 or higher (Extensions required: `pdo_mysql`, `openssl`, `mbstring`, `json`, `gd`, optional `redis`)
- **Database:** MySQL 8.0+ or MariaDB 10.5+
- **Composer:** Dependency manager for PHP

### Setup Instructions
1. **Clone repository and install Composer dependencies:**
   ```bash
   git clone https://github.com/your-org/nuvis-medcare-x.git
   cd nuvis-medcare-x
   composer install
   ```

2. **Database Configuration:**
   Copy example configuration and update database connection credentials:
   ```bash
   cp config/config.example.php config/config.php
   ```

3. **Database Migration & Seeding:**
   Run schema migrations and initialize default tenant records:
   ```bash
   php database/migrate.php
   php database/seed.php
   ```

4. **Directory Permissions:**
   Ensure read/write permissions for upload and cache directories:
   ```bash
   chmod -R 775 uploads/
   ```

---

## 🧪 Testing & Quality Assurance

Run the automated PHPUnit test suite covering Domain Entities, Repositories, Services, VMS Fiscalization, Lab Orders, File Storage, and Security:

```bash
./vendor/bin/phpunit tests
```

*Test Coverage Highlights:* 87 tests, 336 assertions passing 100%.

---

## 📜 Version History & Release Notes

### **UI refresh** - *October 2026*
- **New app shell:** collapsible navy sidebar with grouped navigation and live counts (patients waiting, appointments left today), a slimmer top bar, profile menu holding the clinician/clinic switchers, skip-to-content link.
- **Light and dark themes:** every colour now resolves to a CSS variable (`includes/theme_head.php`, `assets/css/custom.css`), so existing pages pick up dark mode without per-page edits. Follows the OS setting until the user chooses.
- **Command palette (Ctrl/⌘+K or `/`):** live patient search by name, MRN, phone or email (`actions/quick_search.php`), upcoming appointments, page jumps and quick actions. Allergies show in results.
- **Keyboard shortcuts** (`?` for the list): `G` then `D/P/A/E/B/I` to navigate, `N` new patient, `Shift+D` theme, `[` sidebar.
- **Dashboard:** "Clinic flow" board (waiting → with a clinician → seen today) with room assignment on call-in and send-back; today's schedule now shows only today's appointments sorted by real time, with "Coming up" below; "At a glance" adds expiring stock and new patients this month.
- **Patients:** filters (with allergies, self pay, new this month), sort options, search-as-you-type across all pages, full-name search, clearer allergy flags.
- **Sign-in and password reset** redesigned: show-password, Caps Lock warning, remember email, inline validation, step indicator and one-time-code input.
- **Global behaviour:** non-blocking toasts replace `alert()`, styled confirm dialogs (`data-nx-confirm`), Esc/backdrop closes modals, double-submit protection on forms, notifications keep an unread count across page loads.
- **Fixes:** dashboard counted all appointments as "today"; times like "1:00 PM" sorted before "9:00 AM"; app used server time instead of clinic time (now `Pacific/Fiji` by default, `timezone` in config or `APP_TIMEZONE`); queue actions had no CSRF check or tenant scoping and always used "Room 2"; notification text was injected as HTML; CSP blocked the Google Drive picker scripts on Documents; undefined colour classes (`primary-container`, `shadow-xs`, …) rendered unstyled.

### **v2.1.31** (Build 31) - *October 2026*
- **Refactoring & UI Cleanliness:** Externalized inline upload JavaScript in `uploads.php` to dedicated `assets/js/uploads.js` with module initialization.
- **Pagination Componentization:** Modularized `includes/pagination.php` into single-responsibility helper functions (`getPaginationParams`, `calculatePaginationStats`, `buildPaginationUrl`, `renderPaginationSummary`, `renderPaginationControls`) with unit tests in `tests/PaginationTest.php`.
- **System Stability:** Ensured zero regressions across all 87 test suites.

### **v2.1.30** (Build 30) - *October 2026*
- **Tenant Subscription Management:** Added pricing plans, billing cycles (Monthly/Annual), renewal dates, currency preferences, and Nuvis ERP Client ID (`nuvis_erp_client_id`) integration fields to `tenants` table and `#tenantModal`.
- **UI Nesting Fixes:** Fixed HTML layout container structure in `developer.php` for seamless child panel tab switching.

### **v2.1.25 – v2.1.29** (Builds 25 - 29) - *October 2026*
- **Email Dispatching & Audit Resiliency:** Upgraded `EmailService` and `actions/test_email.php` with automatic `email_logs` table fallback creation and tenant context resolution.
- **Cloud Storage & Google Drive Improvements:** Enhanced OAuth refresh token and service account JSON key normalization for Google Drive file uploads.

### **v2.1.20 – v2.1.24** (Builds 20 - 24) - *October 2026*
- **Redis Diagnostics Workspace:** Added dedicated Redis Cache & Session diagnostics tab in `developer.php` with live latency benchmarking, flush cache tools, and socket daemon status toggles.
- **Session SessionBootstrap Standardization:** Enforced `SessionBootstrap::init()` across all action scripts and entry points, registering `NuRedisSessionHandler` with graceful file fallback.

### **v2.1.15 – v2.1.19** (Builds 15 - 19) - *October 2026*
- **FRCS SDC Accreditation & Certs:** Integrated PKCS#12 / X.509 client certificate management (`uploads/vms/`) with OpenSSL passphrase validation for mTLS SDC API communication.
- **Extended Vitals & Encounter Finalization:** Added `heart_rhythm`, `respiratory_rate`, `oxygen_sat`, and `sugar` fields to clinical encounter views and vitals schema.

### **v2.1.10 – v2.1.14** (Builds 10 - 14) - *October 2026*
- **Real-time SSE Notification Header:** Implemented unread counter badge and background SSE stream (`api/notifications/stream.php`) with non-blocking session release.
- **Lab Order Document Attachments:** Added support for file/PDF result uploads attached to lab orders with direct download links in patient chart history.

### **v2.1.1 – v2.1.9** (Builds 1 - 9) - *October 2026*
- **Layered PSR-4 Refactoring:** Migrated monolith services into `src/Domain`, `src/Infrastructure`, `src/Services`, and `src/Shared`.
- **Dynamic Versioning Engine:** Built `AppVersion` helper reading `version.json` and fallback `.git/HEAD` parsing for automatic cache-busting and build tracking.
- **Multi-Tenant Foundation:** Established `TenantContext` resolution, strict DB multi-tenancy migrations, and fine-grained RBAC permissions.

---

## 📁 Key Directory Structure

```text
├── actions/                  # Endpoint action handlers for AJAX / Form submissions
├── api/                      # REST API & SSE notification streaming endpoints
├── assets/                   # Static assets (CSS, JS, Images, Icons)
│   ├── css/custom.css        # Centralized design system tokens & FOUC prevention styles
│   └── js/uploads.js         # Modular file upload & Google Drive integration JS
├── config/                   # Database & application configuration
├── core/                     # AppVersion, Cache (NuCache), SessionBootstrap, Redis Session Handler
├── database/                 # Migration runner & schema files
├── includes/                 # Common UI components (Header, Sidebar, Footer, Pagination)
├── src/                      # PSR-4 Domain-Driven Application Code
│   ├── Domain/               # Entities and Value Objects
│   ├── Infrastructure/       # Tenant-scoped Repositories
│   ├── Services/             # Domain & Business Services
│   └── Shared/               # Container DI & TenantContext
├── tests/                    # Automated PHPUnit test suite
├── print_invoice.php         # Commercial printable invoice view
├── print_prescription.php    # Printable doctor prescription with digital e-signatures
├── print_zreport.php         # Printable Daily Fiscal Z-Report
└── version.json              # Version release metadata
```

---

## 🔒 Security & Compliance

- **SQL Injection Prevention:** 100% prepared PDO statements across all repositories and action handlers.
- **XSS Protection:** Output sanitization via `htmlspecialchars()` across templates.
- **CSRF Defense:** Strict CSRF token generation and validation on state-modifying requests.
- **Multi-Tenant Boundary Guard:** Enforced tenant context isolation prevents cross-tenant data leaks.
