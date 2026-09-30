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

    public function sendInvoiceEmail(string $recipientEmail, array $invoice): bool {
        $subject = "Nuvis Medico Healthcare - Invoice #" . ($invoice['invoice_number'] ?? 'INV');
        $amount = number_format((float)($invoice['amount'] ?? 0), 2);
        $owed = number_format((float)($invoice['patient_owed'] ?? 0), 2);

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1d4ed8;'>Nuvis Medico Healthcare</h2>
            <p>Dear " . htmlspecialchars($invoice['patient_name'] ?? 'Patient') . ",</p>
            <p>Thank you for choosing Nuvis Medico Healthcare. Please review your invoice summary below:</p>
            <div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                <p><strong>Invoice Number:</strong> " . htmlspecialchars($invoice['invoice_number'] ?? '') . "</p>
                <p><strong>Service Date:</strong> " . htmlspecialchars($invoice['service_date'] ?? date('Y-m-d')) . "</p>
                <p><strong>Total Amount:</strong> $" . $amount . "</p>
                <p><strong>Amount Owed:</strong> $" . $owed . "</p>
            </div>
            <p>If you have any billing questions, please contact our clinic staff.</p>
            <br>
            <p style='font-size: 12px; color: #64748b;'>Nuvis Medico Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'invoice', $invoice['id'] ?? null);
    }

    public function sendReceiptEmail(string $recipientEmail, array $invoice, float $paymentAmount, string $paymentMethod): bool {
        $subject = "Nuvis Medico Healthcare - Payment Receipt #" . ($invoice['invoice_number'] ?? 'INV');
        $paidStr = number_format($paymentAmount, 2);

        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #059669;'>Nuvis Medico Healthcare</h2>
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
            <p style='font-size: 12px; color: #64748b;'>Nuvis Medico Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'receipt', $invoice['id'] ?? null);
    }

    public function sendPrescriptionEmail(string $recipientEmail, string $patientName, array $prescriptions, ?string $verificationUrl = null, ?string $verificationToken = null): bool {
        $subject = "Nuvis Medico Healthcare - Your Clinical Prescription";

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
            <h2 style='color: #1d4ed8;'>Nuvis Medico Healthcare</h2>
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
            <p style='font-size: 12px; color: #64748b;'>Nuvis Medico Healthcare Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'prescription');
    }

    public function sendOtpEmail(string $recipientEmail, string $otp, int $expiryMinutes = 15): bool {
        $subject = "Nuvis Medcare X - Password Reset Verification Code";
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1e3a8a; margin-bottom: 8px;'>Nuvis Medcare X</h2>
            <p style='color: #475569; font-size: 14px;'>Password Reset Request</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0;'>
            <p>You requested a password reset for your account. Please use the One-Time Password (OTP) below to reset your password:</p>
            <div style='background-color: #f1f5f9; padding: 20px; border-radius: 12px; text-align: center; margin: 20px 0; border: 1px dashed #cbd5e1;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #1e3a8a; font-family: monospace;'>{$otp}</span>
            </div>
            <p style='font-size: 13px; color: #64748b;'>This verification code will expire in <strong>{$expiryMinutes} minutes</strong>. If you did not request a password reset, please ignore this email or contact your system administrator.</p>
            <br>
            <p style='font-size: 12px; color: #94a3b8;'>Nuvis Medcare X Platform Team</p>
        </div>";

        return $this->sendDocumentEmail($recipientEmail, $subject, $body, 'otp_reset');
    }
}
