<?php
declare(strict_types=1);
/** TurfMate — Expenses & Inventory overview. Read-only; no new database tables. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_ov_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function tm_ov_date_label(string $key, string $group): string
{
    $date = new DateTimeImmutable($key);
    return $group === 'month' ? $date->format('M y') : $date->format('j M');
}
function tm_ov_money_short(float $number): string
{
    return $number >= 1000000 ? 'Rs ' . number_format($number / 1000000, 1) . 'm'
        : ($number >= 1000 ? 'Rs ' . number_format($number / 1000, 1) . 'k' : 'Rs ' . number_format($number, 0));
}

$facilityQuery = $pdo->prepare('SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name');
$facilityQuery->execute([$ownerId]);
$facilities = $facilityQuery->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

$selectedFacility = filter_var($_GET['facility_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedFacility = $selectedFacility !== false && in_array((int) $selectedFacility, $facilityIds, true) ? (int) $selectedFacility : 0;
$today = new DateTimeImmutable('today');
$from = (string) ($_GET['from'] ?? $today->format('Y-m-01'));
$to = (string) ($_GET['to'] ?? $today->format('Y-m-d'));
if (!tm_ov_valid_date($from)) {
    $from = $today->format('Y-m-01');
}
if (!tm_ov_valid_date($to)) {
    $to = $today->format('Y-m-d');
}
if ($from > $to) [$from, $to] = [$to, $from];
$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
$rangeNotice = '';
if ($fromDate->diff($toDate)->days > 730) {
    $fromDate = $toDate->modify('-730 days');
    $from = $fromDate->format('Y-m-d');
    $rangeNotice = 'Date range was limited to the latest 731 days for chart readability.';
}
$days = $fromDate->diff($toDate)->days + 1;
$group = $days <= 45 ? 'day' : ($days <= 210 ? 'week' : 'month');
$groupExpression = match ($group) {
    'day' => 'DATE_FORMAT(e.expense_date, \'%Y-%m-%d\')',
    'week' => 'DATE_FORMAT(DATE_SUB(e.expense_date, INTERVAL WEEKDAY(e.expense_date) DAY), \'%Y-%m-%d\')',
    default => 'DATE_FORMAT(e.expense_date, \'%Y-%m-01\')',
};

// All queries are scoped by the logged-in owner's facilities, never by a submitted owner ID.
$facilityClause = $selectedFacility ? ' AND f.id = ?' : '';
$expenseParams = [$ownerId, $from, $to];
if ($selectedFacility) {
    $expenseParams[] = $selectedFacility;
}
$liveParams = [$ownerId];
if ($selectedFacility) {
    $liveParams[] = $selectedFacility;
}

$expenseStats = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) AS total, COUNT(*) AS records
    FROM expenses e JOIN facilities f ON f.id=e.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ? $facilityClause");
$expenseStats->execute($expenseParams);
$expense = $expenseStats->fetch(PDO::FETCH_ASSOC);

$inventoryStats = $pdo->prepare("SELECT COUNT(*) AS total_items,
    COALESCE(SUM(i.quantity * COALESCE(i.cost_per_unit,0)),0) AS stock_value,
    COALESCE(SUM(CASE WHEN i.quantity <= i.reorder_level THEN 1 ELSE 0 END),0) AS low_count
    FROM inventory_items i JOIN facilities f ON f.id=i.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND i.status='active' $facilityClause");
$inventoryStats->execute($liveParams);
$inventory = $inventoryStats->fetch(PDO::FETCH_ASSOC);

$maintenanceStats = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN m.status IN ('planned','in_progress') THEN 1 ELSE 0 END),0) AS open_count,
    COALESCE(SUM(CASE WHEN m.status='completed' THEN 1 ELSE 0 END),0) AS completed_count
    FROM maintenance_records m JOIN turfs t ON t.id=m.turf_id
    JOIN facilities f ON f.id=t.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL $facilityClause");
$maintenanceStats->execute($liveParams);
$maintenance = $maintenanceStats->fetch(PDO::FETCH_ASSOC);

$trendQuery = $pdo->prepare("SELECT $groupExpression AS bucket, COALESCE(SUM(e.amount),0) AS total
    FROM expenses e JOIN facilities f ON f.id=e.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ? $facilityClause
    GROUP BY bucket ORDER BY bucket");
$trendQuery->execute($expenseParams);
$trendValues = [];
foreach ($trendQuery->fetchAll(PDO::FETCH_ASSOC) as $row) $trendValues[(string) $row['bucket']] = (float) $row['total'];

// Fill dates with zero spending, so an empty day/week/month appears in the line chart.
$cursor = $group === 'week' ? $fromDate->modify('monday this week')
    : ($group === 'month' ? $fromDate->modify('first day of this month') : $fromDate);
$trend = [];
while ($cursor <= $toDate) {
    $key = $cursor->format('Y-m-d');
    $trend[] = ['label' => tm_ov_date_label($key, $group), 'value' => $trendValues[$key] ?? 0.0];
    $cursor = $cursor->modify($group === 'day' ? '+1 day' : ($group === 'week' ? '+1 week' : '+1 month'));
}
$maxTrend = max(1.0, ...array_column($trend, 'value'));
$chartW = 850;
$chartH = 260;
$left = 58;
$top = 18;
$plotW = 765;
$plotH = 195;
$count = count($trend);
$points = [];
foreach ($trend as $idx => $point) {
    $x = $left + ($count > 1 ? $idx * $plotW / ($count - 1) : $plotW / 2);
    $y = $top + $plotH * (1 - $point['value'] / $maxTrend);
    $points[] = [round($x, 2), round($y, 2), $point];
}
$polyline = implode(' ', array_map(static fn(array $p): string => $p[0] . ',' . $p[1], $points));
$area = $points ? $left . ',' . ($top + $plotH) . ' ' . $polyline . ' ' . $points[count($points)-1][0] . ',' . ($top + $plotH) : '';

$categoryQuery = $pdo->prepare("SELECT e.category, COALESCE(SUM(e.amount),0) AS total
    FROM expenses e JOIN facilities f ON f.id=e.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ? $facilityClause
    GROUP BY e.category ORDER BY total DESC");
$categoryQuery->execute($expenseParams);
$rawCategories = $categoryQuery->fetchAll(PDO::FETCH_ASSOC);
$categories = [];
foreach ($rawCategories as $index => $row) {
    $key = $index < 5 ? (string) $row['category'] : 'Other categories';
    $categories[$key] = ($categories[$key] ?? 0) + (float) $row['total'];
}
$palette = ['#237b52','#6cb88b','#a2d8b1','#f1b96a','#81a6cf','#bd91c3'];
$totalCategory = array_sum($categories);
$stop = 0.0;
$slices = [];
$categoryLabels = [];
$i = 0;
foreach ($categories as $category => $value) {
    if ($value <= 0) continue;
    $next = $stop + $value / $totalCategory * 100;
    $color = $palette[$i % count($palette)];
    $slices[] = sprintf('%s %.3f%% %.3f%%', $color, $stop, $next);
    $categoryLabels[] = ['name' => $category, 'value' => $value, 'percent' => $value / $totalCategory * 100, 'color' => $color];
    $stop = $next;
    $i++;
}
$donut = $slices ? 'conic-gradient(' . implode(', ', $slices) . ')' : 'conic-gradient(#e4ede8 0% 100%)';

$lowQuery = $pdo->prepare("SELECT i.name, i.quantity, i.reorder_level, i.unit, f.name AS facility_name
    FROM inventory_items i JOIN facilities f ON f.id=i.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND i.status='active' AND i.quantity <= i.reorder_level $facilityClause
    ORDER BY (i.reorder_level - i.quantity) DESC, i.quantity ASC, i.name ASC LIMIT 6");
$lowQuery->execute($liveParams);
$lowItems = $lowQuery->fetchAll(PDO::FETCH_ASSOC);

$recentExpenses = $pdo->prepare("SELECT e.expense_date, e.category, e.description, e.amount, f.name AS facility_name
    FROM expenses e JOIN facilities f ON f.id=e.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ? $facilityClause
    ORDER BY e.expense_date DESC,e.id DESC LIMIT 6");
$recentExpenses->execute($expenseParams);
$latestExpenses = $recentExpenses->fetchAll(PDO::FETCH_ASSOC);

// Activity timestamps are filtered by date; live stock/maintenance cards are not date-limited.
$recentStock = $pdo->prepare("SELECT x.created_at, x.transaction_type, x.quantity_change, i.name AS item_name, i.unit, f.name AS facility_name
    FROM inventory_transactions x JOIN inventory_items i ON i.id=x.item_id
    JOIN facilities f ON f.id=i.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL AND x.created_at >= ? AND x.created_at < DATE_ADD(?, INTERVAL 1 DAY) $facilityClause
    ORDER BY x.created_at DESC,x.id DESC LIMIT 6");
$recentStock->execute($expenseParams);
$latestStock = $recentStock->fetchAll(PDO::FETCH_ASSOC);

$recentMaintenance = $pdo->prepare("SELECT m.description,m.start_date,m.end_date,m.status,m.cost,t.name AS turf_name,f.name AS facility_name
    FROM maintenance_records m JOIN turfs t ON t.id=m.turf_id JOIN facilities f ON f.id=t.facility_id
    WHERE f.owner_id=? AND f.deleted_at IS NULL $facilityClause
    ORDER BY CASE WHEN m.status='in_progress' THEN 0 WHEN m.status='planned' THEN 1 ELSE 2 END,
        CASE WHEN m.status='completed' THEN m.start_date END DESC,
        CASE WHEN m.status <> 'completed' THEN m.start_date END ASC, m.id DESC LIMIT 6");
$recentMaintenance->execute($liveParams);
$maintenanceList = $recentMaintenance->fetchAll(PDO::FETCH_ASSOC);

$filterQuery = http_build_query(['from' => $from, 'to' => $to, 'facility_id' => $selectedFacility]);

tm_page_header('Expenses & Inventory', 'expenses-inventory', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-overview.css?v=1">
<div class="ov-page">
    <div class="ov-heading">
        <div>
            <div class="ov-kicker">
                OPERATIONS OVERVIEW
            </div>
            <h2>
                Expenses & Inventory Overview
            </h2>
            <p>
                Track business spending, equipment stock, and turf upkeep in one place.
            </p>
        </div>
        <a class="ov-action" href="expenses.php?new=1">
            + Record Expense
        </a>
    </div>
    <nav class="ov-tabs" aria-label="Expenses and Inventory pages">
        <a class="active" aria-current="page" href="expenses-inventory.php">
            Overview
        </a>
        <a href="expenses.php">
            Expenses
        </a>
        <a href="inventory.php">
            Inventory
        </a>
        <a href="inventory-transactions.php">
            Stock Transactions
        </a>
        <a href="maintenance.php">
            Maintenance
        </a>
    </nav>
    <form class="ov-filters" method="get" action="expenses-inventory.php">
        <label>
            Facility
            <select name="facility_id">
                <option value="0">
                    All my facilities
                </option>
                <?php foreach ($facilities as $facility): ?>
                    <option value="<?= (int)$facility['id'] ?>" <?= $selectedFacility === (int)$facility['id'] ? 'selected' : '' ?>>
                        <?= tm_e((string)$facility['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            From
            <input type="date" name="from" value="<?= tm_e($from) ?>" required>
        </label>
        <label>
            To
            <input type="date" name="to" value="<?= tm_e($to) ?>" required>
        </label>
        <button type="submit">
            Apply Filters
        </button>
        <a href="expenses-inventory.php" class="ov-reset">
            Reset
        </a>
    </form>
    <?php if ($rangeNotice): ?>
        <div class="ov-note">
            <?= tm_e($rangeNotice) ?>
        </div>
    <?php endif; ?>
    <?php if (!$facilities): ?>
        <div class="ov-note">
            No active facilities yet.
            <a href="facilities.php">
                Create your first facility
            </a>
            to start tracking your business.
        </div>
    <?php endif; ?>
    <div class="ov-metrics">
        <a href="expenses.php" class="ov-metric">
            <span class="ov-metric-icon">
                ↗
            </span>
            <span class="ov-metric-label">
                Expenses in period
            </span>
            <strong>
                <?= tm_money($expense['total'] ?? 0) ?>
            </strong>
            <small>
                <?= (int)($expense['records'] ?? 0) ?>
                expense records
            </small>
        </a>
        <a href="inventory.php" class="ov-metric">
            <span class="ov-metric-icon">
                ▦
            </span>
            <span class="ov-metric-label">
                Inventory stock value
            </span>
            <strong>
                <?= tm_money($inventory['stock_value'] ?? 0) ?>
            </strong>
            <small>
                <?= (int)($inventory['total_items'] ?? 0) ?>
                active items · estimated cost
            </small>
        </a>
        <a href="inventory.php" class="ov-metric">
            <span class="ov-metric-icon ov-warn-icon">
                !
            </span>
            <span class="ov-metric-label">
                Low-stock alerts
            </span>
            <strong>
                <?= (int)($inventory['low_count'] ?? 0) ?>
            </strong>
            <small>
                Items at/below reorder level
            </small>
        </a>
        <a href="maintenance.php" class="ov-metric">
            <span class="ov-metric-icon">
                ⚒
            </span>
            <span class="ov-metric-label">
                Open maintenance
            </span>
            <strong>
                <?= (int)($maintenance['open_count'] ?? 0) ?>
            </strong>
            <small>
                <?= (int)($maintenance['completed_count'] ?? 0) ?>
                completed overall
            </small>
        </a>
    </div>
    <div class="ov-charts">
        <section class="ov-panel ov-trend-panel">
            <div class="ov-panel-head">
                <div>
                    <h3>
                        Expense Trend
                    </h3>
                    <p>
                        Actual recorded expenses ·
                        <?= tm_e($from) ?>
                        to
                        <?= tm_e($to) ?>
                    </p>
                </div>
                <span class="ov-pill">
                    <?= $group === 'day' ? 'Daily' : ($group === 'week' ? 'Weekly' : 'Monthly') ?>
                </span>
            </div>
            <div class="ov-trend-wrap">
                <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" role="img" aria-label="Expense trend line chart for the selected period" preserveAspectRatio="xMidYMid meet">
                    <defs>
                        <linearGradient id="ovFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#4cad78" stop-opacity=".22"/>
                            <stop offset="100%" stop-color="#4cad78" stop-opacity=".005"/>
                        </linearGradient>
                    </defs>
                    <?php for ($step = 0; $step <= 4; $step++): $y = $top + $plotH * $step / 4; $val = $maxTrend * (4-$step) / 4; ?>
                    <line x1="<?= $left ?>" y1="<?= $y ?>" x2="<?= $left+$plotW ?>" y2="<?= $y ?>" stroke="#e7eee9" stroke-width="1" stroke-dasharray="4 5"/>
                    <text x="<?= $left-10 ?>" y="<?= $y+4 ?>" text-anchor="end" class="ov-axis-text">
                        <?= tm_e(tm_ov_money_short($val)) ?>
                    </text>
                <?php endfor; ?>
                <?php if ($count): ?>
                    <polygon points="<?= tm_e($area) ?>" fill="url(#ovFill)"/>
                    <polyline points="<?= tm_e($polyline) ?>" fill="none" stroke="#237b52" stroke-width="3.2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                    <?php foreach ($points as $idx => $point): ?>
                        <circle cx="<?= $point[0] ?>" cy="<?= $point[1] ?>" r="<?= $count <= 16 ? '4.2' : '2.8' ?>" fill="#237b52" stroke="white" stroke-width="1.5">
                        <title>
                            <?= tm_e($point[2]['label']) ?>
                            :
                            <?= tm_e(tm_money($point[2]['value'])) ?>
                        </title>
                    </circle>
                    <?php if ($idx === 0 || $idx === $count-1 || ($count > 2 && $idx % max(1, (int)ceil($count / 6)) === 0)): ?>
                        <text x="<?= $point[0] ?>" y="<?= $top+$plotH+23 ?>" text-anchor="middle" class="ov-axis-text">
                            <?= tm_e($point[2]['label']) ?>
                        </text>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </svg>
    </div>
    <div class="ov-chart-footer">
        <span>
            <i class="ov-legend-dot">
            </i>
            Expense amount (PKR)
        </span>
        <span>
            Hover a point to see its value
        </span>
    </div>
</section>
<section class="ov-panel ov-category-panel">
    <div class="ov-panel-head">
        <div>
            <h3>
                Expenses by Category
            </h3>
            <p>
                Where your money is going
            </p>
        </div>
    </div>
    <div class="ov-donut-wrap">
        <div class="ov-donut" style="background:<?= tm_e($donut) ?>" role="img" aria-label="Expense category proportions">
            <div class="ov-donut-hole">
                <span>
                    Total spent
                </span>
                <strong>
                    <?= tm_e(tm_ov_money_short((float)$totalCategory)) ?>
                </strong>
            </div>
        </div>
        <div class="ov-categories">
            <?php if (!$categoryLabels): ?>
                <div class="ov-empty">
                    No expenses in this date range.
                </div>
            <?php endif; ?>
            <?php foreach ($categoryLabels as $entry): ?>
                <div class="ov-category-row">
                    <span class="ov-cat-name">
                        <i style="background:<?= tm_e($entry['color']) ?>">
                        </i>
                        <?= tm_e($entry['name']) ?>
                    </span>
                    <strong>
                        <?= number_format($entry['percent'],1) ?>
                        %
                    </strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
</div>
<div class="ov-bottom-grid">
    <section class="ov-panel">
        <div class="ov-panel-head">
            <div>
                <h3>
                    Low-stock Items
                </h3>
                <p>
                    Equipment that needs attention
                </p>
            </div>
            <a href="inventory.php">
                View inventory →
            </a>
        </div>
        <?php if (!$lowItems): ?>
            <p class="ov-empty">
                No low-stock alerts. You're all set!
            </p>
        <?php else: ?>
            <div class="ov-list">
                <?php foreach ($lowItems as $item): ?>
                    <div class="ov-list-row">
                        <div>
                            <strong>
                                <?= tm_e((string)$item['name']) ?>
                            </strong>
                            <small>
                                <?= tm_e((string)$item['facility_name']) ?>
                            </small>
                        </div>
                        <span class="ov-low-badge">
                            <?= (int)$item['quantity'] ?>
                            /
                            <?= (int)$item['reorder_level'] ?>
                            <?= tm_e((string)$item['unit']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="ov-panel">
        <div class="ov-panel-head">
            <div>
                <h3>
                    Maintenance Activities
                </h3>
                <p>
                    Open work comes first
                </p>
            </div>
            <a href="maintenance.php">
                Manage →
            </a>
        </div>
        <?php if (!$maintenanceList): ?>
            <p class="ov-empty">
                No maintenance records yet.
            </p>
        <?php else: ?>
            <div class="ov-list">
                <?php foreach ($maintenanceList as $job): ?>
                    <div class="ov-list-row">
                        <div>
                            <strong>
                                <?= tm_e((string)$job['description']) ?>
                            </strong>
                            <small>
                                <?= tm_e((string)$job['turf_name']) ?>
                                ·
                                <?= tm_e((string)$job['start_date']) ?>
                            </small>
                        </div>
                        <span class="ov-status ov-<?= tm_e((string)$job['status']) ?>">
                            <?= tm_e(ucwords(str_replace('_',' ',(string)$job['status']))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="ov-panel">
        <div class="ov-panel-head">
            <div>
                <h3>
                    Recent Expenses
                </h3>
                <p>
                    Spending in the selected period
                </p>
            </div>
            <a href="expenses.php">
                All expenses →
            </a>
        </div>
        <?php if (!$latestExpenses): ?>
            <p class="ov-empty">
                No expenses for these filters.
            </p>
        <?php else: ?>
            <div class="ov-list">
                <?php foreach ($latestExpenses as $item): ?>
                    <div class="ov-list-row">
                        <div>
                            <strong>
                                <?= tm_e((string)$item['category']) ?>
                            </strong>
                            <small>
                                <?= tm_e((string)($item['description'] ?: $item['facility_name'])) ?>
                                ·
                                <?= tm_e((string)$item['expense_date']) ?>
                            </small>
                        </div>
                        <span class="ov-row-amount">
                            <?= tm_money($item['amount']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="ov-panel">
        <div class="ov-panel-head">
            <div>
                <h3>
                    Recent Stock Movements
                </h3>
                <p>
                    Movements in the selected period
                </p>
            </div>
            <a href="inventory-transactions.php">
                View history →
            </a>
        </div>
        <?php if (!$latestStock): ?>
            <p class="ov-empty">
                No stock movements for these filters.
            </p>
        <?php else: ?>
            <div class="ov-list">
                <?php foreach ($latestStock as $movement): ?>
                    <div class="ov-list-row">
                        <div>
                            <strong>
                                <?= tm_e((string)$movement['item_name']) ?>
                            </strong>
                            <small>
                                <?= tm_e(ucfirst((string)$movement['transaction_type'])) ?>
                                ·
                                <?= tm_e((string)$movement['facility_name']) ?>
                            </small>
                        </div>
                        <span class="ov-stock-change <?= (int)$movement['quantity_change'] < 0 ? 'negative' : '' ?>">
                            <?= (int)$movement['quantity_change'] > 0 ? '+' : '' ?>
                            <?= (int)$movement['quantity_change'] ?>
                            <?= tm_e((string)$movement['unit']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<div class="ov-footnote">
    Financial note: spending totals come only from the
    <strong>
        Expenses
    </strong>
    table. Maintenance costs are shown separately in Maintenance and are not added again here. Inventory value is an estimate (quantity × unit cost), not cash received.
</div>
</div>
<?php tm_page_footer(); ?>
