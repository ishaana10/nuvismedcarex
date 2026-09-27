# Nuvis Medcare X - Industrial Multi-Tenant EHR & VMS Platform

**Current Version:** `v2.1.0-VMS3` (FRCS Fiji VMS Phase 3, Multi-Tenancy & Layered Architecture Release)
**Target Environment:** PHP 8.1+ (PDO / MySQL 8.0+)

---

## 🚀 Key Architecture & Feature Highlights

### 1. Multi-Tenancy & Request-Scoped Isolation
- **Mandatory Non-Nullable Tenant Schema:** All business entities (`patients`, `clinical_visits`, `invoices`, `inventory`, `inventory_logs`, `audit_logs`, `vms_transactions`) enforce `tenant_id` columns with foreign key constraints to the `tenants` table.
- **Request-Scoped `TenantContext` Resolver (`src/Shared/TenantContext.php`):** Resolves active tenant context with deterministic precedence:
  1. `HTTP_X_TENANT_ID` request header.
  2. Authenticated user session (`$_SESSION['tenant_id']`).
  3. Domain host subdomain parsing (e.g. `clinic1.yourdomain.com`). Ignores raw IP host requests.
  4. Default tenant fallback (`default-clinic`).
- **Multi-Clinic User Assignment & Switching (`user_tenants`):** Supports users assigned to multiple practice locations with real-time UI clinic switching (`TenantService::switchTenant()`).

### 2. Layered PSR-4 Industrial Architecture
Refactored into a clean, modern PHP PSR-4 domain-driven directory structure:
```text
src/
├── Domain/          # Entities & Value Objects (e.g. Money VO, Patient entity)
├── Infrastructure/  # Repositories (PatientRepository, InvoiceRepository, InventoryRepository, TenantRepository, AuditRepository)
├── Application/     # Use Cases & Application Services (TenantService, SecurityService)
├── Http/            # Middleware (AuthMiddleware, TenantContextMiddleware) & Handlers
├── Services/        # Domain Services (VMSService for FRCS Fiji VMS, MigrationRunner)
└── Shared/          # System Kernel, Database connection, & TenantContext
```

### 3. FRCS Fiji VMS Phase 3 Billing & Fiscalization Engine
- **SDC Fiscalization (`src/Services/VMSService.php`):** FRCS Fiji VAT Monitoring System Phase 3 compliance:
  - **Tax Labels:** Label A (15.00% VAT), Label E (Exempt 0%), Label F (Zero-rated 0%), Label P (0.25% Levy).
  - **Invoice Types:** Normal Sales, Advance Invoices, Proforma Invoices, Copy Invoices, Training Invoices, and Refund Transactions.
  - **Resiliency & Idempotency:** Support for fiscal retries, dead-letter queues, and status state machines.
  - **Fiscal Documentation:** Printable receipts and invoices (`print_invoice.php`, `print_receipt.php`, `print_prescription.php`) with SDC timestamp, verification QR codes, Buyer TIN, and itemized tax breakdowns.

### 4. Advanced FEFO Inventory & Billing Integration
- **FEFO Batch Selection:** First-Expired-First-Out stock deduction and batch tracking.
- **Auto Stock Reservation:** Invoices linked directly to inventory items automatically adjust stock and record immutable entries in `inventory_logs`.

### 5. Security, RBAC & Audit Trails
- **Password Policy:** Enforces password strength rules (minimum 8 characters, at least 1 uppercase letter, and at least 1 digit).
- **Fine-Grained RBAC:** Modular permissions (`can_manage_inventory`, `can_view_all_patients_in_clinic`, `can_fiscalize`, `can_manage_tenants`).
- **Immutable Audit Trail:** Comprehensive tracking of patient records, financial transactions, stock adjustments, and clinical encounter modifications.

---

## 🛠️ Setup & Installation

### Quick Setup
1. Configure database connection credentials in `config/config.php` (copied from `config/config.example.php`).
2. Run database migrations to provision the multi-tenant schema:
   ```bash
   php database/migrate.php
   ```
3. Seed default data and tenant records:
   ```bash
   php database/seed.php
   ```

---

## 🧪 Testing & Verification
Run the unit and repository integration test suite with PHPUnit:
```bash
vendor/bin/phpunit tests
```
*Status:* 37 tests, 122 assertions passing 100%.

---

## 📁 Key Directory Structure

- `src/Domain/` - Domain entities and Money value objects.
- `src/Infrastructure/` - Tenant-scoped repositories and DB persistence logic.
- `src/Shared/TenantContext.php` - Multi-tenant context resolver.
- `src/Services/VMSService.php` - FRCS Fiji VMS Phase 3 fiscalization service.
- `database/migrations/` - Versioned schema migrations.
- `print_invoice.php`, `print_prescription.php`, `print_medical_certificate.php` - Printable clinical and financial documents.
