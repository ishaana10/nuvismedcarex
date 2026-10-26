<?php
namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\ClinicSettings;

class EmailService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Ready-made settings for the mail providers clinics actually use, so a
     * clinic only has to supply its address and password.
     */
    public const PROVIDERS = [
        'gmail' => [
            'label' => 'Gmail / Google Workspace',
            'host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls',
            'help' => 'Google requires an App password, not your normal password. Turn on 2-Step Verification, then create one under Google Account → Security → App passwords.',
        ],
        'outlook' => [
            'label' => 'Outlook / Microsoft 365',
            'host' => 'smtp-mail.outlook.com', 'port' => 587, 'secure' => 'tls',
            'help' => 'Use the mailbox address and its password. If the account has 2-step verification, create an app password first.',
        ],
        'yahoo' => [
            'label' => 'Yahoo Mail',
            'host' => 'smtp.mail.yahoo.com', 'port' => 587, 'secure' => 'tls',
            'help' => 'Yahoo requires an app password, created under Account security → Generate app password.',
        ],
        'zoho' => [
            'label' => 'Zoho Mail',
            'host' => 'smtp.zoho.com', 'port' => 587, 'secure' => 'tls',
            'help' => 'Use your Zoho address and password, or an app password if two-factor sign-in is on.',
        ],
        'custom' => [
            'label' => 'Another provider',
            'host' => '', 'port' => 587, 'secure' => 'tls',
            'help' => 'Enter the outgoing (SMTP) server details your email provider or web host gave you.',
        ],
    ];

    /**
     * This clinic's mail settings, falling back per key to the installation
     * defaults — so a clinic that only overrides the from-address keeps the
     * shared server details.
     */
    public function getSmtpSettings(?string $tenantId = null): array {
        $tenantId = $tenantId ?: TenantContext::getTenantId();
        $rows = (new ClinicSettings($this->db, $tenantId))->all();

        $port = (int) ($rows['smtp_port'] ?? 0);
        $secure = strtolower(trim((string) ($rows['smtp_secure'] ?? '')));
        if ($secure === '') {
            $secure = $port === 465 ? 'ssl' : 'tls';
        }
        if ($port === 0) {
            $port = $secure === 'ssl' ? 465 : 587;
        }

        return [
            'smtp_provider' => trim((string) ($rows['smtp_provider'] ?? 'custom')),
            'smtp_host'   => trim((string) ($rows['smtp_host'] ?? '')),
            'smtp_port'   => $port,
            'smtp_user'   => trim((string) ($rows['smtp_user'] ?? '')),
            'smtp_pass'   => (string) ($rows['smtp_pass'] ?? ''),
            'smtp_from'   => trim((string) ($rows['smtp_from'] ?? '')),
            'smtp_secure' => $secure,
            'smtp_from_name' => trim((string) ($rows['smtp_from_name'] ?? '')),
            'email_reply_to' => trim((string) ($rows['email_reply_to'] ?? '')),
        ];
    }

    /** The clinic's name, for subjects and templates. */
    private function clinicName(?string $tenantId = null): string {
        try {
            return (new ClinicSettings($this->db, $tenantId ?: TenantContext::getTenantId()))->profile()['name'];
        } catch (\Throwable $e) {
            return 'Your clinic';
        }
    }

    /** Is this clinic able to send email at all? */
    public function isConfigured(?string $tenantId = null): bool {
        $cfg = $this->getSmtpSettings($tenantId);
        return $cfg['smtp_host'] !== '' && $cfg['smtp_user'] !== '';
    }

    /**
     * Send one message over SMTP.
     *
     * Talks SMTP directly so it works on any host, including those with no
     * local mail server and those that block PHP's mail(). STARTTLS is used
     * whenever the server offers it, which is what Gmail, Outlook, Yahoo and
     * Zoho all require.
     *
     * @return array{success:bool, error?:string, message?:string, transcript?:string}
     */
    public function sendSmtpEmail(string $recipient, string $subject, string $htmlBody, array $smtpConfig): array {
        $host = trim($smtpConfig['smtp_host'] ?? '');
        $port = (int) ($smtpConfig['smtp_port'] ?? 587);
        $user = trim($smtpConfig['smtp_user'] ?? '');
        $pass = (string) ($smtpConfig['smtp_pass'] ?? '');
        $from = trim($smtpConfig['smtp_from'] ?? '') ?: $user;
        $fromName = trim($smtpConfig['smtp_from_name'] ?? '');
        $replyTo = trim($smtpConfig['email_reply_to'] ?? '');
        $secure = strtolower(trim($smtpConfig['smtp_secure'] ?? 'tls'));

        if ($host === '') {
            return ['success' => false, 'error' => 'No outgoing mail server is set up for this clinic yet.'];
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'That recipient address is not valid.'];
        }

        $transcript = [];
        $timeout = 15;
        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            return ['success' => false, 'error' => $this->friendlyConnectError($host, $port, $secure, (string) $errstr)];
        }
        stream_set_timeout($socket, $timeout);

        $read = function () use ($socket, &$transcript): string {
            $response = '';
            while (($line = fgets($socket, 1024)) !== false) {
                $response .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break;
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) break;
            }
            $transcript[] = '< ' . trim($response);
            return $response;
        };
        $write = function (string $cmd, bool $secret = false) use ($socket, &$transcript, $read): string {
            $transcript[] = '> ' . ($secret ? '***' : $cmd);
            fwrite($socket, $cmd . "\r\n");
            return $read();
        };
        $fail = function (string $message) use ($socket, &$transcript): array {
            @fclose($socket);
            return ['success' => false, 'error' => $message, 'transcript' => implode("\n", array_slice($transcript, -12))];
        };

        $greeting = $read();
        if (!str_starts_with($greeting, '220')) {
            return $fail('The mail server refused the connection: ' . trim($greeting));
        }

        $helo = 'nuvis-medcare-x.local';
        $ehlo = $write('EHLO ' . $helo);
        if (!str_starts_with($ehlo, '250')) {
            $ehlo = $write('HELO ' . $helo);
            if (!str_starts_with($ehlo, '250')) {
                return $fail('The mail server rejected the greeting: ' . trim($ehlo));
            }
        }

        // Upgrade to TLS whenever the server offers it and we are not already encrypted
        if ($secure !== 'ssl' && $secure !== 'none' && stripos($ehlo, 'STARTTLS') !== false) {
            $resp = $write('STARTTLS');
            if (!str_starts_with($resp, '220')) {
                return $fail('The mail server would not start an encrypted session: ' . trim($resp));
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                return $fail('Could not set up an encrypted connection to ' . $host . '. Check the port and encryption setting.');
            }
            $ehlo = $write('EHLO ' . $helo);
        } elseif ($secure === 'tls' && stripos($ehlo, 'STARTTLS') === false) {
            return $fail('This server does not offer encryption on port ' . $port . '. Try port 587, or choose "No encryption" only on a trusted local server.');
        }

        if ($user !== '' && $pass !== '') {
            $authed = false;
            if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') !== false) {
                $resp = $write('AUTH LOGIN');
                if (str_starts_with($resp, '334')) {
                    $write(base64_encode($user), true);
                    $resp = $write(base64_encode($pass), true);
                    $authed = str_starts_with($resp, '235');
                }
            }
            if (!$authed && stripos($ehlo, 'PLAIN') !== false) {
                $resp = $write('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), true);
                $authed = str_starts_with($resp, '235');
            }
            if (!$authed) {
                return $fail($this->friendlyAuthError($host, $resp ?? ''));
            }
        }

        $resp = $write('MAIL FROM:<' . $from . '>');
        if (!str_starts_with($resp, '250')) {
            return $fail('The server rejected the sender address ' . $from . ': ' . trim($resp)
                . ' Most providers require the sender to be the same mailbox you signed in with.');
        }

        $resp = $write('RCPT TO:<' . $recipient . '>');
        if (!str_starts_with($resp, '250') && !str_starts_with($resp, '251')) {
            return $fail('The server rejected the recipient ' . $recipient . ': ' . trim($resp));
        }

        $resp = $write('DATA');
        if (!str_starts_with($resp, '354')) {
            return $fail('The server would not accept the message: ' . trim($resp));
        }

        $message = $this->buildMessage($recipient, $subject, $htmlBody, $from, $fromName, $replyTo, $host);
        fwrite($socket, $message . "\r\n.\r\n");
        $transcript[] = '> [message body]';
        $resp = $read();
        $write('QUIT');
        @fclose($socket);

        if (str_starts_with($resp, '250')) {
            return ['success' => true, 'message' => 'Sent.', 'transcript' => implode("\n", array_slice($transcript, -12))];
        }
        return ['success' => false, 'error' => 'The server did not accept the message: ' . trim($resp), 'transcript' => implode("\n", array_slice($transcript, -12))];
    }

    /** RFC-compliant message: encoded headers, and body lines starting with "." doubled. */
    private function buildMessage(string $recipient, string $subject, string $htmlBody, string $from, string $fromName, string $replyTo, string $host): string {
        $encode = static fn(string $v): string => '=?UTF-8?B?' . base64_encode($v) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'From: ' . ($fromName !== '' ? $encode($fromName) . ' ' : '') . '<' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Nuvis Medcare X',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }

        // Normalise line endings, then dot-stuff so a line of "." cannot end the message early
        $body = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $htmlBody);
        $body = preg_replace('/^\./m', '..', $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function friendlyConnectError(string $host, int $port, string $secure, string $errstr): string {
        $hint = 'Check the server name and port with your email provider.';
        if (stripos($errstr, 'timed out') !== false || $errstr === '') {
            $hint = 'The connection timed out. Some web hosts block outgoing mail ports — ask your host to allow port ' . $port . ', or try port 465 with SSL.';
        } elseif (stripos($errstr, 'refused') !== false) {
            $hint = 'Nothing is listening on that port. Try 587 with TLS, or 465 with SSL.';
        }
        return "Could not reach {$host} on port {$port}. {$hint}";
    }

    private function friendlyAuthError(string $host, string $resp): string {
        $base = 'The mail server would not accept that username and password.';
        if (stripos($host, 'gmail') !== false || stripos($host, 'google') !== false) {
            return $base . ' Gmail needs an App password rather than your normal password — create one under Google Account → Security → App passwords.';
        }
        if (stripos($host, 'outlook') !== false || stripos($host, 'office365') !== false) {
            return $base . ' Microsoft accounts with two-step verification need an app password.';
        }
        if (stripos($host, 'yahoo') !== false) {
            return $base . ' Yahoo requires an app password generated in Account security.';
        }
        return $base . ' ' . trim($resp);
    }

    /** Try the settings without sending anything to a patient. */
    public function testSettings(string $recipient, array $smtpConfig, string $clinicName = ''): array {
        $body = '<div style="font-family:system-ui,sans-serif;max-width:560px">'
              . '<h2 style="margin:0 0 8px">Your email set-up works</h2>'
              . '<p>This is a test message from ' . htmlspecialchars($clinicName !== '' ? $clinicName : 'your clinic') . '.</p>'
              . '<p style="color:#64748b;font-size:13px">Receipts, invoices and prescriptions sent to patients will come from this address.</p>'
              . '</div>';
        return $this->sendSmtpEmail($recipient, 'Test message from ' . ($clinicName ?: 'Nuvis Medcare X'), $body, $smtpConfig);
    }

    /**
     * Dispatch email with fallback and record entry in email_logs
     */
    public function sendDocumentEmail(string $recipientEmail, string $subject, string $htmlBody, string $documentType = 'general', ?string $documentId = null): bool {
        $tenantId = TenantContext::getTenantId();

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logEmailResult($tenantId, (string) $recipientEmail, $subject, $documentType, $documentId, 'Failed', 'That email address is not valid.');
            return false;
        }

        $smtpConfig = $this->getSmtpSettings($tenantId);

        // Send as the clinic, so patients recognise who the message is from
        if (($smtpConfig['smtp_from_name'] ?? '') === '') {
            try {
                $smtpConfig['smtp_from_name'] = (new ClinicSettings($this->db, $tenantId))->profile()['name'];
            } catch (\Throwable $e) {}
        }

        if ($smtpConfig['smtp_host'] === '') {
            $this->logEmailResult($tenantId, $recipientEmail, $subject, $documentType, $documentId, 'Failed',
                'No outgoing mail server is set up for this clinic. Add one in Administration → Email.');
            return false;
        }

        $res = $this->sendSmtpEmail($recipientEmail, $subject, $htmlBody, $smtpConfig);
        $this->logEmailResult(
            $tenantId, $recipientEmail, $subject, $documentType, $documentId,
            $res['success'] ? 'Sent' : 'Failed',
            $res['success'] ? null : ($res['error'] ?? 'Unknown error')
        );
        return (bool) $res['success'];
    }

    /**
     * Log email result into email_logs table
     */
    public function logEmailResult(string $tenantId, string $recipient, string $subject, string $documentType, ?string $documentId, string $status, ?string $errorMessage = null): string {
        $id = 'elog-' . bin2hex(random_bytes(8));
        $effectiveTenantId = !empty($tenantId) ? $tenantId : (TenantContext::getTenantId() ?: 'default-clinic');

        try {
            $stmt = $this->db->prepare("INSERT INTO email_logs (id, tenant_id, recipient, subject, document_type, document_id, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([
                $id,
                $effectiveTenantId,
                $recipient,
                $subject,
                $documentType,
                $documentId,
                $status,
                $errorMessage
            ]);
        } catch (\Exception $e) {
            // Auto-create email_logs table if missing and retry
            try {
                $this->db->exec("CREATE TABLE IF NOT EXISTS email_logs (
                    id VARCHAR(64) PRIMARY KEY,
                    tenant_id VARCHAR(64) NOT NULL DEFAULT 'default-clinic',
                    recipient VARCHAR(255) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    document_type VARCHAR(64) DEFAULT 'general',
                    document_id VARCHAR(64) NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'Pending',
                    error_message TEXT NULL,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $stmt = $this->db->prepare("INSERT INTO email_logs (id, tenant_id, recipient, subject, document_type, document_id, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $stmt->execute([
                    $id,
                    $effectiveTenantId,
                    $recipient,
                    $subject,
                    $documentType,
                    $documentId,
                    $status,
                    $errorMessage
                ]);
            } catch (\Exception $ex) {
                error_log("[EmailService logEmailResult] Error inserting email log: " . $ex->getMessage());
            }
        }
        return $id;
    }

    /**
     * Query tenant email logs
     */
    public function getEmailLogs(string $tenantId, int $limit = 25, int $offset = 0, ?string $statusFilter = null, ?string $search = null): array {
        $where = ["tenant_id = ?"];
        $params = [$tenantId];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $where[] = "status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($search)) {
            $where[] = "(recipient LIKE ? OR subject LIKE ? OR document_type LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(" AND ", $where);
        $sql = "SELECT * FROM email_logs WHERE {$whereClause} ORDER BY sent_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get email logs count
     */
    public function getEmailLogsCount(string $tenantId, ?string $statusFilter = null, ?string $search = null): int {
        $where = ["tenant_id = ?"];
        $params = [$tenantId];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $where[] = "status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($search)) {
            $where[] = "(recipient LIKE ? OR subject LIKE ? OR document_type LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(" AND ", $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM email_logs WHERE {$whereClause}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Resend failed email log record
     */
    public function resendEmailLog(string $logId): array {
        $tenantId = TenantContext::getTenantId();
        $stmt = $this->db->prepare("SELECT * FROM email_logs WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$logId, $tenantId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            return ['success' => false, 'error' => 'Email log record not found.'];
        }

        $clinic = $this->clinicName();
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>" . htmlspecialchars($clinic) . "</h2>
            <p>Dear Recipient,</p>
            <p>This is a re-sent document notification regarding your <strong>" . htmlspecialchars($log['document_type']) . "</strong>.</p>
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #cbd5e1;'>
                <p><strong>Subject:</strong> " . htmlspecialchars($log['subject']) . "</p>
                <p><strong>Original Attempt:</strong> " . htmlspecialchars($log['sent_at']) . "</p>
            </div>
            <p>Thank you for choosing " . htmlspecialchars($clinic) . ".</p>
        </div>";

        $res = $this->sendDocumentEmail($log['recipient'], $log['subject'], $body, $log['document_type'], $log['document_id']);

        return [
            'success' => $res,
            'message' => $res ? "Email re-sent successfully to {$log['recipient']}." : "Email dispatch retry failed."
        ];
    }

    public function sendInvoiceEmail(string $recipientEmail, array $invoice): bool {
        $clinic = $this->clinicName();
        $subject = $clinic . ' - Invoice #' . ($invoice['invoice_number'] ?? 'INV');
        $amount = number_format((float)($invoice['amount'] ?? 0), 2);
        $owed = number_format((float)($invoice['patient_owed'] ?? 0), 2);

        $clinic = $this->clinicName();
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>" . htmlspecialchars($clinic) . "</h2>
            <p>Dear " . htmlspecialchars($invoice['patient_name'] ?? 'Patient') . ",</p>
            <p>Thank you for choosing " . htmlspecialchars($clinic) . ". Please review your invoice summary below:</p>
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                <p><strong>Invoice Number:</strong> " . htmlspecialchars($invoice['invoice_number'] ?? '') . "</p>
                <p><strong>Service Date:</strong> " . htmlspecialchars($invoice['service_date'] ?? date('Y-m-d')) . "</p>
                <p><strong>Total Amount:</strong> $" . $amount . "</p>
                <p><strong>Amount Owed:</strong> $" . $owed . "</p>
            </div>
            <p>If you have any billing questions, please contact our clinic staff.</p>
            <br>
            <p style='font-size: 12px; color: #64748b;'>" . htmlspecialchars($clinic) . "</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'invoice', $invoice['id'] ?? null);
    }

    public function sendReceiptEmail(string $recipientEmail, array $invoice, float $paymentAmount, string $paymentMethod): bool {
        $clinic = $this->clinicName();
        $subject = $clinic . ' - Payment receipt #' . ($invoice['invoice_number'] ?? 'INV');
        $paidStr = number_format($paymentAmount, 2);

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #059669;'>" . htmlspecialchars($clinic) . "</h2>
            <p>Dear " . htmlspecialchars($invoice['patient_name'] ?? 'Patient') . ",</p>
            <p>We have received your payment. Here is your official payment receipt details:</p>
            <div style='background-color: #f0fdf4; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #bbf7d0;'>
                <p><strong>Invoice Number:</strong> " . htmlspecialchars($invoice['invoice_number'] ?? '') . "</p>
                <p><strong>Amount Paid:</strong> $" . $paidStr . "</p>
                <p><strong>Payment Method:</strong> " . htmlspecialchars($paymentMethod) . "</p>
                <p><strong>Date:</strong> " . date('F j, Y g:i A') . "</p>
            </div>
            <p>Thank you for your prompt payment.</p>
            <br>
            <p style='font-size: 12px; color: #64748b;'>" . htmlspecialchars($clinic) . "</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'receipt', $invoice['id'] ?? null);
    }

    public function sendPrescriptionEmail(string $recipientEmail, string $patientName, array $prescriptions, ?string $verificationUrl = null, ?string $verificationToken = null): bool {
        $clinic = $this->clinicName();
        $subject = $clinic . ' - Your prescription';

        $rxItems = "";
        foreach ($prescriptions as $rx) {
            $rxItems .= "<li style='margin-bottom: 8px;'><strong>" . htmlspecialchars($rx['medication_name'] ?? '') . "</strong> (" . htmlspecialchars($rx['dosage'] ?? '') . ") - " . htmlspecialchars($rx['frequency'] ?? '') . " for " . htmlspecialchars($rx['duration'] ?? '') . "<br><small style='color: #475569;'>" . htmlspecialchars($rx['instructions'] ?? '') . "</small></li>";
        }

        $qrSection = "";
        if (!empty($verificationUrl)) {
            $qrSection = "
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #e2e8f0; text-align: center;'>
                <p style='margin: 0 0 10px 0; font-size: 13px; font-weight: bold; color: #1e293b;'>Pharmacy Verification QR Code</p>
                <img src='https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=" . urlencode($verificationUrl) . "' alt='QR Verification' style='width: 140px; height: 140px; border-radius: 6px; border: 1px solid #cbd5e1;'>
                <p style='margin: 8px 0 0 0; font-size: 11px; font-family: monospace; color: #475569;'>Token: " . htmlspecialchars($verificationToken ?? '') . "</p>
                <p style='margin: 4px 0 0 0; font-size: 12px;'><a href='" . htmlspecialchars($verificationUrl) . "' style='color: #2563eb; text-decoration: underline;'>Verify Prescription Online</a></p>
            </div>";
        }

        $clinic = $this->clinicName();
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>" . htmlspecialchars($clinic) . "</h2>
            <p>Dear " . htmlspecialchars($patientName) . ",</p>
            <p>Your attending physician has issued the following prescription for your clinical visit:</p>
            <div style='background-color: #eff6ff; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #bfdbfe;'>
                <ul style='padding-left: 20px;'>
                    " . $rxItems . "
                </ul>
            </div>
            " . $qrSection . "
            <p>Please follow your physician's instructions carefully.</p>
            <br>
            <p style='font-size: 12px; color: #64748b;'>" . htmlspecialchars($clinic) . "</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'prescription');
    }

    public function sendOtpEmail(string $recipientEmail, string $otp, int $expiryMinutes = 15): bool {
        $clinic = $this->clinicName();
        $subject = $clinic . ' - Password reset code';
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1e3a8a; margin-bottom: 8px;'>" . htmlspecialchars($clinic) . "</h2>
            <p style='color: #475569; font-size: 14px;'>Password Reset Request</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;'>
            <p>You requested a password reset for your account. Please use the One-Time Password (OTP) below to reset your password:</p>
            <div style='background-color: #f1f5f9; padding: 20px; border-radius: 12px; text-align: center; margin: 20px 0; border: 1px dashed #cbd5e1;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1e3a8a; font-family: monospace;'>{$otp}</span>
            </div>
            <p style='font-size: 13px; color: #64748b;'>This verification code will expire in <strong>{$expiryMinutes} minutes</strong>. If you did not request a password reset, please ignore this email or contact your system administrator.</p>
            <br>
            <p style='font-size: 12px; color: #94a3b8;'>" . htmlspecialchars($clinic) . "</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'otp_reset');
    }
}
