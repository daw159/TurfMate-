<?php
/**
 * TurfMate — Platform-wide Reports.
 * Read-only reporting; no owner pricing or booking management actions.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin']);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_management.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Default to the last 30 calendar days, including today.
$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('-29 days')->format('Y-m-d');
$start = (string) ($_GET['start'] ?? $defaultStart);
$end = (string) ($_GET['end'] ?? $today->format('Y-m-d'));
$error = '';

function reportDateIsValid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if (!reportDateIsValid($start) || !reportDateIsValid($end)) {
    $error = 'Please choose valid dates.';
} elseif ($start > $end) {
    $error = 'The start date must not be after the end date.';
} elseif ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 366) {
    $error = 'Choose a date range of 367 days or fewer.';
}

// Date filters use half-open ranges to include every time on the end date.
if ($error === '') {
    $endExclusive = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
    try {
        $bookingStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS amount
             FROM bookings
             WHERE created_at >= ? AND created_at < ?
             GROUP BY status'
        );
        $bookingStmt->execute([$start, $endExclusive]);
        $bookingRows = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);

        $userStmt = $pdo->prepare(
            'SELECT role, COUNT(*) AS total FROM users
             WHERE created_at >= ? AND created_at < ? GROUP BY role'
        );
        $userStmt->execute([$start, $endExclusive]);
        $userRows = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $facilityStmt = $pdo->prepare(
            'SELECT approval_status, COUNT(*) AS total FROM facilities
             WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?
             GROUP BY approval_status'
        );
        $facilityStmt->execute([$start, $endExclusive]);
        $facilityRows = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);

        $trendStmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM bookings WHERE created_at >= ? AND created_at < ?
             GROUP BY DATE(created_at) ORDER BY day'
        );
        $trendStmt->execute([$start, $endExclusive]);
        $bookingTrend = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $registrationStmt = $pdo->prepare(
            'SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM users WHERE created_at >= ? AND created_at < ?
             GROUP BY DATE(created_at) ORDER BY day'
        );
        $registrationStmt->execute([$start, $endExclusive]);
        $registrationTrend = $registrationStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $exception) {
        error_log('Super Admin reports: ' . $exception->getMessage());
        http_response_code(503);
        exit('Reports are temporarily unavailable. Please check the server logs.');
    }
}

$bookingCounts = array_fill_keys(['pending', 'confirmed', 'completed', 'cancelled', 'rejected'], 0);
$registrationCounts = array_fill_keys(['customer', 'owner', 'staff', 'super_admin'], 0);
$facilityCounts = array_fill_keys(['pending', 'approved', 'rejected', 'suspended'], 0);
$bookingTotal = 0;
$completedAmount = 0.0;

if ($error === '') {
    foreach ($bookingRows as $row) {
        $bookingCounts[$row['status']] = (int) $row['total'];
        $bookingTotal += (int) $row['total'];
        if ($row['status'] === 'completed') {
            $completedAmount += (float) $row['amount'];
        }
    }
    foreach ($userRows as $row) {
        $registrationCounts[$row['role']] = (int) $row['total'];
    }
    foreach ($facilityRows as $row) {
        $facilityCounts[$row['approval_status']] = (int) $row['total'];
    }
}

// Aggregate by calendar day; the UI limits the chart to the latest 14 days
// while the summary and status tables use the entire selected range.
$days = [];
if ($error === '') {
    $chartStart = max($start, (new DateTimeImmutable($end))->modify('-13 days')->format('Y-m-d'));
    $cursor = new DateTimeImmutable($chartStart);
    $lastDay = new DateTimeImmutable($end);
    while ($cursor <= $lastDay) {
        $day = $cursor->format('Y-m-d');
        $days[] = [
            'day' => $cursor->format('d M'),
            'bookings' => (int) ($bookingTrend[$day] ?? 0),
            'registrations' => (int) ($registrationTrend[$day] ?? 0),
        ];
        $cursor = $cursor->modify('+1 day');
    }
}

adminPageStart('Platform Reports', 'reports', 'Booking, facility and registration statistics across TurfMate.');
?>
<link rel="stylesheet" href="../assets/css/super-admin-reports.css?v=1">

<section class="panel">
    <div class="panel-heading">
        <div>
            <h3>Reporting period</h3>
            <p>Counts are based on record creation dates, not scheduled booking dates.</p>
        </div>
    </div>
    <form class="report-filters" method="get">
        <label>From <input type="date" name="start" value="<?= adminEscape($start) ?>" required></label>
        <label>To <input type="date" name="end" value="<?= adminEscape($end) ?>" required></label>
        <button class="report-button" type="submit">Apply filters</button>
    </form>
</section>

<?php if ($error !== ''): ?>
    <div class="management-alert error" role="alert"><?= adminEscape($error) ?></div>
<?php else: ?>
    <section class="report-kpis" aria-label="Report highlights">
        <div class="report-kpi"><span>Bookings created</span><strong><?= number_format($bookingTotal) ?></strong></div>
        <div class="report-kpi"><span>New customers</span><strong><?= number_format($registrationCounts['customer']) ?></strong></div>
        <div class="report-kpi"><span>New owners</span><strong><?= number_format($registrationCounts['owner']) ?></strong></div>
        <div class="report-kpi"><span>New facilities</span><strong><?= number_format(array_sum($facilityCounts)) ?></strong></div>
        <div class="report-kpi"><span>Completed booking value (PKR)</span><strong><?= number_format($completedAmount, 2) ?></strong></div>
    </section>
    <p class="eyebrow">Completed booking value is the sum of recorded booking totals, not verified payments or platform revenue.</p>

    <div class="report-grid">
        <section class="panel">
            <div class="panel-heading"><div><h3>Booking statuses</h3><p>Bookings created during the selected period</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>
                <?php foreach ($bookingCounts as $status => $count): ?>
                    <tr><td><?= adminEscape(ucfirst($status)) ?></td><td><?= number_format($count) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="panel">
            <div class="panel-heading"><div><h3>New user registrations</h3><p>All roles during the selected period</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Role</th><th>Count</th></tr></thead><tbody>
                <?php foreach ($registrationCounts as $role => $count): ?>
                    <tr><td><?= adminEscape(ucwords(str_replace('_', ' ', $role))) ?></td><td><?= number_format($count) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="panel">
            <div class="panel-heading"><div><h3>Facility approval statuses</h3><p>Non-deleted facilities created in the period</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Approval status</th><th>Count</th></tr></thead><tbody>
                <?php foreach ($facilityCounts as $status => $count): ?>
                    <tr><td><?= adminEscape(ucfirst($status)) ?></td><td><?= number_format($count) ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>

    <section class="panel" style="margin-top: 18px">
        <div class="panel-heading"><div><h3>Daily activity</h3><p>Latest 14 days within your selected range</p></div></div>
        <div class="report-grid" style="padding: 0 20px 20px">
            <?php foreach (['bookings' => 'Bookings', 'registrations' => 'Registrations'] as $key => $label): ?>
                <?php $maximum = max(1, ...array_column($days, $key)); ?>
                <div>
                    <h4><?= adminEscape($label) ?></h4>
                    <div class="report-chart" role="img" aria-label="<?= adminEscape($label) ?> per day">
                        <?php foreach ($days as $day): ?>
                            <div class="report-column">
                                <strong><?= $day[$key] ?></strong>
                                <div class="bar-wrap">
                                    <div class="bar" style="height: <?= max(2, round($day[$key] / $maximum * 100)) ?>%"></div>
                                </div>
                                <span><?= adminEscape($day['day']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php adminPageEnd(); ?>
