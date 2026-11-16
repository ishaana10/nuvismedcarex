<?php
/**
 * Switch the "acting clinician" shown in the header.
 *
 * Hardened: requires a valid CSRF token, only accepts a clinician from the
 * signed-in user's own clinic, and only redirects back to a page on this site
 * (it used to redirect to whatever the Referer header said, and accept any id).
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (empty($_SESSION['authenticated'])) {
    header('Location: ../login.php');
    exit;
}

/** Same-site relative target only; anything else falls back to the dashboard. */
function nxSafeBack(?string $referer): string
{
    $fallback = '../index.php';
    if (!$referer) {
        return $fallback;
    }
    $parts = parse_url($referer);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($parts === false || (isset($parts['host']) && strcasecmp($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $host) !== 0)) {
        return $fallback;
    }
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return preg_match('#^/[^\r\n]*$#', $path) ? $path : $fallback;
}

$back = nxSafeBack($_SERVER['HTTP_REFERER'] ?? null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not changed', 'That request expired. Reload the page and try again.', 'error');
    header('Location: ' . $back);
    exit;
}

$doctorId = trim((string) ($_POST['doctor_id'] ?? ''));
$pdo = getDB();
$st = $pdo->prepare("SELECT id FROM doctors WHERE id = ? AND tenant_id = ? AND COALESCE(is_active, 1) = 1 LIMIT 1");
$st->execute([$doctorId, \ClinicFlow\Shared\TenantContext::getTenantId()]);
if ($st->fetchColumn()) {
    $_SESSION['current_doctor_id'] = $doctorId;
} else {
    setToast('Not changed', 'That clinician is not part of this clinic.', 'error');
}

header('Location: ' . $back);
exit;
