<?php
/**
 * Save the signed-in person's own preferences. Nothing here touches the
 * clinic's settings or anyone else's account.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Services\UserPreferences;

requireAuth();

$section = (string) ($_POST['section'] ?? 'appearance');
$back = '../settings.php?tab=' . ($section === 'alerts' ? 'alerts' : 'appearance');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    setToast('Not saved', 'That request expired. Reload the page and try again.', 'error');
    header('Location: ' . $back);
    exit;
}

$userId = (string) ($_SESSION['user_id'] ?? '');
if ($userId === '') {
    setToast('Not saved', 'Sign in again and retry.', 'error');
    header('Location: ../login.php');
    exit;
}

$svc = new UserPreferences(getDB());

if (!empty($_POST['reset'])) {
    try {
        $svc->reset($userId);
        setToast('Preferences reset', 'Everything is back to its default.');
    } catch (\Throwable $e) {
        error_log('[preferences_save] reset: ' . $e->getMessage());
        setToast('Not reset', 'Your preferences could not be reset. Try again.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

$current = $svc->load($userId);

// A form only posts its own section, so merge rather than replace: saving the
// alerts tab must not wipe the appearance choices and the other way round.
$input = $_POST;
if ($section === 'alerts') {
    $input = array_merge($current, array_intersect_key($_POST, array_flip(array_keys(UserPreferences::ALERTS))));
    foreach (array_keys(UserPreferences::ALERTS) as $k) {
        $input[$k] = !empty($_POST[$k]); // unticked boxes are not posted
    }
} else {
    $input = array_merge($current, $_POST);
    $input['sidebar_collapsed'] = !empty($_POST['sidebar_collapsed']);
    $input['show_help_hints'] = !empty($_POST['show_help_hints']);
}

$prefs = UserPreferences::sanitise($input, $current);

try {
    $svc->save($userId, $prefs);
    setToast('Preferences saved', $section === 'alerts'
        ? 'You will see the alerts you chose.'
        : 'The app will look this way wherever you sign in.');
} catch (\Throwable $e) {
    error_log('[preferences_save] ' . $e->getMessage());
    setToast('Not saved', 'Your preferences could not be saved. Try again.', 'error');
}

header('Location: ' . $back);
exit;
