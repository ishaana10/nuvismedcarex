# Nuvis Medcare X - Deployment & System Release Notes

**Current Version:** `v2.1.0-VMS3` (FRCS Fiji VMS Phase 3 & Inventory Module Release)
**Target Environment:** Native PHP 8.1+ PDO Architecture (A2 Hosting / MySQL)

---

## 🚀 Recent Release Updates & Feature Progress

### 1. FRCS Fiji VMS Phase 3 Billing & POS Integration
- **SDC Fiscalization Engine (`src/Services/VMSService.php`):** Implemented calculations for FRCS Fiji VAT Monitoring System Phase 3 compliance:
  - **Tax Labels:** Label A (15.00% VAT), Label E (Exempt 0%), Label F (Zero-rated 0%), Label P (0.25% Levy).
  - **Invoice & Transaction Types:** Normal Sales, Advance Invoices, Proforma Invoices, Copy Invoices, Training Invoices, and Refund Transactions.
  - **Multi-Payment Split:** Full breakdown support across Cash, Card, Mobile Pay, Insurance, and Check.
  - **Fiscal Receipts & Invoices (`print_invoice.php`, `print_receipt.php`):** Generated fiscalized receipt layouts containing SDC Time, SDC Invoice No, Buyer TIN, internal QR verification URL, and Tax Itemization.
  - **Invoice Cancellations:** Automated buyer TIN fallback to seller TIN per VMS Phase 3 rules (Section 10.2).
  - **Daily Z-Report Summaries:** Automated daily fiscal sales, refund totals, and tax breakdowns.

### 2. Enhanced Inventory Management Module (`inventory.php`)
- **Stock & Cost Tracking:** Full tracking for Selling Unit Price, Cost Price, Batch Numbers, Expiry Dates, and VMS Tax Classifications per item.
- **Restocking Workflow:** Added support for quick single-item restocks and detailed batch-based restocking modal forms (`actions/inventory_restock.php`).
- **Movement Audit Log (`inventory_logs` table):** Records all inventory adjustments, stock additions, price updates, and soft deletions with timestamp and practitioner credentials.
- **Soft Deletion & Role Safeguards:** Soft-delete mechanism (`is_active = 0`) preserving historical visit line items while hiding inactive stock items from active dropdowns (`actions/inventory_delete.php`).
- **Developer Settings:** Dynamic custom JSON fields setup in `admin.php` for custom stock attributes.

### 3. Account Security & User Self-Service
- **User Password Change:** Integrated "Change Password" modal in header navigation bar handled by `actions/change_password.php`.
- **CSRF & Autoloader Enhancements:** Centralized security helpers in `includes/security.php` (`getCsrfToken()`, `validateCsrfRequest()`) and standard PSR-4 autoloader fallback (`includes/autoloader.php`).

### 4. Application Versioning & Dual DB Engine
- **Versioning Metadata (`config/version.php`):** Defined system release constants (`APP_NAME`, `APP_VERSION`, `APP_RELEASE_DATE`, `APP_BUILD_NAME`) displayed in login pages, header bars, and footers.
- **MySQL Database Persistence:** Native PDO layer optimized for MySQL persistence with `ON DUPLICATE KEY UPDATE` dialect resolution.

---

## 🛠️ Quick Setup & Installation Guide

### Web Installer (Recommended)
1. Upload the application files to your web server (e.g., `public_html/` or a subdomain folder).
2. Open your browser and navigate to:
   `https://yourdomain.com/install.php`
3. Enter database credentials (MySQL setup) and clinic administrator profiles.
4. Click **Save Config & Install Database**.

### Manual Configuration
1. Copy `config/config.example.php` to `config/config.php`.
2. Configure database host, username, password, and database name.
3. Import schema:
   ```bash
   mysql -u user -p database_name < database/schema.sql
   ```
4. Run seed data script:
   ```bash
   php database/seed.php
   ```

---

## 🧪 Testing & Verification
Execute the PHPUnit unit test suite to verify VMS Phase 3 calculations and database repository functions:
```bash
vendor/bin/phpunit tests
```
*Status:* All 6 unit tests (24 assertions) passing 100%.

---

## 📁 Key File Structure

- `config/version.php` - Release version metadata.
- `src/Services/VMSService.php` - FRCS Fiji VMS Phase 3 core service class.
- `billing.php` & `actions/vms_*.php` - Billing ledger and SDC fiscalization actions.
- `inventory.php` & `actions/inventory_*.php` - Inventory stock management & audit logging.
- `includes/security.php` - CSRF protection, rate limiting, and session security helpers.
- `includes/autoloader.php` - PSR-4 ClinicFlow autoloader fallback.
- `print_invoice.php` & `print_receipt.php` - Printable fiscal invoice & receipt templates.
