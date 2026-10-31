<?php
/**
 * Feature wish board actions.
 *  - create_wish / add_comment / vote_wish : any authenticated clinic user
 *  - update_wish (status + timeline + dev notes) : developers only
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

use ClinicFlow\Shared\Container;
use ClinicFlow\Shared\TenantContext;
use ClinicFlow\Services\WishBoardService;
use ClinicFlow\Shared\Logger;

requireAuth();
validateCsrfRequest();

$action = $_POST['action'] ?? '';
$tenantId = TenantContext::getTenantId();
$role = function_exists('nxCurrentRole') ? nxCurrentRole() : ($_SESSION['user_role'] ?? 'Doctor');
$isDeveloper = ($role === 'Developer');
$userId = $_SESSION['user_id'] ?? null;
$userName = $_SESSION['user_name'] ?? null;

$wishService = Container::getInstance()->get(WishBoardService::class);
$logger = Container::getInstance()->get(Logger::class);

$back = $_SERVER['HTTP_REFERER'] ?? '../wishboard.php';

if ($action === 'create_wish') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        setToast('Validation error', 'A short title for the wish is required.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $wishService->createWish($tenantId, [
            'created_by'      => $userId,
            'created_by_name' => $userName,
            'title'           => $title,
            'description'     => $_POST['description'] ?? '',
            'category'        => $_POST['category'] ?? 'general',
            'clinic_priority' => $_POST['clinic_priority'] ?? 'medium',
        ]);
        setToast('Wish submitted', 'Thanks! Your feature wish was sent to the development team.');
    } catch (\Throwable $e) {
        $logger->error('Failed to create wish: ' . $e->getMessage());
        setToast('Error', 'Could not submit the wish.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'vote_wish') {
    $id = trim($_POST['wish_id'] ?? '');
    if ($id !== '') {
        $voted = $_SESSION['wish_votes'] ?? [];
        if (in_array($id, $voted, true)) {
            setToast('Already voted', 'You have already upvoted this wish.', 'error');
        } else {
            $wishService->vote($id);
            $voted[] = $id;
            $_SESSION['wish_votes'] = $voted;
            setToast('Vote counted', 'Thanks for the upvote.');
        }
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'add_comment') {
    $wishId = trim($_POST['wish_id'] ?? '');
    $body = trim($_POST['body'] ?? '');
    if ($wishId === '' || $body === '') {
        setToast('Validation error', 'A message is required.', 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $wishService->addComment($wishId, [
            'author_id'    => $userId,
            'author_name'  => $userName,
            'author_role'  => $role,
            'is_developer' => $isDeveloper,
            'body'         => $body,
        ]);
        setToast('Message posted', 'Your message was added to the thread.');
    } catch (\Throwable $e) {
        $logger->error('Failed to add wish comment: ' . $e->getMessage());
        setToast('Error', 'Could not post the message.', 'error');
    }
    header('Location: ' . $back);
    exit;
}

if ($action === 'update_wish') {
    requireRole(['Developer']);
    $id = trim($_POST['wish_id'] ?? '');
    if ($id !== '') {
        try {
            $wishService->updateWish($id, [
                'status'      => $_POST['status'] ?? '',
                'dev_notes'   => $_POST['dev_notes'] ?? '',
                'target_date' => $_POST['target_date'] ?? '',
            ]);
            setToast('Wish updated', 'Status and timeline updated.');
        } catch (\Throwable $e) {
            $logger->error('Failed to update wish: ' . $e->getMessage());
            setToast('Error', 'Could not update the wish.', 'error');
        }
    }
    header('Location: ' . $back);
    exit;
}

header('Location: ' . $back);
exit;
