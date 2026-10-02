<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startDashboardSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validDashboardCsrfToken()) {
    http_response_code(400);
    exit('Invalid sign-out request.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

header('Location: login.php');
exit;