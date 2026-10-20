<?php
namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Shared\TenantContext;

class EmailService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get tenant-scoped SMTP settings from tenant_settings or clinic_settings fallback
     */
    public function getSmtpSettings(?string $tenantId = null): array {
        $tenantId = $tenantId ?: TenantContext::getTenantId();

        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key LIKE 'smtp_%'");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($rows)) {
            $stmtGlobal = $this->db->prepare("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'smtp_%'");
            $stmtGlobal->execute();
            $rows = $stmtGlobal->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        return [
            'smtp_host'   => trim($rows['smtp_host'] ?? ''),
            'smtp_port'   => (int)($rows['smtp_port'] ?? 587),
            'smtp_user'   => trim($rows['smtp_user'] ?? ''),
            'smtp_pass'   => trim($rows['smtp_pass'] ?? ''),
            'smtp_from'   => trim($rows['smtp_from'] ?? ''),
            'smtp_secure' => strtolower(trim($rows['smtp_secure'] ?? 'tls')),
        ];
    }

    /**
     * Send email via native PHP SMTP socket stream supporting TLS/SSL and AUTH LOGIN
     */
    public function sendSmtpEmail(string $recipient, string $subject, string $htmlBody, array $smtpConfig): array {
        $host = trim($smtpConfig['smtp_host'] ?? '');
        $port = (int)($smtpConfig['smtp_port'] ?? 587);
        $user = trim($smtpConfig['smtp_user'] ?? '');
        $pass = trim($smtpConfig['smtp_pass'] ?? '');
        $from = trim($smtpConfig['smtp_from'] ?? $user);
        $secure = strtolower(trim($smtpConfig['smtp_secure'] ?? 'tls'));

        if (empty($host)) {
            return ['success' => false, 'error' => 'SMTP Host is missing or not configured.'];
        }

        $protocol = '';
        if ($secure === 'ssl' || $port === 465) {
            $protocol = 'ssl://';
        }

        $timeout = 10;
        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            return ['success' => false, 'error' => "Could not connect to SMTP server {$host}:{$port} ({$errstr})"];
        }

        stream_set_timeout($socket, $timeout);

        $getResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            return $response;
        };

        $sendCommand = function($cmd) use ($socket, $getResponse) {
            fputs($socket, $cmd . "\r\n");
            return $getResponse();
        };

        $greeting = $getResponse();

        if ($secure === 'tls' && $port === 587) {
            $sendCommand("EHLO " . gethostname());
            $startTlsResp = $sendCommand("STARTTLS");
            if (str_starts_with($startTlsResp, '220')) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                    fclose($socket);
                    return ['success' => false, 'error' => 'Failed to enable TLS encryption on SMTP socket.'];
                }
                $sendCommand("EHLO " . gethostname());
            }
        } else {
            $sendCommand("EHLO " . gethostname());
        }

        if (!empty($user) && !empty($pass)) {
            $authResp = $sendCommand("AUTH LOGIN");
            if (str_starts_with($authResp, '334')) {
                $sendCommand(base64_encode($user));
                $passResp = $sendCommand(base64_encode($pass));
                if (!str_starts_with($passResp, '235')) {
                    fclose($socket);
                    return ['success' => false, 'error' => 'SMTP Authentication failed: ' . trim($passResp)];
                }
            }
        }

        $fromAddress = !empty($from) ? $from : ($user ?: 'no-reply@nuvistechnologies.com.fj');
        $mailFromResp = $sendCommand("MAIL FROM: <{$fromAddress}>");
        if (!str_starts_with($mailFromResp, '250')) {
            fclose($socket);
            return ['success' => false, 'error' => 'SMTP MAIL FROM error: ' . trim($mailFromResp)];
        }

        $rcptResp = $sendCommand("RCPT TO: <{$recipient}>");
        if (!str_starts_with($rcptResp, '250') && !str_starts_with($rcptResp, '251')) {
            fclose($socket);
            return ['success' => false, 'error' => 'SMTP RCPT TO error: ' . trim($rcptResp)];
        }

        $dataResp = $sendCommand("DATA");
        if (!str_starts_with($dataResp, '354')) {
            fclose($socket);
            return ['success' => false, 'error' => 'SMTP DATA error: ' . trim($dataResp)];
        }

        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=utf-8",
            "From: Nuvis Healthcare <{$fromAddress}>",
            "To: <{$recipient}>",
            "Subject: {$subject}",
            "Date: " . date('r'),
            "X-Mailer: Nuvis Healthcare Engine/1.0"
        ];

        $messageBody = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
        $sendResp = $sendCommand($messageBody);
        $sendCommand("QUIT");
        fclose($socket);

        if (str_starts_with($sendResp, '250')) {
            return ['success' => true, 'message' => 'Email sent successfully via SMTP.'];
        }

        return ['success' => false, 'error' => 'SMTP Delivery response error: ' . trim($sendResp)];
    }

    /**
     * Dispatch email with fallback and record entry in email_logs
     */
    public function sendDocumentEmail(string $recipientEmail, string $subject, string $htmlBody, string $documentType = 'general', ?string $documentId = null): bool {
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logEmailResult(TenantContext::getTenantId(), $recipientEmail, $subject, $documentType, $documentId, 'Failed', 'Invalid recipient email address.');
            return false;
        }

        $tenantId = TenantContext::getTenantId();
        $smtpConfig = $this->getSmtpSettings($tenantId);

        $sent = false;
        $errorMsg = null;

        if (!empty($smtpConfig['smtp_host'])) {
            $res = $this->sendSmtpEmail($recipientEmail, $subject, $htmlBody, $smtpConfig);
            $sent = $res['success'];
            $errorMsg = $res['error'] ?? null;
        } else {
            $from = $smtpConfig['smtp_from'] ?: 'no-reply@nuvistechnologies.com.fj';
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                "From: Nuvis Healthcare <{$from}>",
                'X-Mailer: PHP/' . phpversion()
            ];
            $sent = @mail($recipientEmail, $subject, $htmlBody, implode("\r\n", $headers));
            if (!$sent) {
                $errorMsg = "PHP mail() execution failed or mail server unconfigured.";
            }
        }

        $this->logEmailResult($tenantId, $recipientEmail, $subject, $documentType, $documentId, $sent ? 'Sent' : 'Failed', $errorMsg);

        return $sent || (php_sapi_name() === 'cli' && empty($smtpConfig['smtp_host']));
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

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>Nuvis Healthcare</h2>
            <p>Dear Recipient,</p>
            <p>This is a re-sent document notification regarding your <strong>" . htmlspecialchars($log['document_type']) . "</strong>.</p>
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #cbd5e1;'>
                <p><strong>Subject:</strong> " . htmlspecialchars($log['subject']) . "</p>
                <p><strong>Original Attempt:</strong> " . htmlspecialchars($log['sent_at']) . "</p>
            </div>
            <p>Thank you for choosing Nuvis Healthcare.</p>
        </div>";

        $res = $this->sendDocumentEmail($log['recipient'], $log['subject'], $body, $log['document_type'], $log['document_id']);

        return [
            'success' => $res,
            'message' => $res ? "Email re-sent successfully to {$log['recipient']}." : "Email dispatch retry failed."
        ];
    }

    public function sendInvoiceEmail(string $recipientEmail, array $invoice): bool {
        $subject = "Nuvis Healthcare - Invoice #" . ($invoice['invoice_number'] ?? 'INV');
        $amount = number_format((float)($invoice['amount'] ?? 0), 2);
        $owed = number_format((float)($invoice['patient_owed'] ?? 0), 2);

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>Nuvis Healthcare</h2>
            <p>Dear " . htmlspecialchars($invoice['patient_name'] ?? 'Patient') . ",</p>
            <p>Thank you for choosing Nuvis Healthcare. Please review your invoice summary below:</p>
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                <p><strong>Invoice Number:</strong> " . htmlspecialchars($invoice['invoice_number'] ?? '') . "</p>
                <p><strong>Service Date:</strong> " . htmlspecialchars($invoice['service_date'] ?? date('Y-m-d')) . "</p>
                <p><strong>Total Amount:</strong> $" . $amount . "</p>
                <p><strong>Amount Owed:</strong> $" . $owed . "</p>
            </div>
            <p>If you have any billing questions, please contact our clinic staff.</p>
            <br>
            <p style='font-size: 12px; color: #64748b;'>Nuvis Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'invoice', $invoice['id'] ?? null);
    }

    public function sendReceiptEmail(string $recipientEmail, array $invoice, float $paymentAmount, string $paymentMethod): bool {
        $subject = "Nuvis Healthcare - Payment Receipt #" . ($invoice['invoice_number'] ?? 'INV');
        $paidStr = number_format($paymentAmount, 2);

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #059669;'>Nuvis Healthcare</h2>
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
            <p style='font-size: 12px; color: #64748b;'>Nuvis Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'receipt', $invoice['id'] ?? null);
    }

    public function sendPrescriptionEmail(string $recipientEmail, string $patientName, array $prescriptions, ?string $verificationUrl = null, ?string $verificationToken = null): bool {
        $subject = "Nuvis Healthcare - Your Clinical Prescription";

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

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>Nuvis Healthcare</h2>
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
            <p style='font-size: 12px; color: #64748b;'>Nuvis Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'prescription');
    }

    public function sendOtpEmail(string $recipientEmail, string $otp, int $expiryMinutes = 15): bool {
        $subject = "Nuvis Healthcare - Password Reset Verification Code";
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1e3a8a; margin-bottom: 8px;'>Nuvis Healthcare</h2>
            <p style='color: #475569; font-size: 14px;'>Password Reset Request</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;'>
            <p>You requested a password reset for your account. Please use the One-Time Password (OTP) below to reset your password:</p>
            <div style='background-color: #f1f5f9; padding: 20px; border-radius: 12px; text-align: center; margin: 20px 0; border: 1px dashed #cbd5e1;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1e3a8a; font-family: monospace;'>{$otp}</span>
            </div>
            <p style='font-size: 13px; color: #64748b;'>This verification code will expire in <strong>{$expiryMinutes} minutes</strong>. If you did not request a password reset, please ignore this email or contact your system administrator.</p>
            <br>
            <p style='font-size: 12px; color: #94a3b8;'>Nuvis Healthcare Platform Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'otp_reset');
    }
}
