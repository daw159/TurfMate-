<?php
declare(strict_types=1);

/**
 * TurfMate | Reports & Analytics | Inventory & Maintenance
 * Read-only reports built from existing tables, scoped to logged-in owner.
 * No database migrations and no third-party JS libraries.
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_im_date_valid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function tm_im_short_money(float $value): string
{
    if (abs($value) >= 1000000) {
        return 'Rs ' . number_format($value / 1000000, 1) . 'm';
    }
    if (abs($value) >= 1000) {
        return 'Rs ' . number_format($value / 1000, 1) . 'k';
    }
    return 'Rs ' . number_format($value, 0);
}

function tm_im_date_label(string $key, string $group): string
{
    $date = new DateTimeImmutable($key);
    return $group === 'month' ? $date->format('M y') : $date->format('j M');
}

/**
 * Render an accessible SVG trend with a real zero baseline.
 * Input points: [['label' => '1 Nov', 'value' => 120.0], ...]
 */
function tm_im_line_chart(array $points, string $label, string $color, bool $money = false): void
{
    $width = 880;
    $height = 265;
    $left = 76;
    $right = 24;
    $top = 24;
    $bottom = 46;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;
    $maximum = max(1.0, ...array_map(static fn (array $row): float => (float) $row['value'], $points));
    $coords = [];
    $count = count($points);

    foreach ($points as $index => $point) {
        $x = $left + ($count > 1 ? $index * $plotW / ($count - 1) : $plotW / 2);
        $y = $top + $plotH * (1 - (float) $point['value'] / $maximum);
        $coords[] = [round($x, 2), round($y, 2), $point];
    }

    $polyline = implode(' ', array_map(
        static fn (array $row): string => $row[0] . ',' . $row[1],
        $coords
    ));

    ?>
    <div class="im-chart-scroll">
        <svg viewBox="0 0 <?= $width ?> <?= $height ?>"
             role="img"
             aria-label="<?= tm_e($label) ?>"
             preserveAspectRatio="xMidYMid meet">
            <?php for ($i = 0; $i <= 4; $i++): ?>
                <?php
                $y = $top + ($plotH * $i / 4);
                $value = $maximum * (4 - $i) / 4;
                ?>
                <line x1="<?= $left ?>" y1="<?= $y ?>"
                      x2="<?= $left + $plotW ?>" y2="<?= $y ?>"
                      stroke="#e4ede7" stroke-dasharray="5 5" />
                <text x="<?= $left - 12 ?>" y="<?= $y + 4 ?>"
                      text-anchor="end" class="im-axis">
                    <?= tm_e($money ? tm_im_short_money($value) : number_format($value, 1)) ?>
                </text>
            <?php endfor; ?>

            <?php if ($coords): ?>
                <polyline points="<?= tm_e($polyline) ?>"
                          fill="none" stroke="<?= tm_e($color) ?>"
                          stroke-width="3.5" stroke-linejoin="round"
                          stroke-linecap="round" vector-effect="non-scaling-stroke" />
                <?php foreach ($coords as $i => $coord): ?>
                    <circle cx="<?= $coord[0] ?>" cy="<?= $coord[1] ?>"
                            r="<?= $count < 18 ? 4 : 2.8 ?>"
                            fill="<?= tm_e($color) ?>" stroke="#fff" stroke-width="1.5">
                        <title><?= tm_e($coord[2]['label']) ?>: <?= tm_e($money ? tm_im_short_money((float) $coord[2]['value']) : (string) $coord[2]['value']) ?></title>
                    </circle>
                    <?php if ($i === 0 || $i === $count - 1 || $i % max(1, (int) ceil($count / 6)) === 0): ?>
                        <text x="<?= $coord[0] ?>" y="<?= $top + $plotH + 27 ?>"
                              text-anchor="middle" class="im-axis">
                            <?= tm_e((string) $coord[2]['label']) ?>
                        </text>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </svg>
    </div>
    <?php
}

// -----------------------------------------------------------------------------
// Owner-authorized facility and date filters.
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
        if (!tm_im_date_valid($from)) {
            $from = $today->format('Y-m-01');
        }
        if (!tm_im_date_valid($to)) {
            $to = $today->format('Y-m-d');
        }
        break;
    default:
        $from = $today->format('Y-m-01');
        $to = $today->format('Y-m-d');
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$notice = '';
$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
if ($fromDate->diff($toDate)->days > 730) {
    $from = $toDate->modify('-730 days')->format('Y-m-d');
    $fromDate = new DateTimeImmutable($from);
    $notice = 'The selected range was limited to 731 days for readable charts.';
}

$numberOfDays = $fromDate->diff($toDate)->days + 1;
$group = $numberOfDays <= 45 ? 'day' : ($numberOfDays <= 210 ? 'week' : 'month');
$timeBucket = match ($group) {
    'day' => "DATE_FORMAT(%s, '%%Y-%%m-%%d')",
    'week' => "DATE_FORMAT(DATE_SUB(%s, INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')",
    default => "DATE_FORMAT(%s, '%%Y-%%m-01')",
};
$bucketFor = static function (string $column) use ($group, $timeBucket): string {
    return $group === 'week'
        ? sprintf($timeBucket, $column, $column)
        : sprintf($timeBucket, $column);
};

$facilityWhere = $facilityId ? ' AND f.id = ?' : '';
$liveParams = [$ownerId];
$rangeParams = [$ownerId, $from, $to];
if ($facilityId) {
    $liveParams[] = $facilityId;
    $rangeParams[] = $facilityId;
}

// -----------------------------------------------------------------------------
// Current inventory snapshot — deliberately NOT limited by the date filter.
// -----------------------------------------------------------------------------
$inventoryStatsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS item_count,
            COALESCE(SUM(i.quantity * COALESCE(i.cost_per_unit, 0)), 0) AS total_value,
            COALESCE(SUM(CASE WHEN i.quantity <= i.reorder_level THEN 1 ELSE 0 END), 0) AS low_stock,
            COALESCE(SUM(CASE WHEN i.quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND i.status = 'active' $facilityWhere"
);
$inventoryStatsStmt->execute($liveParams);
$inventoryStats = $inventoryStatsStmt->fetch(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(TRIM(i.category), ''), 'Uncategorized') AS category,
            SUM(i.quantity * COALESCE(i.cost_per_unit, 0)) AS value,
            COUNT(*) AS items
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND i.status = 'active' $facilityWhere
     GROUP BY COALESCE(NULLIF(TRIM(i.category), ''), 'Uncategorized')
     ORDER BY value DESC, category ASC"
);
$categoryStmt->execute($liveParams);
$rawCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
$categories = [];
foreach ($rawCategories as $index => $row) {
    $label = $index < 5 ? (string) $row['category'] : 'Other categories';
    $categories[$label] = ($categories[$label] ?? 0) + (float) $row['value'];
}

$palette = ['#1f7650', '#58aa78', '#9bd3ae', '#f1bb74', '#739dc2', '#b897c8'];
$totalCategoryValue = array_sum($categories);
$categoryLegend = [];
$gradientStops = [];
$position = 0.0;
foreach ($categories as $name => $value) {
    if ($value <= 0 || $totalCategoryValue <= 0) {
        continue;
    }
    $color = $palette[count($categoryLegend) % count($palette)];
    $next = $position + $value / $totalCategoryValue * 100;
    $gradientStops[] = sprintf('%s %.3f%% %.3f%%', $color, $position, $next);
    $categoryLegend[] = [
        'name' => $name,
        'value' => $value,
        'percent' => 100 * $value / $totalCategoryValue,
        'color' => $color,
    ];
    $position = $next;
}
$donutBackground = $gradientStops
    ? 'conic-gradient(' . implode(', ', $gradientStops) . ')'
    : 'conic-gradient(#e3ede6 0% 100%)';

$lowStockStmt = $pdo->prepare(
    "SELECT i.name, i.quantity, i.reorder_level, i.unit, f.name AS facility_name
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND i.status = 'active' AND i.quantity <= i.reorder_level $facilityWhere
     ORDER BY (i.reorder_level - i.quantity) DESC, i.quantity ASC
     LIMIT 8"
);
$lowStockStmt->execute($liveParams);
$lowStockItems = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Stock movement report — date-filtered activity; different units are NOT added
// together (the trend counts movements, not all units across unlike items).
// -----------------------------------------------------------------------------
$stockStatsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS movements,
            SUM(CASE WHEN x.transaction_type = 'purchase' THEN 1 ELSE 0 END) AS purchases,
            SUM(CASE WHEN x.transaction_type = 'usage' THEN 1 ELSE 0 END) AS usages,
            SUM(CASE WHEN x.transaction_type = 'adjustment' THEN 1 ELSE 0 END) AS adjustments
     FROM inventory_transactions x
     JOIN inventory_items i ON i.id = x.item_id
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND x.created_at >= ? AND x.created_at < DATE_ADD(?, INTERVAL 1 DAY)
       $facilityWhere"
);
$stockStatsStmt->execute($rangeParams);
$stockStats = $stockStatsStmt->fetch(PDO::FETCH_ASSOC);

$stockBucket = $bucketFor('x.created_at');
$stockTrendStmt = $pdo->prepare(
    "SELECT $stockBucket AS bucket, COUNT(*) AS total
     FROM inventory_transactions x
     JOIN inventory_items i ON i.id = x.item_id
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND x.created_at >= ? AND x.created_at < DATE_ADD(?, INTERVAL 1 DAY)
       $facilityWhere
     GROUP BY bucket ORDER BY bucket"
);
$stockTrendStmt->execute($rangeParams);
$stockTrendValues = [];
foreach ($stockTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stockTrendValues[(string) $row['bucket']] = (float) $row['total'];
}

$usageStmt = $pdo->prepare(
    "SELECT i.name, i.unit, f.name AS facility_name,
            SUM(-x.quantity_change) AS used_quantity,
            COUNT(*) AS usage_events
     FROM inventory_transactions x
     JOIN inventory_items i ON i.id = x.item_id
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ?
       AND x.transaction_type = 'usage' AND x.quantity_change < 0
       AND x.created_at >= ? AND x.created_at < DATE_ADD(?, INTERVAL 1 DAY)
       $facilityWhere
     GROUP BY i.id, i.name, i.unit, f.name
     ORDER BY used_quantity DESC, usage_events DESC LIMIT 8"
);
$usageStmt->execute($rangeParams);
$mostUsed = $usageStmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Maintenance — activities selected by start_date. Amounts are recorded
// maintenance COSTS, not verified cash expenses or payments.
// -----------------------------------------------------------------------------
$maintenanceStatsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_jobs,
            COALESCE(SUM(m.cost), 0) AS recorded_cost,
            COALESCE(SUM(CASE WHEN m.status = 'planned' THEN 1 ELSE 0 END), 0) AS planned,
            COALESCE(SUM(CASE WHEN m.status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress,
            COALESCE(SUM(CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed
     FROM maintenance_records m
     JOIN turfs t ON t.id = m.turf_id
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
       AND m.start_date BETWEEN ? AND ? $facilityWhere"
);
$maintenanceStatsStmt->execute($rangeParams);
$maintenanceStats = $maintenanceStatsStmt->fetch(PDO::FETCH_ASSOC);

$maintenanceBucket = $bucketFor('m.start_date');
$maintenanceTrendStmt = $pdo->prepare(
    "SELECT $maintenanceBucket AS bucket, COALESCE(SUM(m.cost), 0) AS total
     FROM maintenance_records m
     JOIN turfs t ON t.id = m.turf_id
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
       AND m.start_date BETWEEN ? AND ? $facilityWhere
     GROUP BY bucket ORDER BY bucket"
);
$maintenanceTrendStmt->execute($rangeParams);
$maintenanceTrendValues = [];
foreach ($maintenanceTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $maintenanceTrendValues[(string) $row['bucket']] = (float) $row['total'];
}

$maintenanceByTurfStmt = $pdo->prepare(
    "SELECT t.name AS turf_name, f.name AS facility_name,
            COUNT(*) AS jobs, COALESCE(SUM(m.cost), 0) AS recorded_cost
     FROM maintenance_records m
     JOIN turfs t ON t.id = m.turf_id
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
       AND m.start_date BETWEEN ? AND ? $facilityWhere
     GROUP BY t.id, t.name, f.name
     ORDER BY recorded_cost DESC, jobs DESC LIMIT 8"
);
$maintenanceByTurfStmt->execute($rangeParams);
$maintenanceByTurf = $maintenanceByTurfStmt->fetchAll(PDO::FETCH_ASSOC);
$maxTurfCost = max(1.0, ...array_map(
    static fn (array $row): float => (float) $row['recorded_cost'],
    $maintenanceByTurf
));

$maintenanceHistoryStmt = $pdo->prepare(
    "SELECT m.description, m.start_date, m.end_date, m.status, m.cost,
            t.name AS turf_name, f.name AS facility_name
     FROM maintenance_records m
     JOIN turfs t ON t.id = m.turf_id
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
       AND m.start_date BETWEEN ? AND ? $facilityWhere
     ORDER BY m.start_date DESC, m.id DESC LIMIT 12"
);
$maintenanceHistoryStmt->execute($rangeParams);
$maintenanceHistory = $maintenanceHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing trend periods with zeros for honest, continuous charts.
$cursor = match ($group) {
    'week' => $fromDate->modify('monday this week'),
    'month' => $fromDate->modify('first day of this month'),
    default => $fromDate,
};
$stockTrend = [];
$maintenanceTrend = [];
$interval = match ($group) {
    'week' => '+1 week',
    'month' => '+1 month',
    default => '+1 day',
};
while ($cursor <= $toDate) {
    $key = $cursor->format('Y-m-d');
    $label = tm_im_date_label($key, $group);
    $stockTrend[] = ['label' => $label, 'value' => $stockTrendValues[$key] ?? 0.0];
    $maintenanceTrend[] = ['label' => $label, 'value' => $maintenanceTrendValues[$key] ?? 0.0];
    $cursor = $cursor->modify($interval);
}

// -----------------------------------------------------------------------------
// Page markup.
// -----------------------------------------------------------------------------
tm_page_header('Reports & Analytics', 'reports', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-inventory-reports.css?v=1">

<div class="im-page">
    <header class="im-heading">
        <div>
            <span class="im-eyebrow">REPORTS & ANALYTICS</span>
            <h2>Inventory &amp; Maintenance Reports</h2>
            <p>Monitor stock, movement history, maintenance work, and recorded upkeep costs.</p>
        </div>
        <span class="im-readonly">Read-only · Live data</span>
    </header>

    <nav class="im-tabs" aria-label="Reports navigation">
        <a href="reports.php">Overview</a>
        <a href="reports-financial.php">Financial</a>
        <a href="reports-bookings.php">Bookings</a>
        <a href="reports-inventory.php" class="active" aria-current="page">Inventory &amp; Maintenance</a>
        <a href="reports-export.php">
    Export
</a>
    </nav>

    <form class="im-filters" method="get" action="reports-inventory.php">
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
            Date range
            <select name="period" id="im-period">
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
            From
            <input type="date" name="from" value="<?= tm_e($from) ?>" id="im-from">
        </label>

        <label>
            To
            <input type="date" name="to" value="<?= tm_e($to) ?>" id="im-to">
        </label>

        <button type="submit">Apply filters</button>
        <a class="im-reset" href="reports-inventory.php">Reset</a>
    </form>

    <?php if ($notice): ?>
        <div class="im-notice"><?= tm_e($notice) ?></div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="im-notice">
            No facilities found. <a href="facilities.php">Create a facility</a> to begin reporting.
        </div>
    <?php endif; ?>

    <div class="im-section-title">
        <div>
            <span class="im-eyebrow">01 / INVENTORY</span>
            <h3>Inventory performance</h3>
        </div>
        <span>Current snapshot + movements from <?= tm_e($from) ?> to <?= tm_e($to) ?></span>
    </div>

    <div class="im-cards im-cards-four">
        <a class="im-card" href="inventory.php">
            <span>Inventory value</span>
            <strong><?= tm_money($inventoryStats['total_value'] ?? 0) ?></strong>
            <small>Current quantity × unit cost</small>
        </a>
        <a class="im-card" href="inventory.php">
            <span>Active items</span>
            <strong><?= (int) ($inventoryStats['item_count'] ?? 0) ?></strong>
            <small>Currently tracked items</small>
        </a>
        <a class="im-card im-card-warn" href="inventory.php">
            <span>Low stock</span>
            <strong><?= (int) ($inventoryStats['low_stock'] ?? 0) ?></strong>
            <small>At or below reorder level</small>
        </a>
        <a class="im-card im-card-danger" href="inventory.php">
            <span>Out of stock</span>
            <strong><?= (int) ($inventoryStats['out_of_stock'] ?? 0) ?></strong>
            <small>Items with zero quantity</small>
        </a>
    </div>

    <div class="im-cards im-cards-four">
        <div class="im-mini-card"><span>Stock movements</span><strong><?= (int) ($stockStats['movements'] ?? 0) ?></strong></div>
        <div class="im-mini-card"><span>Purchases / stock in</span><strong><?= (int) ($stockStats['purchases'] ?? 0) ?></strong></div>
        <div class="im-mini-card"><span>Usage / stock out</span><strong><?= (int) ($stockStats['usages'] ?? 0) ?></strong></div>
        <div class="im-mini-card"><span>Adjustments</span><strong><?= (int) ($stockStats['adjustments'] ?? 0) ?></strong></div>
    </div>

    <section class="im-panel">
        <div class="im-panel-head">
            <div>
                <h3>Stock movement trend</h3>
                <p>Number of recorded movements per <?= tm_e($group) ?> (not mixed item units).</p>
            </div>
            <span class="im-tag"><?= tm_e(ucfirst($group)) ?>ly trend</span>
        </div>
        <?php tm_im_line_chart($stockTrend, 'Stock movements over the selected period', '#237b52'); ?>
    </section>

    <div class="im-two-columns">
        <section class="im-panel">
            <div class="im-panel-head">
                <div>
                    <h3>Inventory value by category</h3>
                    <p>Current estimated cost value of active items.</p>
                </div>
            </div>
            <div class="im-donut-layout">
                <div class="im-donut" style="background: <?= tm_e($donutBackground) ?>"
                     role="img" aria-label="Inventory value by category">
                    <div class="im-donut-center">
                        <span>Total value</span>
                        <strong><?= tm_e(tm_im_short_money((float) $totalCategoryValue)) ?></strong>
                    </div>
                </div>
                <div class="im-category-legend">
                    <?php if (!$categoryLegend): ?>
                        <p class="im-empty">No priced inventory stock available.</p>
                    <?php else: ?>
                        <?php foreach ($categoryLegend as $category): ?>
                            <div class="im-legend-row">
                                <span>
                                    <i style="background: <?= tm_e($category['color']) ?>"></i>
                                    <?= tm_e($category['name']) ?>
                                </span>
                                <strong><?= number_format($category['percent'], 1) ?>%</strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="im-panel">
            <div class="im-panel-head">
                <div>
                    <h3>Low-stock watchlist</h3>
                    <p>Reorder or replenish these items.</p>
                </div>
                <a href="inventory.php">Manage inventory →</a>
            </div>
            <?php if (!$lowStockItems): ?>
                <p class="im-empty">No items currently require restocking.</p>
            <?php else: ?>
                <div class="im-list">
                    <?php foreach ($lowStockItems as $item): ?>
                        <div class="im-list-row">
                            <div>
                                <strong><?= tm_e((string) $item['name']) ?></strong>
                                <small><?= tm_e((string) $item['facility_name']) ?></small>
                            </div>
                            <span class="im-low-badge">
                                <?= (int) $item['quantity'] ?> / <?= (int) $item['reorder_level'] ?>
                                <?= tm_e((string) $item['unit']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="im-panel">
        <div class="im-panel-head">
            <div>
                <h3>Most-used inventory items</h3>
                <p>Recorded stock usage during the selected date range.</p>
            </div>
            <a href="inventory-transactions.php">Stock history →</a>
        </div>
        <div class="im-table-scroll">
            <table class="im-table">
                <thead>
                    <tr><th>Item</th><th>Facility</th><th>Quantity used</th><th>Usage events</th></tr>
                </thead>
                <tbody>
                    <?php if (!$mostUsed): ?>
                        <tr><td colspan="4" class="im-empty-cell">No recorded usage in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($mostUsed as $item): ?>
                            <tr>
                                <td><strong><?= tm_e((string) $item['name']) ?></strong></td>
                                <td><?= tm_e((string) $item['facility_name']) ?></td>
                                <td><?= number_format((float) $item['used_quantity'], 0) ?> <?= tm_e((string) $item['unit']) ?></td>
                                <td><?= (int) $item['usage_events'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="im-section-title im-maintenance-title">
        <div>
            <span class="im-eyebrow">02 / MAINTENANCE</span>
            <h3>Maintenance performance</h3>
        </div>
        <span>Activities with start dates from <?= tm_e($from) ?> to <?= tm_e($to) ?></span>
    </div>

    <div class="im-cards im-cards-five">
        <div class="im-card"><span>Recorded costs</span><strong><?= tm_money($maintenanceStats['recorded_cost'] ?? 0) ?></strong><small>Not necessarily cash paid</small></div>
        <div class="im-card"><span>Total activities</span><strong><?= (int) ($maintenanceStats['total_jobs'] ?? 0) ?></strong><small>Selected period</small></div>
        <div class="im-card"><span>Completed</span><strong><?= (int) ($maintenanceStats['completed'] ?? 0) ?></strong><small>Finished work</small></div>
        <div class="im-card"><span>In progress</span><strong><?= (int) ($maintenanceStats['in_progress'] ?? 0) ?></strong><small>Work underway</small></div>
        <div class="im-card"><span>Planned</span><strong><?= (int) ($maintenanceStats['planned'] ?? 0) ?></strong><small>Scheduled work</small></div>
    </div>

    <section class="im-panel">
        <div class="im-panel-head">
            <div>
                <h3>Recorded maintenance cost trend</h3>
                <p>Maintenance cost assigned to each activity's start date.</p>
            </div>
            <span class="im-tag"><?= tm_e(ucfirst($group)) ?>ly trend</span>
        </div>
        <?php tm_im_line_chart($maintenanceTrend, 'Recorded maintenance costs over the selected period', '#d58d38', true); ?>
    </section>

    <section class="im-panel">
        <div class="im-panel-head">
            <div>
                <h3>Maintenance cost by turf</h3>
                <p>Compare recorded upkeep costs across your turfs.</p>
            </div>
        </div>
        <?php if (!$maintenanceByTurf): ?>
            <p class="im-empty">No maintenance records in this date range.</p>
        <?php else: ?>
            <div class="im-bars">
                <?php foreach ($maintenanceByTurf as $turf): ?>
                    <?php $percentage = 100 * (float) $turf['recorded_cost'] / $maxTurfCost; ?>
                    <div class="im-bar-row">
                        <div class="im-bar-caption">
                            <strong><?= tm_e((string) $turf['turf_name']) ?></strong>
                            <small><?= tm_e((string) $turf['facility_name']) ?> · <?= (int) $turf['jobs'] ?> activities</small>
                        </div>
                        <div class="im-bar-track">
                            <div class="im-bar-fill" style="width: <?= round($percentage, 2) ?>%"></div>
                        </div>
                        <strong class="im-bar-amount"><?= tm_money($turf['recorded_cost']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="im-panel">
        <div class="im-panel-head">
            <div>
                <h3>Maintenance history</h3>
                <p>Recent activities starting in the selected range.</p>
            </div>
            <a href="maintenance.php">Manage maintenance →</a>
        </div>
        <div class="im-table-scroll">
            <table class="im-table">
                <thead>
                    <tr>
                        <th>Activity</th><th>Turf</th><th>Start</th><th>End</th>
                        <th>Status</th><th>Recorded cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$maintenanceHistory): ?>
                        <tr><td colspan="6" class="im-empty-cell">No maintenance activities for these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($maintenanceHistory as $job): ?>
                            <tr>
                                <td><strong><?= tm_e((string) $job['description']) ?></strong></td>
                                <td><?= tm_e((string) $job['turf_name']) ?><small><?= tm_e((string) $job['facility_name']) ?></small></td>
                                <td><?= tm_e((string) $job['start_date']) ?></td>
                                <td><?= $job['end_date'] !== null ? tm_e((string) $job['end_date']) : '—' ?></td>
                                <td>
                                    <span class="im-status im-status-<?= tm_e((string) $job['status']) ?>">
                                        <?= tm_e(ucwords(str_replace('_', ' ', (string) $job['status']))) ?>
                                    </span>
                                </td>
                                <td><strong><?= tm_money($job['cost']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="im-footnote">
        <strong>Reporting note:</strong> Current inventory value is an estimate based on quantity × unit cost.
        Stock movement charts count transaction events rather than combining incompatible units.
        Maintenance costs are recorded project costs, not automatically paid expenses; they are not added
        again to Financial Reports totals.
    </p>
</div>

<script>
(() => {
    const period = document.getElementById('im-period');
    const from = document.getElementById('im-from');
    const to = document.getElementById('im-to');
    const update = () => {
        const custom = period.value === 'custom';
        from.disabled = !custom;
        to.disabled = !custom;
    };
    period.addEventListener('change', update);
    update();
})();
</script>
<?php tm_page_footer(); ?>
