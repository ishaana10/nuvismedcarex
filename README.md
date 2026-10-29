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

### **Insurance reports: the member's signature, and a copy shaped for each provider** - *October 2026*
- **The signature itself now appears on the report.** The "Signed by member" column printed only a name; it is now **Member signature** and draws the signature the patient gave, with who signed and when beneath it, on screen and on the printed copy. Two companion columns were added for reports that want the facts without the picture: **Signed by (name)** and **Date signed**. A CSV cannot carry a drawing, so there the column reads "Signed by <name> on <date>".
- **Fixed: a real signature was all but invisible.** A signature is dark ink on a transparent background, and the tile behind it inherited the page colour, so in dark mode the ink sat on a dark panel and could barely be seen. The tile is now white whatever the theme — which is also what it must be when the page is printed.
- **Send a provider only its own claim list.** Each layout now says what a printed or downloaded copy contains: the full report — summary figures, the ageing table, the by-provider breakdown and anything billed without a claim — or **only the claim list in that layout**. Both the PDF and the CSV follow it, so a provider gets a sheet with nothing on it but the claims being submitted, under that provider's own heading, column order and reference wording.
- **Portrait or landscape**, saved per layout, so a provider wanting a dozen columns gets a page wide enough to hold them. It sets the real page size, so the browser's "Save as PDF" produces a landscape file without anyone changing the print dialogue.

### **Documents on a cloud drive** - *October 2026*
- **Developer → Document storage** is where the drive is chosen, and it is chosen once for the whole installation. Google Drive, OneDrive, Dropbox and WebDAV (Nextcloud, ownCloud and anything else that speaks it) are supported, alongside keeping documents on this server. Whatever is set here is what every upload in the application uses — the document centre, a scan attached to a lab result, anything else that takes a file — so there is no per-upload or per-clinic choice to get wrong.
- **Credentials are a developer's business.** Client IDs, secrets and refresh tokens live in `config/config.cloud.php`, written 0600 and kept out of git; nothing secret is held in the database. Each drive has its own card with the redirect address to register, a Connect button that completes the OAuth sign-in, Disconnect, and a Test that signs in, uploads a small file, reads it back and removes it again — reporting each step, so a test only passes when a round trip really worked.
- **Where files land is readable**: a folder you name, then a sub-folder per clinic and, if you want one, per patient, then year and month. The panel shows the exact path a file will take before you save.
- **Both copies, or only the drive.** Keeping a copy on this server means a document still opens when the drive is unreachable; choosing drive-only removes the local copy once it has safely arrived. Either way opening a document works from wherever it actually is, and deleting one removes it from the drive as well, saying so if the drive refuses.
- **Nothing is lost when a drive is down.** An upload that cannot be copied is still saved here and marked, the document centre shows how many are waiting or refused, and Retry and Backfill in the developer panel copy them up afterwards — Backfill also brings documents uploaded before a drive was ever configured.
- **Fixed: uploads were being discarded.** The upload box held three file inputs with the same name, one per provider tab, hidden with CSS rather than removed; PHP keeps the last one, which was always the empty one, so the chosen file was thrown away and an empty form was submitted. The box now has a single chooser and says where the file is going.
- **Fixed: CSRF was not actually enforced** on uploading, deleting a document, or saving storage settings — the check ran and its answer was ignored. Uploading and deleting also took a redirect target straight from the request, which would send someone to any address after the action. Both are fixed, and deleting now requires full access to Documents rather than any access at all.
- **Which drive is in use is shown only to a developer.** Clinic staff see that a document is stored and nothing about where: no storage column in the document centre, no drive filter, no drive counters, and the upload box simply says the document is kept with the clinic's records. The lab-result form no longer names the drive either, and the clinic-level "Dedicated Drive Storage Destination" dropdown in Administration is gone, since storage is now one setting for the installation.
- **Fixed: the server allowance ignored most of what was on disk.** Used space was counted only for files marked as living on this server, so a file copied to a drive stopped counting even though its local copy was still there. It now counts every file that has a copy on disk.

### **Patient consent forms** - *October 2026*
- **Administration → Consent forms**: a clinic designs its own forms, one per purpose. Each has its own wording, its own questions and its own declaration above the signature. Questions come in ten kinds — a section heading, a paragraph to read, a statement that must be ticked before signing, an optional tick, choose-one, a dropdown, short and long answers, a date, and initials beside a particular clause — and are dragged into the order the patient reads them. The panel beside the designer shows what the patient will see, updating as you type.
- **Three ready-made templates** to start from rather than an empty designer: general treatment and examination, a procedure or minor surgery (which requires a witness), and privacy and sharing of records. Each is a complete form, installed into the clinic and editable from there.
- **Signed on the encounter.** Active forms appear on every clinical encounter with a count of what is still to sign. The patient signs on screen with a finger or the mouse, signing as themselves or as a parent, guardian, spouse, next of kin or power of attorney; a form marked as needing a witness will not save without one. Consents signed at earlier visits are listed underneath, and each one prints as an A4 copy for the patient.
- **What was signed stays signed.** A record keeps its own copy of the wording and questions as they stood at the moment of signing, so editing a template afterwards never changes what someone already agreed to; the version is printed on the copy. A form that has been signed against is retired rather than deleted, and a consent can be withdrawn with a reason — the record is kept and marked, because having consented and later withdrawn is itself part of the history.
- **Fixed while building it:** choice lists defined by a template arrived as an array and were cast to a string, so every radio and dropdown in the sample forms collapsed into a single option called "Array". The parser now takes either a list or one choice per line.

### **One pending migration was breaking two features silently** - *October 2026*
- **Fixed: the insurance report showed no claims, and signatures would not save.** Both read columns added by the signature migration. On a database where that migration had not been applied the report's query failed, was logged, and returned nothing — an empty report rather than an error — while the signature update failed and the claim came back "Not signed" with the success message still shown. Neither feature now depends on the columns being there: the report asks for them only when they exist, and signing explains exactly what is missing and where to fix it instead of reporting success.
- **Fixed the reason they were missing.** `MigrationRunner` threw on the first migration that failed and the caller swallowed the exception, so one old failure stopped every later schema change from ever being applied, with nothing written anywhere. Failures are now recorded and logged, the remaining changes still run, and `commit`/`rollBack` are guarded because MySQL commits DDL implicitly and leaves no transaction to close.
- **Developer → Database** is new: what has been applied, what is still pending (with a count on the tab), which features are waiting on a schema change, and a button to apply them after a failure has been dealt with.
- **A View button on each insurance claim**, opening the claim on one screen — amount, patient and date of birth, policy number, invoice and total, date of service, notes and the signature with who signed and when — with links through to the invoice and the patient record.

### **Claims raised from the visit, with the member's signature** - *October 2026*
- **The insurance portion on "Finalize Encounter" now raises the claim.** Entering an amount reveals the provider, the policy number and a signature pad; finalising the visit writes the invoice and the matching claim together, and the confirmation says what was paid at the desk, what went to the insurer, and that the claim was raised. Previously that amount only ever reached the invoice, which is why the report could list insurance billed with no claim behind it.
- **The provider can be filled in at the desk.** If the patient has no insurer on file, the finalize screen offers the clinic's list and, with a tick, saves the choice back to the patient's record so it is not asked for again.
- **A claim can be signed by the patient**, drawn with a finger or the mouse. The signature is stored with the claim along with who signed and when (new columns, added by migration), shown in the claims list, replaceable or removable from the claim form, and available as a column on the insurance report. Only a real PNG, JPEG or WebP under 400 KB is accepted — the image is decoded and checked, not trusted by its header.
- **Fixed: the payment line recorded the whole bill.** When an insurer covered part of an invoice raised from an encounter, the payment method was still written for the full amount, overstating what came across the counter. It now records the patient's share.
- **Fixed: a second cross-clinic lookup.** Finalising a visit read the patient with `SELECT * FROM patients WHERE id = ?` and no clinic filter, so a posted id could reach another clinic's record. Both finalise paths are scoped now.
- **Fixed: a failure while raising the invoice was invisible.** It was written to the log and the visit reported as finalised with nothing else said. The message now reaches the person who closed the visit. (Found this way: a mistyped class name meant the claim silently failed on the first run.)
- Both finalise paths — an open visit and a past one — shared a copied-out invoice builder, which is how the same two faults lived in both. They now call one.

### **Fiji sample data, provider pickers and an honest Drive test** - *October 2026*
- **Fixed: the Google Drive test called a failure a success.** The Drive API refuses API keys outright — *"API keys are not supported by this API"* — and the old test treated that 401 as proof the endpoint was reachable, reporting a tick. It now signs in the way the server would for an unattended upload (refresh token, else a service account, with the JWT signed locally), asks Drive who it is connected as and how much space is left, and resolves the target folder by ID *or* by name, since people type the name. Where only the browser picker is configured it says so plainly as a warning rather than claiming a working connection, and a missing Drive API on the Google Cloud project is named as such.
- **OneDrive is tested too**, by asking Entra ID for a token and translating the AADSTS codes into plain words — expired secret, unknown application, wrong tenant.
- **Drive credentials are developer-only**, confirmed end to end: the Developer page, the save endpoint and the test endpoint all turn an administrator away, and the `developer` module is never delegated to a clinic role.
- **The insurance provider is now picked, not typed, when registering or editing a patient**, from the clinic's own list (Administration → Insurance), with "Self pay", an "Another insurer…" escape, and the current value kept if it predates the list. The same spelling therefore reaches the reports, which is what makes the per-provider layouts match.
- **Fixed: more unreadable fields in dark mode.** A control with no background utility of its own fell through to the browser's default — a mid grey under a dark colour scheme — with blue label text on top, measuring 1.2:1 against text that should be at least 4.5:1. Form controls now take the card surface, and blue text inside a control uses the shade that reads on it. Every focusable field on twenty screens, modals included, was measured before and after; none now falls below 4:1.
- **Sample data is Fijian**: patient and clinician names drawn from iTaukei, Indo-Fijian and part-European naming, `+679` phone numbers, addresses in Suva, Lautoka, Nadi, Nausori, Labasa and Savusavu, and local insurers (FijiCare, BSP Life Health, Sun Insurance, Tower, Dominion, FNPF Medical Scheme). The install defaults and the placeholder text on the registration and print screens follow suit.

### **Insurance providers, drive set-up and two interface fixes** - *October 2026*
- **Fixed: the unit field on a new inventory item offered nothing.** It was a text box tied to a suggestion list (`list="nx-units"`) that was never rendered anywhere in the page, so no units ever appeared. It is now a dropdown of the units configured in Administration → Inventory, and an item saved with a unit since removed from that list keeps it rather than silently switching to the first option — which the category dropdown had been doing too.
- **Fixed: in dark mode, text vanished while you typed.** Forms across the app turn white on focus (`focus:bg-white`), and the dark theme re-pointed `bg-white` and `hover:bg-white` at the card surface but never the focus variant, so pale text sat on a white field in 68 places. One rule fixes all of them.
- **Insurance providers** (Administration → Insurance): the insurers a clinic bills, each with what it calls the policy number, its usual share of the bill, a claims deadline, contact details and a note for whoever raises the claim. Names already typed into patient records or past claims are offered for adoption in one click, and each row shows how many claims are on file and how much is still outstanding.
- **The claim form picks rather than types**: the invoice decides the patient, the provider comes from the clinic's list with the patient's own insurer preselected, the policy number is filled from the patient record, and the amount is suggested from the insurer's share already on the invoice, or from that provider's usual percentage.
- **Fixed: a claim could be filed against the wrong patient.** The form carried a free-text "Patient ID" prefilled with the *first* invoice's patient, so unless that invoice was the one chosen, the claim was recorded against someone else. The patient is now read from the invoice, which is itself checked against the active clinic — neither was verified before, so a posted id could reach another clinic's records.
- **Document storage moved to Developer**: the "Cloud API Setup" button has gone from the document centre, and the Developer tab lists each drive — this server, OneDrive, Google Drive — with its own status, its own credentials and its own connection test. The Google Drive test had been sitting in the Pagination panel; OneDrive had no test at all and now asks Entra ID for a token, reporting an expired secret or an unknown tenant in plain words.
- **Fixed: cloud credentials were unprotected.** The save endpoint called `verifyCsrfToken()` and threw the result away, required no permission beyond being signed in, and redirected to any address posted with the form. It now enforces CSRF, requires developer access, and returns to the Developer tab. Secrets are no longer printed back into the page — a stored one shows as masked and is kept when the field is left blank.

### **Insurance reporting and a Z-Report that reconciles** - *October 2026*
- **Insurance report** (Billing → Insurance report): claims filtered by date range, by provider, by status and by a free-text search over patient, MRN, policy or invoice number, with dates read either as the date a claim was submitted or the date of service. Summary figures cover what was claimed, approved, rejected and what is still with the insurer, alongside the approval rate and the average time to a decision.
- **Ageing**: open claims grouped into 0–30, 31–60, 61–90 and over 90 days, so a clinic can see what to chase and how long it has been waiting.
- **A report shaped per provider**: each clinic keeps a default layout plus, optionally, one per insurer — which columns and in what order (drag to reorder), what that insurer calls the policy number, a claim-reference prefix, how the list is grouped, which statuses to include, a declaration for the foot of the page, and whether the summary, ageing, logo and signature line are printed. Layouts are stored per clinic, so one clinic's wording never reaches another's.
- **CSV and PDF**: a CSV download laid out to the selected provider's template, with money as plain numbers so a spreadsheet can total it, and a print-ready A4 page (header, totals, signature block, repeating table headers) that the browser saves as PDF.
- **Found while building it: insurance billed but never claimed.** The report now lists invoices in the period where part of the bill was put to an insurer but no claim was ever submitted — money the clinic has not asked for.
- **Fixed: claim references were not unique.** Claims were identified by the first eight characters of their id, but ids are UUIDv7, whose leading characters are a timestamp — every claim raised the same week shared them. References are now built from the month plus the random tail of the id, and the claims list and the report agree.
- **Fixed: the Z-Report counted insured invoices as cash.** With no itemised payments recorded, the full invoice total was added to the cash line even when an insurer covered most of it. Insurance is now a payment line of its own, and the payment breakdown is shown against turnover with any difference flagged.
- **Fixed: refunds were added to the takings instead of subtracted**, so a day with a refund never balanced. Refund invoices now count against the payment lines, as they do against turnover.
- **Z-Report, rebuilt**: invoice counts and the invoice-number range, turnover and the figure net of VAT, what patients paid against what was billed to insurers, how many invoices still have no FRCS signature, VAT split by tax label (A, E, F, P) from the invoice line items, and a CSV download. The printable version is laid out for A4 with the clinic header, logo, totals and a signature block.
- **Fixed: the period buttons never looked selected.** The active preset carried both `bg-white` and `bg-primary`, so the highlight depended on stylesheet order. Dates typed into the filter are validated too — a malformed one used to report on 1 January 1970.
- **Fixed: Billing read VMS settings from the installation-wide table**, so one clinic's tax numbers were shown to every clinic. It now reads the active clinic's settings.
- **Layering**: overlays, menus, the command palette and toasts now take their z-index from one documented scale rather than hand-picked numbers, dialogs opened on top of another are raised above it, and the page behind a dialog no longer scrolls away under it.

### **Cross-clinic leak, insurance on receipts, inventory alerts and reports** - *October 2026*
- **Fixed: the booking form listed patients from every clinic.** `appointment.php` fetched patients with no clinic filter. The picker is now scoped, and `AppointmentService` refuses a booking whose patient does not belong to the active clinic instead of quietly using the name posted with the form. The same leak was closed on the Documents upload picker and on the patient, prescription, certificate, lab-order, invoice and patient-update lookups, which read a record by id without checking which clinic it belonged to.
- **Fixed: `print_prescription.php` fell back to the first patient in the database** when the requested one was not found, so a bad link could print someone else's prescription. It now stops with "Patient file not found".
- **Fixed: receipts overstated what the patient paid.** With no itemised payments recorded the receipt used the full invoice total, ignoring the insurer's share. Receipts now show the total, the insurer's contribution as a deduction, the patient's share, and what the patient actually paid — and fall back to the patient's share, never the full amount. The insurer is named from the patient's record, and the line can be turned off in the receipt designer.
- **Inventory alerts**: a "Needs attention" view grouping expired stock, items at or below their reorder level, and items expiring inside the clinic's warning window, each with days remaining and a restock action. The count sits on the tab, and the dashboard and stock-list cards link straight to it.
- **Inventory reports**: value of stock at cost and at selling price, units received and used with the amount spent, a by-category breakdown, the items that moved most, and stock sitting unused over a chosen period — with a CSV download for a spreadsheet or an accountant.
- **Linked to the inventory set-up**: the extra fields a clinic defines in Administration → Inventory now show against each item in the stock list, and the reorder level and expiry window configured there drive the alerts, the reports and the dashboard figures.

### **Settings becomes personal preferences** - *October 2026*
- **Removed the duplicates.** Settings had its own copies of clinic branding, the SMTP set-up and the email log, all of which now live in Administration. Those tabs are gone; Settings links across instead, so each thing is configured in exactly one place.
- **Settings is now about the person signed in**, not the clinic, and the preferences are stored per account (`doctors.preferences`, added by migration) so they follow someone to any device and never affect a colleague.
  - **Your profile**: name, job title, email, photo — and your own signature and stamp, which previously only an administrator could set even though they print on the documents you sign.
  - **Appearance**: theme (follow device / light / dark), comfortable or compact spacing, rows per page, date and time format, which page opens after signing in, whether the sidebar starts collapsed, and whether hint text shows under fields.
  - **Alerts**: choose which live alerts you want and whether they make a sound.
  - **Sign-in & security**: change password, see the current session, and links to staff and roles for those who manage them.
  - **Clinic plan**: the read-only subscription summary, kept as the one thing here that is clinic-level.
- Preferences actually take effect: the theme and spacing are applied before first paint from the saved value rather than browser storage, rows per page feeds the pagination used by every list, the chosen page opens after sign-in, and the alert choices filter the live notifications.
- **Fix:** "Reset to defaults" was the first submit button in the preferences form, so pressing Enter in any field reset everything instead of saving. It now submits its own form.

### **Per-clinic email, live notifications and clinic switching** - *October 2026*
- **Each clinic sends from its own mailbox.** Administration → Email now has one-click set-up for Gmail/Google Workspace, Outlook/Microsoft 365, Yahoo and Zoho (server, port and encryption filled in, with the app-password guidance each provider requires), plus any other provider. Settings are stored per clinic, so receipts, invoices and prescriptions reach patients from the clinic's own address wherever the system is hosted. A "send yourself a test" button reports the real reason when something is wrong — wrong app password, blocked port, server refusing the sender.
- **Mail sending rewritten** (`src/Services/EmailService.php`): STARTTLS is negotiated whenever the server advertises it rather than only on port 587, AUTH PLAIN is tried when AUTH LOGIN is unavailable, headers are UTF-8 encoded, Reply-To and Message-ID are set, the From name is the clinic, and message bodies are dot-stuffed — a body line consisting of "." used to truncate the email. The silent fall back to PHP `mail()` is gone: a clinic with no mail server set up now gets a clear failure instead of a message that never arrives.
- **Email audit log**: the Email tab previously had no log at all. It now lists every message attempted for the clinic with recipient, subject, type, status and the failure reason, with search, status filter, paging, and a working "Try again" for failures.
- **Live notifications fixed**: the stream ends every 25 seconds by design, and anything that happened while the browser reconnected was lost. Events now carry an id and the stream resumes from `Last-Event-ID`, so nothing is missed or repeated. Both queries also read from the same cursor — previously a new patient could advance it past an appointment booked moments earlier, skipping that notification.
- **Switching clinics is developer-only.** The switcher is hidden for everyone else and `switch_tenant` is rejected server-side, so an administrator can no longer move into another clinic's records by posting the form directly.
- **Fixes:** the resend and test-email endpoints called `verifyCsrfToken()` without checking the result, so CSRF was not enforced on either; the SMTP password was rendered into the page HTML and is now never echoed back (leave the box blank to keep it); patient emails were headed "Nuvis Healthcare" instead of the clinic's name.

### **Per-clinic branding and inventory set-up** - *October 2026*
- **Fixed: one clinic's details overwrote every other clinic's.** Saving on Administration → Clinic & branding wrote to both the shared `clinic_settings` table and the clinic's own, and most printed documents read only the shared one — so the clinic name, address and headings showed the last clinic saved rather than the one selected. Settings now go to the store that owns them (`src/Services/ClinicSettings.php`): clinic identity, document wording, tax numbers and inventory set-up belong to the clinic; SMTP, git and cloud storage stay installation-wide.
- **One name resolver** (`includes/settings.php`): a clinic's own setting, then its tenant record, then the installation default — used by the header, sidebar, receipts, invoices, prescriptions, certificates, lab orders, Z-reports and the public prescription-verification page, which now shows the clinic that issued the prescription.
- **Clinic & branding rebuilt:** logo upload with live preview of the printed header, strapline, address, phone, email and website, registration numbers, and the wording for prescriptions, invoices and certificates in one place. A banner names which clinic is being edited.
- **Inventory set-up rebuilt:** categories and units are edited as chips instead of a comma-separated string, and extra item fields are built with a form — label, type (text, number, date, choice list, long text), hint and whether it is required — instead of hand-written JSON. Reorder level and an expiry warning window are set per clinic and feed the dashboard, the stock list and the add-item form, which now offers the clinic's categories, units and extra fields.
- **Fixes:** the invoice heading was hardcoded as "COMMERCIAL INVOICE" and ignored the configured one; `print_invoice.php` skipped the installation defaults whenever a clinic had any setting of its own; a stale clinic name was hardcoded on medical certificates; custom field values were stored unvalidated; inventory prices showed `$` regardless of the clinic's currency.

### **Access control & receipt design** - *October 2026*
- **Per-clinic role access (Administration → Access & roles):** a grid of roles against the nine areas of the app, each set to No access / View / Edit / Full. Defaults ship for Administrator, Doctor, Nurse, Receptionist and Staff; each clinic can change them and add its own roles (e.g. Pharmacist), starting from an existing role's access. Staff are assigned roles on the same screen.
- **Enforced in three places:** the menu hides areas a role can't open, pages check on load, and `nxGuardRequest()` checks every action endpoint and print page, so posting directly to an endpoint is blocked too. Administrator and Developer keep full control of Staff & access, so a clinic can't lock itself out.
- **Role changes apply immediately:** the signed-in user's role and active status are re-read each request, so a changed role takes effect on the next page load and a deactivated account is signed out at once.
- **Receipt design per clinic (Administration → Receipts):** paper size (A4, Letter, 80 mm and 58 mm rolls), accent colour, text size, date format, logo and placement, wording (title, receipt-number prefix, thank-you message, footer note), currency symbol, copies per print, and 18 toggles for what is printed — all with a live preview beside the form.
- **Fixes:** receipt settings had no save endpoint, so the design could not be stored; the settings sanitiser warned on every preview when "copies per print" was absent; the FRCS verification QR came from an external service with no fallback, so an offline front desk printed a broken image — the receipt now prints the verification link as text instead.

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
