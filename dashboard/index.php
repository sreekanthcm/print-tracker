<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireDashboardLogin();
$config = dashboardConfig();

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function dateFilter(string $alias, string $column, string $start, string $endExclusive, ?int $deviceId): array
{
    $where = sprintf(
        '`%s`.`%s` >= :range_start AND `%s`.`%s` < :range_end',
        $alias,
        $column,
        $alias,
        $column
    );
    $parameters = [
        ':range_start' => $start . ' 00:00:00',
        ':range_end' => $endExclusive . ' 00:00:00',
    ];

    if ($deviceId !== null) {
        $where .= sprintf(' AND `%s`.`device_id` = :device_id', $alias);
        $parameters[':device_id'] = $deviceId;
    }

    return [$where, $parameters];
}

$today = new DateTimeImmutable('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = $today->modify('-29 days')->format('Y-m-d');
$start = isset($_GET['start']) && is_string($_GET['start']) ? $_GET['start'] : $defaultStart;
$end = isset($_GET['end']) && is_string($_GET['end']) ? $_GET['end'] : $defaultEnd;
$selectedDevice = filter_input(INPUT_GET, 'device', FILTER_VALIDATE_INT);
$selectedDevice = is_int($selectedDevice) && $selectedDevice > 0 ? $selectedDevice : null;
$selectedHost = isset($_GET['host']) && is_string($_GET['host']) ? trim($_GET['host']) : '';
$filterError = '';

if (!validDate($start) || !validDate($end)) {
    $filterError = 'Choose valid start and end dates.';
    $start = $defaultStart;
    $end = $defaultEnd;
}

$startDate = new DateTimeImmutable($start);
$endDate = new DateTimeImmutable($end);

if ($endDate < $startDate) {
    $filterError = 'The end date must be on or after the start date.';
    $start = $defaultStart;
    $end = $defaultEnd;
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
}

if ($startDate->diff($endDate)->days > 365) {
    $filterError = 'Date ranges are limited to 366 days.';
    $start = $defaultStart;
    $end = $defaultEnd;
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
}

$endExclusive = $endDate->modify('+1 day')->format('Y-m-d');
$devices = [];
$dailyPrint = [];
$deviceTotals = [];
$hostSummary = [];
$dailyActivity = [];
$recentJobs = [];
$summary = ['jobs' => 0, 'pages' => 0, 'devices' => 0, 'movement' => 0, 'keystrokes' => 0];
$databaseError = false;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        (int) $config['port'],
        $config['database']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $devices = $pdo->query('SELECT device_id, hostname FROM devices ORDER BY hostname')->fetchAll();
    $hostnamesByDeviceId = [];
    $matchedHostId = null;
    foreach ($devices as $device) {
        $deviceId = (int) $device['device_id'];
        $hostname = (string) $device['hostname'];
        $hostnamesByDeviceId[$deviceId] = $hostname;
        if ($selectedHost !== '' && strcasecmp($hostname, $selectedHost) === 0) {
            $matchedHostId = $deviceId;
        }
    }
    if ($selectedHost !== '') {
        if ($matchedHostId === null) {
            $selectedDevice = 0;
            $filterError = 'Choose a host from the available suggestions.';
        } else {
            $selectedDevice = $matchedHostId;
            $selectedHost = $hostnamesByDeviceId[$matchedHostId];
        }
    } elseif ($selectedDevice !== null && isset($hostnamesByDeviceId[$selectedDevice])) {
        $selectedHost = $hostnamesByDeviceId[$selectedDevice];
    } else {
        $selectedDevice = null;
    }

    [$printWhere, $printParameters] = dateFilter('p', 'print_timestamp', $start, $endExclusive, $selectedDevice);
    [$activityWhere, $activityParameters] = dateFilter('a', 'interval_start', $start, $endExclusive, $selectedDevice);

    foreach ($devices as $device) {
        $deviceId = (int) $device['device_id'];
        if ($selectedDevice !== null && $deviceId !== $selectedDevice) {
            continue;
        }
        $hostSummary[$deviceId] = [
            'hostname' => (string) $device['hostname'],
            'activityLogs' => 0,
            'movement' => 0,
            'keystrokes' => 0,
            'jobs' => 0,
            'pages' => 0,
        ];
    }

    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS jobs,
                COALESCE(SUM(p.total_pages), 0) AS pages,
                COUNT(DISTINCT p.device_id) AS devices
         FROM print_logs p
         WHERE $printWhere"
    );
    $statement->execute($printParameters);
    $summary = array_merge($summary, $statement->fetch());

    $statement = $pdo->prepare(
        "SELECT DATE(p.print_timestamp) AS day,
                COUNT(*) AS jobs,
                COALESCE(SUM(p.total_pages), 0) AS pages
         FROM print_logs p
         WHERE $printWhere
         GROUP BY DATE(p.print_timestamp)
         ORDER BY day"
    );
    $statement->execute($printParameters);
    foreach ($statement->fetchAll() as $row) {
        $dailyPrint[$row['day']] = ['jobs' => (int) $row['jobs'], 'pages' => (int) $row['pages']];
    }

    $statement = $pdo->prepare(
        "SELECT d.hostname,
                COUNT(p.id) AS jobs,
                COALESCE(SUM(p.total_pages), 0) AS pages
         FROM print_logs p
         INNER JOIN devices d ON d.device_id = p.device_id
         WHERE $printWhere
         GROUP BY d.device_id, d.hostname
         ORDER BY pages DESC, jobs DESC
         LIMIT 8"
    );
    $statement->execute($printParameters);
    $deviceTotals = $statement->fetchAll();

    $statement = $pdo->prepare(
        "SELECT p.device_id,
                COUNT(*) AS jobs,
                COALESCE(SUM(p.total_pages), 0) AS pages
         FROM print_logs p
         WHERE $printWhere
         GROUP BY p.device_id"
    );
    $statement->execute($printParameters);
    foreach ($statement->fetchAll() as $row) {
        $deviceId = (int) $row['device_id'];
        if (isset($hostSummary[$deviceId])) {
            $hostSummary[$deviceId]['jobs'] = (int) $row['jobs'];
            $hostSummary[$deviceId]['pages'] = (int) $row['pages'];
        }
    }

    $statement = $pdo->prepare(
        "SELECT a.device_id,
                COUNT(*) AS activity_logs,
                COALESCE(SUM(a.mouse_movement_count), 0) AS movement,
                COALESCE(SUM(a.keyboard_stroke_count), 0) AS keystrokes
         FROM activity_logs a
         WHERE $activityWhere
         GROUP BY a.device_id"
    );
    $statement->execute($activityParameters);
    foreach ($statement->fetchAll() as $row) {
        $deviceId = (int) $row['device_id'];
        if (isset($hostSummary[$deviceId])) {
            $hostSummary[$deviceId]['activityLogs'] = (int) $row['activity_logs'];
            $hostSummary[$deviceId]['movement'] = (int) $row['movement'];
            $hostSummary[$deviceId]['keystrokes'] = (int) $row['keystrokes'];
        }
    }

    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(a.mouse_movement_count), 0) AS movement,
                COALESCE(SUM(a.keyboard_stroke_count), 0) AS keystrokes
         FROM activity_logs a
         WHERE $activityWhere"
    );
    $statement->execute($activityParameters);
    $summary = array_merge($summary, $statement->fetch());

    $statement = $pdo->prepare(
        "SELECT DATE(a.interval_start) AS day,
                COALESCE(SUM(a.mouse_movement_count), 0) AS movement,
                COALESCE(SUM(a.keyboard_stroke_count), 0) AS keystrokes
         FROM activity_logs a
         WHERE $activityWhere
         GROUP BY DATE(a.interval_start)
         ORDER BY day"
    );
    $statement->execute($activityParameters);
    foreach ($statement->fetchAll() as $row) {
        $dailyActivity[$row['day']] = [
            'movement' => (int) $row['movement'],
            'keystrokes' => (int) $row['keystrokes'],
        ];
    }

    $statement = $pdo->prepare(
        "SELECT d.hostname, p.document_name, p.copies, p.total_pages, p.print_timestamp
         FROM print_logs p
         INNER JOIN devices d ON d.device_id = p.device_id
         WHERE $printWhere
         ORDER BY p.print_timestamp DESC, p.id DESC
         LIMIT 10"
    );
    $statement->execute($printParameters);
    $recentJobs = $statement->fetchAll();
} catch (Throwable $error) {
    error_log('Print Tracker dashboard: ' . $error->getMessage());
    $databaseError = true;
}

$chartDays = [];
$chartPages = [];
$chartJobs = [];
$chartMovement = [];
$chartKeystrokes = [];
$period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
foreach ($period as $day) {
    $key = $day->format('Y-m-d');
    $chartDays[] = $day->format('M j');
    $chartPages[] = $dailyPrint[$key]['pages'] ?? 0;
    $chartJobs[] = $dailyPrint[$key]['jobs'] ?? 0;
    $chartMovement[] = $dailyActivity[$key]['movement'] ?? 0;
    $chartKeystrokes[] = $dailyActivity[$key]['keystrokes'] ?? 0;
}

$reportData = [
    'period' => $start . ' to ' . $end,
    'device' => $selectedHost === '' ? 'All hosts' : $selectedHost,
    'days' => $chartDays,
    'pages' => $chartPages,
    'jobs' => $chartJobs,
    'movement' => $chartMovement,
    'keystrokes' => $chartKeystrokes,
    'deviceNames' => array_column($deviceTotals, 'hostname'),
    'devicePages' => array_map('intval', array_column($deviceTotals, 'pages')),
    'topDevices' => array_map(static fn (array $row): array => [
        'hostname' => $row['hostname'],
        'jobs' => (int) $row['jobs'],
        'pages' => (int) $row['pages'],
    ], $deviceTotals),
    'hostSummary' => array_values($hostSummary),
    'recentJobs' => array_map(static fn (array $row): array => [
        'hostname' => $row['hostname'],
        'document' => $row['document_name'] ?: 'Untitled document',
        'copies' => (int) ($row['copies'] ?? 0),
        'pages' => (int) ($row['total_pages'] ?? 0),
        'timestamp' => (string) $row['print_timestamp'],
    ], $recentJobs),
];

$averagePages = (int) $summary['jobs'] > 0 ? (int) round((int) $summary['pages'] / (int) $summary['jobs']) : 0;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#15211d">
    <title>Usage dashboard | Print Tracker</title>
    <link rel="stylesheet" href="assets/dashboard.css">
    <script defer src="assets/vendor/chart.umd.min.js"></script>
    <script defer src="assets/vendor/jspdf.umd.min.js"></script>
    <script defer src="assets/vendor/jspdf.plugin.autotable.min.js"></script>
    <script defer src="assets/dashboard.js?v=<?= (int) filemtime(__DIR__ . '/assets/dashboard.js') ?>"></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php" aria-label="Print Tracker dashboard">
            <span class="brand-mark">PT</span>
            <span class="brand-name">print<span>tracker</span></span>
        </a>
        <div class="side-label">WORKSPACE</div>
        <nav class="side-nav" aria-label="Dashboard sections">
            <a class="nav-link active" href="#overview"><span class="nav-dot"></span>Overview</a>
            <a class="nav-link" href="#print-volume"><span class="nav-dot"></span>Print volume</a>
            <a class="nav-link" href="#activity"><span class="nav-dot"></span>Activity</a>
            <a class="nav-link" href="#host-summary"><span class="nav-dot"></span>Hosts</a>
            <a class="nav-link" href="#latest-jobs"><span class="nav-dot"></span>Latest jobs</a>
        </nav>
        <div class="sidebar-foot">
            <span class="status-light"></span>
            <span>Database reporting</span>
            <span class="status-caption">MYSQL</span>
        </div>
    </aside>

    <main class="main-content" id="overview">
        <header class="topbar">
            <div class="breadcrumb">PRINT TRACKER <span>/</span> ANALYTICS</div>
            <div class="topbar-actions">
                <span class="account-name"><?= escape($_SESSION['dashboard_username']) ?></span>
                <form action="logout.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(dashboardCsrfToken()) ?>">
                    <button class="button button-quiet" type="submit">Sign out</button>
                </form>
                <button class="button button-dark pdf-button" id="download-pdf" type="button">
                    <span class="button-icon" aria-hidden="true">↓</span> Export PDF
                </button>
            </div>
        </header>

        <section class="page-heading">
            <div>
                <div class="eyebrow">OPERATIONS / REPORTING</div>
                <h1>Usage overview</h1>
                <p>Print volume and workstation activity across your fleet.</p>
            </div>
            <div class="report-period"><span class="period-dot"></span> REPORT PERIOD <strong><?= escape($start) ?> — <?= escape($end) ?></strong></div>
        </section>

        <form class="filter-bar" method="get" action="index.php" aria-label="Report filters">
            <label class="filter-field">
                <span>FROM</span>
                <input type="date" name="start" value="<?= escape($start) ?>" required>
            </label>
            <span class="filter-separator" aria-hidden="true">→</span>
            <label class="filter-field">
                <span>TO</span>
                <input type="date" name="end" value="<?= escape($end) ?>" required>
            </label>
            <label class="filter-field device-filter">
                <span>HOST</span>
                <input type="search" name="host" list="host-options" value="<?= escape($selectedHost) ?>" placeholder="All hosts" autocomplete="off">
                <datalist id="host-options">
                    <?php foreach ($devices as $device): ?>
                        <option value="<?= escape($device['hostname']) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>
            <button class="button button-accent filter-submit" type="submit">Apply filters <span aria-hidden="true">↗</span></button>
        </form>

        <?php if ($filterError !== ''): ?>
            <div class="notice notice-warn" role="status"><?= escape($filterError) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice-error" role="alert">
                <strong>Database unavailable.</strong> Check the dashboard connection settings and confirm the account can read the Print Tracker tables.
            </div>
        <?php endif; ?>

        <section class="metric-grid" aria-label="Summary metrics">
            <article class="metric-card metric-primary">
                <div class="metric-label">PRINT JOBS <span class="metric-mark">01</span></div>
                <div class="metric-value"><?= number_format((int) $summary['jobs']) ?></div>
                <div class="metric-note">Jobs in selected period</div>
            </article>
            <article class="metric-card">
                <div class="metric-label">PAGES PRINTED <span class="metric-mark">02</span></div>
                <div class="metric-value"><?= number_format((int) $summary['pages']) ?></div>
                <div class="metric-note">Across all recorded jobs</div>
            </article>
            <article class="metric-card">
                <div class="metric-label">ACTIVE DEVICES <span class="metric-mark">03</span></div>
                <div class="metric-value"><?= number_format((int) $summary['devices']) ?></div>
                <div class="metric-note">Devices with print activity</div>
            </article>
            <article class="metric-card">
                <div class="metric-label">AVG. PAGES / JOB <span class="metric-mark">04</span></div>
                <div class="metric-value"><?= number_format($averagePages) ?></div>
                <div class="metric-note">Average size per print job</div>
            </article>
        </section>

        <section class="chart-section" id="print-volume">
            <div class="section-heading">
                <div><div class="eyebrow">OUTPUT / 01</div><h2>Print volume</h2></div>
                <div class="legend-note"><span class="legend-swatch swatch-green"></span>Pages <span class="legend-swatch swatch-gold"></span>Jobs</div>
            </div>
            <div class="chart-grid">
                <article class="panel panel-wide">
                    <div class="panel-heading"><div><h3>Daily output</h3><p>Pages printed and job count by day</p></div><span class="panel-period"><?= escape($startDate->format('M j')) ?> – <?= escape($endDate->format('M j, Y')) ?></span></div>
                    <div class="chart-wrap trend-wrap"><canvas id="trend-chart" aria-label="Daily print volume chart"></canvas></div>
                </article>
                <article class="panel">
                    <div class="panel-heading"><div><h3>Device ranking</h3><p>Pages by workstation</p></div><span class="panel-index">TOP 8</span></div>
                    <div class="chart-wrap device-wrap"><canvas id="device-chart" aria-label="Printed pages by device chart"></canvas></div>
                    <?php if ($deviceTotals === []): ?><p class="empty-note">No print data for this period.</p><?php endif; ?>
                </article>
            </div>
        </section>

        <section class="activity-section" id="activity">
            <div class="section-heading">
                <div><div class="eyebrow">WORKSTATION / 02</div><h2>Activity signals</h2></div>
                <div class="activity-totals">
                    <div><span>MOUSE MOVEMENTS</span><strong><?= number_format((int) $summary['movement']) ?></strong></div>
                    <i></i>
                    <div><span>KEYSTROKES</span><strong><?= number_format((int) $summary['keystrokes']) ?></strong></div>
                </div>
            </div>
            <article class="panel activity-panel">
                <div class="panel-heading"><div><h3>Daily activity totals</h3><p>Aggregated counts only; no key values or pointer positions are stored.</p></div><span class="panel-index">BY DAY</span></div>
                <div class="chart-wrap activity-wrap"><canvas id="activity-chart" aria-label="Daily workstation activity chart"></canvas></div>
                <?php if ((int) $summary['movement'] === 0 && (int) $summary['keystrokes'] === 0): ?><p class="empty-note">No activity data for this period.</p><?php endif; ?>
            </article>
        </section>

        <section class="jobs-section" id="host-summary">
            <div class="section-heading">
                <div><div class="eyebrow">FLEET DETAIL / 03</div><h2>Host activity and print totals</h2></div>
                <span class="table-count"><?= count($hostSummary) ?> HOSTS</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>HOST</th><th>ACTIVITY LOGS</th><th>MOUSE MOVEMENTS</th><th>KEYSTROKES</th><th>PRINT JOBS</th><th>PAGES PRINTED</th></tr></thead>
                    <tbody>
                    <?php foreach ($hostSummary as $host): ?>
                        <tr>
                            <td><?= escape($host['hostname']) ?></td>
                            <td><?= number_format($host['activityLogs']) ?></td>
                            <td><?= number_format($host['movement']) ?></td>
                            <td><?= number_format($host['keystrokes']) ?></td>
                            <td><?= number_format($host['jobs']) ?></td>
                            <td class="pages-cell"><?= number_format($host['pages']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($hostSummary === []): ?><tr><td class="empty-table" colspan="6">No host activity matches these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="jobs-section" id="latest-jobs">
            <div class="section-heading">
                <div><div class="eyebrow">DETAIL / 04</div><h2>Latest print jobs</h2></div>
                <span class="table-count"><?= count($recentJobs) ?> RECORDS</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>DOCUMENT</th><th>DEVICE</th><th>COPIES</th><th>PAGES</th><th>PRINTED AT</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentJobs as $job): ?>
                        <tr>
                            <td class="document-cell" title="<?= escape($job['document_name'] ?: 'Untitled document') ?>"><?= escape($job['document_name'] ?: 'Untitled document') ?></td>
                            <td><?= escape($job['hostname']) ?></td>
                            <td><?= number_format((int) ($job['copies'] ?? 0)) ?></td>
                            <td class="pages-cell"><?= number_format((int) ($job['total_pages'] ?? 0)) ?></td>
                            <td class="time-cell"><?= escape((new DateTimeImmutable($job['print_timestamp']))->format('M j, Y · H:i')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recentJobs === []): ?><tr><td class="empty-table" colspan="5">No print jobs match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="page-footer"><span>PRINT TRACKER / ANALYTICS</span><span>Data shown in the database server's recorded time.</span><span>© 2026 Korbiz Solutions. Designed to support secure, scalable IT operations.</span></footer>
    </main>
</div>
<script id="report-data" type="application/json"><?= json_encode($reportData, $jsonFlags) ?></script>
</body>
</html>