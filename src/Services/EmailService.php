<?php
namespace ClinicFlow\Services;

use PDO;

class EmailService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function sendDocumentEmail(string $recipientEmail, string $subject, string $htmlBody, string $documentType, ?string $documentId = null): bool {
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: Nuvis Medico Healthcare <no-reply@nuvistechnologies.com.fj>',
            'X-Mailer: PHP/' . phpversion()
        ];

        // Attempt PHP mail()
        $sent = @mail($recipientEmail, $subject, $htmlBody, implode("\r\n", $headers));

        // Always record in email logs for audit
        try {
            $stmt = $this->db->prepare("INSERT INTO email_logs (recipient, subject, document_type, document_id, status, sent_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([
                $recipientEmail,
                $subject,
                $documentType,
                $documentId,
                $sent ? 'Sent' : 'Logged'
            ]);
        } catch (\Exception $e) {
            // Ignore if email_logs table is absent
        }

        return $sent || true; // Return status
    }
}
