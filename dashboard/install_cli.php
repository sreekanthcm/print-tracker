<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$debugEnabled = in_array('--debug', $argv ?? [], true);
$installStage = 'startup';

function debugInstall(bool $enabled, string $message): void
{
    if ($enabled) {
        fwrite(STDERR, "[debug] $message\n");
    }
}

function promptLine(string $label, string $default = ''): string
{
    $suffix = $default === '' ? '' : " [$default]";
    fwrite(STDOUT, $label . $suffix . ': ');
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Could not read terminal input.');
    }

    $value = rtrim($value, "\r\n");
    return $value === '' ? $default : $value;
}

function promptSecret(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $canHideInput = DIRECTORY_SEPARATOR === '/'
        && function_exists('shell_exec')
        && function_exists('posix_isatty')
        && posix_isatty(STDIN);

    if ($canHideInput) {
        shell_exec('stty -echo');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($canHideInput) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    if ($value === false) {
        throw new RuntimeException('Could not read terminal input.');
    }

    return rtrim($value, "\r\n");
}

function validateIdentifier(string $value, int $maxLength, string $label): void
{
    if ($value === '' || strlen($value) > $maxLength || preg_match('/[;\r\n]/', $value)) {
        throw new InvalidArgumentException("Invalid $label.");
    }
}

try {
    $installStage = 'checking configuration path and permissions';
    $configPath = __DIR__ . '/config.local.php';
    debugInstall($debugEnabled, 'PHP version: ' . PHP_VERSION);
    debugInstall($debugEnabled, 'PHP binary: ' . PHP_BINARY);
    debugInstall($debugEnabled, 'Installer path: ' . __FILE__);
    debugInstall($debugEnabled, 'Config destination: ' . $configPath);
    debugInstall($debugEnabled, 'Config directory writable by CLI: ' . (is_writable(__DIR__) ? 'yes' : 'no'));
    if (function_exists('posix_geteuid')) {
        $effectiveUid = posix_geteuid();
        $effectiveUser = function_exists('posix_getpwuid') ? posix_getpwuid($effectiveUid) : false;
        debugInstall(
            $debugEnabled,
            'CLI effective user: ' . ($effectiveUser['name'] ?? (string) $effectiveUid)
                . ' (uid ' . $effectiveUid . ')'
        );
    }

    $installStage = 'loading existing configuration';
    $existingConfig = is_file($configPath) ? require $configPath : [];
    if (!is_array($existingConfig)) {
        $existingConfig = [];
    }

    if (is_file($configPath)) {
        $replace = strtolower(promptLine('config.local.php exists. Replace it? Type yes to continue', 'no'));
        if ($replace !== 'yes') {
            fwrite(STDOUT, "No changes made.\n");
            exit(0);
        }
    }

    $installStage = 'collecting configuration';
    $host = promptLine('MySQL host', (string) ($existingConfig['host'] ?? '127.0.0.1'));
    $portInput = promptLine('MySQL port', (string) ($existingConfig['port'] ?? '3306'));
    $database = promptLine('Database name', (string) ($existingConfig['database'] ?? 'print_tracking_db'));
    $databaseUser = promptLine('MySQL read-only username', (string) ($existingConfig['username'] ?? 'print_tracker_reporter'));
    $databasePassword = promptSecret('MySQL password');
    $dashboardUser = promptLine('Dashboard username', (string) ($existingConfig['dashboard_username'] ?? 'report_viewer'));
    $dashboardPassword = promptSecret('Dashboard password (minimum 12 characters)');
    $dashboardPasswordConfirmation = promptSecret('Confirm dashboard password');

    $installStage = 'validating configuration';
    validateIdentifier($host, 255, 'MySQL host');
    if (!ctype_digit($portInput) || (int) $portInput < 1 || (int) $portInput > 65535) {
        throw new InvalidArgumentException('MySQL port must be between 1 and 65535.');
    }
    if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database)) {
        throw new InvalidArgumentException('Database name contains unsupported characters.');
    }
    validateIdentifier($databaseUser, 128, 'MySQL username');
    validateIdentifier($dashboardUser, 128, 'dashboard username');
    if (strlen($dashboardPassword) < 12) {
        throw new InvalidArgumentException('Dashboard password must be at least 12 characters.');
    }
    if (!hash_equals($dashboardPassword, $dashboardPasswordConfirmation)) {
        throw new InvalidArgumentException('Dashboard passwords do not match.');
    }

    $installStage = 'connecting to MySQL';
    $port = (int) $portInput;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $databaseUser, $databasePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $installStage = 'verifying required database tables';
    foreach ([
        'devices' => 'device_id, hostname',
        'print_logs' => 'id, device_id, document_name, copies, total_pages, print_timestamp',
        'activity_logs' => 'id, device_id, interval_start, interval_end, interval_minutes, mouse_movement_count, keyboard_stroke_count',
    ] as $table => $columns) {
        $pdo->query("SELECT $columns FROM `$table` LIMIT 0");
    }

    $installStage = 'building local configuration';
    $config = [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $databaseUser,
        'password' => $databasePassword,
        'dashboard_username' => $dashboardUser,
        'dashboard_password_hash' => password_hash($dashboardPassword, PASSWORD_DEFAULT),
    ];
    $configSource = "<?php\ndeclare(strict_types=1);\n\nreturn "
        . var_export($config, true)
        . ";\n";

    $installStage = 'creating temporary config file';
    $temporaryPath = tempnam(__DIR__, '.config.local.');
    if ($temporaryPath === false) {
        throw new RuntimeException('Could not create a temporary config file.');
    }
    debugInstall($debugEnabled, 'Temporary config path: ' . $temporaryPath);
    $temporaryDirectory = realpath(dirname($temporaryPath));
    $configDirectory = realpath(__DIR__);
    if ($temporaryDirectory !== $configDirectory) {
        debugInstall(
            $debugEnabled,
            'WARNING: PHP created the temporary file outside the config directory; rename may fail across filesystems.'
        );
    }

    try {
        $installStage = 'writing temporary config file';
        if (file_put_contents($temporaryPath, $configSource, LOCK_EX) === false
        ) {
            throw new RuntimeException('Could not write the temporary config file.');
        }

        $installStage = 'moving config file into place';
        if (!rename($temporaryPath, $configPath)) {
            throw new RuntimeException('Could not write config.local.php.');
        }

        $installStage = 'setting config file permissions';
        if (!chmod($configPath, 0640)) {
            debugInstall($debugEnabled, 'WARNING: chmod(0640) failed; inspect the resulting file permissions.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    $installStage = 'verifying created config file';
    if (!is_file($configPath)) {
        throw new RuntimeException('The config file is missing after the write completed.');
    }
    $configPermissions = fileperms($configPath);
    debugInstall($debugEnabled, 'Config file exists: yes');
    debugInstall($debugEnabled, 'Config file readable by CLI: ' . (is_readable($configPath) ? 'yes' : 'no'));
    debugInstall(
        $debugEnabled,
        'Config file mode: ' . ($configPermissions === false ? 'unknown' : substr(sprintf('%o', $configPermissions), -4))
    );
    debugInstall($debugEnabled, 'Config file owner uid: ' . (string) fileowner($configPath));
    debugInstall($debugEnabled, 'Config file group gid: ' . (string) filegroup($configPath));

    fwrite(STDOUT, "\nDatabase connection and required tables verified.\n");
    fwrite(STDOUT, "Created " . $configPath . " with a hashed dashboard password.\n");
    fwrite(STDOUT, "Remove install.php from the dashboard directory when finished.\n");
    fwrite(STDOUT, "If PHP-FPM runs under another account, ensure that account can read this file.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Installation failed: " . $error->getMessage() . "\n");
    debugInstall($debugEnabled, 'Failed stage: ' . $installStage);
    debugInstall($debugEnabled, 'Exception type: ' . get_class($error));
    debugInstall($debugEnabled, 'Exception location: ' . $error->getFile() . ':' . $error->getLine());
    exit(1);
}
