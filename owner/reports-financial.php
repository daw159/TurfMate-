<?php
declare(strict_types=1);

/**
 * TurfMate | Owner Financial Reports
 * Read-only reports; works with existing bookings, payments, expenses and turfs.
 * Requires the existing owner/_init.php and owner/_layout.php helpers.
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_fin_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function tm_fin_short_money(float $amount): string
{
    $prefix = $amount < 0 ? '-' : '';
    $amount = abs($amount);
    if ($amount >= 1000000) {
        return $prefix . 'Rs. ' . number_format($amount / 1000000, 1) . 'm';
    }
    if ($amount >= 1000) {
        return $prefix . 'Rs. ' . number_format($amount / 1000, 1) . 'k';
    }
    return $prefix . 'Rs. ' . number_format($amount, 0);
}

function tm_fin_period_key(string $dateField, string $group): string
{
    return match ($group) {
        'day' => "DATE_FORMAT($dateField, '%Y-%m-%d')",
        'week' => "DATE_FORMAT(DATE_SUB($dateField, INTERVAL WEEKDAY($dateField) DAY), '%Y-%m-%d')",
        default => "DATE_FORMAT($dateField, '%Y-%m-01')",
    };
}

function tm_fin_query(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tm_fin_sum(PDO $pdo, string $sql, array $params): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Filters: never accept an owner ID from a query string.
// ---------------------------------------------------------------------------
$facilities = tm_fin_query(
    $pdo,
    'SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name',
    [$ownerId]
);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

$facilityRequest = filter_var(
    $_GET['facility_id'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$facilityId = $facilityRequest !== false
    && in_array((int) $facilityRequest, $facilityIds, true)
    ? (int) $facilityRequest
    : 0;

$turfs = tm_fin_query(
    $pdo,
    'SELECT t.id, t.name, t.facility_id, f.name AS facility_name
     FROM turfs t
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
     ORDER BY f.name, t.name',
    [$ownerId]
);
$allowedTurfs = [];
foreach ($turfs as $turf) {
    $allowedTurfs[(int) $turf['id']] = (int) $turf['facility_id'];
}
$turfsForDropdown = array_values(array_filter(
    $turfs,
    static fn (array $t): bool => $facilityId === 0 || (int) $t['facility_id'] === $facilityId
));
$turfRequest = filter_var(
    $_GET['turf_id'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$turfId = $turfRequest !== false && isset($allowedTurfs[(int) $turfRequest])
    && ($facilityId === 0 || $allowedTurfs[(int) $turfRequest] === $facilityId)
    ? (int) $turfRequest
    : 0;

// If a turf is selected, facility-level expenses cover its parent facility.
$expenseFacilityId = $turfId > 0 ? $allowedTurfs[$turfId] : $facilityId;

$today = new DateTimeImmutable('today');
$period = (string) ($_GET['period'] ?? 'month');
if (!in_array($period, ['today', 'yesterday', 'week', 'month', 'year', 'custom'], true)) {
    $period = 'month';
}

switch ($period) {
    case 'today':
        $from = $today->format('Y-m-d');
        $to = $from;
        break;
    case 'yesterday':
        $from = $today->modify('-1 day')->format('Y-m-d');
        $to = $from;
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
        if (!tm_fin_valid_date($from)) {
            $from = $today->format('Y-m-01');
        }
        if (!tm_fin_valid_date($to)) {
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

$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
$notice = '';
if ($fromDate->diff($toDate)->days > 730) {
    $fromDate = $toDate->modify('-730 days');
    $from = $fromDate->format('Y-m-d');
    $notice = 'Date range limited to the latest 731 days for chart readability.';
}
$days = $fromDate->diff($toDate)->days + 1;
$group = $days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month');

$bookingFilter = $turfId > 0
    ? ' AND t.id = ?'
    : ($facilityId > 0 ? ' AND f.id = ?' : '');
$bookingParams = [$ownerId, $from, $to];
if ($turfId > 0) {
    $bookingParams[] = $turfId;
} elseif ($facilityId > 0) {
    $bookingParams[] = $facilityId;
}

$expenseFilter = $expenseFacilityId > 0 ? ' AND f.id = ?' : '';
$expenseParams = [$ownerId, $from, $to];
if ($expenseFacilityId > 0) {
    $expenseParams[] = $expenseFacilityId;
}
$paymentFilter = $bookingFilter;
$paymentParams = $bookingParams;

// ---------------------------------------------------------------------------
// KPI totals. Never join raw payment rows to booking amounts: row multiplication
// would inflate booking revenue. Booking value excludes pending/cancelled/rejected.
// ---------------------------------------------------------------------------
$bookingValue = tm_fin_sum(
    $pdo,
    "SELECT COALESCE(SUM(b.total_amount), 0)
     FROM bookings b
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND b.status IN ('confirmed', 'completed')
       AND b.booking_date BETWEEN ? AND ? $bookingFilter",
    $bookingParams
);

$collected = tm_fin_sum(
    $pdo,
    "SELECT COALESCE(SUM(CASE
            WHEN p.status = 'received' THEN p.amount
            WHEN p.status = 'refunded' THEN -p.amount
            ELSE 0 END), 0)
     FROM payments p
     INNER JOIN bookings b ON b.id = p.booking_id
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND DATE(p.paid_at) BETWEEN ? AND ? $paymentFilter",
    $paymentParams
);

$expenses = tm_fin_sum(
    $pdo,
    "SELECT COALESCE(SUM(e.amount), 0)
     FROM expenses e
     INNER JOIN facilities f ON f.id = e.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND e.expense_date BETWEEN ? AND ? $expenseFilter",
    $expenseParams
);
$cashSurplus = $collected - $expenses;

// Outstanding is the CURRENT unpaid value of eligible bookings whose BOOKING
// DATES are in the selected range. Includes payments from all dates, not only
// those received in the selected period; otherwise it would inflate arrears.
$bookingBalancesSql = "
    FROM bookings b
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    LEFT JOIN (
        SELECT booking_id,
               SUM(CASE WHEN status = 'received' THEN amount
                        WHEN status = 'refunded' THEN -amount
                        ELSE 0 END) AS net_paid
        FROM payments
        GROUP BY booking_id
    ) paid ON paid.booking_id = b.id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
      AND b.status IN ('confirmed', 'completed')
      AND b.booking_date BETWEEN ? AND ? $bookingFilter
";

$balanceRows = tm_fin_query(
    $pdo,
    "SELECT b.id, b.customer_name, b.booking_date, b.total_amount,
            b.status, t.name AS turf_name, f.name AS facility_name,
            COALESCE(paid.net_paid, 0) AS net_paid
     $bookingBalancesSql
     ORDER BY b.booking_date DESC, b.id DESC",
    $bookingParams
);

$outstanding = 0.0;
$cohortCollected = 0.0;
$unpaidBookings = [];
foreach ($balanceRows as $row) {
    $total = (float) $row['total_amount'];
    $paid = (float) $row['net_paid'];
    $remaining = max(0.0, $total - $paid);
    $outstanding += $remaining;
    $cohortCollected += min($total, max(0.0, $paid));
    if ($remaining > 0.005) {
        $row['remaining'] = $remaining;
        $unpaidBookings[] = $row;
    }
}
$collectionRate = $bookingValue > 0
    ? min(100.0, $cohortCollected * 100 / $bookingValue)
    : 0.0;

// ---------------------------------------------------------------------------
// Three-line financial performance chart: separate aggregate queries.
// Booking value by booking date, collected cash by payment date, expenses by
// expense date. Weekly/monthly grouping scales with selected range.
// ---------------------------------------------------------------------------
$bookingBucket = tm_fin_period_key('b.booking_date', $group);
$paymentBucket = tm_fin_period_key('p.paid_at', $group);
$expenseBucket = tm_fin_period_key('e.expense_date', $group);

$bookingTrendRows = tm_fin_query(
    $pdo,
    "SELECT $bookingBucket AS bucket, SUM(b.total_amount) AS total
     FROM bookings b
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND b.status IN ('confirmed', 'completed')
       AND b.booking_date BETWEEN ? AND ? $bookingFilter
     GROUP BY bucket ORDER BY bucket",
    $bookingParams
);
$paymentTrendRows = tm_fin_query(
    $pdo,
    "SELECT $paymentBucket AS bucket,
            SUM(CASE WHEN p.status = 'received' THEN p.amount
                     WHEN p.status = 'refunded' THEN -p.amount
                     ELSE 0 END) AS total
     FROM payments p
     INNER JOIN bookings b ON b.id = p.booking_id
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND DATE(p.paid_at) BETWEEN ? AND ? $paymentFilter
     GROUP BY bucket ORDER BY bucket",
    $paymentParams
);
$expenseTrendRows = tm_fin_query(
    $pdo,
    "SELECT $expenseBucket AS bucket, SUM(e.amount) AS total
     FROM expenses e
     INNER JOIN facilities f ON f.id = e.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND e.expense_date BETWEEN ? AND ? $expenseFilter
     GROUP BY bucket ORDER BY bucket",
    $expenseParams
);

$trendMaps = [];
foreach (['booking' => $bookingTrendRows, 'payment' => $paymentTrendRows, 'expense' => $expenseTrendRows] as $name => $rows) {
    $trendMaps[$name] = [];
    foreach ($rows as $row) {
        $trendMaps[$name][(string) $row['bucket']] = (float) $row['total'];
    }
}

$cursor = match ($group) {
    'week' => $fromDate->modify('monday this week'),
    'month' => $fromDate->modify('first day of this month'),
    default => $fromDate,
};
$chartRows = [];
while ($cursor <= $toDate) {
    $key = $cursor->format('Y-m-d');
    $chartRows[] = [
        'label' => $group === 'month' ? $cursor->format('M y') : $cursor->format('j M'),
        'booking' => $trendMaps['booking'][$key] ?? 0.0,
        'payment' => $trendMaps['payment'][$key] ?? 0.0,
        'expense' => $trendMaps['expense'][$key] ?? 0.0,
    ];
    $cursor = $cursor->modify(match ($group) {
        'day' => '+1 day',
        'week' => '+1 week',
        default => '+1 month',
    });
}

$series = [
    'booking' => ['label' => 'Booking revenue', 'color' => '#176b46'],
    'payment' => ['label' => 'Cash collected', 'color' => '#518dcb'],
    'expense' => ['label' => 'Expenses', 'color' => '#ec9c52'],
];
$chartMin = 0.0;
$chartMax = 1.0;
foreach ($chartRows as $row) {
    foreach (array_keys($series) as $seriesKey) {
        $chartMin = min($chartMin, (float) $row[$seriesKey]);
        $chartMax = max($chartMax, (float) $row[$seriesKey]);
    }
}
$chartW = 950;
$chartH = 300;
$chartLeft = 82;
$chartTop = 25;
$plotW = 835;
$plotH = 215;
$count = count($chartRows);
$lineData = [];
foreach ($series as $seriesKey => $style) {
    $points = [];
    foreach ($chartRows as $idx => $row) {
        $x = $chartLeft + ($count > 1 ? $idx * $plotW / ($count - 1) : $plotW / 2);
        $y = $chartTop + $plotH * ($chartMax - (float) $row[$seriesKey]) / ($chartMax - $chartMin);
        $points[] = ['x' => round($x, 2), 'y' => round($y, 2), 'row' => $row];
    }
    $lineData[$seriesKey] = [
        'points' => $points,
        'path' => implode(' ', array_map(
            static fn (array $p): string => $p['x'] . ',' . $p['y'],
            $points
        )),
    ];
}

// ---------------------------------------------------------------------------
// Expense categories, payment methods, revenue by turf and outstanding list.
// ---------------------------------------------------------------------------
$categoryRows = tm_fin_query(
    $pdo,
    "SELECT e.category, SUM(e.amount) AS total
     FROM expenses e
     INNER JOIN facilities f ON f.id = e.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND e.expense_date BETWEEN ? AND ? $expenseFilter
     GROUP BY e.category ORDER BY total DESC",
    $expenseParams
);
$categoryTotals = [];
foreach ($categoryRows as $idx => $row) {
    $name = $idx < 5 ? (string) $row['category'] : 'Other';
    $categoryTotals[$name] = ($categoryTotals[$name] ?? 0.0) + (float) $row['total'];
}
$palette = ['#1e724c', '#62a97b', '#97cba8', '#f3b666', '#7f9eca', '#be9bca'];
$categoryTotal = array_sum($categoryTotals);
$categoryDisplay = [];
$donutStops = [];
$cursorPct = 0.0;
$colorIndex = 0;
foreach ($categoryTotals as $name => $amount) {
    if ($amount <= 0 || $categoryTotal <= 0) {
        continue;
    }
    $color = $palette[$colorIndex % count($palette)];
    $nextPct = $cursorPct + $amount / $categoryTotal * 100;
    $donutStops[] = sprintf('%s %.3f%% %.3f%%', $color, $cursorPct, $nextPct);
    $categoryDisplay[] = [
        'name' => $name,
        'amount' => $amount,
        'percent' => $amount / $categoryTotal * 100,
        'color' => $color,
    ];
    $cursorPct = $nextPct;
    $colorIndex++;
}
$donutBackground = $donutStops
    ? 'conic-gradient(' . implode(', ', $donutStops) . ')'
    : 'conic-gradient(#e5ece7 0% 100%)';

$methodRows = tm_fin_query(
    $pdo,
    "SELECT p.method,
            SUM(CASE WHEN p.status = 'received' THEN p.amount
                     WHEN p.status = 'refunded' THEN -p.amount
                     ELSE 0 END) AS total
     FROM payments p
     INNER JOIN bookings b ON b.id = p.booking_id
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND DATE(p.paid_at) BETWEEN ? AND ? $paymentFilter
     GROUP BY p.method ORDER BY total DESC",
    $paymentParams
);
$methodMax = 1.0;
foreach ($methodRows as $row) {
    $methodMax = max($methodMax, abs((float) $row['total']));
}

$turfRows = tm_fin_query(
    $pdo,
    "SELECT t.name AS turf_name, f.name AS facility_name, SUM(b.total_amount) AS total
     FROM bookings b
     INNER JOIN turfs t ON t.id = b.turf_id
     INNER JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL
       AND b.status IN ('confirmed', 'completed')
       AND b.booking_date BETWEEN ? AND ? $bookingFilter
     GROUP BY t.id, t.name, f.name ORDER BY total DESC LIMIT 8",
    $bookingParams
);
$turfMax = 1.0;
foreach ($turfRows as $row) {
    $turfMax = max($turfMax, (float) $row['total']);
}

$unpaidShown = array_slice($unpaidBookings, 0, 10);

// Show the standard owner page structure and report tabs.
tm_page_header('Reports & Analytics', 'reports', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-financial.css?v=1">

<div class="fin-page">
    <header class="fin-heading">
        <div>
            <span class="fin-eyebrow">REPORTS & ANALYTICS / FINANCIAL</span>
            <h2>Financial Reports</h2>
            <p>See booking value, actual collections, business spending and outstanding balances.</p>
        </div>
        <span class="fin-live">Read-only · Live data</span>
    </header>

    <nav class="fin-tabs" aria-label="Reports sections">
        <a href="reports.php">Overview</a>
        <a href="reports-financial.php" class="active" aria-current="page">Financial</a>
        <span class="fin-upcoming">Bookings <small>soon</small></span>
        <span class="fin-upcoming">Inventory &amp; Maintenance <small>soon</small></span>
        <span class="fin-upcoming">Export <small>soon</small></span>
    </nav>

    <form class="fin-filters" action="reports-financial.php" method="get">
        <label>
            Facility
            <select name="facility_id" id="finFacility">
                <option value="0">All my facilities</option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= (int) $facility['id'] ?>" <?= $facilityId === (int) $facility['id'] ? 'selected' : '' ?>>
                        <?= tm_e((string) $facility['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Turf
            <select name="turf_id" id="finTurf">
                <option value="0">All turfs</option>
                <?php foreach ($turfsForDropdown as $turf): ?>
                    <option value="<?= (int) $turf['id'] ?>" <?= $turfId === (int) $turf['id'] ? 'selected' : '' ?>>
                        <?= tm_e((string) $turf['name']) ?> · <?= tm_e((string) $turf['facility_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Period
            <select name="period" id="finPeriod">
                <?php foreach ([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'week' => 'This week',
                    'month' => 'This month',
                    'year' => 'This year',
                    'custom' => 'Custom range',
                ] as $key => $title): ?>
                    <option value="<?= tm_e($key) ?>" <?= $period === $key ? 'selected' : '' ?>>
                        <?= tm_e($title) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            From
            <input name="from" id="finFrom" type="date" value="<?= tm_e($from) ?>">
        </label>

        <label>
            To
            <input name="to" id="finTo" type="date" value="<?= tm_e($to) ?>">
        </label>

        <div class="fin-filter-actions">
            <button type="submit">Apply Filters</button>
            <a href="reports-financial.php">Reset</a>
        </div>
    </form>

    <?php if ($notice !== ''): ?>
        <div class="fin-notice"><?= tm_e($notice) ?></div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="fin-notice">
            You do not have any active facilities yet.
            <a href="facilities.php">Create a facility</a> to start reporting.
        </div>
    <?php endif; ?>

    <?php if ($turfId > 0): ?>
        <div class="fin-notice">
            Turf-specific booking and payment figures are shown. Expenses are recorded at facility level,
            so spending and cash surplus reflect the selected turf's <strong>entire facility</strong>.
        </div>
    <?php endif; ?>

    <div class="fin-range-caption">
        Reporting dates: <strong><?= tm_e($from) ?> to <?= tm_e($to) ?></strong>
        <span>Booking date · Payment date · Expense date</span>
    </div>

    <section class="fin-metrics" aria-label="Financial summary">
        <article class="fin-metric">
            <span>Booking Revenue</span>
            <strong><?= tm_money($bookingValue) ?></strong>
            <small>Confirmed and completed bookings dated in range</small>
        </article>

        <article class="fin-metric">
            <span>Net Payments Collected</span>
            <strong><?= tm_money($collected) ?></strong>
            <small>Received minus refunded, paid in range</small>
        </article>

        <article class="fin-metric">
            <span>Outstanding Balance</span>
            <strong><?= tm_money($outstanding) ?></strong>
            <small>Currently unpaid on bookings dated in range</small>
        </article>

        <article class="fin-metric">
            <span>Total Expenses</span>
            <strong><?= tm_money($expenses) ?></strong>
            <small>Recorded facility spending in range</small>
        </article>

        <article class="fin-metric <?= $cashSurplus < 0 ? 'fin-negative' : '' ?>">
            <span>Cash Surplus / Deficit</span>
            <strong><?= tm_money($cashSurplus) ?></strong>
            <small>Net collections minus recorded expenses</small>
        </article>

        <article class="fin-metric">
            <span>Collection Rate</span>
            <strong><?= number_format($collectionRate, 1) ?>%</strong>
            <small>Current paid share of bookings dated in range</small>
        </article>
    </section>

    <section class="fin-panel">
        <div class="fin-panel-heading">
            <div>
                <h3>Financial Performance</h3>
                <p>Booking revenue vs. collections vs. expenses</p>
            </div>
            <span class="fin-chip"><?= tm_e(['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'][$group]) ?> trend</span>
        </div>

        <div class="fin-line-chart">
            <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>"
                 role="img"
                 aria-label="Financial trend for selected dates"
                 preserveAspectRatio="xMidYMid meet">
                <?php for ($tick = 0; $tick <= 4; $tick++): ?>
                    <?php
                    $y = $chartTop + $tick * $plotH / 4;
                    $value = $chartMax - ($chartMax - $chartMin) * $tick / 4;
                    ?>
                    <line x1="<?= $chartLeft ?>" y1="<?= $y ?>"
                          x2="<?= $chartLeft + $plotW ?>" y2="<?= $y ?>"
                          stroke="#e5eee8" stroke-dasharray="4 5" />
                    <text x="<?= $chartLeft - 10 ?>" y="<?= $y + 4 ?>"
                          text-anchor="end" class="fin-axis">
                        <?= tm_e(tm_fin_short_money($value)) ?>
                    </text>
                <?php endfor; ?>

                <?php foreach ($series as $key => $style): ?>
                    <polyline points="<?= tm_e($lineData[$key]['path']) ?>"
                              fill="none" stroke="<?= tm_e($style['color']) ?>"
                              stroke-width="3.2" stroke-linecap="round"
                              stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                    <?php foreach ($lineData[$key]['points'] as $index => $point): ?>
                        <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>"
                                r="<?= $count <= 20 ? '3.8' : '2.5' ?>"
                                fill="<?= tm_e($style['color']) ?>" stroke="#fff" stroke-width="1.4">
                            <title>
                                <?= tm_e($style['label'] . ' · ' . $point['row']['label']) ?>:
                                <?= tm_e(tm_money($point['row'][$key])) ?>
                            </title>
                        </circle>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php foreach ($lineData['booking']['points'] as $idx => $point): ?>
                    <?php if ($idx === 0 || $idx === $count - 1 || $idx % max(1, (int) ceil($count / 6)) === 0): ?>
                        <text x="<?= $point['x'] ?>" y="<?= $chartTop + $plotH + 26 ?>"
                              text-anchor="middle" class="fin-axis">
                            <?= tm_e($point['row']['label']) ?>
                        </text>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
        </div>

        <div class="fin-series-legend">
            <?php foreach ($series as $style): ?>
                <span>
                    <i style="background: <?= tm_e($style['color']) ?>"></i>
                    <?= tm_e($style['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="fin-two-panels">
        <section class="fin-panel">
            <div class="fin-panel-heading">
                <div>
                    <h3>Expenses by Category</h3>
                    <p>How recorded spending is distributed</p>
                </div>
            </div>

            <div class="fin-donut-layout">
                <div class="fin-donut" style="background: <?= tm_e($donutBackground) ?>"
                     role="img" aria-label="Expenses grouped by category">
                    <div class="fin-donut-center">
                        <small>Total expenses</small>
                        <strong><?= tm_e(tm_fin_short_money($categoryTotal)) ?></strong>
                    </div>
                </div>

                <div class="fin-category-legend">
                    <?php if (!$categoryDisplay): ?>
                        <p class="fin-empty">No expenses for this period.</p>
                    <?php endif; ?>

                    <?php foreach ($categoryDisplay as $entry): ?>
                        <div class="fin-legend-line">
                            <span>
                                <i style="background: <?= tm_e($entry['color']) ?>"></i>
                                <?= tm_e($entry['name']) ?>
                            </span>
                            <strong><?= number_format($entry['percent'], 1) ?>%</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="fin-panel">
            <div class="fin-panel-heading">
                <div>
                    <h3>Payments by Method</h3>
                    <p>Net collected after refunds, grouped by method</p>
                </div>
            </div>

            <div class="fin-bar-list">
                <?php if (!$methodRows): ?>
                    <p class="fin-empty">No payments for this period.</p>
                <?php endif; ?>

                <?php foreach ($methodRows as $method): ?>
                    <?php $amount = (float) $method['total']; ?>
                    <div class="fin-bar-row">
                        <div class="fin-bar-caption">
                            <strong><?= tm_e(ucwords(str_replace('_', ' ', (string) $method['method']))) ?></strong>
                            <span><?= tm_money($amount) ?></span>
                        </div>
                        <div class="fin-bar-track">
                            <div class="fin-bar-fill <?= $amount < 0 ? 'fin-refund' : '' ?>"
                                 style="width: <?= round(abs($amount) / $methodMax * 100, 2) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="fin-panel">
        <div class="fin-panel-heading">
            <div>
                <h3>Booking Revenue by Turf</h3>
                <p>Top turfs by confirmed/completed booking value</p>
            </div>
        </div>

        <div class="fin-bar-list fin-turf-list">
            <?php if (!$turfRows): ?>
                <p class="fin-empty">No eligible bookings for this period.</p>
            <?php endif; ?>

            <?php foreach ($turfRows as $turf): ?>
                <?php $amount = (float) $turf['total']; ?>
                <div class="fin-bar-row">
                    <div class="fin-bar-caption">
                        <strong>
                            <?= tm_e((string) $turf['turf_name']) ?>
                            <small><?= tm_e((string) $turf['facility_name']) ?></small>
                        </strong>
                        <span><?= tm_money($amount) ?></span>
                    </div>
                    <div class="fin-bar-track">
                        <div class="fin-bar-fill" style="width: <?= round($amount / $turfMax * 100, 2) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="fin-panel">
        <div class="fin-panel-heading">
            <div>
                <h3>Outstanding Payments</h3>
                <p>
                    <?= count($unpaidBookings) ?> eligible bookings with unpaid balances
                    · currently outstanding: <strong><?= tm_money($outstanding) ?></strong>
                </p>
            </div>
            <a href="bookings.php" class="fin-manage-link">Manage Bookings →</a>
        </div>

        <div class="fin-table-scroll">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Customer</th>
                        <th>Turf</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Net Paid</th>
                        <th>Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$unpaidShown): ?>
                        <tr>
                            <td colspan="7" class="fin-empty-cell">
                                No outstanding bookings for the selected period.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($unpaidShown as $booking): ?>
                        <tr>
                            <td>#<?= (int) $booking['id'] ?></td>
                            <td><?= tm_e((string) $booking['customer_name']) ?></td>
                            <td><?= tm_e((string) $booking['turf_name']) ?></td>
                            <td><?= tm_e((string) $booking['booking_date']) ?></td>
                            <td><?= tm_money($booking['total_amount']) ?></td>
                            <td><?= tm_money($booking['net_paid']) ?></td>
                            <td class="fin-unpaid"><?= tm_money($booking['remaining']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($unpaidBookings) > 10): ?>
            <p class="fin-table-hint">Showing 10 of <?= count($unpaidBookings) ?> unpaid bookings.</p>
        <?php endif; ?>
    </section>

    <div class="fin-footnote">
        <strong>How figures are calculated:</strong>
        Booking revenue uses confirmed/completed booking dates. Net collections use payment dates
        (receipts minus refunds). Expenses use expense dates. Outstanding balances and collection
        rate refer to the <em>current</em> payment status of bookings dated in the selected range.
        Cash surplus is not accounting net profit. Maintenance costs are counted only when entered
        in Expenses; they are not added again from maintenance records.
    </div>
</div>

<script>
(function () {
    const period = document.getElementById('finPeriod');
    const from = document.getElementById('finFrom');
    const to = document.getElementById('finTo');
    const facility = document.getElementById('finFacility');
    const turf = document.getElementById('finTurf');

    function toggleCustom() {
        const custom = period.value === 'custom';
        from.disabled = !custom;
        to.disabled = !custom;
    }

    period.addEventListener('change', toggleCustom);
    facility.addEventListener('change', function () {
        // Reset a previously selected turf after changing facility.
        turf.value = '0';
    });
    toggleCustom();
})();
</script>

<?php tm_page_footer(); ?>
