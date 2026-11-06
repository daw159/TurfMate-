<?php
declare(strict_types=1);

/** TurfMate - Owner maintenance management. Existing maintenance_records table. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_mt_id(mixed $value): int
{
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $result === false ? 0 : (int) $result;
}

function tm_mt_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function tm_mt_cost(string $value): bool
{
    return (bool) preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value)
        && (float) $value <= 99999999.99;
}

$errors = [];
$statusLabels = [
    'planned' => 'Planned',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];

// Turf ownership is always checked through its facility.
$turfsQuery = $pdo->prepare(
    'SELECT t.id, t.name, t.facility_id, f.name AS facility_name
     FROM turfs t
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL AND t.deleted_at IS NULL
     ORDER BY f.name, t.name'
);
$turfsQuery->execute([$ownerId]);
$turfs = $turfsQuery->fetchAll(PDO::FETCH_ASSOC);
$turfIds = array_map('intval', array_column($turfs, 'id'));

$facilitiesQuery = $pdo->prepare('SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name');
$facilitiesQuery->execute([$ownerId]);
$facilities = $facilitiesQuery->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

$form = [
    'id' => 0,
    'turf_id' => $turfIds[0] ?? 0,
    'description' => '',
    'cost' => '0.00',
    'start_date' => date('Y-m-d'),
    'end_date' => '',
    'status' => 'planned',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = tm_mt_id($_POST['id'] ?? null);

    if ($action === 'delete') {
        $stmt = $pdo->prepare(
            'DELETE m FROM maintenance_records m
             JOIN turfs t ON t.id = m.turf_id
             JOIN facilities f ON f.id = t.facility_id
             WHERE m.id = ? AND f.owner_id = ?'
        );
        $stmt->execute([$id, $ownerId]);
        tm_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount()
            ? 'Maintenance record deleted.' : 'Record not found or not yours.');
        tm_redirect('maintenance.php');
    }

    if ($action === 'save') {
        $form = [
            'id' => $id,
            'turf_id' => tm_mt_id($_POST['turf_id'] ?? null),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'cost' => trim((string) ($_POST['cost'] ?? '')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? '')),
        ];

        if (!in_array($form['turf_id'], $turfIds, true)) $errors[] = 'Choose one of your active turfs.';
        if ($form['description'] === '' || mb_strlen($form['description']) > 255) $errors[] = 'Description is required (maximum 255 characters).';
        if (!tm_mt_cost($form['cost'])) $errors[] = 'Cost must be between 0 and 99,999,999.99 (up to two decimals).';
        if (!tm_mt_date($form['start_date'])) $errors[] = 'Enter a valid start date.';
        if ($form['end_date'] !== '' && !tm_mt_date($form['end_date'])) $errors[] = 'Enter a valid end date.';
        if ($form['end_date'] !== '' && $form['start_date'] > $form['end_date']) $errors[] = 'End date cannot be earlier than start date.';
        if (!isset($statusLabels[$form['status']])) $errors[] = 'Choose a valid maintenance status.';

        if (!$errors) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE maintenance_records m
                         JOIN turfs t ON t.id = m.turf_id
                         JOIN facilities f ON f.id = t.facility_id
                         SET m.turf_id = ?, m.description = ?, m.cost = ?,
                             m.start_date = ?, m.end_date = ?, m.status = ?
                         WHERE m.id = ? AND f.owner_id = ?'
                    );
                    $stmt->execute([
                        $form['turf_id'], $form['description'], $form['cost'],
                        $form['start_date'], $form['end_date'] ?: null,
                        $form['status'], $id, $ownerId,
                    ]);
                    // UPDATE rowCount may be 0 when submitted values are unchanged.
                    $check = $pdo->prepare(
                        'SELECT 1 FROM maintenance_records m JOIN turfs t ON t.id=m.turf_id
                         JOIN facilities f ON f.id=t.facility_id WHERE m.id=? AND f.owner_id=?'
                    );
                    $check->execute([$id, $ownerId]);
                    if (!$check->fetchColumn()) throw new RuntimeException('Maintenance record not found or not yours.');
                    tm_flash('success', 'Maintenance record updated.');
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO maintenance_records
                         (turf_id, created_by, description, cost, start_date, end_date, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $form['turf_id'], $ownerId, $form['description'], $form['cost'],
                        $form['start_date'], $form['end_date'] ?: null, $form['status'],
                    ]);
                    tm_flash('success', 'Maintenance record created.');
                }
                tm_redirect('maintenance.php');
            } catch (Throwable $e) {
                error_log('TurfMate maintenance save: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage() : 'Unable to save maintenance. Please check the server logs.';
            }
        }
    } else {
        $errors[] = 'Invalid form action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = tm_mt_id($_GET['edit']);
    $stmt = $pdo->prepare(
        'SELECT m.* FROM maintenance_records m
         JOIN turfs t ON t.id = m.turf_id
         JOIN facilities f ON f.id = t.facility_id
         WHERE m.id=? AND f.owner_id=? AND f.deleted_at IS NULL AND t.deleted_at IS NULL'
    );
    $stmt->execute([$id, $ownerId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        tm_flash('error', 'Maintenance record not found or not yours.');
        tm_redirect('maintenance.php');
    }
    $form = $existing;
}

$selectedFacility = tm_mt_id($_GET['facility_id'] ?? null);
if (!in_array($selectedFacility, $facilityIds, true)) $selectedFacility = 0;
$selectedStatus = (string) ($_GET['status'] ?? 'all');
if ($selectedStatus !== 'all' && !isset($statusLabels[$selectedStatus])) $selectedStatus = 'all';
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);

$where = ['f.owner_id=?', 'f.deleted_at IS NULL'];
$params = [$ownerId];
if ($selectedFacility) { $where[] = 'f.id=?'; $params[] = $selectedFacility; }
if ($selectedStatus !== 'all') { $where[] = 'm.status=?'; $params[] = $selectedStatus; }
if ($search !== '') {
    $where[] = '(m.description LIKE ? OR t.name LIKE ? OR f.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$listQuery = $pdo->prepare(
    "SELECT m.*, t.name AS turf_name, f.name AS facility_name, u.name AS created_by_name
     FROM maintenance_records m
     JOIN turfs t ON t.id=m.turf_id
     JOIN facilities f ON f.id=t.facility_id
     JOIN users u ON u.id=m.created_by
     WHERE $whereSql
     ORDER BY m.start_date DESC, m.id DESC LIMIT 250"
);
$listQuery->execute($params);
$records = $listQuery->fetchAll(PDO::FETCH_ASSOC);

$statsQuery = $pdo->prepare(
    "SELECT COUNT(*) AS total,
     COALESCE(SUM(m.status='planned'),0) AS planned,
     COALESCE(SUM(m.status='in_progress'),0) AS ongoing,
     COALESCE(SUM(m.status='completed'),0) AS completed,
     COALESCE(SUM(CASE WHEN m.status='completed' THEN m.cost ELSE 0 END),0) AS completed_cost
     FROM maintenance_records m JOIN turfs t ON t.id=m.turf_id
     JOIN facilities f ON f.id=t.facility_id
     WHERE f.owner_id=? AND f.deleted_at IS NULL"
);
$statsQuery->execute([$ownerId]);
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
$showForm = isset($_GET['new']) || isset($_GET['edit']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save');

tm_page_header('Maintenance Management', 'maintenance', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-maintenance.css?v=1">
<div class="mt-page">
    <div class="mt-heading">
        <div>
            <h2>Maintenance Management</h2>
            <p>Schedule turf repairs, track progress and record maintenance costs.</p>
        </div>
        <a class="mt-btn mt-primary" href="maintenance.php?new=1">+ Add Maintenance</a>
    </div>

    <nav class="mt-tabs" aria-label="Expenses and inventory navigation">
        <span class="mt-disabled">Overview (next)</span>
        <a href="expenses.php">Expenses</a>
        <a href="inventory.php">Inventory</a>
        <a href="inventory-transactions.php">Stock Transactions</a>
        <a href="maintenance.php" class="active" aria-current="page">Maintenance</a>
    </nav>

    <?php if ($errors): ?>
        <div class="mt-error" role="alert"><strong>Please fix:</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= tm_e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if (!$turfs): ?>
        <div class="mt-notice">No active turfs are available. <a href="turfs.php">Create a turf</a> first.</div>
    <?php endif; ?>

    <section class="mt-stats" aria-label="Maintenance statistics">
        <article><small>Total Records</small><strong><?= (int) $stats['total'] ?></strong></article>
        <article><small>Planned</small><strong><?= (int) $stats['planned'] ?></strong></article>
        <article><small>In Progress</small><strong><?= (int) $stats['ongoing'] ?></strong></article>
        <article><small>Completed Cost</small><strong><?= tm_money($stats['completed_cost']) ?></strong></article>
    </section>

    <?php if ($showForm): ?>
        <section class="mt-card" id="maintenance-form">
            <div class="mt-section-head">
                <h3><?= (int) $form['id'] > 0 ? 'Edit maintenance' : 'Add maintenance' ?></h3>
                <a href="maintenance.php">Close</a>
            </div>
            <form class="mt-form" method="post" action="maintenance.php">
                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                <label>Turf *
                    <select name="turf_id" required>
                        <option value="">Select a turf</option>
                        <?php foreach ($turfs as $turf): ?>
                            <option value="<?= (int) $turf['id'] ?>" <?= (int) $form['turf_id'] === (int) $turf['id'] ? 'selected' : '' ?>>
                                <?= tm_e($turf['facility_name'] . ' / ' . $turf['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status *
                    <select name="status" required>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= tm_e($value) ?>" <?= $form['status'] === $value ? 'selected' : '' ?>><?= tm_e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Start Date *
                    <input type="date" name="start_date" required value="<?= tm_e((string) $form['start_date']) ?>">
                </label>
                <label>End Date (optional)
                    <input type="date" name="end_date" value="<?= tm_e((string) ($form['end_date'] ?? '')) ?>">
                </label>
                <label>Cost (Rs.) *
                    <input type="number" name="cost" min="0" max="99999999.99" step="0.01" required value="<?= tm_e((string) $form['cost']) ?>">
                </label>
                <label class="mt-wide">Description *
                    <textarea name="description" maxlength="255" rows="3" required placeholder="E.g. Replace damaged turf carpet"><?= tm_e((string) $form['description']) ?></textarea>
                </label>
                <div class="mt-wide mt-actions">
                    <button class="mt-btn mt-primary" type="submit" <?= !$turfs ? 'disabled' : '' ?>><?= (int) $form['id'] ? 'Save Changes' : 'Create Maintenance' ?></button>
                    <a class="mt-btn mt-secondary" href="maintenance.php">Cancel</a>
                </div>
            </form>
            <p class="mt-hint">Maintenance costs are tracked here only. To include actual spending in Expenses, record it once on the Expenses page; automatic linking is not implemented yet.</p>
        </section>
    <?php endif; ?>

    <section class="mt-card">
        <div class="mt-section-head"><div><h3>Maintenance History</h3><p>Search and filter maintenance activities for your facilities.</p></div></div>
        <form class="mt-filters" method="get" action="maintenance.php">
            <label>Facility
                <select name="facility_id">
                    <option value="0">All Facilities</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= $selectedFacility === (int) $facility['id'] ? 'selected' : '' ?>><?= tm_e($facility['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="all">All Statuses</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= tm_e($value) ?>" <?= $selectedStatus === $value ? 'selected' : '' ?>><?= tm_e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Search
                <input name="q" value="<?= tm_e($search) ?>" placeholder="Turf or description">
            </label>
            <div class="mt-filter-actions">
                <button class="mt-btn mt-primary" type="submit">Filter</button>
                <a class="mt-btn mt-secondary" href="maintenance.php">Reset</a>
            </div>
        </form>
        <div class="mt-table-wrap">
            <table class="mt-table">
                <thead><tr><th>Start Date</th><th>Facility / Turf</th><th>Description</th><th>Cost</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$records): ?>
                    <tr><td colspan="6" class="mt-empty">No maintenance records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= tm_e($record['start_date']) ?><?php if ($record['end_date']): ?><small>To <?= tm_e($record['end_date']) ?></small><?php endif; ?></td>
                        <td><strong><?= tm_e($record['facility_name']) ?></strong><small><?= tm_e($record['turf_name']) ?></small></td>
                        <td><?= tm_e($record['description']) ?></td>
                        <td class="mt-cost"><?= tm_money($record['cost']) ?></td>
                        <td><span class="mt-status mt-<?= tm_e($record['status']) ?>"><?= tm_e($statusLabels[$record['status']] ?? $record['status']) ?></span></td>
                        <td class="mt-row-actions">
                            <a href="maintenance.php?edit=<?= (int) $record['id'] ?>">Edit</a>
                            <form method="post" action="maintenance.php" onsubmit="return confirm('Delete this maintenance record?');">
                                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-hint">Showing up to 250 matching records. Completed costs shown above are informational, not additional entries in Expenses.</p>
    </section>
</div>
<?php tm_page_footer(); ?>
