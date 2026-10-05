<?php

namespace Tests;

use ClinicFlow\Services\FileUploadService;
use ClinicFlow\Services\MigrationRunner;
use ClinicFlow\Shared\TenantContext;
use PHPUnit\Framework\TestCase;
use PDO;

class FileUploadServiceTest extends TestCase
{
    private PDO $pdo;
    private FileUploadService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once __DIR__ . '/../config/database.php';
        \executeAutoSchemaMigrations($this->pdo);

        $runner = new MigrationRunner($this->pdo, dirname(__DIR__) . '/database/migrations');
        $runner->run();

        TenantContext::setTenantId('test-tenant-1');

        $this->tempDir = sys_get_temp_dir() . '/clinicflow_test_uploads_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->service = new FileUploadService($this->pdo, $this->tempDir);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($this->tempDir);
        }
    }

    public function testSaveAndGetCloudSettings(): void
    {
        $settings = [
            'cloud_onedrive_client_id' => 'od-client-123',
            'cloud_onedrive_client_secret' => 'od-secret-456',
            'cloud_onedrive_tenant_id' => 'od-tenant-789',
            'cloud_googledrive_client_id' => 'gd-client-123',
            'cloud_googledrive_api_key' => 'gd-key-456',
            'cloud_googledrive_folder_id' => 'gd-folder-789',
        ];

        $this->service->saveCloudSettings($settings);

        $retrieved = $this->service->getCloudSettings();

        $this->assertEquals('od-client-123', $retrieved['cloud_onedrive_client_id']);
        $this->assertEquals('od-secret-456', $retrieved['cloud_onedrive_client_secret']);
        $this->assertEquals('gd-client-123', $retrieved['cloud_googledrive_client_id']);
        $this->assertEquals('gd-folder-789', $retrieved['cloud_googledrive_folder_id']);
    }

    public function testUploadToServerAndRetrieve(): void
    {
        $dummyFilePath = sys_get_temp_dir() . '/test_doc.pdf';
        file_put_contents($dummyFilePath, 'Dummy PDF content for testing');

        $fileArray = [
            'name' => 'patient_report.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $dummyFilePath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($dummyFilePath),
        ];

        $uploaded = $this->service->uploadToServer($fileArray, null, 'Lab Results', 'Dr. Smith');

        $this->assertNotNull($uploaded);
        $this->assertEquals('patient_report.pdf', $uploaded['original_name']);
        $this->assertEquals('server', $uploaded['storage_provider']);
        $this->assertEquals('Lab Results', $uploaded['category']);
        $this->assertEquals('Dr. Smith', $uploaded['uploaded_by']);

        $list = $this->service->listFiles();
        $this->assertCount(1, $list);
        $this->assertEquals($uploaded['id'], $list[0]['id']);

        if (file_exists($dummyFilePath)) {
            unlink($dummyFilePath);
        }
    }

    public function testLinkOneDriveAndGoogleDriveFiles(): void
    {
        $odFile = $this->service->linkOneDriveFile('chest_xray.png', 'https://onedrive.live.com/file/123', 'od-123', null, 'X-Ray / Imaging', 1024, 'Dr. Jones');
        $this->assertEquals('onedrive', $odFile['storage_provider']);
        $this->assertEquals('https://onedrive.live.com/file/123', $odFile['external_url']);

        $gdFile = $this->service->linkGoogleDriveFile('blood_report.pdf', 'https://drive.google.com/file/d/456', 'gd-456', null, 'Lab Results', 2048, 'Dr. Jones');
        $this->assertEquals('googledrive', $gdFile['storage_provider']);
        $this->assertEquals('https://drive.google.com/file/d/456', $gdFile['external_url']);

        $allFiles = $this->service->listFiles();
        $this->assertCount(2, $allFiles);

        $odOnly = $this->service->listFiles(null, 'onedrive');
        $this->assertCount(1, $odOnly);
        $this->assertEquals('chest_xray.png', $odOnly[0]['original_name']);

        $gdOnly = $this->service->listFiles(null, 'googledrive');
        $this->assertCount(1, $gdOnly);
        $this->assertEquals('blood_report.pdf', $gdOnly[0]['original_name']);
    }

    public function testMultiTenantIsolationAndFileDelete(): void
    {
        $gdFile = $this->service->linkGoogleDriveFile('tenant1_doc.pdf', 'https://drive.google.com/file/d/1', 'gd-1');
        $this->assertCount(1, $this->service->listFiles());

        // Switch tenant context
        TenantContext::setTenantId('test-tenant-2');
        $this->assertCount(0, $this->service->listFiles());
        $this->assertNull($this->service->getFileById($gdFile['id']));

        // Switch back to original tenant
        TenantContext::setTenantId('test-tenant-1');
        $deleted = $this->service->deleteFile($gdFile['id']);
        $this->assertTrue($deleted);
        $this->assertCount(0, $this->service->listFiles());
    }
}
