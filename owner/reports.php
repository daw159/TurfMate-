<?php
declare(strict_types=1);

/**
 * TurfMate — Owner Reports & Analytics overview.
 * Read-only dashboard using existing tables. No new tables or dependencies.
 * Booking value uses confirmed/completed bookings by booking_date.
 * Collected cash uses payments.paid_at (received less refunded).
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_rp_date_valid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function tm_rp_compact_money(float $amount): string
{
    $sign = $amount < 0 ? '-' : '';
    $value = abs($amount);
    if ($value >= 1000000) {
        return $sign . 'Rs. ' . number_format($value / 1000000, 1) . 'm';
    }
    if ($value >= 1000) {
        return $sign . 'Rs. ' . number_format($value / 1000, 1) . 'k';
    }
    return $sign . 'Rs. ' . number_format($value, 0);
}

function tm_rp_chart_label(DateTimeImmutable $date, string $interval): string
{
    return match ($interval) {
        'day' => $date->format('j M'),
        'week' => $date->format('j M'),
        default => $date->format('M y'),
    };
}

// Facilities always come from the signed-in owner, never an input owner ID.
$facilityStmt = $pdo->prepare(
    'SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name'
);
$facilityStmt->execute([$ownerId]);
$facilities = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedFacilityIds = array_map('intval', array_column($facilities, 'id'));

$facilityFilter = filter_var(
    $_GET['facility_id'] ?? 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$facilityId = $facilityFilter !== false
    && in_array((int) $facilityFilter, $allowedFacilityIds, true)
    ? (int) $facilityFilter
    : 0;

$today = new DateTimeImmutable('today');
$period = (string) ($_GET['period'] ?? 'month');
$periodOptions = ['today', 'yesterday', 'week', 'month', 'year', 'custom'];
if (!in_array($period, $periodOptions, true)) {
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
        if (!tm_rp_date_valid($from)) {
            $from = $today->format('Y-m-01');
        }
        if (!tm_rp_date_valid($to)) {
            $to = $today->format('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        break;
    default:
        $from = $today->format('Y-m-01');
        $to = $today->format('Y-m-d');
        break;
}

$notice = '';
$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
if ($fromDate->diff($toDate)->days > 730) {
    $fromDate = $toDate->modify('-730 days');
    $from = $fromDate->format('Y-m-d');
    $notice = 'The chart range was limited to 731 days.';
}

$days = $fromDate->diff($toDate)->days + 1;
$interval = $days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month');
$bookingBucket = match ($interval) {
    'day' => "DATE_FORMAT(b.booking_date, '%Y-%m-%d')",
    'week' => "DATE_FORMAT(DATE_SUB(b.booking_date, INTERVAL WEEKDAY(b.booking_date) DAY), '%Y-%m-%d')",
    default => "DATE_FORMAT(b.booking_date, '%Y-%m-01')",
};
$expenseBucket = match ($interval) {
    'day' => "DATE_FORMAT(e.expense_date, '%Y-%m-%d')",
    'week' => "DATE_FORMAT(DATE_SUB(e.expense_date, INTERVAL WEEKDAY(e.expense_date) DAY), '%Y-%m-%d')",
    default => "DATE_FORMAT(e.expense_date, '%Y-%m-01')",
};
$paymentBucket = match ($interval) {
    'day' => "DATE_FORMAT(p.paid_at, '%Y-%m-%d')",
    'week' => "DATE_FORMAT(DATE_SUB(p.paid_at, INTERVAL WEEKDAY(p.paid_at) DAY), '%Y-%m-%d')",
    default => "DATE_FORMAT(p.paid_at, '%Y-%m-01')",
};

$facilityWhere = $facilityId !== 0 ? ' AND f.id = ?' : '';
$rangeParams = [$ownerId, $from, $to];
if ($facilityId !== 0) {
    $rangeParams[] = $facilityId;
}

// Confirmed/completed booking value. Pending, cancelled and rejected are excluded.
$bookingSummaryStmt = $pdo->prepare("SELECT
        COUNT(*) AS total_bookings,
        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS booking_value,
        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN 1 ELSE 0 END), 0) AS valid_bookings,
        COALESCE(SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_bookings
    FROM bookings b
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND b.booking_date BETWEEN ? AND ? $facilityWhere");
$bookingSummaryStmt->execute($rangeParams);
$bookingSummary = $bookingSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$expenseSummaryStmt = $pdo->prepare("SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    INNER JOIN facilities f ON f.id = e.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND e.expense_date BETWEEN ? AND ? $facilityWhere");
$expenseSummaryStmt->execute($rangeParams);
$totalExpenses = (float) $expenseSummaryStmt->fetchColumn();

// The payment table records received and refunded transactions separately.
// Sum receipts and subtract refunds once. Do not join payment rows to booking totals.
$paymentSummaryStmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN p.status='received' THEN p.amount ELSE 0 END), 0) AS received,
        COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END), 0) AS refunded
    FROM payments p
    INNER JOIN bookings b ON b.id = p.booking_id
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND DATE(p.paid_at) BETWEEN ? AND ? $facilityWhere");
$paymentSummaryStmt->execute($rangeParams);
$paymentSummary = $paymentSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$bookingValue = (float) ($bookingSummary['booking_value'] ?? 0);
$received = (float) ($paymentSummary['received'] ?? 0);
$refunded = (float) ($paymentSummary['refunded'] ?? 0);
$netCollected = $received - $refunded;
$cashSurplus = $netCollected - $totalExpenses;
$totalBookings = (int) ($bookingSummary['total_bookings'] ?? 0);

// Pull each trend separately: joining bookings/payments/expenses would multiply amounts.
$bookingsTrendStmt = $pdo->prepare("SELECT $bookingBucket AS bucket, SUM(b.total_amount) AS amount
    FROM bookings b
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND b.status IN ('confirmed','completed')
        AND b.booking_date BETWEEN ? AND ? $facilityWhere
    GROUP BY bucket ORDER BY bucket");
$bookingsTrendStmt->execute($rangeParams);
$bookingTrendValues = [];
foreach ($bookingsTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $bookingTrendValues[(string) $row['bucket']] = (float) $row['amount'];
}

$expensesTrendStmt = $pdo->prepare("SELECT $expenseBucket AS bucket, SUM(e.amount) AS amount
    FROM expenses e
    INNER JOIN facilities f ON f.id = e.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND e.expense_date BETWEEN ? AND ? $facilityWhere
    GROUP BY bucket ORDER BY bucket");
$expensesTrendStmt->execute($rangeParams);
$expenseTrendValues = [];
foreach ($expensesTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $expenseTrendValues[(string) $row['bucket']] = (float) $row['amount'];
}

$paymentsTrendStmt = $pdo->prepare("SELECT $paymentBucket AS bucket,
        SUM(CASE WHEN p.status='received' THEN p.amount ELSE -p.amount END) AS amount
    FROM payments p
    INNER JOIN bookings b ON b.id = p.booking_id
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND p.status IN ('received','refunded')
        AND DATE(p.paid_at) BETWEEN ? AND ? $facilityWhere
    GROUP BY bucket ORDER BY bucket");
$paymentsTrendStmt->execute($rangeParams);
$paymentTrendValues = [];
foreach ($paymentsTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $paymentTrendValues[(string) $row['bucket']] = (float) $row['amount'];
}

$cursor = match ($interval) {
    'week' => $fromDate->modify('monday this week'),
    'month' => $fromDate->modify('first day of this month'),
    default => $fromDate,
};

$chartData = [];
while ($cursor <= $toDate) {
    $key = $cursor->format('Y-m-d');
    $chartData[] = [
        'label' => tm_rp_chart_label($cursor, $interval),
        'booking' => $bookingTrendValues[$key] ?? 0.0,
        'collected' => $paymentTrendValues[$key] ?? 0.0,
        'expenses' => $expenseTrendValues[$key] ?? 0.0,
    ];
    $cursor = $cursor->modify(match ($interval) {
        'week' => '+1 week',
        'month' => '+1 month',
        default => '+1 day',
    });
}

// Line chart SVG coordinates, generated server-side (no external chart scripts).
$chartWidth = 960;
$chartHeight = 320;
$chartLeft = 78;
$chartTop = 26;
$plotWidth = 845;
$plotHeight = 238;
$pointCount = count($chartData);
$chartMax = 1.0;
foreach ($chartData as $point) {
    $chartMax = max($chartMax, (float) $point['booking'], (float) $point['collected'], (float) $point['expenses']);
}
$chartMin = 0.0;
foreach ($chartData as $point) {
    $chartMin = min($chartMin, (float) $point['collected']);
}
if ($chartMax <= $chartMin) {
    $chartMax = $chartMin + 1;
}

$seriesStyles = [
    'booking' => ['color' => '#146c43', 'name' => 'Booking value'],
    'collected' => ['color' => '#58ad80', 'name' => 'Cash collected'],
    'expenses' => ['color' => '#e9a64a', 'name' => 'Expenses'],
];
$lines = [];
foreach ($seriesStyles as $series => $style) {
    $points = [];
    foreach ($chartData as $index => $data) {
        $x = $chartLeft + ($pointCount > 1 ? $index * $plotWidth / ($pointCount - 1) : $plotWidth / 2);
        $y = $chartTop + $plotHeight * ($chartMax - (float) $data[$series]) / ($chartMax - $chartMin);
        $points[] = ['x' => round($x, 2), 'y' => round($y, 2), 'value' => $data[$series], 'label' => $data['label']];
    }
    $lines[$series] = [
        'points' => $points,
        'polyline' => implode(' ', array_map(
            static fn (array $p): string => $p['x'] . ',' . $p['y'],
            $points
        )),
    ];
}

// Revenue share by turf. Booking-level amounts are not duplicated by payment rows.
$turfShareStmt = $pdo->prepare("SELECT t.name AS turf_name, f.name AS facility_name,
        COALESCE(SUM(b.total_amount), 0) AS amount
    FROM bookings b
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND b.status IN ('confirmed','completed')
        AND b.booking_date BETWEEN ? AND ? $facilityWhere
    GROUP BY t.id, t.name, f.name
    ORDER BY amount DESC");
$turfShareStmt->execute($rangeParams);
$rawTurfs = $turfShareStmt->fetchAll(PDO::FETCH_ASSOC);
$turfCategories = [];
foreach ($rawTurfs as $index => $row) {
    $label = $index < 5 ? $row['turf_name'] . ' · ' . $row['facility_name'] : 'Other turfs';
    $turfCategories[$label] = ($turfCategories[$label] ?? 0) + (float) $row['amount'];
}
$turfColors = ['#156b43', '#378d60', '#75bc92', '#b0dbc0', '#efbd78', '#8cabce'];
$donutStops = [];
$donutLabels = [];
$position = 0.0;
$totalTurfValue = array_sum($turfCategories);
$colorIndex = 0;
foreach ($turfCategories as $name => $amount) {
    if ($amount <= 0 || $totalTurfValue <= 0) {
        continue;
    }
    $next = $position + $amount * 100 / $totalTurfValue;
    $color = $turfColors[$colorIndex % count($turfColors)];
    $donutStops[] = sprintf('%s %.3f%% %.3f%%', $color, $position, $next);
    $donutLabels[] = [
        'name' => $name,
        'percent' => $amount * 100 / $totalTurfValue,
        'amount' => $amount,
        'color' => $color,
    ];
    $position = $next;
    $colorIndex++;
}
$donutCss = $donutStops
    ? 'conic-gradient(' . implode(', ', $donutStops) . ')'
    : 'conic-gradient(#e4eee7 0% 100%)';

$recentBookingsStmt = $pdo->prepare("SELECT b.customer_name, b.booking_date, b.total_amount,
        b.status, t.name AS turf_name, f.name AS facility_name
    FROM bookings b
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND b.booking_date BETWEEN ? AND ? $facilityWhere
    ORDER BY b.booking_date DESC, b.id DESC LIMIT 6");
$recentBookingsStmt->execute($rangeParams);
$recentBookings = $recentBookingsStmt->fetchAll(PDO::FETCH_ASSOC);

$recentExpensesStmt = $pdo->prepare("SELECT e.category, e.description, e.expense_date, e.amount,
        f.name AS facility_name
    FROM expenses e
    INNER JOIN facilities f ON f.id = e.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND e.expense_date BETWEEN ? AND ? $facilityWhere
    ORDER BY e.expense_date DESC, e.id DESC LIMIT 6");
$recentExpensesStmt->execute($rangeParams);
$recentExpenses = $recentExpensesStmt->fetchAll(PDO::FETCH_ASSOC);

$recentPaymentsStmt = $pdo->prepare("SELECT p.amount, p.status, p.method, p.paid_at,
        b.customer_name, t.name AS turf_name
    FROM payments p
    INNER JOIN bookings b ON b.id = p.booking_id
    INNER JOIN turfs t ON t.id = b.turf_id
    INNER JOIN facilities f ON f.id = t.facility_id
    WHERE f.owner_id = ? AND f.deleted_at IS NULL
        AND DATE(p.paid_at) BETWEEN ? AND ? $facilityWhere
    ORDER BY p.paid_at DESC, p.id DESC LIMIT 6");
$recentPaymentsStmt->execute($rangeParams);
$recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Report links remain functional even before the other report pages exist.
$tabs = [
    'Overview' => 'reports.php',
    'Financial' => 'reports-financial.php',
    'Bookings' => null,
    'Inventory & Maintenance' => null,
    'Export' => null,
];

tm_page_header('Reports & Analytics', 'reports', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-reports.css?v=1">

<div class="rp-page">
    <div class="rp-heading">
        <div>
            <span class="rp-eyebrow">BUSINESS INTELLIGENCE</span>
            <h2>Reports Overview</h2>
            <p>Understand booking value, cash collections, expenses, and business performance.</p>
        </div>
        <span class="rp-readonly">Live database figures</span>
    </div>

    <nav class="rp-tabs" aria-label="Reports and Analytics modules">
        <?php foreach ($tabs as $label => $link): ?>
            <?php if ($link !== null): ?>
                <a href="<?= tm_e($link) ?>"
   class="<?= $label === 'Overview' ? 'active' : '' ?>"
   <?= $label === 'Overview' ? 'aria-current="page"' : '' ?>>

    <?= tm_e($label) ?>

</a>
            <?php else: ?>
                <span class="rp-tab-upcoming" title="Coming in the next development stages">
                    <?= tm_e($label) ?> <small>soon</small>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <form class="rp-filters" method="get" action="reports.php">
        <label>
            Facility
            <select name="facility_id">
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
            Period
            <select name="period" id="rpPeriod">
                <?php foreach ([
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'week' => 'This week',
                    'month' => 'This month',
                    'year' => 'This year',
                    'custom' => 'Custom range',
                ] as $key => $text): ?>
                    <option value="<?= tm_e($key) ?>" <?= $period === $key ? 'selected' : '' ?>>
                        <?= tm_e($text) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            From
            <input type="date" name="from" value="<?= tm_e($from) ?>" id="rpFrom">
        </label>

        <label>
            To
            <input type="date" name="to" value="<?= tm_e($to) ?>" id="rpTo">
        </label>

        <div class="rp-filter-actions">
            <button type="submit">Apply Filters</button>
            <a href="reports.php">Reset</a>
        </div>
    </form>

    <?php if ($notice !== ''): ?>
        <div class="rp-notice"><?= tm_e($notice) ?></div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="rp-notice">
            No active facilities found. <a href="facilities.php">Add a facility</a> to start reporting.
        </div>
    <?php endif; ?>

    <div class="rp-period-note">
        <span>Reporting period: <strong><?= tm_e($from) ?> → <?= tm_e($to) ?></strong></span>
        <span>Bookings use booking dates · collections use payment dates</span>
    </div>

    <section class="rp-metrics" aria-label="Key business numbers">
        <article class="rp-metric">
            <div class="rp-metric-icon">▣</div>
            <div class="rp-metric-title">Confirmed booking value</div>
            <strong><?= tm_money($bookingValue) ?></strong>
            <small><?= (int) ($bookingSummary['valid_bookings'] ?? 0) ?> confirmed / completed bookings</small>
        </article>

        <article class="rp-metric">
            <div class="rp-metric-icon rp-icon-blue">↙</div>
            <div class="rp-metric-title">Net payments collected</div>
            <strong><?= tm_money($netCollected) ?></strong>
            <small><?= tm_money($refunded) ?> refunded in period</small>
        </article>

        <article class="rp-metric">
            <div class="rp-metric-icon rp-icon-red">↗</div>
            <div class="rp-metric-title">Recorded expenses</div>
            <strong><?= tm_money($totalExpenses) ?></strong>
            <small>Costs recorded for this period</small>
        </article>

        <article class="rp-metric">
            <div class="rp-metric-icon rp-icon-amber">◈</div>
            <div class="rp-metric-title">Cash surplus / deficit</div>
            <strong class="<?= $cashSurplus < 0 ? 'rp-loss' : '' ?>">
                <?= tm_money($cashSurplus) ?>
            </strong>
            <small>Net collections minus expenses</small>
        </article>
    </section>

    <div class="rp-secondary-stats">
        <div><strong><?= $totalBookings ?></strong> total bookings for selected dates</div>
        <div><strong><?= (int) ($bookingSummary['pending_bookings'] ?? 0) ?></strong> pending bookings</div>
        <div><strong><?= tm_e(tm_rp_compact_money($bookingValue)) ?></strong> eligible booking value</div>
    </div>

    <div class="rp-visuals">
        <section class="rp-panel rp-trend-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Financial Performance</h3>
                    <p>Booking value, actual collections, and expenses over time</p>
                </div>
                <span class="rp-badge"><?= tm_e(['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'][$interval]) ?></span>
            </div>

            <div class="rp-chart-scroller">
                <svg class="rp-line-chart"
                     viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>"
                     role="img"
                     aria-label="Booking value, cash collections, and expense trends">
                    <?php for ($step = 0; $step <= 4; $step++): ?>
                        <?php
                        $y = $chartTop + $plotHeight * $step / 4;
                        $tickValue = $chartMax - ($chartMax - $chartMin) * $step / 4;
                        ?>
                        <line x1="<?= $chartLeft ?>" y1="<?= $y ?>"
                              x2="<?= $chartLeft + $plotWidth ?>" y2="<?= $y ?>"
                              stroke="#e5eee8" stroke-dasharray="5 6" />
                        <text x="<?= $chartLeft - 12 ?>" y="<?= $y + 4 ?>"
                              text-anchor="end" class="rp-axis">
                            <?= tm_e(tm_rp_compact_money($tickValue)) ?>
                        </text>
                    <?php endfor; ?>

                    <?php foreach ($seriesStyles as $series => $style): ?>
                        <polyline points="<?= tm_e($lines[$series]['polyline']) ?>"
                                  fill="none"
                                  stroke="<?= tm_e($style['color']) ?>"
                                  stroke-width="3"
                                  stroke-linejoin="round"
                                  stroke-linecap="round" />

                        <?php foreach ($lines[$series]['points'] as $idx => $point): ?>
                            <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>"
                                    r="<?= $pointCount <= 14 ? '4' : '2.3' ?>"
                                    fill="<?= tm_e($style['color']) ?>"
                                    stroke="white" stroke-width="1.2">
                                <title><?= tm_e($point['label']) ?> · <?= tm_e($style['name']) ?>: <?= tm_money($point['value']) ?></title>
                            </circle>

                            <?php if ($series === 'booking'
                                && ($idx === 0 || $idx === $pointCount - 1
                                    || $idx % max(1, (int) ceil($pointCount / 6)) === 0)): ?>
                                <text x="<?= $point['x'] ?>"
                                      y="<?= $chartTop + $plotHeight + 26 ?>"
                                      text-anchor="middle"
                                      class="rp-axis">
                                    <?= tm_e($point['label']) ?>
                                </text>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </svg>
            </div>

            <div class="rp-chart-legend">
                <?php foreach ($seriesStyles as $style): ?>
                    <span>
                        <i style="background: <?= tm_e($style['color']) ?>"></i>
                        <?= tm_e($style['name']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="rp-panel rp-donut-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Booking Value by Turf</h3>
                    <p>Share of confirmed/completed booking value</p>
                </div>
            </div>

            <div class="rp-donut-body">
                <div class="rp-donut" style="background: <?= tm_e($donutCss) ?>"
                     role="img" aria-label="Booking value by turf doughnut chart">
                    <div class="rp-donut-center">
                        <span>Total</span>
                        <strong><?= tm_e(tm_rp_compact_money($totalTurfValue)) ?></strong>
                    </div>
                </div>

                <div class="rp-donut-legend">
                    <?php if (!$donutLabels): ?>
                        <p class="rp-empty">No confirmed booking value in this period.</p>
                    <?php endif; ?>

                    <?php foreach ($donutLabels as $entry): ?>
                        <div class="rp-donut-item">
                            <div class="rp-donut-name">
                                <i style="background: <?= tm_e($entry['color']) ?>"></i>
                                <span><?= tm_e($entry['name']) ?></span>
                            </div>
                            <strong><?= number_format($entry['percent'], 1) ?>%</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="rp-bottom-grid">
        <section class="rp-panel rp-list-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Recent Bookings</h3>
                    <p>Bookings scheduled in the selected period</p>
                </div>
                <a href="bookings.php">Open bookings →</a>
            </div>

            <?php if (!$recentBookings): ?>
                <p class="rp-empty">No bookings match the selected filters.</p>
            <?php else: ?>
                <div class="rp-list">
                    <?php foreach ($recentBookings as $booking): ?>
                        <div class="rp-list-row">
                            <div>
                                <strong><?= tm_e((string) $booking['customer_name']) ?></strong>
                                <small><?= tm_e((string) $booking['turf_name']) ?> · <?= tm_e((string) $booking['booking_date']) ?></small>
                            </div>
                            <div class="rp-amount-status">
                                <strong><?= tm_money($booking['total_amount']) ?></strong>
                                <small><?= tm_e(ucfirst((string) $booking['status'])) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="rp-panel rp-list-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Recent Payments</h3>
                    <p>Receipts and refunds recorded in this period</p>
                </div>
            </div>

            <?php if (!$recentPayments): ?>
                <p class="rp-empty">No payments match the selected filters.</p>
            <?php else: ?>
                <div class="rp-list">
                    <?php foreach ($recentPayments as $payment): ?>
                        <div class="rp-list-row">
                            <div>
                                <strong><?= tm_e((string) $payment['customer_name']) ?></strong>
                                <small><?= tm_e(ucwords(str_replace('_', ' ', (string) $payment['method']))) ?> · <?= tm_e(substr((string) $payment['paid_at'], 0, 10)) ?></small>
                            </div>
                            <div class="rp-amount-status">
                                <strong class="<?= $payment['status'] === 'refunded' ? 'rp-loss' : '' ?>">
                                    <?= $payment['status'] === 'refunded' ? '−' : '+' ?><?= tm_money($payment['amount']) ?>
                                </strong>
                                <small><?= tm_e(ucfirst((string) $payment['status'])) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="rp-panel rp-list-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Recent Expenses</h3>
                    <p>Business spending for the selected period</p>
                </div>
                <a href="expenses.php">View expenses →</a>
            </div>

            <?php if (!$recentExpenses): ?>
                <p class="rp-empty">No expenses match the selected filters.</p>
            <?php else: ?>
                <div class="rp-list">
                    <?php foreach ($recentExpenses as $expense): ?>
                        <div class="rp-list-row">
                            <div>
                                <strong><?= tm_e((string) $expense['category']) ?></strong>
                                <small><?= tm_e((string) ($expense['description'] ?: $expense['facility_name'])) ?> · <?= tm_e((string) $expense['expense_date']) ?></small>
                            </div>
                            <strong class="rp-expense-amount"><?= tm_money($expense['amount']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="rp-panel rp-tips-panel">
            <div class="rp-panel-head">
                <div>
                    <h3>Understanding Your Numbers</h3>
                    <p>How the overview calculates financial performance</p>
                </div>
            </div>

            <div class="rp-tips">
                <div>
                    <span>01</span>
                    <p><strong>Booking value</strong> includes confirmed and completed bookings on their scheduled dates. It is not cash collected.</p>
                </div>
                <div>
                    <span>02</span>
                    <p><strong>Net collections</strong> includes recorded received payments minus refunded payments, based on payment dates.</p>
                </div>
                <div>
                    <span>03</span>
                    <p><strong>Cash surplus</strong> = net collections − recorded expenses. It is not accounting net profit.</p>
                </div>
                <div>
                    <span>04</span>
                    <p><strong>Maintenance cost</strong> is only included if it has been recorded in Expenses, preventing accidental double-counting.</p>
                </div>
            </div>
        </section>
    </div>

    <p class="rp-footnote">
        Figures are scoped to facilities owned by your account. Reports are read-only.
        More detailed Financial, Booking, Inventory & Maintenance, and Export pages will be added next.
    </p>
</div>

<?php tm_page_footer(); ?>
