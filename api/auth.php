<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

use ClinicFlow\Services\AuditService;

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!empty($_SESSION['authenticated'])) {
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'] ?? null,
                'name' => $_SESSION['user_name'] ?? null,
                'email' => $_SESSION['user_email'] ?? null,
                'role' => $_SESSION['user_role'] ?? null,
                'tenant_id' => $_SESSION['tenant_id'] ?? 'default-clinic'
            ],
            'csrf_token' => getCsrfToken()
        ]);
    } else {
        echo json_encode([
            'authenticated' => false,
            'csrf_token' => getCsrfToken()
        ]);
    }
    exit;
}

if ($method === 'POST') {
    validateCsrfRequest();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!checkLoginRateLimit($ip, $email, $pdo)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts. Please try again in 5 minutes.']);
        exit;
    }

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE email = :email AND is_active = 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        clearLoginAttempts($ip, $email, $pdo);
        session_regenerate_id(true);

        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'] ?? 'Doctor';
        $_SESSION['tenant_id'] = $user['tenant_id'] ?? 'default-clinic';
        $_SESSION['user'] = $user;

        $audit = new AuditService($pdo);
        $audit->log("LOGIN_SUCCESS", "User {$user['email']} logged in successfully");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    }

    recordLoginAttempt($ip, $email, $pdo);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

if ($method === 'DELETE' || ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'logout')) {
    validateCsrfRequest();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
