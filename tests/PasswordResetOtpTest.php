<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\EmailService;
use ClinicFlow\Services\MigrationRunner;
use ClinicFlow\Utils\Uuid;

class PasswordResetOtpTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/security.php';

        \executeAutoSchemaMigrations($this->pdo);
        $runner = new MigrationRunner($this->pdo);
        $runner->run();

        // Seed a test doctor
        $stmt = $this->pdo->prepare("INSERT INTO doctors (id, tenant_id, name, specialty, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'doc-test-1',
            'default-clinic',
            'Dr. Test User',
            'General Medicine',
            'testdoc@clinicflow.com',
            password_hash('OldPassword123', PASSWORD_DEFAULT),
            'Doctor',
            1
        ]);
    }

    public function testPasswordResetsTableExists(): void {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='password_resets'");
        $this->assertEquals('password_resets', $stmt->fetchColumn());
    }

    public function testEmailServiceSendOtpEmail(): void {
        $emailService = new EmailService($this->pdo);
        $result = $emailService->sendOtpEmail('testdoc@clinicflow.com', '654321', 15);
        $this->assertTrue($result);
    }

    public function testOtpGenerationAndValidation(): void {
        $email = 'testdoc@clinicflow.com';
        $otp = '123456';
        $expiry = date('Y-m-d H:i:s', time() + 900); // +15 mins
        $id = Uuid::uuidv7();

        $stmt = $this->pdo->prepare("INSERT INTO password_resets (id, email, otp, expires_at, used) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$id, $email, $otp, $expiry]);

        // Verify query finds valid OTP
        $now = date('Y-m-d H:i:s');
        $checkStmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND used = 0 AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
        $checkStmt->execute([$email, $otp, $now]);
        $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($record);
        $this->assertEquals($otp, $record['otp']);
        $this->assertEquals(0, (int)$record['used']);
    }

    public function testExpiredOtpRejection(): void {
        $email = 'testdoc@clinicflow.com';
        $otp = '999999';
        $expiredTime = date('Y-m-d H:i:s', time() - 300); // 5 mins ago
        $id = Uuid::uuidv7();

        $stmt = $this->pdo->prepare("INSERT INTO password_resets (id, email, otp, expires_at, used) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$id, $email, $otp, $expiredTime]);

        $now = date('Y-m-d H:i:s');
        $checkStmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND used = 0 AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
        $checkStmt->execute([$email, $otp, $now]);
        $record = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEmpty($record);
    }

    public function testSuccessfulPasswordResetProcess(): void {
        $email = 'testdoc@clinicflow.com';
        $otp = '888888';
        $expiry = date('Y-m-d H:i:s', time() + 900);
        $id = Uuid::uuidv7();

        // 1. Insert OTP
        $stmt = $this->pdo->prepare("INSERT INTO password_resets (id, email, otp, expires_at, used) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$id, $email, $otp, $expiry]);

        // 2. Perform Password Reset Logic
        $now = date('Y-m-d H:i:s');
        $otpStmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND used = 0 AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
        $otpStmt->execute([$email, $otp, $now]);
        $resetRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($resetRecord);

        // Update Doctor Password
        $newPassword = 'NewSecretPass123';
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $this->pdo->prepare("UPDATE doctors SET password_hash = ? WHERE LOWER(email) = LOWER(?)");
        $updateStmt->execute([$newHash, $email]);

        // Mark OTP as used
        $markStmt = $this->pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        $markStmt->execute([$resetRecord['id']]);

        // 3. Assert Doctor Password was changed
        $userStmt = $this->pdo->prepare("SELECT * FROM doctors WHERE LOWER(email) = LOWER(?)");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue(password_verify($newPassword, $user['password_hash']));
        $this->assertFalse(password_verify('OldPassword123', $user['password_hash']));

        // 4. Assert OTP cannot be reused
        $reuseStmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE LOWER(email) = LOWER(?) AND otp = ? AND used = 0 AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
        $reuseStmt->execute([$email, $otp, $now]);
        $this->assertEmpty($reuseStmt->fetch(PDO::FETCH_ASSOC));
    }
}
