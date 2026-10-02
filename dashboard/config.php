<?php
declare(strict_types=1);

$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

if (!is_array($localConfig)) {
    $localConfig = [];
}

$environmentConfig = [
    'host' => getenv('PRINT_TRACKER_DB_HOST'),
    'port' => getenv('PRINT_TRACKER_DB_PORT'),
    'database' => getenv('PRINT_TRACKER_DB_NAME'),
    'username' => getenv('PRINT_TRACKER_DB_USER'),
    'password' => getenv('PRINT_TRACKER_DB_PASSWORD'),
    'dashboard_username' => getenv('PRINT_TRACKER_DASHBOARD_USER'),
    'dashboard_password' => getenv('PRINT_TRACKER_DASHBOARD_PASSWORD'),
    'dashboard_password_hash' => getenv('PRINT_TRACKER_DASHBOARD_PASSWORD_HASH'),
];

$environmentConfig = array_filter(
    $environmentConfig,
    static fn ($value): bool => $value !== false && $value !== ''
);

return array_replace(
    [
        'host' => '',
        'port' => 3306,
        'database' => 'print_tracking_db',
        'username' => '',
        'password' => '',
        'dashboard_username' => '',
        'dashboard_password' => '',
        'dashboard_password_hash' => '',
    ],
    $localConfig,
    $environmentConfig
);