<?php
/**
 * Save only the signed-in person's colour theme (header toggle).
 * Everything else in their preferences is left exactly as it was.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\UserPreferences;

header('Content-Type: application/json');

$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
};

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
    $fail(401, 'Sign in again.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail(405, 'POST only.');
}
$body = json_decode((string) file_get_contents('php://input'), true);
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($token) ? $token : null)) {
    $fail(403, 'Invalid or missing CSRF token.');
}

$theme = is_array($body) ? ($body['theme'] ?? '') : ($_POST['theme'] ?? '');
if (!is_string($theme) || !isset(UserPreferences::THEMES[$theme])) {
    $fail(422, 'Unknown theme.');
}

try {
    $svc = new UserPreferences(getDB());
    $userId = (string) $_SESSION['user_id'];
    $current = $svc->load($userId);
    $svc->save($userId, UserPreferences::sanitise(array_merge($current, ['theme' => $theme]), $current));
    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (\Throwable $e) {
    error_log('[theme_save] ' . $e->getMessage());
    $fail(500, 'Could not save the theme.');
}
