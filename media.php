<?php
/**
 * Serves a doctor's signature, stamp or profile photo from uploads/.
 *
 * uploads/ is closed to direct web access, so these pictures cannot be fetched
 * by guessing a URL. This script lets a signed-in member of the same clinic
 * load one that belongs to a clinician of that clinic, and nothing else.
 */
require_once __DIR__ . '/core/SessionBootstrap.php';
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    exit;
}

$path = (string) ($_GET['p'] ?? '');
if (!preg_match('#^uploads/(signatures|stamps|avatars)/[A-Za-z0-9_.-]+\.(png|jpe?g|webp|gif|svg)$#i', $path)
    || str_contains($path, '..')) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$tenantId = \ClinicFlow\Shared\TenantContext::getTenantId();
$st = $pdo->prepare(
    'SELECT 1 FROM doctors WHERE (tenant_id = ? OR tenant_id IS NULL)
        AND (esignature = ? OR digital_stamp = ? OR avatar = ?) LIMIT 1'
);
$st->execute([$tenantId, $path, $path, $path]);
$file = __DIR__ . '/' . $path;
if (!$st->fetchColumn() || !is_file($file)) {
    http_response_code(404);
    exit;
}

$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($file));
readfile($file);
