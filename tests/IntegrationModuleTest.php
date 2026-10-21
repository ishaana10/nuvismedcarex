<?php

namespace ClinicFlow\Tests;

use PHPUnit\Framework\TestCase;
use PDO;
use ClinicFlow\Services\WebhookService;

class IntegrationModuleTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id VARCHAR(50) PRIMARY KEY,
            tenant_id VARCHAR(50) NOT NULL,
            user_id VARCHAR(50),
            user_name VARCHAR(100),
            user_role VARCHAR(50),
            patient_id VARCHAR(50) DEFAULT NULL,
            action VARCHAR(100),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS webhook_subscriptions (
            id VARCHAR(64) PRIMARY KEY,
            tenant_id VARCHAR(64) NOT NULL DEFAULT 'default-clinic',
            app_name VARCHAR(255) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            target_url TEXT NOT NULL,
            secret_token VARCHAR(255) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_integrations (
            id VARCHAR(64) PRIMARY KEY,
            tenant_id VARCHAR(64) NOT NULL DEFAULT 'default-clinic',
            app_name VARCHAR(255) NOT NULL,
            api_key VARCHAR(128) NOT NULL UNIQUE,
            permissions TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            last_used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testWebhookSubscriptionManagementAndDispatch(): void {
        $webhookService = new WebhookService($this->pdo);

        $subId = $webhookService->saveSubscription('Lab Partner App', 'LAB_ORDER_SUBMITTED', 'https://lab.partner.com/webhook', 'secret-123', null, 'clinic-x');
        $this->assertNotEmpty($subId);

        $subscriptions = $webhookService->getSubscriptions('clinic-x');
        $this->assertCount(1, $subscriptions);
        $this->assertEquals('Lab Partner App', $subscriptions[0]['app_name']);
        $this->assertEquals('LAB_ORDER_SUBMITTED', $subscriptions[0]['event_type']);

        $dispatchResult = $webhookService->dispatch('LAB_ORDER_SUBMITTED', ['order_id' => 'lab-101'], null, 'clinic-x');
        $this->assertTrue($dispatchResult['success']);
        $this->assertCount(1, $dispatchResult['dispatches']);
        $this->assertEquals('https://lab.partner.com/webhook', $dispatchResult['dispatches'][0]['target_url']);

        $deleted = $webhookService->deleteSubscription($subId, 'clinic-x');
        $this->assertTrue($deleted);
        $this->assertEmpty($webhookService->getSubscriptions('clinic-x'));
    }

    public function testAppIntegrationsApiKeyStorage(): void {
        $stmt = $this->pdo->prepare("INSERT INTO app_integrations (id, tenant_id, app_name, api_key, permissions) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['key-1', 'clinic-x', 'External EHR Portal', 'cf_live_abc123xyz', 'read,write']);

        $stmtSel = $this->pdo->prepare("SELECT * FROM app_integrations WHERE tenant_id = ?");
        $stmtSel->execute(['clinic-x']);
        $keys = $stmtSel->fetchAll();

        $this->assertCount(1, $keys);
        $this->assertEquals('External EHR Portal', $keys[0]['app_name']);
        $this->assertEquals('cf_live_abc123xyz', $keys[0]['api_key']);
    }
}
