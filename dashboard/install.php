<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startDashboardSession();

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
$expectedInstallToken = getenv('PRINT_TRACKER_INSTALL_TOKEN');
if (!is_string($expectedInstallToken)) {
    $expectedInstallToken = '';
}
$remoteInstallEnabled = $isHttps && strlen($expectedInstallToken) >= 32;

if (!$isLocalRequest && !$remoteInstallEnabled) {
    http_response_code(403);
    exit('Remote installation requires HTTPS and a server-side PRINT_TRACKER_INSTALL_TOKEN of at least 32 characters. Without HTTPS, use an SSH port-forward and open the installer through localhost.');
}

$localConfigPath = __DIR__ . '/config.local.php';
$existingConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($existingConfig)) {
    $existingConfig = [];
}

function dashboardInstallComplete(array $config): bool
{
    return (string) ($config['host'] ?? '') !== ''
        && (string) ($config['database'] ?? '') !== ''
        && (string) ($config['username'] ?? '') !== ''
        && (string) ($config['dashboard_username'] ?? '') !== ''
        && (string) ($config['dashboard_password_hash'] ?? '') !== '';
}

function dashboardInstallEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (dashboardInstallComplete($existingConfig)) {
    $installed = true;
} else {
    $installed = false;
}

$fields = [
    'host' => (string) ($existingConfig['host'] ?? '127.0.0.1'),
    'port' => (string) ($existingConfig['port'] ?? 3306),
    'database' => (string) ($existingConfig['database'] ?? 'print_tracking_db'),
    'username' => (string) ($existingConfig['username'] ?? 'print_tracker_reporter'),
    'dashboard_username' => (string) ($existingConfig['dashboard_username'] ?? 'report_viewer'),
];
$installError = '';
$installationSucceeded = false;

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($fields) as $field) {
        $postedValue = $_POST[$field] ?? null;
        if (is_string($postedValue)) {
            $fields[$field] = trim($postedValue);
        }
    }

    $databasePassword = $_POST['password'] ?? null;
    $dashboardPassword = $_POST['dashboard_password'] ?? null;
    $dashboardPasswordConfirmation = $_POST['dashboard_password_confirmation'] ?? null;
    $submittedInstallToken = $_POST['install_token'] ?? null;

    if (!validDashboardCsrfToken()) {
        http_response_code(400);
        $installError = 'This installer form expired. Reload the page and try again.';
    } elseif (!$isLocalRequest
        && (!is_string($submittedInstallToken)
            || !hash_equals($expectedInstallToken, $submittedInstallToken))) {
        $installError = 'The installer access token is incorrect.';
    } elseif (!is_string($databasePassword)
        || !is_string($dashboardPassword)
        || !is_string($dashboardPasswordConfirmation)) {
        $installError = 'Complete all password fields.';
    } elseif ($fields['host'] === '' || $fields['database'] === '' || $fields['username'] === '') {
        $installError = 'Enter the MySQL host, database, and username.';
    } elseif (strlen($fields['host']) > 255 || strlen($fields['database']) > 64 || strlen($fields['username']) > 128) {
        $installError = 'One or more MySQL settings exceed the supported length.';
    } elseif (filter_var($fields['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        $installError = 'Enter a valid MySQL port between 1 and 65535.';
    } elseif ($fields['dashboard_username'] === '' || strlen($fields['dashboard_username']) > 128) {
        $installError = 'Enter a dashboard username between 1 and 128 characters.';
    } elseif (strlen($dashboardPassword) < 12) {
        $installError = 'Use a dashboard password with at least 12 characters.';
    } elseif (!hash_equals($dashboardPassword, $dashboardPasswordConfirmation)) {
        $installError = 'The dashboard passwords do not match.';
    } else {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $fields['host'],
                (int) $fields['port'],
                $fields['database']
            );
            $pdo = new PDO($dsn, $fields['username'], $databasePassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            foreach ([
                'devices' => 'device_id, hostname',
                'print_logs' => 'id, device_id, document_name, copies, total_pages, print_timestamp',
                'activity_logs' => 'id, device_id, interval_start, interval_end, interval_minutes, mouse_movement_count, keyboard_stroke_count',
            ] as $table => $columns) {
                $pdo->query("SELECT $columns FROM `$table` LIMIT 0");
            }

            $passwordHash = password_hash($dashboardPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('PHP could not create a password hash.');
            }

            $localConfig = [
                'host' => $fields['host'],
                'port' => (int) $fields['port'],
                'database' => $fields['database'],
                'username' => $fields['username'],
                'password' => $databasePassword,
                'dashboard_username' => $fields['dashboard_username'],
                'dashboard_password_hash' => $passwordHash,
            ];
            $configSource = "<?php\ndeclare(strict_types=1);\n\nreturn "
                . var_export($localConfig, true)
                . ";\n";
            $temporaryPath = tempnam(__DIR__, '.config.local.');
            if ($temporaryPath === false) {
                throw new RuntimeException('Could not create a temporary config file.');
            }

            try {
                if (file_put_contents($temporaryPath, $configSource, LOCK_EX) === false
                    || !rename($temporaryPath, $localConfigPath)) {
                    throw new RuntimeException('Could not save config.local.php.');
                }
                @chmod($localConfigPath, 0600);
            } finally {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }

            $installationSucceeded = true;
        } catch (Throwable $error) {
            error_log('Print Tracker dashboard installer: ' . $error->getMessage());
            $installError = 'Installation failed. Check the MySQL connection, permissions, required tables, and PHP folder write access.';
        }
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
    <title>Dashboard installation | Print Tracker</title>
    <link rel="stylesheet" href="assets/dashboard.css">
</head>
<body class="login-page">
    <main class="login-shell">
        <a class="brand login-brand" href="login.php" aria-label="Print Tracker installation">
            <span class="brand-mark">PT</span>
            <span class="brand-name">print<span>tracker</span></span>
        </a>
        <section class="login-panel" aria-labelledby="install-title">
            <div class="eyebrow">INITIAL CONFIGURATION</div>
            <?php if ($installationSucceeded): ?>
                <h1 id="install-title">Installation complete</h1>
                <p>Database access was verified and the local configuration was saved. The dashboard password is stored as a hash.</p>
                <div class="login-error" role="status">For security, delete <strong>install.php</strong> from the dashboard folder now.</div>
                <a class="button button-accent login-submit" href="login.php">Continue to sign in <span aria-hidden="true">↗</span></a>
            <?php elseif ($installed): ?>
                <h1 id="install-title">Already installed</h1>
                <p>The dashboard configuration is already present. Remove <strong>install.php</strong> from the dashboard folder.</p>
                <a class="button button-accent login-submit" href="login.php">Go to sign in <span aria-hidden="true">↗</span></a>
            <?php else: ?>
                <h1 id="install-title">Configure dashboard</h1>
                <p>Enter the read-only MySQL account and create your dashboard login. The installer verifies the database tables before saving.</p>
                <?php if ($installError !== ''): ?>
                    <div class="login-error" role="alert"><?= dashboardInstallEscape($installError) ?></div>
                <?php endif; ?>
                <form class="login-form" method="post" action="install.php">
                    <input type="hidden" name="csrf_token" value="<?= dashboardInstallEscape($csrfToken) ?>">
                    <?php if (!$isLocalRequest): ?>
                        <label for="install_token">INSTALLER ACCESS TOKEN</label>
                        <input id="install_token" name="install_token" type="password" autocomplete="off" required>
                    <?php endif; ?>
                    <label for="host">MYSQL HOST</label>
                    <input id="host" name="host" type="text" value="<?= dashboardInstallEscape($fields['host']) ?>" maxlength="255" required>
                    <label for="port">MYSQL PORT</label>
                    <input id="port" name="port" type="number" value="<?= dashboardInstallEscape($fields['port']) ?>" min="1" max="65535" required>
                    <label for="database">DATABASE</label>
                    <input id="database" name="database" type="text" value="<?= dashboardInstallEscape($fields['database']) ?>" maxlength="64" required>
                    <label for="username">MYSQL READ-ONLY USERNAME</label>
                    <input id="username" name="username" type="text" value="<?= dashboardInstallEscape($fields['username']) ?>" maxlength="128" autocomplete="username" required>
                    <label for="password">MYSQL PASSWORD</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required>
                    <label for="dashboard_username">DASHBOARD LOGIN NAME</label>
                    <input id="dashboard_username" name="dashboard_username" type="text" value="<?= dashboardInstallEscape($fields['dashboard_username']) ?>" maxlength="128" required>
                    <label for="dashboard_password">DASHBOARD PASSWORD (12+ CHARACTERS)</label>
                    <input id="dashboard_password" name="dashboard_password" type="password" minlength="12" autocomplete="new-password" required>
                    <label for="dashboard_password_confirmation">CONFIRM DASHBOARD PASSWORD</label>
                    <input id="dashboard_password_confirmation" name="dashboard_password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
                    <button class="button button-accent login-submit" type="submit">Test and install <span aria-hidden="true">↗</span></button>
                </form>
                <div class="login-foot"><span class="status-light"></span> Local access or HTTPS with installer token</div>
            <?php endif; ?>
        </section>
        <footer class="login-footer">PRINT TRACKER <span>/</span> ADMIN CONSOLE</footer>
    </main>
</body>
</html>
