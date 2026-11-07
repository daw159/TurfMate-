<?php
declare(strict_types=1);

/** TurfMate | Reports & Analytics | Export & Custom Reports */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_reports_export_data.php';

$facilities = tm_x_options($pdo, $ownerId);
$error = '';
$config = null;
$report = null;

try {
    $config = tm_x_config($_GET, $facilities);
    $report = tm_x_report($pdo, $ownerId, $config, 26);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log('TurfMate export preview: ' . $exception->getMessage());
    $error = 'The report could not be loaded. Check the Apache error log.';
}

$params = [
    'type' => $config['type'] ?? 'financial',
    'facility_id' => $config['facilityId'] ?? 0,
    'period' => $config['period'] ?? 'month',
    'from' => $config['from'] ?? date('Y-m-01'),
    'to' => $config['to'] ?? date('Y-m-d'),
];
$downloadUrl = 'reports-download.php?' . http_build_query($params);
$selectedTitle = $config['types'][$config['type']] ?? 'Custom Report';
$facilityName = 'All my facilities';
foreach ($facilities as $facility) {
    if ((int) $facility['id'] === (int) ($params['facility_id'] ?? 0)) {
        $facilityName = (string) $facility['name'];
        break;
    }
}

tm_page_header('Reports & Analytics', 'reports', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-export.css?v=1">

<div class="xr-page">
    <div class="xr-heading">
        <div>
            <div class="xr-kicker">REPORTS &amp; ANALYTICS</div>
            <h2>Export &amp; Custom Reports</h2>
            <p>Choose a report, preview the results, and export a spreadsheet or print-ready PDF.</p>
        </div>
        <span class="xr-chip">Owner-only · Read only</span>
    </div>

    <nav class="xr-tabs" aria-label="Reports navigation">
        <a href="reports.php">Overview</a>
        <a href="reports-financial.php">Financial</a>
        <a href="reports-bookings.php">Bookings</a>
        <a href="reports-inventory.php">Inventory &amp; Maintenance</a>
        <a href="reports-export.php" class="active" aria-current="page">Export</a>
    </nav>

    <?php if ($error !== ''): ?>
        <div class="xr-error" role="alert"><?= tm_e($error) ?></div>
    <?php endif; ?>

    <section class="xr-panel xr-controls">
        <div class="xr-panel-title">
            <h3>Generate Custom Report</h3>
            <p>Only data belonging to your facilities can be included.</p>
        </div>

        <form method="get" action="reports-export.php" class="xr-form">
            <label>
                Report Type
                <select name="type" required>
                    <?php foreach (($config['types'] ?? [
                        'financial' => 'Financial Summary',
                        'bookings' => 'Booking Reservations',
                        'payments' => 'Payments and Refunds',
                        'expenses' => 'Expense History',
                        'inventory' => 'Inventory Snapshot',
                        'stock' => 'Stock Transactions',
                        'maintenance' => 'Maintenance History',
                    ]) as $type => $label): ?>
                        <option value="<?= tm_e($type) ?>"
                            <?= $params['type'] === $type ? 'selected' : '' ?>>
                            <?= tm_e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Facility
                <select name="facility_id">
                    <option value="0">All my facilities</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>"
                            <?= (int) $params['facility_id'] === (int) $facility['id'] ? 'selected' : '' ?>>
                            <?= tm_e((string) $facility['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Date Range
                <select name="period" id="xrPeriod">
                    <?php foreach ([
                        'today' => 'Today', 'yesterday' => 'Yesterday',
                        'week' => 'This Week', 'month' => 'This Month',
                        'year' => 'This Year', 'custom' => 'Custom Dates',
                    ] as $value => $label): ?>
                        <option value="<?= tm_e($value) ?>"
                            <?= $params['period'] === $value ? 'selected' : '' ?>>
                            <?= tm_e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                From
                <input type="date" name="from" id="xrFrom" value="<?= tm_e($params['from']) ?>">
            </label>

            <label>
                To
                <input type="date" name="to" id="xrTo" value="<?= tm_e($params['to']) ?>">
            </label>

            <div class="xr-form-actions">
                <button type="submit" class="xr-primary">Generate Report</button>
                <a href="reports-export.php" class="xr-muted">Reset</a>
            </div>
        </form>
    </section>

    <?php if ($report !== null && $config !== null): ?>
        <section class="xr-panel xr-preview" id="report-preview">
            <div class="xr-preview-head">
                <div>
                    <div class="xr-kicker">REPORT PREVIEW</div>
                    <h3><?= tm_e($selectedTitle) ?></h3>
                    <p><?= tm_e($facilityName) ?> · <?= tm_e($config['from']) ?> to <?= tm_e($config['to']) ?></p>
                </div>
                <div class="xr-downloads">
                    <a href="<?= tm_e($downloadUrl) ?>" class="xr-primary">Download CSV</a>
                    <button type="button" class="xr-outline" onclick="window.print()">Print / Save as PDF</button>
                </div>
            </div>

            <p class="xr-note"><?= tm_e($report['note']) ?></p>
            <div class="xr-table-scroll">
                <table class="xr-table">
                    <thead>
                        <tr>
                            <?php foreach ($report['columns'] as $label): ?>
                                <th scope="col"><?= tm_e($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$report['rows']): ?>
                            <tr>
                                <td colspan="<?= count($report['columns']) ?>" class="xr-empty">
                                    No records match the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($report['rows'], 0, 25) as $row): ?>
                                <tr>
                                    <?php foreach (array_keys($report['columns']) as $key): ?>
                                        <td><?= tm_e((string) ($row[$key] ?? '—')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="xr-table-footer">
                <span>Showing up to 25 rows in preview. CSV export includes up to 20,000 rows.</span>
                <span>Currency: PKR</span>
            </div>
        </section>
    <?php endif; ?>

    <div class="xr-hint">
        <strong>PDF:</strong> Click <em>Print / Save as PDF</em>, then select
        <em>Save to PDF</em> in your browser's print dialog. No library installation needed.
    </div>
</div>

<script>
(function () {
    const period = document.getElementById('xrPeriod');
    const from = document.getElementById('xrFrom');
    const to = document.getElementById('xrTo');

    function updateDates() {
        const custom = period.value === 'custom';
        from.disabled = !custom;
        to.disabled = !custom;
        from.required = custom;
        to.required = custom;
    }

    period.addEventListener('change', updateDates);
    updateDates();
})();
</script>

<?php tm_page_footer(); ?>
