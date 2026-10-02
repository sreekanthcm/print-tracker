<?php
declare(strict_types=1);

function dashboardConfig(): array
{
    static $config;
    if (!isset($config)) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function startDashboardSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $scriptDirectory = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $cookiePath = $scriptDirectory === '/' ? '/' : rtrim($scriptDirectory, '/') . '/';
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('print_tracker_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    session_start();
}

function dashboardCredentialsConfigured(): bool
{
    $config = dashboardConfig();
    return (string) ($config['dashboard_username'] ?? '') !== ''
        && ((string) ($config['dashboard_password_hash'] ?? '') !== ''
            || (string) ($config['dashboard_password'] ?? '') !== '');
}

function dashboardPasswordMatches(string $providedPassword): bool
{
    $config = dashboardConfig();
    $passwordHash = (string) ($config['dashboard_password_hash'] ?? '');

    if ($passwordHash !== '') {
        return password_verify($providedPassword, $passwordHash);
    }

    $configuredPassword = (string) ($config['dashboard_password'] ?? '');
    return $configuredPassword !== '' && hash_equals($configuredPassword, $providedPassword);
}

function dashboardCsrfToken(): string
{
    startDashboardSession();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validDashboardCsrfToken(): bool
{
    $providedToken = $_POST['csrf_token'] ?? null;
    return is_string($providedToken)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $providedToken);
}

function requireDashboardLogin(): void
{
    startDashboardSession();

    if (!dashboardCredentialsConfigured()) {
        http_response_code(503);
        exit('Dashboard access is not configured. Set a dashboard username and password hash.');
    }

    $lastActivity = $_SESSION['last_activity'] ?? 0;
    if (empty($_SESSION['authenticated']) || !is_string($_SESSION['dashboard_username'] ?? null) || time() - (int) $lastActivity > 28800) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }

    $_SESSION['last_activity'] = time();
}