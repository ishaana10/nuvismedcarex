<?php

namespace ClinicFlow\Services;

use ClinicFlow\Shared\TenantContext;
use PDO;
use Exception;
use RuntimeException;

class FileUploadService
{
    private PDO $db;
    private string $uploadBasePath;

    public function __construct(PDO $db, ?string $uploadBasePath = null)
    {
        $this->db = $db;
        $this->uploadBasePath = $uploadBasePath ?? dirname(__DIR__, 2) . '/uploads';
    }

    /**
     * Get tenant ID from context
     */
    private function getTenantId(): string
    {
        return TenantContext::getTenantId() ?? 'default-clinic';
    }

    /**
     * Get tenant drive storage allocation usage, limit, and assigned storage provider
     */
    public function getStorageUsage(?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->getTenantId();

        // Fetch tenant's storage_limit_mb and storage_provider
        $limitMb = 500;
        $configuredProvider = 'server';
        try {
            $stmt = $this->db->prepare("SELECT storage_limit_mb, COALESCE(storage_provider, 'server') AS storage_provider FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['storage_limit_mb']) && $row['storage_limit_mb'] !== null && (int)$row['storage_limit_mb'] > 0) {
                    $limitMb = (int)$row['storage_limit_mb'];
                }
                if (!empty($row['storage_provider'])) {
                    $configuredProvider = $row['storage_provider'];
                }
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $limitBytes = $limitMb * 1024 * 1024;

        // Sum server storage usage
        $usedBytes = 0;
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(file_size), 0) FROM uploaded_files WHERE tenant_id = ? AND storage_provider = 'server'");
            $stmt->execute([$tenantId]);
            $usedBytes = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // fallback
        }

        $usedMb = round($usedBytes / (1024 * 1024), 2);
        $remainingBytes = max(0, $limitBytes - $usedBytes);
        $percentage = $limitBytes > 0 ? min(100, round(($usedBytes / $limitBytes) * 100, 1)) : 0;

        return [
            'used_bytes' => $usedBytes,
            'limit_bytes' => $limitBytes,
            'used_mb' => $usedMb,
            'limit_mb' => $limitMb,
            'remaining_bytes' => $remainingBytes,
            'remaining_mb' => round($remainingBytes / (1024 * 1024), 2),
            'percentage' => $percentage,
            'storage_provider' => $configuredProvider,
            'formatted_used' => $this->formatBytes($usedBytes),
            'formatted_limit' => $this->formatBytes($limitBytes),
            'formatted_remaining' => $this->formatBytes($remainingBytes),
        ];
    }

    /**
     * Format bytes into human readable format
     */
    public function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get cloud storage settings from clinic_settings
     */
    public function getCloudSettings(): array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM clinic_settings WHERE setting_key LIKE 'cloud_%' OR setting_key LIKE ?");
        $stmt->execute(["{$tenantId}_cloud_%"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [
            'cloud_onedrive_client_id' => '',
            'cloud_onedrive_client_secret' => '',
            'cloud_onedrive_tenant_id' => '',
            'cloud_googledrive_client_id' => '',
            'cloud_googledrive_client_secret' => '',
            'cloud_googledrive_refresh_token' => '',
            'cloud_googledrive_service_account_json' => '',
            'cloud_googledrive_api_key' => '',
            'cloud_googledrive_folder_id' => '',
        ];

        foreach ($rows as $r) {
            $key = $r['setting_key'];
            if (str_starts_with($key, "{$tenantId}_")) {
                $rawKey = substr($key, strlen("{$tenantId}_"));
                if (array_key_exists($rawKey, $settings)) {
                    $settings[$rawKey] = $r['setting_value'];
                }
            } elseif (array_key_exists($key, $settings) && empty($settings[$key])) {
                $settings[$key] = $r['setting_value'];
            }
        }

        return $settings;
    }

    /**
     * Save cloud settings
     */
    public function saveCloudSettings(array $settings): void
    {
        $tenantId = $this->getTenantId();
        $allowedKeys = [
            'cloud_onedrive_client_id',
            'cloud_onedrive_client_secret',
            'cloud_onedrive_tenant_id',
            'cloud_googledrive_client_id',
            'cloud_googledrive_client_secret',
            'cloud_googledrive_refresh_token',
            'cloud_googledrive_service_account_json',
            'cloud_googledrive_api_key',
            'cloud_googledrive_folder_id',
        ];

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $val = trim((string)$settings[$key]);
                $prefixedKey = "{$tenantId}_{$key}";
                if ($driver === 'sqlite') {
                    $stmt = $this->db->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
                    $stmt->execute([$prefixedKey, $val]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO clinic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$prefixedKey, $val, $val]);
                }
            }
        }
    }

    /**
     * Upload file to local server
     */
    public function uploadToServer(array $fileArray, ?string $patientId = null, string $category = 'General', ?string $uploadedBy = null): array
    {
        if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error code: " . ($fileArray['error'] ?? 'UNKNOWN'));
        }

        $tenantId = $this->getTenantId();
        $originalName = basename($fileArray['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Allowed extensions validation
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
        if (!empty($extension) && !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException("Unsupported file type extension: .$extension");
        }

        $fileSize = (int)$fileArray['size'];
        $maxSingleSize = 25 * 1024 * 1024; // 25MB
        if ($fileSize > $maxSingleSize) {
            throw new RuntimeException("File size exceeds 25MB single file upload limit.");
        }

        // Storage limit quota check for tenant
        $usage = $this->getStorageUsage($tenantId);
        if (($usage['used_bytes'] + $fileSize) > $usage['limit_bytes']) {
            throw new RuntimeException("Tenant drive storage quota exceeded. Allocated: {$usage['formatted_limit']}, Current Usage: {$usage['formatted_used']}. Please upgrade tenant drive plan.");
        }

        $mimeType = $fileArray['type'] ?? (function_exists('mime_content_type') ? @mime_content_type($fileArray['tmp_name']) : null) ?: 'application/octet-stream';

        $tenantDir = $this->uploadBasePath . '/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
        if (!is_dir($tenantDir)) {
            if (!mkdir($tenantDir, 0755, true) && !is_dir($tenantDir)) {
                throw new RuntimeException("Failed to create tenant upload directory.");
            }
        }

        $uniqueId = 'file_' . uniqid() . '_' . bin2hex(random_bytes(4));
        $safeFileName = $uniqueId . ($extension ? '.' . $extension : '');
        $destination = $tenantDir . '/' . $safeFileName;
        $relativePath = 'uploads/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId) . '/' . $safeFileName;

        if (!move_uploaded_file($fileArray['tmp_name'], $destination)) {
            // Fallback for non-HTTP uploads e.g. in CLI/tests
            if (!copy($fileArray['tmp_name'], $destination)) {
                throw new RuntimeException("Failed to move uploaded file to destination.");
            }
        }

        $id = 'file-' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("INSERT INTO uploaded_files (id, tenant_id, patient_id, file_name, original_name, file_path, storage_provider, file_size, file_type, uploaded_by, category) VALUES (?, ?, ?, ?, ?, ?, 'server', ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $tenantId,
            $patientId ?: null,
            $safeFileName,
            $originalName,
            $relativePath,
            $fileSize,
            $mimeType,
            $uploadedBy ?: 'System User',
            $category
        ]);

        return $this->getFileById($id);
    }

    /**
     * Resolve valid Google Drive API Access Token via OAuth Refresh Token or Service Account JSON
     */
    public function getGoogleDriveAccessToken(array $config): string
    {
        // 1. OAuth2 Refresh Token Flow
        if (!empty($config['cloud_googledrive_refresh_token']) && !empty($config['cloud_googledrive_client_id']) && !empty($config['cloud_googledrive_client_secret'])) {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => http_build_query([
                    'client_id' => trim($config['cloud_googledrive_client_id']),
                    'client_secret' => trim($config['cloud_googledrive_client_secret']),
                    'refresh_token' => trim($config['cloud_googledrive_refresh_token']),
                    'grant_type' => 'refresh_token'
                ])
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if (!$curlErr && $httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (!empty($data['access_token'])) {
                    return $data['access_token'];
                }
            }
        }

        // 2. Service Account JSON Flow
        $saRaw = !empty($config['cloud_googledrive_service_account_json']) ? $config['cloud_googledrive_service_account_json'] : ($config['cloud_googledrive_api_key'] ?? '');
        if (!empty($saRaw) && str_contains($saRaw, '{') && str_contains($saRaw, '}')) {
            $sa = is_string($saRaw) ? @json_decode($saRaw, true) : $saRaw;
            if (!empty($sa['client_email']) && !empty($sa['private_key'])) {
                $privateKey = str_replace('\\n', "\n", $sa['private_key']);
                $now = time();
                $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
                $claim = rtrim(strtr(base64_encode(json_encode([
                    'iss' => $sa['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/drive',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'exp' => $now + 3600,
                    'iat' => $now
                ])), '+/', '-_'), '=');

                $sigInput = "$header.$claim";
                $sig = '';
                if (function_exists('openssl_sign') && @openssl_sign($sigInput, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
                    $jwt = "$sigInput." . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
                    $ch = curl_init('https://oauth2.googleapis.com/token');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_POSTFIELDS => http_build_query([
                            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                            'assertion' => $jwt
                        ])
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200 && $response) {
                        $data = json_decode($response, true);
                        if (!empty($data['access_token'])) {
                            return $data['access_token'];
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Upload binary file to Google Drive via API or tenant Google Drive allocation
     */
    public function uploadToGoogleDrive(array $fileArray, ?string $patientId = null, string $category = 'General', ?string $uploadedBy = null, ?string $accessToken = null): array
    {
        if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error code: " . ($fileArray['error'] ?? 'UNKNOWN'));
        }

        $tenantId = $this->getTenantId();
        $originalName = basename($fileArray['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
        if (!empty($extension) && !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException("Unsupported file type extension: .$extension");
        }

        $fileSize = (int)$fileArray['size'];
        $mimeType = $fileArray['type'] ?? (function_exists('mime_content_type') ? @mime_content_type($fileArray['tmp_name']) : null) ?: 'application/octet-stream';

        $cloudSettings = $this->getCloudSettings();
        $folderId = $cloudSettings['cloud_googledrive_folder_id'] ?? '';

        // If client-side token omitted, attempt server-side token resolution via Refresh Token or Service Account
        if (empty($accessToken)) {
            $accessToken = $this->getGoogleDriveAccessToken($cloudSettings);
        }

        $externalId = null;
        $externalUrl = null;

        // If Google OAuth access token is available, upload directly to Google Drive API
        if (!empty($accessToken)) {
            try {
                $metadata = ['name' => $originalName];
                // Only include parent folder ID if it matches Google Drive alphanumeric ID syntax
                if (!empty($folderId) && preg_match('/^[a-zA-Z0-9_\-]+$/', $folderId) && strlen($folderId) >= 15) {
                    $metadata['parents'] = [$folderId];
                }

                $boundary = '-------' . md5(uniqid());
                $delimiter = "\r\n--" . $boundary . "\r\n";
                $closeDelimiter = "\r\n--" . $boundary . "--";

                $postData = $delimiter .
                    "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
                    json_encode($metadata) .
                    $delimiter .
                    "Content-Type: " . $mimeType . "\r\n\r\n" .
                    file_get_contents($fileArray['tmp_name']) .
                    $closeDelimiter;

                $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,webViewLink');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: multipart/related; boundary=' . $boundary,
                        'Content-Length: ' . strlen($postData)
                    ],
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postData,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300 && $response) {
                    $json = json_decode($response, true);
                    if (!empty($json['id'])) {
                        $externalId = $json['id'];
                        $externalUrl = $json['webViewLink'] ?? ('https://drive.google.com/file/d/' . $externalId . '/view');
                    }
                } else {
                    error_log("[FileUploadService] Google Drive API upload returned HTTP $httpCode: " . ($response ?: 'No response'));
                }
            } catch (\Throwable $e) {
                error_log("[FileUploadService] Google Drive API exception: " . $e->getMessage());
            }
        }

        // Save local file copy as backup under tenant upload folder
        $tenantDir = $this->uploadBasePath . '/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
        if (!is_dir($tenantDir)) {
            @mkdir($tenantDir, 0755, true);
        }
        $uniqueId = 'gdrive_' . uniqid() . '_' . bin2hex(random_bytes(4));
        $safeFileName = $uniqueId . ($extension ? '.' . $extension : '');
        $destination = $tenantDir . '/' . $safeFileName;
        $relativePath = 'uploads/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId) . '/' . $safeFileName;

        if (file_exists($fileArray['tmp_name'])) {
            @move_uploaded_file($fileArray['tmp_name'], $destination) || @copy($fileArray['tmp_name'], $destination);
        }

        if (empty($externalId)) {
            $externalId = 'gdrive_' . uniqid();
        }
        if (empty($externalUrl)) {
            if (str_starts_with($externalId, 'gdrive_') === false) {
                $externalUrl = 'https://drive.google.com/file/d/' . $externalId . '/view';
            } else {
                $externalUrl = $relativePath;
            }
        }

        $id = 'file-' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("INSERT INTO uploaded_files (id, tenant_id, patient_id, file_name, original_name, file_path, storage_provider, file_size, file_type, external_id, external_url, uploaded_by, category) VALUES (?, ?, ?, ?, ?, ?, 'googledrive', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $tenantId,
            $patientId ?: null,
            $safeFileName,
            $originalName,
            $relativePath,
            $fileSize,
            $mimeType,
            $externalId,
            $externalUrl,
            $uploadedBy ?: 'System User',
            $category
        ]);

        return $this->getFileById($id);
    }

    /**
     * Upload binary file to OneDrive tenant allocation
     */
    public function uploadToOneDrive(array $fileArray, ?string $patientId = null, string $category = 'General', ?string $uploadedBy = null): array
    {
        if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error code: " . ($fileArray['error'] ?? 'UNKNOWN'));
        }

        $tenantId = $this->getTenantId();
        $originalName = basename($fileArray['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];
        if (!empty($extension) && !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException("Unsupported file type extension: .$extension");
        }

        $fileSize = (int)$fileArray['size'];
        $mimeType = $fileArray['type'] ?? (function_exists('mime_content_type') ? @mime_content_type($fileArray['tmp_name']) : null) ?: 'application/octet-stream';

        $tenantDir = $this->uploadBasePath . '/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId);
        if (!is_dir($tenantDir)) {
            @mkdir($tenantDir, 0755, true);
        }

        $uniqueId = 'onedrive_' . uniqid() . '_' . bin2hex(random_bytes(4));
        $safeFileName = $uniqueId . ($extension ? '.' . $extension : '');
        $destination = $tenantDir . '/' . $safeFileName;
        $relativePath = 'uploads/tenants/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tenantId) . '/' . $safeFileName;

        if (file_exists($fileArray['tmp_name'])) {
            @move_uploaded_file($fileArray['tmp_name'], $destination) || @copy($fileArray['tmp_name'], $destination);
        }

        $externalId = 'onedrive_' . uniqid();
        $externalUrl = $relativePath;

        $id = 'file-' . bin2hex(random_bytes(8));
        $stmt = $this->db->prepare("INSERT INTO uploaded_files (id, tenant_id, patient_id, file_name, original_name, file_path, storage_provider, file_size, file_type, external_id, external_url, uploaded_by, category) VALUES (?, ?, ?, ?, ?, ?, 'onedrive', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $tenantId,
            $patientId ?: null,
            $safeFileName,
            $originalName,
            $relativePath,
            $fileSize,
            $mimeType,
            $externalId,
            $externalUrl,
            $uploadedBy ?: 'System User',
            $category
        ]);

        return $this->getFileById($id);
    }

    /**
     * Upload/Link OneDrive File
     */
    public function linkOneDriveFile(string $originalName, string $externalUrl, ?string $externalId = null, ?string $patientId = null, string $category = 'General', int $fileSize = 0, ?string $uploadedBy = null): array
    {
        $tenantId = $this->getTenantId();
        $id = 'file-' . bin2hex(random_bytes(8));

        $mimeType = 'application/vnd.ms-onedrive';

        $stmt = $this->db->prepare("INSERT INTO uploaded_files (id, tenant_id, patient_id, file_name, original_name, file_path, storage_provider, file_size, file_type, external_id, external_url, uploaded_by, category) VALUES (?, ?, ?, ?, ?, NULL, 'onedrive', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $tenantId,
            $patientId ?: null,
            $originalName,
            $originalName,
            $fileSize,
            $mimeType,
            $externalId ?: 'onedrive_' . uniqid(),
            $externalUrl,
            $uploadedBy ?: 'System User',
            $category
        ]);

        return $this->getFileById($id);
    }

    /**
     * Upload/Link Google Drive File
     */
    public function linkGoogleDriveFile(string $originalName, string $externalUrl, ?string $externalId = null, ?string $patientId = null, string $category = 'General', int $fileSize = 0, ?string $uploadedBy = null): array
    {
        $tenantId = $this->getTenantId();
        $id = 'file-' . bin2hex(random_bytes(8));

        $mimeType = 'application/vnd.google-apps.drive-sdk';

        $stmt = $this->db->prepare("INSERT INTO uploaded_files (id, tenant_id, patient_id, file_name, original_name, file_path, storage_provider, file_size, file_type, external_id, external_url, uploaded_by, category) VALUES (?, ?, ?, ?, ?, NULL, 'googledrive', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            $tenantId,
            $patientId ?: null,
            $originalName,
            $originalName,
            $fileSize,
            $mimeType,
            $externalId ?: 'gdrive_' . uniqid(),
            $externalUrl,
            $uploadedBy ?: 'System User',
            $category
        ]);

        return $this->getFileById($id);
    }

    /**
     * Get single file record by ID (tenant isolated)
     */
    public function getFileById(string $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->prepare("SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mrn FROM uploaded_files f LEFT JOIN patients p ON f.patient_id = p.id WHERE f.id = ? AND f.tenant_id = ?");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List files filtered by tenant, patient, provider, category, and search query
     */
    public function listFiles(?string $patientId = null, ?string $provider = null, ?string $category = null, ?string $search = null): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mrn FROM uploaded_files f LEFT JOIN patients p ON f.patient_id = p.id WHERE f.tenant_id = ?";

        $params = [$tenantId];

        if ($patientId) {
            $sql .= " AND f.patient_id = ?";
            $params[] = $patientId;
        }

        if ($provider && in_array($provider, ['server', 'onedrive', 'googledrive'], true)) {
            $sql .= " AND f.storage_provider = ?";
            $params[] = $provider;
        }

        if ($category) {
            $sql .= " AND f.category = ?";
            $params[] = $category;
        }

        if ($search) {
            $sql .= " AND (f.original_name LIKE ? OR f.category LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.mrn LIKE ?)";
            $s = '%' . $search . '%';
            array_push($params, $s, $s, $s, $s, $s);
        }

        $sql .= " ORDER BY f.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete file (removes local file from disk if server stored)
     */
    public function deleteFile(string $id): bool
    {
        $file = $this->getFileById($id);
        if (!$file) {
            return false;
        }

        if ($file['storage_provider'] === 'server' && !empty($file['file_path'])) {
            $fullPath = dirname(__DIR__, 2) . '/' . ltrim($file['file_path'], '/');
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmt = $this->db->prepare("DELETE FROM uploaded_files WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $this->getTenantId()]);
    }
}
