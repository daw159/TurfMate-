<?php
declare(strict_types=1);

/** Read-only maintenance view for assigned, approved facilities. */
require_once __DIR__ . '/_init.php';

$allowedStatuses = ['all', 'planned', 'in_progress', 'completed'];
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$facilityId = filter_input(INPUT_GET, 'facility_id', FILTER_VALIDATE_INT);
$facilityId = ($facilityId !== false && $facilityId !== null && $facilityId > 0) ? $facilityId : 0;
$facilities = staffAssignments($db, $staffId);
$allowedIds = array_map('intval', array_column($facilities, 'id'));
if ($facilityId && !in_array($facilityId, $allowedIds, true)) {
    http_response_code(403);
    exit('You do not have access to this facility.');
}

// Facility permissions are enforced in SQL, not merely by the filter UI.
$sql = <<<'SQL'
    SELECT m.id, m.description, m.start_date, m.end_date, m.status,
           t.name AS turf_name, f.name AS facility_name, f.id AS facility_id
    FROM maintenance_records m
    JOIN turfs t ON t.id = m.turf_id
    JOIN facilities f ON f.id = t.facility_id
    JOIN users owner ON owner.id = f.owner_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    WHERE sa.staff_id = :staff_id
      AND sa.status = 'active'
      AND f.status = 'active'
      AND f.approval_status = 'approved'
      AND f.deleted_at IS NULL
      AND t.deleted_at IS NULL
      AND owner.status = 'active'
SQL;
$params = ['staff_id' => $staffId];
if ($facilityId) {
    $sql .= ' AND f.id = :facility_id';
    $params['facility_id'] = $facilityId;
}
if ($status !== 'all') {
    $sql .= ' AND m.status = :status';
    $params['status'] = $status;
}
$sql .= " ORDER BY CASE WHEN m.status = 'in_progress' THEN 0 WHEN m.status = 'planned' THEN 1 ELSE 2 END, m.start_date DESC, m.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = ['planned' => 'Planned', 'in_progress' => 'In Progress', 'completed' => 'Completed'];

staffLayoutStart('Maintenance', 'maintenance');
?>
<section class="mt-wrap">
    <div class="mt-heading">
        <div>
            <h1>Maintenance</h1>
            <p>Track repair and maintenance work at your assigned facilities.</p>
        </div>
        <span class="mt-readonly">View only</span>
    </div>

    <form method="get" class="mt-filters">
        <label for="facility_id">Facility</label>
        <select name="facility_id" id="facility_id">
            <option value="0">All assigned facilities</option>
            <?php foreach ($facilities as $facility): ?>
                <option value="<?= (int) $facility['id'] ?>" <?= $facilityId === (int) $facility['id'] ? 'selected' : '' ?>>
                    <?= staffEscape($facility['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="status">Status</label>
        <select name="status" id="status">
            <option value="all">All statuses</option>
            <?php foreach ($labels as $key => $label): ?>
                <option value="<?= staffEscape($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= staffEscape($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <div class="mt-panel">
        <div class="mt-panel-header"><h2>Maintenance records</h2><span><?= count($records) ?> records</span></div>
        <?php if (!$records): ?>
            <p class="mt-empty">No maintenance records found for the selected filters.</p>
        <?php else: ?>
            <div class="mt-table-scroll">
                <table class="mt-table">
                    <thead><tr><th>Facility</th><th>Turf</th><th>Maintenance work</th><th>Start date</th><th>End date</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= staffEscape($record['facility_name']) ?></td>
                                <td><?= staffEscape($record['turf_name']) ?></td>
                                <td><?= staffEscape($record['description']) ?></td>
                                <td><?= staffEscape($record['start_date']) ?></td>
                                <td><?= $record['end_date'] ? staffEscape($record['end_date']) : '—' ?></td>
                                <td><span class="mt-status mt-status-<?= staffEscape($record['status']) ?>"><?= staffEscape($labels[$record['status']] ?? $record['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php staffLayoutEnd(); ?>
