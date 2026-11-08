<?php
declare(strict_types=1);

/**
 * TurfMate | Owner Booking Reports
 * Read-only reporting using the existing bookings and booking-group tables.
 * Each overnight customer booking group is counted as ONE reservation.
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_br_date_ok(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function tm_br_short(float $value): string
{
    if (abs($value) >= 1000000) {
        return number_format($value / 1000000, 1) . 'm';
    }
    if (abs($value) >= 1000) {
        return number_format($value / 1000, 1) . 'k';
    }
    return number_format($value, $value === floor($value) ? 0 : 1);
}

function tm_br_status(string $status): string
{
    return match ($status) {
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'pending' => 'Pending',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        default => ucfirst($status),
    };
}

// -----------------------------------------------------------------------------
// Filters — facility and turf selections are verified against the signed-in owner.
// -----------------------------------------------------------------------------
$facilityStmt = $pdo->prepare(
    'SELECT id, name FROM facilities
     WHERE owner_id = ? ORDER BY name'
);
$facilityStmt->execute([$ownerId]);
$facilities = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedFacilityIds = array_map('intval', array_column($facilities, 'id'));

$requestedFacility = filter_var(
    $_GET['facility_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$facilityId = $requestedFacility !== false
    && $requestedFacility !== null
    && in_array((int) $requestedFacility, $allowedFacilityIds, true)
    ? (int) $requestedFacility
    : 0;

$turfStmt = $pdo->prepare(
    'SELECT t.id, t.name, t.facility_id, f.name AS facility_name
     FROM turfs t
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
     ORDER BY f.name, t.name'
);
$turfStmt->execute([$ownerId]);
$turfs = $turfStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedTurfs = [];
foreach ($turfs as $turf) {
    $allowedTurfs[(int) $turf['id']] = (int) $turf['facility_id'];
}

$requestedTurf = filter_var(
    $_GET['turf_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$turfId = $requestedTurf !== false
    && $requestedTurf !== null
    && isset($allowedTurfs[(int) $requestedTurf])
    && ($facilityId === 0 || $allowedTurfs[(int) $requestedTurf] === $facilityId)
    ? (int) $requestedTurf
    : 0;

$today = new DateTimeImmutable('today');
$period = (string) ($_GET['period'] ?? 'month');
if (!in_array($period, ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)) {
    $period = 'month';
}

switch ($period) {
    case 'today':
        $from = $to = $today->format('Y-m-d');
        break;
    case 'yesterday':
        $from = $to = $today->modify('-1 day')->format('Y-m-d');
        break;
    case 'week':
        $from = $today->modify('monday this week')->format('Y-m-d');
        $to = $today->format('Y-m-d');
        break;
    case 'year':
        $from = $today->format('Y-01-01');
        $to = $today->format('Y-m-d');
        break;
    case 'custom':
        $from = (string) ($_GET['from'] ?? $today->format('Y-m-01'));
        $to = (string) ($_GET['to'] ?? $today->format('Y-m-d'));
        if (!tm_br_date_ok($from)) {
            $from = $today->format('Y-m-01');
        }
        if (!tm_br_date_ok($to)) {
            $to = $today->format('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        break;
    default:
        $from = $today->format('Y-m-01');
        $to = $today->format('Y-m-d');
}

// Keep reports manageable for a classroom LAMP installation.
$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
$rangeNotice = '';
if ($fromDate->diff($toDate)->days > 730) {
    $fromDate = $toDate->modify('-730 days');
    $from = $fromDate->format('Y-m-d');
    $rangeNotice = 'The selected date range was shortened to the latest 731 days.';
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
$validStatuses = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'rejected'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

// -----------------------------------------------------------------------------
// Reservation rows: ordinary bookings and customer overnight booking groups.
// Group segments in bookings are excluded, then replaced with one group row.
// This prevents counting a two-day reservation twice or summing its value twice.
// -----------------------------------------------------------------------------
$sql = <<<'SQL'
SELECT
    r.record_id,
    r.kind,
    r.customer_name,
    r.booking_date,
    r.start_time,
    r.status,
    r.source,
    r.total_amount,
    r.booked_hours,
    r.created_at,
    t.id AS turf_id,
    t.name AS turf_name,
    f.id AS facility_id,
    f.name AS facility_name
FROM (
    SELECT
        b.id AS record_id,
        CONVERT('booking' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS kind,
        b.turf_id,
        CONVERT(b.customer_name USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS customer_name,
        b.booking_date,
        b.start_time,
        CONVERT(b.status USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS status,
        CONVERT(b.source USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS source,
        b.total_amount,
        (TIME_TO_SEC(b.end_time) - TIME_TO_SEC(b.start_time)) / 3600 AS booked_hours,
        b.created_at
    FROM bookings b
    WHERE NOT EXISTS (
        SELECT 1 FROM tm_booking_group_items gi WHERE gi.booking_id = b.id
    )

    UNION ALL

    SELECT
        g.id AS record_id,
        CONVERT('group' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS kind,
        g.turf_id,
        CONVERT(COALESCE(u.name, 'Customer') USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS customer_name,
        DATE(g.start_at) AS booking_date,
        TIME(g.start_at) AS start_time,
        CONVERT(g.status USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS status,
        CONVERT('online' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS source,
        g.total_amount,
        TIMESTAMPDIFF(SECOND, g.start_at, g.end_at) / 3600 AS booked_hours,
        g.created_at
    FROM tm_booking_groups g
    LEFT JOIN users u ON u.id = g.customer_id
) r
INNER JOIN turfs t ON t.id = r.turf_id
INNER JOIN facilities f ON f.id = t.facility_id
WHERE f.owner_id = ?
 
  AND r.booking_date BETWEEN ? AND ?
SQL;

$params = [$ownerId, $from, $to];
if ($facilityId > 0) {
    $sql .= ' AND f.id = ?';
    $params[] = $facilityId;
}
if ($turfId > 0) {
    $sql .= ' AND t.id = ?';
    $params[] = $turfId;
}
if ($statusFilter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY r.booking_date DESC, r.start_time DESC, r.record_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Derive report metrics from the SAME filtered reservation set.
// -----------------------------------------------------------------------------
$total = count($reservations);
$counts = array_fill_keys(['pending', 'confirmed', 'completed', 'cancelled', 'rejected'], 0);
$sources = array_fill_keys(['online', 'walk_in', 'phone', 'whatsapp'], 0);
$turfPerformance = [];
$trendValues = [];
$weekdayCounts = array_fill(0, 7, 0); // Monday = 0
$validValue = 0.0;
$bookedHours = 0.0;

foreach ($reservations as $row) {
    $status = (string) $row['status'];
    $source = (string) $row['source'];
    $date = (string) $row['booking_date'];
    $value = (float) $row['total_amount'];
    $hours = max(0.0, (float) $row['booked_hours']);

    $counts[$status] = ($counts[$status] ?? 0) + 1;
    $sources[$source] = ($sources[$source] ?? 0) + 1;
    $trendValues[$date] = ($trendValues[$date] ?? 0) + 1;
    $weekday = (int) (new DateTimeImmutable($date))->format('N') - 1;
    $weekdayCounts[$weekday]++;

    $id = (int) $row['turf_id'];
    if (!isset($turfPerformance[$id])) {
        $turfPerformance[$id] = [
            'name' => (string) $row['turf_name'],
            'facility' => (string) $row['facility_name'],
            'bookings' => 0,
            'hours' => 0.0,
            'value' => 0.0,
        ];
    }
    $turfPerformance[$id]['bookings']++;

    if (in_array($status, ['confirmed', 'completed'], true)) {
        $validValue += $value;
        $bookedHours += $hours;
        $turfPerformance[$id]['hours'] += $hours;
        $turfPerformance[$id]['value'] += $value;
    }
}

usort(
    $turfPerformance,
    static fn (array $a, array $b): int => $b['hours'] <=> $a['hours']
);

$cancellationRate = $total > 0
    ? ($counts['cancelled'] + $counts['rejected']) / $total * 100
    : 0.0;

// Daily / weekly / monthly trend. Zero-fill missing periods.
$days = $fromDate->diff($toDate)->days + 1;
$group = $days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month');
$trendGrouped = [];
foreach ($trendValues as $date => $value) {
    $d = new DateTimeImmutable($date);
    $key = match ($group) {
        'week' => $d->modify('monday this week')->format('Y-m-d'),
        'month' => $d->format('Y-m-01'),
        default => $date,
    };
    $trendGrouped[$key] = ($trendGrouped[$key] ?? 0) + $value;
}
$cursor = match ($group) {
    'week' => $fromDate->modify('monday this week'),
    'month' => $fromDate->modify('first day of this month'),
    default => $fromDate,
};
$trend = [];
while ($cursor <= $toDate) {
    $key = $cursor->format('Y-m-d');
    $label = $group === 'month' ? $cursor->format('M y') : $cursor->format('j M');
    $trend[] = ['label' => $label, 'count' => (int) ($trendGrouped[$key] ?? 0)];
    $cursor = $cursor->modify(match ($group) {
        'week' => '+1 week',
        'month' => '+1 month',
        default => '+1 day',
    });
}

// SVG chart, rendered by PHP — no JavaScript or CDN required.
$chartW = 920;
$chartH = 286;
$left = 57;
$top = 20;
$plotW = 820;
$plotH = 207;
$maxCount = max(1, ...array_column($trend, 'count'));
$points = [];
foreach ($trend as $index => $bucket) {
    $x = $left + (count($trend) > 1 ? $index * $plotW / (count($trend) - 1) : $plotW / 2);
    $y = $top + $plotH * (1 - $bucket['count'] / $maxCount);
    $points[] = [round($x, 2), round($y, 2), $bucket];
}
$linePoints = implode(' ', array_map(
    static fn (array $p): string => $p[0] . ',' . $p[1],
    $points
));
$fillPoints = $points
    ? $points[0][0] . ',' . ($top + $plotH) . ' ' . $linePoints . ' ' .
        $points[count($points) - 1][0] . ',' . ($top + $plotH)
    : '';

$recent = array_slice($reservations, 0, 10);
$maxTurfHours = max(1.0, ...array_column($turfPerformance, 'hours'));
$maxSource = max(1, ...array_values($sources));

// All requested chart data is already fetched; now render the page.
tm_page_header('Reports & Analytics', 'reports', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-booking-reports.css?v=1">

<div class="br-page">
    <header class="br-heading">
        <div>
            <span class="br-eyebrow">REPORTS & ANALYTICS / BOOKINGS</span>
            <h2>Booking Reports</h2>
            <p>Track reservation demand, booking outcomes, sources, and turf activity.</p>
        </div>
        <span class="br-live">Read-only · Live records</span>
    </header>

    <nav class="br-tabs" aria-label="Report modules">
        <a href="reports.php">Overview</a>
        <a href="reports-financial.php">Financial</a>
        <a href="reports-bookings.php" class="active" aria-current="page">Bookings</a>
        <a href="reports-inventory.php">
    Inventory &amp; Maintenance
</a>
        <a href="reports-export.php">
    Export
</a>
    </nav>

    <form class="br-filters" method="get" action="reports-bookings.php">
        <label>
            Facility
            <select name="facility_id" id="br-facility">
                <option value="0">All my facilities</option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= (int) $facility['id'] ?>"
                        <?= $facilityId === (int) $facility['id'] ? 'selected' : '' ?>>
                        <?= tm_e((string) $facility['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Turf
            <select name="turf_id" id="br-turf">
                <option value="0">All turfs</option>
                <?php foreach ($turfs as $turf): ?>
                    <option value="<?= (int) $turf['id'] ?>"
                        data-facility="<?= (int) $turf['facility_id'] ?>"
                        <?= $turfId === (int) $turf['id'] ? 'selected' : '' ?>>
                        <?= tm_e((string) $turf['facility_name']) ?> — <?= tm_e((string) $turf['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Period
            <select name="period" id="br-period">
                <?php foreach ([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'week' => 'This week',
                    'month' => 'This month',
                    'year' => 'This year',
                    'custom' => 'Custom dates',
                ] as $value => $label): ?>
                    <option value="<?= tm_e($value) ?>" <?= $period === $value ? 'selected' : '' ?>>
                        <?= tm_e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Status
            <select name="status">
                <?php foreach ([
                    'all' => 'All statuses',
                    'pending' => 'Pending',
                    'confirmed' => 'Confirmed',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'rejected' => 'Rejected',
                ] as $value => $label): ?>
                    <option value="<?= tm_e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
                        <?= tm_e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            From
            <input type="date" name="from" value="<?= tm_e($from) ?>">
        </label>
        <label>
            To
            <input type="date" name="to" value="<?= tm_e($to) ?>">
        </label>

        <button type="submit">Apply Filters</button>
        <a class="br-reset" href="reports-bookings.php">Reset</a>
    </form>

    <?php if ($rangeNotice !== ''): ?>
        <div class="br-notice"><?= tm_e($rangeNotice) ?></div>
    <?php endif; ?>
    <?php if ($facilities === []): ?>
        <div class="br-notice">No facilities found for this owner.</div>
    <?php endif; ?>

    <div class="br-metrics">
        <article class="br-metric">
            <span>Reservations in period</span>
            <strong><?= number_format($total) ?></strong>
            <small>Includes pending, cancelled and rejected</small>
        </article>
        <article class="br-metric">
            <span>Confirmed &amp; completed</span>
            <strong><?= number_format($counts['confirmed'] + $counts['completed']) ?></strong>
            <small>Eligible bookings</small>
        </article>
        <article class="br-metric">
            <span>Eligible booking value</span>
            <strong><?= tm_e(tm_money($validValue)) ?></strong>
            <small>Not the same as cash collected</small>
        </article>
        <article class="br-metric">
            <span>Booked turf hours</span>
            <strong><?= number_format($bookedHours, 1) ?> hrs</strong>
            <small>Confirmed and completed only</small>
        </article>
        <article class="br-metric">
            <span>Pending reservations</span>
            <strong><?= number_format($counts['pending']) ?></strong>
            <small>Awaiting owner action</small>
        </article>
        <article class="br-metric">
            <span>Cancellation / rejection rate</span>
            <strong><?= number_format($cancellationRate, 1) ?>%</strong>
            <small>Of all filtered reservations</small>
        </article>
    </div>

    <div class="br-chart-grid">
        <section class="br-panel br-trend-panel">
            <div class="br-panel-head">
                <div>
                    <h3>Booking Trend</h3>
                    <p>Scheduled reservations · <?= tm_e($from) ?> to <?= tm_e($to) ?></p>
                </div>
                <span class="br-chip"><?= tm_e(ucfirst($group)) ?> totals</span>
            </div>

            <div class="br-svg-wrap">
                <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>"
                     role="img" aria-label="Bookings over the selected date range">
                    <defs>
                        <linearGradient id="brAreaFill" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="#3da16a" stop-opacity=".20" />
                            <stop offset="100%" stop-color="#3da16a" stop-opacity=".01" />
                        </linearGradient>
                    </defs>
                    <?php for ($step = 0; $step <= 4; $step++): ?>
                        <?php
                        $y = $top + $plotH * $step / 4;
                        $tickValue = $maxCount * (4 - $step) / 4;
                        ?>
                        <line x1="<?= $left ?>" y1="<?= $y ?>"
                              x2="<?= $left + $plotW ?>" y2="<?= $y ?>"
                              stroke="#e3ece6" stroke-dasharray="4 5" />
                        <text x="<?= $left - 12 ?>" y="<?= $y + 4 ?>"
                              text-anchor="end" class="br-axis-label">
                            <?= tm_e(tm_br_short($tickValue)) ?>
                        </text>
                    <?php endfor; ?>

                    <?php if ($points !== []): ?>
                        <polygon points="<?= tm_e($fillPoints) ?>" fill="url(#brAreaFill)" />
                        <polyline points="<?= tm_e($linePoints) ?>"
                                  fill="none" stroke="#237b52" stroke-width="3.5"
                                  stroke-linecap="round" stroke-linejoin="round" />
                        <?php foreach ($points as $index => $point): ?>
                            <circle cx="<?= $point[0] ?>" cy="<?= $point[1] ?>"
                                    r="<?= count($points) <= 16 ? 4 : 2.5 ?>"
                                    fill="#237b52" stroke="#fff" stroke-width="1.3">
                                <title><?= tm_e($point[2]['label']) ?>: <?= (int) $point[2]['count'] ?> reservations</title>
                            </circle>
                            <?php if ($index === 0 || $index === count($points) - 1 ||
                                $index % max(1, (int) ceil(count($points) / 7)) === 0): ?>
                                <text x="<?= $point[0] ?>" y="<?= $top + $plotH + 26 ?>"
                                      text-anchor="middle" class="br-axis-label">
                                    <?= tm_e($point[2]['label']) ?>
                                </text>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </svg>
            </div>
        </section>

        <section class="br-panel">
            <div class="br-panel-head">
                <div>
                    <h3>Booking Status</h3>
                    <p>Outcomes of filtered reservations</p>
                </div>
            </div>
            <?php
            $statusColors = [
                'confirmed' => '#237b52',
                'completed' => '#68b78a',
                'pending' => '#f0b55f',
                'cancelled' => '#e37a7a',
                'rejected' => '#9aa6a0',
            ];
            $slices = [];
            $stop = 0.0;
            foreach ($statusColors as $key => $color) {
                if ($total === 0 || $counts[$key] === 0) {
                    continue;
                }
                $next = $stop + $counts[$key] * 100 / $total;
                $slices[] = $color . ' ' . $stop . '% ' . $next . '%';
                $stop = $next;
            }
            $gradient = $slices !== []
                ? 'conic-gradient(' . implode(', ', $slices) . ')'
                : 'conic-gradient(#e0eae4 0% 100%)';
            ?>
            <div class="br-status-layout">
                <div class="br-donut" style="background: <?= tm_e($gradient) ?>">
                    <div class="br-donut-center">
                        <span>Reservations</span>
                        <strong><?= number_format($total) ?></strong>
                    </div>
                </div>
                <div class="br-legend">
                    <?php foreach ($statusColors as $key => $color): ?>
                        <div>
                            <span><i style="background: <?= tm_e($color) ?>"></i><?= tm_e(tm_br_status($key)) ?></span>
                            <strong><?= number_format($counts[$key]) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="br-detail-grid">
        <section class="br-panel">
            <div class="br-panel-head">
                <div>
                    <h3>Bookings by Source</h3>
                    <p>How customers make reservations</p>
                </div>
            </div>
            <?php foreach (['online' => 'Online', 'walk_in' => 'Walk-in', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'] as $key => $label): ?>
                <div class="br-bar-row">
                    <div class="br-bar-title">
                        <span><?= tm_e($label) ?></span>
                        <strong><?= number_format($sources[$key]) ?></strong>
                    </div>
                    <div class="br-bar-track"><div class="br-bar-fill"
                        style="width: <?= $maxSource > 0 ? round($sources[$key] / $maxSource * 100, 2) : 0 ?>%"></div></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="br-panel">
            <div class="br-panel-head">
                <div>
                    <h3>Bookings by Weekday</h3>
                    <p>Which days receive the most reservations</p>
                </div>
            </div>
            <?php $weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
            <div class="br-weekdays">
                <?php foreach ($weekdayCounts as $index => $value): ?>
                    <div class="br-weekday">
                        <strong><?= (int) $value ?></strong>
                        <div class="br-weekday-track">
                            <div style="height: <?= round($value / max(1, ...$weekdayCounts) * 100, 2) ?>%"></div>
                        </div>
                        <span><?= $weekdayNames[$index] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="br-panel">
        <div class="br-panel-head">
            <div>
                <h3>Turf Activity</h3>
                <p>Confirmed/completed reservation hours and booking value per turf</p>
            </div>
            <span class="br-chip">Booked hours, not utilization %</span>
        </div>
        <?php if ($turfPerformance === []): ?>
            <p class="br-empty">No reservations match these filters.</p>
        <?php else: ?>
            <div class="br-table-wrap">
                <table class="br-table">
                    <thead>
                        <tr>
                            <th>Turf</th>
                            <th>Reservations</th>
                            <th>Booked hours</th>
                            <th>Booking value</th>
                            <th>Relative activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($turfPerformance as $turf): ?>
                            <tr>
                                <td><strong><?= tm_e($turf['name']) ?></strong><small><?= tm_e($turf['facility']) ?></small></td>
                                <td><?= (int) $turf['bookings'] ?></td>
                                <td><?= number_format((float) $turf['hours'], 1) ?> hrs</td>
                                <td><?= tm_e(tm_money($turf['value'])) ?></td>
                                <td class="br-performance-cell">
                                    <div class="br-bar-track"><div class="br-bar-fill"
                                        style="width: <?= round($turf['hours'] / $maxTurfHours * 100, 2) ?>%"></div></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="br-panel">
        <div class="br-panel-head">
            <div>
                <h3>Recent Reservations</h3>
                <p>Latest scheduled reservations for the selected filters</p>
            </div>
            <a href="bookings.php" class="br-manage-link">Manage bookings →</a>
        </div>
        <?php if ($recent === []): ?>
            <p class="br-empty">No reservations in this date range.</p>
        <?php else: ?>
            <div class="br-table-wrap">
                <table class="br-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Turf</th>
                            <th>Booking date</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><strong><?= $row['kind'] === 'group' ? 'Group #' : '#' ?><?= (int) $row['record_id'] ?></strong></td>
                                <td><?= tm_e((string) $row['customer_name']) ?></td>
                                <td><?= tm_e((string) $row['turf_name']) ?></td>
                                <td><?= tm_e((string) $row['booking_date']) ?></td>
                                <td><?= tm_e(ucwords(str_replace('_', ' ', (string) $row['source']))) ?></td>
                                <td><span class="br-status br-status-<?= tm_e((string) $row['status']) ?>">
                                    <?= tm_e(tm_br_status((string) $row['status'])) ?>
                                </span></td>
                                <td><?= tm_e(tm_money($row['total_amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="br-note">
        <strong>How these reports are calculated:</strong>
        One overnight customer group counts as one reservation, using its group status and complete duration.
        Other bookings count individually. Value and booked hours include only confirmed and completed
        reservations; all statuses are included in the total and demand charts unless you filter by status.
        Booked hours are not an occupancy percentage because opening-hour capacity has not been calculated.
        Reports use scheduled booking dates, not payment dates. Customer booking requests that are not
        converted to bookings are not counted.
    </div>
</div>

<script>
// Small enhancement only: retain consistent turf selection when facility changes.
(() => {
    const facility = document.getElementById('br-facility');
    const turf = document.getElementById('br-turf');
    if (!facility || !turf) return;

    const update = () => {
        const selected = facility.value;
        for (const option of turf.options) {
            if (!option.value) continue;
            option.hidden = selected !== '0' && option.dataset.facility !== selected;
        }
        if (turf.selectedOptions[0]?.hidden) turf.value = '0';
    };
    facility.addEventListener('change', update);
    update();
})();
</script>
<?php tm_page_footer(); ?>
