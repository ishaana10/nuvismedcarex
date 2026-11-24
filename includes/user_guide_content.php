<?php
/**
 * Content of the in-app User Guide (user_guide.php).
 *
 * Each section: id, title, icon, module (permission module it relates to, or
 * null for "everyone"), who (roles that mostly use it), summary, body (HTML).
 * Callouts: <div class="g-tip">, <div class="g-note">, <div class="g-warn">.
 * Keep wording in plain language: this is read by front-desk staff, nurses and
 * doctors as much as by administrators.
 */
return [

[
    'id' => 'welcome', 'title' => 'Welcome and quick start', 'icon' => 'waving_hand', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'What Nuvis Medcare X is for and the first five minutes.',
    'body' => <<<'HTML'
<p>Nuvis Medcare X is your clinic's record keeper. It holds patients, appointments, visit notes, prescriptions, certificates, bills, stock and documents in one place, so everyone at the clinic works from the same information.</p>
<h4>Your first five minutes</h4>
<ol>
  <li><strong>Sign in</strong> with the email address your administrator registered for you (see <a href="#signing-in">Signing in</a>).</li>
  <li>Look at the <strong>Dashboard</strong>. It shows who is waiting, today's appointments and recent activity.</li>
  <li>Open <strong>Settings</strong> (bottom of the left menu) and choose your theme, the page you want to open after signing in and your signature.</li>
  <li>Press <kbd>Ctrl</kbd> + <kbd>K</kbd> (<kbd>⌘</kbd> + <kbd>K</kbd> on a Mac) and type a patient's name. This is the quickest way to get anywhere.</li>
  <li>Press <kbd>?</kbd> to see every keyboard shortcut.</li>
</ol>
<h4>What you will see depends on your role</h4>
<p>A receptionist does not see clinical notes and a nurse does not see billing. That is intentional: each person sees what their job needs. If a menu item is missing, it is switched off for your role (see <a href="#roles">Roles and what they can do</a>). Ask your administrator if you need more access.</p>
<h4>A typical day, by role</h4>
<ul>
  <li><strong>Receptionist:</strong> register new patients, book appointments, take payments and print receipts.</li>
  <li><strong>Nurse:</strong> open the encounter from the queue, record vitals, order tests and attach documents.</li>
  <li><strong>Doctor:</strong> see the patient, write the SOAP note, prescribe, issue a certificate and finish the visit with an invoice.</li>
  <li><strong>Administrator:</strong> look after staff, roles, branding, email, stock setup and the audit log.</li>
</ul>
HTML,
],

[
    'id' => 'signing-in', 'title' => 'Signing in and your account', 'icon' => 'login', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Signing in, forgotten passwords, switching clinics and signing out.',
    'body' => <<<'HTML'
<h4>Signing in</h4>
<p>Enter your email and password and press <strong>Sign in</strong>. Tick <strong>Remember my email on this device</strong> only on a computer that is yours or the clinic's own. The password is never remembered.</p>
<h4>"Session expired"</h4>
<p>For safety the app signs you out after a period without activity, or when the browser is closed. You will see <em>Your session has expired. Please log in again.</em> Anything you had not saved is not kept, so press <strong>Save</strong> before you leave a form open for a long time.</p>
<h4>Forgot your password</h4>
<ol>
  <li>On the sign-in page choose <strong>Forgot password?</strong></li>
  <li>Enter your email. A one-time code is emailed to you and works for 15 minutes.</li>
  <li>Enter the code, then choose a new password.</li>
</ol>
<div class="g-note">The code is sent by your clinic's own mail server. If no email arrives, ask your administrator to check <strong>Administration → Email</strong> (see <a href="#admin-email">Email</a>).</div>
<h4>Changing your password</h4>
<p>Go to <strong>Settings → Security</strong>. Use a long password you do not use anywhere else. Never share it: the audit log records actions under your name.</p>
<h4>Working at more than one clinic</h4>
<p>If you were added to several clinics, open the menu under your name (top right) and choose the clinic you want. Everything on screen then belongs to that clinic. Patients, bills and stock of one clinic are never shown in another.</p>
<h4>Signing out</h4>
<p>Choose <strong>Sign out</strong> from the menu under your name, especially on a shared computer. <strong>Settings → Security</strong> also has a Sign out button for this session.</p>
HTML,
],

[
    'id' => 'finding-your-way', 'title' => 'Finding your way around', 'icon' => 'explore', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Menu, search, shortcuts, alerts and using the app on a phone.',
    'body' => <<<'HTML'
<h4>The menu</h4>
<p>The left menu groups the app into <strong>Clinic</strong> (Dashboard, Patients, Appointments, Clinical encounter), <strong>Operations</strong> (Billing, Inventory, Documents) and <strong>System</strong> (Wish board, User guide, Settings, Administration). Use the <strong>Collapse</strong> button, or press <kbd>[</kbd>, to make it narrower.</p>
<h4>On a phone or tablet</h4>
<ul>
  <li>A bar at the bottom of the screen gives one-tap access to the places used most (Dashboard, Patients, Appointments and Billing, or the first areas your role has); <strong>More</strong> opens the full menu.</li>
  <li>Long tables turn into cards so you can read them without sideways scrolling.</li>
  <li>Fields are large enough to tap, and the app keeps clear of the notch and the home bar.</li>
</ul>
<h4>Search everything</h4>
<p>Press <kbd>Ctrl</kbd> + <kbd>K</kbd> (or <kbd>/</kbd>), type part of a patient's name, MRN or a page name, and press Enter. It finds patients and pages together.</p>
<h4>Keyboard shortcuts</h4>
<table class="g-table">
<thead><tr><th>Keys</th><th>What it does</th></tr></thead>
<tbody>
<tr><td><kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>K</kbd> or <kbd>/</kbd></td><td>Search patients and pages</td></tr>
<tr><td><kbd>G</kbd> then <kbd>D</kbd></td><td>Go to the dashboard</td></tr>
<tr><td><kbd>G</kbd> then <kbd>P</kbd></td><td>Go to patients</td></tr>
<tr><td><kbd>G</kbd> then <kbd>A</kbd></td><td>Go to appointments</td></tr>
<tr><td><kbd>G</kbd> then <kbd>E</kbd></td><td>Go to clinical encounter</td></tr>
<tr><td><kbd>G</kbd> then <kbd>B</kbd></td><td>Go to billing</td></tr>
<tr><td><kbd>G</kbd> then <kbd>I</kbd></td><td>Go to inventory</td></tr>
<tr><td><kbd>G</kbd> then <kbd>S</kbd></td><td>Go to your settings</td></tr>
<tr><td><kbd>G</kbd> then <kbd>U</kbd></td><td>Open this user guide</td></tr>
<tr><td><kbd>N</kbd></td><td>Register a new patient</td></tr>
<tr><td><kbd>Shift</kbd> + <kbd>D</kbd></td><td>Switch between light and dark</td></tr>
<tr><td><kbd>[</kbd></td><td>Collapse or expand the menu</td></tr>
<tr><td><kbd>Esc</kbd></td><td>Close a pop-up or panel</td></tr>
<tr><td><kbd>?</kbd></td><td>Show the shortcut list</td></tr>
</tbody></table>
<div class="g-note">Shortcuts do not fire while you are typing in a field, and a shortcut for an area your role cannot open shows a short "no access" message instead.</div>
<h4>Alerts</h4>
<p>The bell at the top shows live alerts (for example "a patient was registered") while the app is open. Choose which alerts you want, and whether they make a sound, in <strong>Settings → Alerts</strong>. If your clinic has switched live alerts off, none will appear.</p>
<h4>Light and dark</h4>
<p>Use the moon icon at the top, or <kbd>Shift</kbd> + <kbd>D</kbd>. Your choice is remembered for you across devices.</p>
HTML,
],

[
    'id' => 'dashboard', 'title' => 'Dashboard', 'icon' => 'space_dashboard', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'The day at a glance: who is waiting, who is being seen, today\'s schedule.',
    'body' => <<<'HTML'
<p>The dashboard is the first page after signing in (you can choose another start page in <strong>Settings</strong>).</p>
<ul>
  <li><strong>Book appointment</strong> and <strong>Register patient</strong> buttons at the top save a trip through the menu.</li>
  <li><strong>Clinic flow</strong> shows the patients who have arrived. A patient starts in <em>Waiting</em>; choose a <strong>room</strong> and press <strong>Call in</strong> to move them to <em>With a clinician</em>; when they are done, mark them seen and they move to <em>Seen today</em>. Pressed by mistake? Use the undo or <em>back to waiting</em> control.</li>
  <li><strong>Today's schedule</strong> lists the day's appointments with time, patient, clinician, visit type and status. <strong>All appointments</strong> opens the full calendar.</li>
  <li><strong>At a glance</strong> shows key numbers for the clinic, and <strong>Recent activity</strong> lists what was just done. Use <em>Show older activity</em> to go back further.</li>
</ul>
<div class="g-note">Your administrator can switch some optional areas off for the whole clinic. If a card or menu item is absent for everyone, that is why.</div>
HTML,
],

[
    'id' => 'patients', 'title' => 'Patients: registering and the patient chart', 'icon' => 'groups', 'module' => 'patients',
    'who' => ['Receptionist', 'Nurse', 'Doctor'],
    'summary' => 'Register a patient, find them again and keep their details correct.',
    'body' => <<<'HTML'
<h4>The patient directory</h4>
<p>Open <strong>Patients</strong>. Type in <strong>Search patients</strong> to find by name, MRN, phone or any ID number on file (social welfare ID, NHN, BRN, voter ID, driving licence and so on). Use the filters <em>All patients</em>, <em>With allergies</em>, <em>Self pay</em> and <em>New this month</em>, and sort by <em>Name (A–Z)</em>, <em>Recently registered</em> or <em>Oldest first</em>. <strong>Clear search</strong> returns to the full list. The table shows age and sex, contact, insurance and allergies at a glance.</p>
<h4>Registering a new patient</h4>
<p>Press <strong>Register patient</strong> (or <kbd>N</kbd>). The form has three parts:</p>
<ol>
  <li><strong>Personal and demographics.</strong> First name, last name, date of birth, gender and phone number are required. Email and residential address are optional but useful for sending documents. Under <strong>ID numbers</strong> add any identity documents the patient has: pick the type (social welfare ID, NHN #, BRN #, voter ID, driving licence, passport, FNPF, TIN, or <em>Other</em> to name your own) and type the number; <strong>Add another ID</strong> adds more. Each one is searchable. The edit window on the patient chart has exactly the same fields.</li>
  <li><strong>Emergency contact and insurance.</strong> Contact name, relationship and phone, plus the insurance provider, policy number and group number. Leave insurance blank for self-pay patients.</li>
  <li><strong>Medical profile and allergies.</strong> Known allergies, blood group and past medical history, medication history or baseline notes.</li>
</ol>
<div class="g-tip"><strong>Children under 15.</strong> Their exact age (for example “5 yrs 6 mths”) is printed on the prescription, to support weight and age based dosing.</div>
<p>Press <strong>Register &amp; Open Chart</strong>. The patient gets a medical record number (MRN) automatically.</p>
<div class="g-tip"><strong>Search before you register.</strong> Registering the same person twice splits their history across two charts. Check the directory first, including spellings.</div>
<h4>The patient chart</h4>
<p>Open any patient to see their chart:</p>
<ul>
  <li><strong>Clinical overview:</strong> known allergies, past medical and medication history and clinical notes.</li>
  <li><strong>Contact and insurance:</strong> phone, address, emergency contact and insurance details.</li>
  <li><strong>Lab and diagnostic orders:</strong> tests ordered for this patient, with <strong>Order Test</strong>.</li>
  <li><strong>Issued medical certificates:</strong> every certificate for this patient, with <strong>Issue New Certificate</strong>.</li>
  <li><strong>Past clinical visits and encounters:</strong> each past visit. Open one to see its vitals summary, the SOAP notes, the prescriptions issued and its invoice.</li>
</ul>
<h4>Correcting a patient's details</h4>
<p>Use <strong>Edit Patient Info</strong> at the top of the chart, change what is wrong and press <strong>Save Patient Changes</strong>. Keep allergies up to date: they are shown to everyone who opens the chart and can be printed on prescriptions.</p>
HTML,
],

[
    'id' => 'appointments', 'title' => 'Appointments', 'icon' => 'calendar_month', 'module' => 'appointments',
    'who' => ['Receptionist', 'Doctor', 'Nurse'],
    'summary' => 'Book, change and complete appointments.',
    'body' => <<<'HTML'
<h4>Booking</h4>
<ol>
  <li>Open <strong>Appointments</strong> and press <strong>Book Appointment</strong>.</li>
  <li>Choose the <strong>patient</strong> (register them first if they are new), the <strong>attending physician</strong>, the <strong>date</strong> and a <strong>time slot</strong>.</li>
  <li>Pick the <strong>visit type</strong>: Consultation, Follow-up, Routine Check, Urgent Care, Lab Results or Physical Exam.</li>
  <li>Add any booking notes and press <strong>Confirm Schedule</strong>.</li>
</ol>
<h4>Viewing</h4>
<p>Three views sit above the list: <strong>Active / Upcoming</strong>, <strong>Completed</strong> and <strong>All Appointments</strong>. Each row shows date and time, doctor, visit type and status.</p>
<h4>Changing or cancelling</h4>
<p>Open the appointment's edit action, change the doctor, date, time, type, status or notes, and press <strong>Save Changes</strong>. To cancel, set the status accordingly so the history is kept. Deleting an appointment completely is limited to roles with full access to appointments (by default Receptionists and Administrators).</p>
<div class="g-tip">When a patient arrives, the receptionist moves them into the queue from the dashboard so the nurse and doctor can see they are waiting.</div>
HTML,
],

[
    'id' => 'encounters', 'title' => 'Clinical encounters: seeing a patient', 'icon' => 'stethoscope', 'module' => 'encounters',
    'who' => ['Doctor', 'Nurse'],
    'summary' => 'Vitals, SOAP notes, prescriptions, lab orders, consent and finishing the visit.',
    'body' => <<<'HTML'
<h4>Starting</h4>
<p>Open <strong>Clinical encounter</strong>. <strong>Active Visits &amp; Queue</strong> lists patients who are waiting or being seen (patient name and MRN, check-in time, assigned doctor, status). Open one, or press <strong>Start New Encounter</strong>, choose the patient and press <strong>Begin Encounter</strong>. <strong>Finalized Encounters</strong> below lists completed visits.</p>
<h4>Vitals</h4>
<p>Record blood pressure, heart rate, heart rhythm, temperature, weight, height, respiratory rate, oxygen saturation and blood sugar. Only fill in what you measured; empty boxes stay empty, nothing is filled in for you. Press <strong>Save Vitals &amp; SOAP Notes</strong>.</p>
<h4>SOAP documentation</h4>
<ul>
  <li><strong>Subjective:</strong> chief complaint and history, in the patient's words.</li>
  <li><strong>Objective:</strong> examination findings and diagnostic data.</li>
  <li><strong>Assessment:</strong> the diagnosis, with its <strong>ICD-10 code</strong>.</li>
  <li><strong>Plan:</strong> treatment and follow-up.</li>
</ul>
<p>Vitals and notes are stored encrypted. Use the microphone button where shown to dictate (set your language in <a href="#settings">Settings</a>).</p>
<h4>Prescriptions</h4>
<p>Under <strong>Current Encounter Prescription</strong> press <strong>Add Medication Line</strong> and give the medication name, dosage, frequency, duration and instructions. Edit a line with its edit button. <strong>Prescription History</strong> shows earlier prescriptions for this patient. See <a href="#printing">Printing and emailing documents</a> to print or email the prescription.</p>
<div class="g-warn"><strong>Check allergies first.</strong> The patient's known allergies are shown on the chart. Confirm them before prescribing.</div>
<h4>Lab and diagnostic orders</h4>
<p>Press <strong>Order Test</strong>, choose the tests and add clinical notes. See <a href="#labs">Lab and diagnostic orders</a>.</p>
<h4>Consent</h4>
<p>If your clinic created consent forms (see <a href="#admin-consent">Consent forms</a>), they appear under <strong>Consent to sign</strong>. The patient answers and signs; <strong>Withdraw consent</strong> records that they changed their mind. If no form is listed, an administrator needs to create one.</p>
<h4>Medical certificate</h4>
<p>Press <strong>Med Cert</strong> to issue a medical certificate from the visit. The diagnosis and notes are filled in from your SOAP note for you to adjust. See <a href="#certificates">Medical certificates</a>.</p>
<h4>Finishing the visit</h4>
<ol>
  <li>Press <strong>Finish Visit / Invoice</strong>.</li>
  <li>Choose the <strong>invoice type</strong> and the <strong>transaction type</strong> (see <a href="#billing">Billing</a>), add line items (consultation, procedures, medicines from stock), the tax rate and the payment method.</li>
  <li>If part of the bill is covered by insurance, enter the <strong>insurance portion</strong>, the insurer and the policy number.</li>
  <li>Press <strong>Finalize Visit &amp; Save Invoice</strong>. The encounter moves to <em>Finalized</em> and the invoice appears in Billing.</li>
</ol>
<div class="g-note">Finishing a visit with items from stock reduces your inventory automatically, oldest expiry first.</div>
HTML,
],

[
    'id' => 'labs', 'title' => 'Lab and diagnostic orders', 'icon' => 'biotech', 'module' => 'encounters',
    'who' => ['Doctor', 'Nurse'],
    'summary' => 'Order tests, track them and record results.',
    'body' => <<<'HTML'
<ol>
  <li>From the patient chart or the encounter press <strong>Order Test</strong>.</li>
  <li>Choose the tests, add clinical notes for the laboratory and save. The order starts as <em>Ordered</em>.</li>
  <li>Print the requisition from the order (see <a href="#printing">Printing</a>).</li>
  <li>When results arrive, update the order's status, paste the result text, tick <strong>abnormal</strong> if it is out of range, and attach the report file if you have one.</li>
</ol>
<p>Files attached to results are kept in <a href="#documents">Documents</a> under <em>Lab results</em>.</p>
HTML,
],

[
    'id' => 'certificates', 'title' => 'Medical certificates', 'icon' => 'workspace_premium', 'module' => 'encounters',
    'who' => ['Doctor'],
    'summary' => 'Issue, print and email certificates; clinic layouts and numbering.',
    'body' => <<<'HTML'
<h4>Issuing a certificate</h4>
<p>From a patient chart choose <strong>Issue Certificate</strong>, or press <strong>Med Cert</strong> inside an encounter. Fill in:</p>
<ul>
  <li><strong>Issue date</strong> and <strong>diagnosis / clinical impression</strong> (required).</li>
  <li><strong>Fitness status:</strong> Fit for Work / School, Fit to Resume Normal Duties, Unfit for Physical Activity, Needs Medical Rest or Fit with Restrictions, with <strong>rest duration or details</strong>.</li>
  <li><strong>Remarks and recommendations</strong>, the <strong>attending physician</strong> and the <strong>medical licence number</strong>.</li>
  <li><strong>Clinic form details (optional):</strong> tick the problem categories, and enter the date the illness started, the date to resume duties and a review date. These print on the Clinic form layout.</li>
</ul>
<p>Press <strong>Issue &amp; Print Certificate</strong>. A print preview opens with your logo, the golden caduceus, your signature and stamp.</p>
<h4>Certificate numbers</h4>
<p>Each certificate gets a number made from your clinic's letters, the date and a running count, for example <code>MTMC-20261117-0007</code>. Administrators choose the format (see <a href="#admin-documents">Document templates</a>). Numbers already issued are never changed or reused.</p>
<h4>Two layouts</h4>
<p>Your clinic prints either the <em>Classic letter</em> or the <em>Clinic form</em>, which looks like a printed clinic pad with tick-boxes and fill-in lines. Which one is used is a clinic setting.</p>
<h4>Printing and emailing</h4>
<p>On the preview press <strong>Print Official Certificate</strong>, or type the patient's email address and press <strong>Email Certificate</strong>.</p>
<div class="g-note">A certificate is only complete with the physician's signature and stamp. Doctors add theirs once under <a href="#settings">Settings → How you appear</a>.</div>
HTML,
],

[
    'id' => 'printing', 'title' => 'Printing and emailing documents', 'icon' => 'print', 'module' => null,
    'who' => ['Doctor', 'Receptionist', 'Nurse'],
    'summary' => 'Prescriptions, certificates, lab orders, invoices, receipts and consent forms.',
    'body' => <<<'HTML'
<p>Every document has a print preview. Check it on screen, then press its <strong>Print</strong> button and choose <em>Save as PDF</em> in the print window if you want a file instead of paper.</p>
<table class="g-table">
<thead><tr><th>Document</th><th>Where to open it</th><th>Notes</th></tr></thead>
<tbody>
<tr><td>Prescription</td><td>Encounter → Prescription</td><td>Shows the prescriber, signature, stamp and a QR code anyone can scan to check it is genuine.</td></tr>
<tr><td>Medical certificate</td><td>Patient chart or encounter</td><td>Classic or Clinic form layout.</td></tr>
<tr><td>Lab requisition</td><td>The lab order</td><td>Sent with the patient or the sample.</td></tr>
<tr><td>Invoice</td><td>Billing → invoice</td><td>Itemised, with VAT shown per tax label.</td></tr>
<tr><td>Receipt</td><td>Billing → invoice</td><td>Can be emailed to the patient.</td></tr>
<tr><td>Consent form</td><td>Consent forms</td><td>Signed copy for the patient file.</td></tr>
<tr><td>Z-report</td><td>Billing → Z-report</td><td>Daily or period summary.</td></tr>
</tbody></table>
<h4>Emailing</h4>
<p>Where you see an email box on a preview, check the patient's address and press the mail button. The message is sent from your clinic's own mail account and a record is kept in the audit trail. If it fails, the reason is shown in plain words and your administrator can fix the mail settings (see <a href="#admin-email">Email</a>).</p>
<h4>Checking a prescription is genuine</h4>
<p>A pharmacist or anyone else can scan the QR code on a prescription to open a public page that confirms the prescriber and prescription. They do not need to sign in and see no patient chart.</p>
<div class="g-tip">If a signature or stamp is missing on a printout, the doctor has not uploaded one yet. They can add it in <strong>Settings → How you appear</strong>.</div>
HTML,
],

[
    'id' => 'billing', 'title' => 'Billing, invoices and payments', 'icon' => 'receipt_long', 'module' => 'billing',
    'who' => ['Receptionist', 'Doctor', 'Administrator'],
    'summary' => 'Create invoices, take payments, print receipts and read the Z-report.',
    'body' => <<<'HTML'
<h4>The Billing page</h4>
<p>Three tabs: <strong>Invoices</strong>, <strong>Insurance claims</strong> and <strong>Z-report</strong>. The invoice list shows invoice number and type, patient, service date, total, tax, status and actions.</p>
<h4>Creating an invoice</h4>
<ol>
  <li>Press <strong>Create Invoice</strong> (or finish an encounter, which creates one for you).</li>
  <li>Choose the <strong>patient</strong>. Their name and MRN fill in.</li>
  <li>Choose the <strong>invoice type</strong> and <strong>transaction type</strong> (below).</li>
  <li>Add <strong>line items</strong>: name, quantity and price. Pick a stock item from the list to fill the name, price and tax automatically, or add a custom row.</li>
  <li>Set the <strong>tax</strong> (15% VAT, 10%, or 0% exempt) and the <strong>payment method</strong>: Cash, Card, Check, Wire Transfer or Mobile Money.</li>
  <li>If insurance covers part of it, enter the <strong>insurance portion</strong>; the patient's balance is the rest.</li>
  <li>Generate the invoice.</li>
</ol>
<h4>Invoice types</h4>
<table class="g-table">
<thead><tr><th>Type</th><th>Use it for</th></tr></thead>
<tbody>
<tr><td>Normal Invoice</td><td>An ordinary sale.</td></tr>
<tr><td>Advance Invoice</td><td>Money taken before the service.</td></tr>
<tr><td>Proforma Invoice</td><td>A quote. Nothing is sold yet.</td></tr>
<tr><td>Copy Invoice</td><td>A reprint of an invoice already issued.</td></tr>
<tr><td>Training Invoice</td><td>Practice only, for teaching new staff. Not a real sale.</td></tr>
</tbody></table>
<p><strong>Transaction type</strong> is either <em>Sale (+)</em> or <em>Refund (−)</em>. A refund reverses an earlier sale; use it rather than deleting.</p>
<h4>Receipts</h4>
<p>Open the invoice to print a receipt, or email it to the patient from the same screen.</p>
<h4>VAT on invoices</h4>
<p>Prices include VAT. Each line carries a tax label: A (15% VAT), E (exempt), F (zero-rated) or P (0.25% levy). Cancelling an invoice is restricted to the Developer role in this release; ask your administrator to arrange it.</p>
<h4>Insurance claims</h4>
<p>On the <strong>Insurance claims</strong> tab press <strong>Submit Claim</strong>, choose the invoice and provider, enter the policy number and claim amount, and add notes. Claims can be <strong>approved</strong> or <strong>rejected</strong>, edited, and signed off. See <a href="#insurance">Insurance</a>.</p>
<h4>Z-report</h4>
<p>Choose <em>Single day</em> or a <em>From–To</em> period and press <strong>Show report</strong>. It totals invoices and refunds by type, VAT by tax label and how the money was paid. Download it as CSV or print it.</p>
<div class="g-warn"><strong>Never delete to correct.</strong> A wrong invoice is corrected with a refund and a new invoice so the books stay accurate.</div>
HTML,
],

[
    'id' => 'insurance', 'title' => 'Insurance providers, claims and reports', 'icon' => 'health_and_safety', 'module' => 'billing',
    'who' => ['Receptionist', 'Administrator'],
    'summary' => 'Keep a provider list, raise claims and see what is outstanding.',
    'body' => <<<'HTML'
<h4>Provider list</h4>
<p>Administrators keep the clinic's list under <strong>Administration → Insurance</strong>: provider name, short code, what the provider calls the policy number, the usual share of the bill, the claim-within period, claims email and phone, and notes for whoever raises the claim. Providers already used on patients but missing from the list are offered so you can add them with one click.</p>
<h4>Raising a claim</h4>
<p>From Billing → <strong>Insurance claims</strong> → <strong>Submit Claim</strong>. Pick the invoice, the provider (or <em>Another provider…</em> to add one), the policy or member number and the amount.</p>
<h4>The insurance report</h4>
<p>Open it from <strong>Insurance</strong> in Administration. Choose the period, whether dates mean <em>date claim submitted</em> or <em>date of service</em>, and a provider. You see totals by provider (claims, claimed, approved, rejected, outstanding), how long claims have been waiting, and a list of invoices that have an insurance portion but no claim yet, with a button to submit one. Use <strong>Customise layout</strong> to choose columns, headings and the footer note for one provider or for all of them, then <strong>Download CSV</strong> or print.</p>
HTML,
],

[
    'id' => 'inventory', 'title' => 'Inventory (pharmacy and supplies)', 'icon' => 'inventory_2', 'module' => 'inventory',
    'who' => ['Doctor', 'Nurse', 'Administrator'],
    'summary' => 'Stock levels, restocking, batches, expiry and movement history.',
    'body' => <<<'HTML'
<h4>Reading the list</h4>
<p>Each row shows item name and SKU, category, stock level, cost and selling price, batch and expiry, and a status of <em>In Stock</em>, <em>Low Stock</em> or <em>Out of Stock</em>. Filter by category or stock status. A short summary shows stock by category, with units and value, and which items move most or sit unused.</p>
<h4>Adding an item</h4>
<p>Press <strong>Add Inventory Item</strong> and enter: item name, SKU or code, unit type, the <strong>VAT tax label</strong> (A standard 15%, E exempt, F zero-rated or P levy), initial quantity, <strong>low-stock threshold</strong>, unit cost and selling price, and the batch number and expiry date.</p>
<h4>Restocking</h4>
<p>Press <strong>Restock</strong> on the row, enter the quantity, supplier and a reference, and submit. Each delivery is recorded as its own batch with its own expiry.</p>
<h4>How stock is used</h4>
<p>When an invoice uses a stock item, the batch that expires <strong>first</strong> is used first (first-expired, first-out), so older stock is not wasted. Every change is written to the movement history.</p>
<h4>Movement history</h4>
<p>Press <strong>Movement History</strong> for a dated record of every restock and every use: type, previous and new quantity, supplier and cost, notes and who did it. Choose a date range and press <strong>Update</strong>, then <strong>Download CSV</strong> for a spreadsheet.</p>
<h4>Editing and deleting</h4>
<p>Editing changes the name, prices and similar details. Deleting an item is limited to roles with full inventory access.</p>
<div class="g-tip">Set the low-stock threshold to roughly one week's use. Then the <em>Low Stock</em> filter becomes your reorder list.</div>
HTML,
],

[
    'id' => 'documents', 'title' => 'Documents and file storage', 'icon' => 'folder_open', 'module' => 'documents',
    'who' => ['Nurse', 'Doctor', 'Receptionist'],
    'summary' => 'Upload scans and reports, link cloud files and find them again.',
    'body' => <<<'HTML'
<h4>Uploading</h4>
<ol>
  <li>Open <strong>Documents</strong> and press <strong>Upload Document</strong>.</li>
  <li>Optionally choose the <strong>patient</strong> the file belongs to.</li>
  <li>Choose a <strong>category</strong>: General, Lab results, X-ray / imaging, Prescription, Insurance claim, Medical certificate or Consent form.</li>
  <li>Choose a file, or paste a <strong>OneDrive sharing link</strong>, or paste or pick a <strong>Google Drive</strong> file.</li>
  <li>Give it a name and press <strong>Submit &amp; Save Document</strong>.</li>
</ol>
<h4>Finding documents</h4>
<p>Filter by storage location and by category. Each row shows the file, the patient it is linked to, where it is stored, its category, size and type, and who uploaded it and when.</p>
<h4>Where files are kept</h4>
<p>Files can live on the clinic's own server, in Google Drive or in OneDrive. Administrators choose this under <strong>Storage settings</strong> on this page.</p>
<div class="g-note"><strong>Google Drive says "reconnect".</strong> The link to Drive has been withdrawn or has expired. An administrator presses <em>Reconnect</em> once under Storage settings. If it keeps happening every week, the clinic's Google Cloud app is still in "Testing" and must be set to <em>In production</em>.</div>
<p>Deleting a file is limited to roles with full access to documents.</p>
HTML,
],

[
    'id' => 'settings', 'title' => 'Your settings', 'icon' => 'tune', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Profile, signature, theme, preferences, alerts and password.',
    'body' => <<<'HTML'
<p>Open <strong>Settings</strong> from the bottom of the menu. These are <em>your</em> settings; they do not change anything for other people.</p>
<table class="g-table">
<thead><tr><th>Section</th><th>What you can do</th></tr></thead>
<tbody>
<tr><td>How you appear</td><td>Change your photo, name, job title or specialty and email. Doctors upload a <strong>signature and stamp</strong> here; they print on prescriptions and certificates.</td></tr>
<tr><td>Theme and spacing</td><td>Light, dark or <em>Follow my device</em>, and how tightly the screens are laid out.</td></tr>
<tr><td>Preferences</td><td>The page to open after signing in, rows per page, date format and time format, start with the menu collapsed, show or hide the hint text under form fields.</td></tr>
<tr><td>Voice dictation</td><td>Choose the spoken language and accent for dictation, and add <strong>My medical words</strong> so unusual drug names are recognised. <strong>Reset to defaults</strong> puts everything back.</td></tr>
<tr><td>Alerts ("Tell me when")</td><td>Choose whether you are told when a patient is registered or an appointment is booked, and whether alerts play a sound.</td></tr>
<tr><td>Security</td><td>Change your password and sign out of this session.</td></tr>
</tbody></table>
<p>Anyone who can see Staff, Access or the Audit log also sees shortcuts to them at the bottom of Settings.</p>
<div class="g-note">Your clinic may set a default look for everyone. What you save here always wins for you.</div>
HTML,
],

[
    'id' => 'admin', 'title' => 'Administration (for administrators)', 'icon' => 'admin_panel_settings', 'module' => 'settings',
    'who' => ['Administrator'],
    'summary' => 'Staff, roles, branding, appearance, email, stock setup, insurance, consent and the audit log.',
    'body' => <<<'HTML'
<p>Administration is a row of tabs. Which tabs you see depends on your access.</p>
<h4 id="admin-staff">Staff</h4>
<p>Add staff, give each a role, switch accounts on or off and reset access. Use a separate account for every person: never share logins, because the audit log records who did what.</p>
<h4 id="admin-access">Access and roles</h4>
<p>Choose for each role how far it may go in each area: nothing, view, edit or full (including delete). Create your own roles by copying an existing one. The Administrator and Developer roles are locked so you cannot lock yourself out. See <a href="#roles">Roles and what they can do</a>.</p>
<h4 id="admin-clinic">Clinic and branding</h4>
<p>The clinic's name, subtitle, address, phone, email, website and logo. These appear on documents, the menu and emails.</p>
<h4 id="admin-appearance">Appearance</h4>
<p>The clinic's accent colour, default look and which optional areas appear in the menu. Hiding an area only tidies the menu: access is still decided by roles. People can override the look for themselves in their own Settings.</p>
<h4>Locations</h4>
<p>The clinic's branches or sites.</p>
<h4>Subscription</h4>
<p>Your plan, billing cycle and renewal date.</p>
<h4 id="admin-receipts">Receipts</h4>
<p>How receipts look: layout, header and footer wording, paper width (including narrow thermal printers) and what prints.</p>
<h4 id="admin-email">Email</h4>
<p>Set the outgoing mail account so the app can email receipts, certificates, prescriptions and password codes. Choose your provider (Gmail, Outlook, Yahoo, Zoho or your own server) and enter the address and password.</p>
<div class="g-note">Gmail and Yahoo need an <strong>app password</strong>, not your normal password. Each provider's page explains how to create one. Press <strong>Send test</strong> to check before you rely on it. The same page shows a log of messages sent and any failures.</div>
<h4>Inventory setup</h4>
<p>Units, categories and the default low-stock levels used on the Inventory page.</p>
<h4 id="admin-insurance">Insurance</h4>
<p>The provider list and the insurance report, described under <a href="#insurance">Insurance</a>.</p>
<h4 id="admin-consent">Consent forms</h4>
<p>Create the forms patients sign, for example treatment consent or photography consent. Start from a template or a blank form, name it, say when it is used, write the wording patients read, add the questions (mark which are required), add a declaration above the signature and choose whether a <strong>witness</strong> must also sign. A <em>What the patient sees</em> preview updates as you type. Copy, edit or delete forms from the list; the list also shows how many have been signed.</p>
<h4 id="admin-documents">Document templates</h4>
<p>Under <strong>Documents</strong> choose <em>Prescription</em> or <em>Medical certificate</em> and set its letterhead: logo, colours, font, paper size, titles, wording, which details print, and the signature and stamp options. For certificates you also:</p>
<ul>
  <li>Pick the layout: <strong>Classic letter</strong> or <strong>Clinic form</strong>.</li>
  <li>Edit the doctor and registration line, the "consultation in" services and the problem tick-boxes (one per line, up to 15).</li>
  <li>Set the <strong>certificate numbers</strong>: clinic letters, date part, separator, digits, when the count restarts, and an optional next number (to continue from a paper pad). The next number is shown before you save.</li>
</ul>
<h4 id="admin-audit">Audit log</h4>
<p>A dated record of who did what: sign-ins, record changes, exports. Use it to answer "who changed this?" and for compliance. It cannot be edited.</p>
HTML,
],

[
    'id' => 'roles', 'title' => 'Roles and what they can do', 'icon' => 'shield_person', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'What each built-in role can see and change.',
    'body' => '__ROLES_TABLE__',
],

[
    'id' => 'wishboard', 'title' => 'Wish board: ask for a feature', 'icon' => 'lightbulb', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Suggest improvements and follow what happens to them.',
    'body' => <<<'HTML'
<p>The <strong>Wish board</strong> is where your clinic asks for new features or changes.</p>
<ol>
  <li>Press <strong>Submit a feature wish</strong>.</li>
  <li>Give it a short <strong>title</strong>, describe what you need and why in <strong>details</strong>, choose the <strong>area</strong> and how much it matters to you (<em>Low</em>, <em>Medium</em> or <em>High</em>).</li>
  <li>Press <strong>Submit wish</strong>.</li>
</ol>
<p>The developers set a <strong>status</strong> and, where they can, a <strong>target date</strong>, and reply with a note you can read. Use the status filter to follow your own wishes. Wishes are specific to your clinic.</p>
HTML,
],

[
    'id' => 'privacy', 'title' => 'Privacy and good habits', 'icon' => 'lock', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'How patient information is protected and what you can do to help.',
    'body' => <<<'HTML'
<p>Patient records are confidential. The app helps, but people make the difference.</p>
<ul>
  <li><strong>Your own login only.</strong> Never use or share someone else's. Every action is logged under the person signed in.</li>
  <li><strong>Lock up.</strong> Sign out when you leave a shared computer, and do not leave a chart open for others to see.</li>
  <li><strong>Only what you need.</strong> Open charts for the patients you are looking after, and no one else's.</li>
  <li><strong>Check before sending.</strong> Confirm the email address before emailing a document. A wrong address sends private information to a stranger.</li>
  <li><strong>Clinics are separate.</strong> You can only ever see the clinic you are signed in to. Clinical notes and vitals are stored encrypted.</li>
  <li><strong>Report problems.</strong> If you think someone else has your password or you opened the wrong record, tell your administrator at once.</li>
</ul>
HTML,
],

[
    'id' => 'troubleshooting', 'title' => 'Troubleshooting and questions', 'icon' => 'help', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Quick answers to the things people ask most.',
    'body' => <<<'HTML'
<dl class="g-faq">
<dt>A menu item or button is missing.</dt>
<dd>Your role does not include it, or your clinic has switched that area off. Check <a href="#roles">Roles</a> and ask your administrator.</dd>
<dt>"Session expired. Please log in again."</dt>
<dd>You were away too long or the browser was closed. Sign in again. Save long forms before stepping away.</dd>
<dt>The password code email never arrives.</dt>
<dd>Check spam. If it is not there, the clinic's mail account is not working: an administrator should open Administration → Email and send a test.</dd>
<dt>An email to a patient failed.</dt>
<dd>The message tells you why in plain words (wrong address, mail account refused the password, no mail server set up). Fix that, then send again.</dd>
<dt>A printout has no signature or stamp.</dt>
<dd>The doctor has not uploaded one. Settings → How you appear.</dd>
<dt>Google Drive keeps asking to reconnect.</dt>
<dd>See <a href="#documents">Documents</a>. An administrator reconnects once; if it repeats weekly, the Google Cloud app must be published (In production).</dd>
<dt>I registered a patient twice.</dt>
<dd>Tell your administrator; do not delete either chart yourself. Records with history need to be merged carefully.</dd>
<dt>An invoice is wrong.</dt>
<dd>Do not delete it. Issue a refund (transaction type Refund) and then a correct invoice.</dd>
<dt>The certificate number is not what I expected.</dt>
<dd>Numbers follow the clinic's format in Administration → Documents → Medical certificate. Numbers already issued never change.</dd>
<dt>Tables look cramped on my phone.</dt>
<dd>They turn into cards on narrow screens. Rotate the phone for more room, or use Settings to change spacing.</dd>
<dt>Pages feel slow.</dt>
<dd>Close tabs you are not using. Alerts are paused when a tab is in the background. Tell your administrator if it persists.</dd>
<dt>Where do I ask for something new?</dt>
<dd>On the <a href="#wishboard">Wish board</a>.</dd>
</dl>
HTML,
],

[
    'id' => 'glossary', 'title' => 'Glossary', 'icon' => 'menu_book', 'module' => null,
    'who' => ['Everyone'],
    'summary' => 'Short meanings for the terms used in the app.',
    'body' => <<<'HTML'
<dl class="g-faq">
<dt>MRN</dt><dd>Medical record number: the unique number given to each patient at registration.</dd>
<dt>SOAP</dt><dd>The structure of a visit note: Subjective, Objective, Assessment, Plan.</dd>
<dt>ICD-10</dt><dd>The international code list for diagnoses. Enter the code with the assessment.</dd>
<dt>Encounter</dt><dd>One visit by a patient: queue, vitals, notes, prescriptions, orders and the invoice.</dd>
<dt>FEFO</dt><dd>First-expired, first-out: stock that expires soonest is used first.</dd>
<dt>Batch</dt><dd>One delivery of an item, with its own batch number and expiry date.</dd>
<dt>VAT / tax label</dt><dd>A: 15% VAT. E: exempt. F: zero-rated. P: 0.25% levy.</dd>
<dt>Z-report</dt><dd>A summary of sales, refunds and VAT for a day or period.</dd>
<dt>Proforma</dt><dd>A quote; not a sale.</dd>
<dt>Refund (−)</dt><dd>A transaction that reverses an earlier sale.</dd>
<dt>Audit log</dt><dd>The permanent record of who did what and when.</dd>
<dt>Tenant / clinic</dt><dd>Each clinic using the system is separate; staff only see their own clinic.</dd>
</dl>
HTML,
],

];
