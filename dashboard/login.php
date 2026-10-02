<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startDashboardSession();

if (!dashboardCredentialsConfigured()) {
    header('Location: install.php');
    exit;
}

if (!empty($_SESSION['authenticated'])) {
    header('Location: index.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? null;
    $password = $_POST['password'] ?? null;

    if (!validDashboardCsrfToken()) {
        http_response_code(400);
        $loginError = 'This sign-in form expired. Reload the page and try again.';
    } elseif (!is_string($username) || !is_string($password)) {
        $loginError = 'The username or password is incorrect.';
    } else {
        $configuredUsername = (string) (dashboardConfig()['dashboard_username'] ?? '');
        if (hash_equals($configuredUsername, $username) && dashboardPasswordMatches($password)) {
            session_regenerate_id(true);
            $_SESSION = [
                'authenticated' => true,
                'dashboard_username' => $configuredUsername,
                'last_activity' => time(),
                'csrf_token' => bin2hex(random_bytes(32)),
            ];
            header('Location: index.php');
            exit;
        }

        $loginError = 'The username or password is incorrect.';
    }
}

$csrfToken = dashboardCsrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#15211d">
    <title>Admin sign in | Print Tracker</title>
    <link rel="stylesheet" href="assets/dashboard.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <a class="brand login-brand" href="login.php" aria-label="Print Tracker sign in">
            <span class="brand-mark">PT</span>
            <span class="brand-name">print<span>tracker</span></span>
        </a>
        <section class="login-panel" aria-labelledby="login-title">
            <div class="eyebrow">ADMIN / REPORTING</div>
            <h1 id="login-title">Sign in</h1>
            <p>Access the Print Tracker reporting dashboard.</p>
            <?php if ($loginError !== ''): ?>
                <div class="login-error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <form class="login-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label for="username">ADMIN USERNAME</label>
                <input id="username" name="username" type="text" autocomplete="username" maxlength="128" required autofocus>
                <label for="password">PASSWORD</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="button button-accent login-submit" type="submit">Sign in <span aria-hidden="true">↗</span></button>
            </form>
            <div class="login-foot"><span class="status-light"></span> Secured reporting access</div>
        </section>
        <footer class="login-footer">PRINT TRACKER <span>/</span> ADMIN CONSOLE</footer>
    </main>
</body>
</html>