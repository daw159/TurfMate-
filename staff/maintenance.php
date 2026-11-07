<?php
declare(strict_types=1);

/** Staff maintenance management: no cost visibility or cost editing. */
require_once __DIR__ . '/_init.php';

function mtId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function mtDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function mtCanEdit(PDO $db, int $staffId, int $recordId): bool
{
    $stmt = $db->prepare(
        'SELECT f.id FROM maintenance_records m
         JOIN turfs t ON t.id = m.turf_id
         JOIN facilities f ON f.id = t.facility_id
         WHERE m.id = ? AND t.deleted_at IS NULL AND f.deleted_at IS NULL'
    );
    $stmt->execute([$recordId]);
    $facilityId = (int) $stmt->fetchColumn();
    return $facilityId > 0 && staffAllowed($db, $staffId, $facilityId);
}

$facilities = staffAssignments($db, $staffId);
$facilityIds = array_map('intval', array_column($facilities, 'id'));
$turfsStmt = $db->prepare(
    'SELECT t.id, t.name, f.name AS facility_name, f.id AS facility_id
     FROM turfs t JOIN facilities f ON f.id = t.facility_id
     JOIN staff_assignments sa ON sa.facility_id = f.id
     JOIN users owner ON owner.id = f.owner_id
     WHERE sa.staff_id = ? AND sa.status = \'active\'
       AND f.status = \'active\' AND f.approval_status = \'approved\'
       AND f.deleted_at IS NULL AND t.deleted_at IS NULL
       AND owner.status = \'active\'
     ORDER BY f.name, t.name'
);
$turfsStmt->execute([$staffId]);
$turfs = $turfsStmt->fetchAll(PDO::FETCH_ASSOC);
$turfIds = array_map('intval', array_column($turfs, 'id'));

$labels = ['planned' => 'Planned', 'in_progress' => 'In Progress', 'completed' => 'Completed'];
$errors = [];
$notice = $_SESSION['mt_notice'] ?? null;
unset($_SESSION['mt_notice']);
$form = [
    'id' => 0, 'turf_id' => $turfIds[0] ?? 0, 'description' => '',
    'start_date' => date('Y-m-d'), 'end_date' => '', 'status' => 'planned',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(staffCsrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid security token. Refresh and try again.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = mtId($_POST['id'] ?? null);

    if ($action === 'delete') {
        if (!$id || !mtCanEdit($db, $staffId, $id)) {
            $errors[] = 'You cannot delete this maintenance record.';
        } else {
            $stmt = $db->prepare('DELETE FROM maintenance_records WHERE id = ?');
            $stmt->execute([$id]);
            $_SESSION['mt_notice'] = 'Maintenance record deleted.';
            header('Location: maintenance.php');
            exit;
        }
    } elseif ($action === 'save') {
        $form = [
            'id' => $id,
            'turf_id' => mtId($_POST['turf_id'] ?? null),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')),
            'status' => (string) ($_POST['status'] ?? ''),
        ];
        if (!in_array($form['turf_id'], $turfIds, true)) $errors[] = 'Choose an assigned turf.';
        if ($form['description'] === '' || mb_strlen($form['description']) > 255) $errors[] = 'Description must be 1–255 characters.';
        if (!mtDate($form['start_date'])) $errors[] = 'Enter a valid start date.';
        if ($form['end_date'] !== '' && !mtDate($form['end_date'])) $errors[] = 'Enter a valid end date.';
        if ($form['end_date'] !== '' && $form['end_date'] < $form['start_date']) $errors[] = 'End date cannot precede start date.';
        if (!isset($labels[$form['status']])) $errors[] = 'Choose a valid status.';
        if ($id && !mtCanEdit($db, $staffId, $id)) $errors[] = 'You cannot edit this record.';

        if (!$errors) {
            try {
                if ($id) {
                    // Do not change the existing cost; it is Owner-only.
                    $stmt = $db->prepare(
                        'UPDATE maintenance_records
                         SET turf_id = ?, description = ?, start_date = ?, end_date = ?, status = ?
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $form['turf_id'], $form['description'], $form['start_date'],
                        $form['end_date'] ?: null, $form['status'], $id,
                    ]);
                } else {
                    // Owner can later record the actual cost; default is zero.
                    $stmt = $db->prepare(
                        'INSERT INTO maintenance_records
                         (turf_id, created_by, description, cost, start_date, end_date, status)
                         VALUES (?, ?, ?, 0, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $form['turf_id'], $staffId, $form['description'],
                        $form['start_date'], $form['end_date'] ?: null, $form['status'],
                    ]);
                }
                $_SESSION['mt_notice'] = $id ? 'Maintenance updated.' : 'Maintenance created.';
                header('Location: maintenance.php');
                exit;
            } catch (Throwable $e) {
                error_log('Staff maintenance: ' . $e->getMessage());
                $errors[] = 'Could not save maintenance. Check the server error log.';
            }
        }
    } else {
        $errors[] = 'Invalid action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = mtId($_GET['edit']);
    if (!$id || !mtCanEdit($db, $staffId, $id)) {
        http_response_code(403);
        exit('You cannot edit this record.');
    }
    $stmt = $db->prepare(
        'SELECT id, turf_id, description, start_date, end_date, status
         FROM maintenance_records WHERE id = ?'
    );
    $stmt->execute([$id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
}

$facilityId = mtId($_GET['facility_id'] ?? null);
if ($facilityId && !in_array($facilityId, $facilityIds, true)) {
    http_response_code(403);
    exit('Facility not assigned.');
}
$status = (string) ($_GET['status'] ?? 'all');
if ($status !== 'all' && !isset($labels[$status])) $status = 'all';

$sql = <<<'SQL'
    SELECT m.id, m.description, m.start_date, m.end_date, m.status,
           t.name AS turf_name, f.name AS facility_name
    FROM maintenance_records m
    JOIN turfs t ON t.id = m.turf_id
    JOIN facilities f ON f.id = t.facility_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    JOIN users owner ON owner.id = f.owner_id
    WHERE sa.staff_id = :staff_id AND sa.status = 'active'
      AND f.status = 'active' AND f.approval_status = 'approved'
      AND f.deleted_at IS NULL AND t.deleted_at IS NULL
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
$sql .= ' ORDER BY m.start_date DESC, m.id DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$showForm = isset($_GET['new']) || isset($_GET['edit']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save');

staffLayoutStart('Maintenance', 'maintenance');
?>
<section class="mt-wrap">
    <div class="mt-heading">
        <div><h1>Maintenance Management</h1><p>Manage repairs for your assigned facilities. Costs are Owner-only.</p></div>
        <a class="mt-action mt-primary" href="maintenance.php?new=1">+ Add Maintenance</a>
    </div>

    <?php if ($notice): ?><div class="mt-message"><?= staffEscape($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="mt-error"><strong>Please fix:</strong><ul>
            <?php foreach ($errors as $error): ?><li><?= staffEscape($error) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <div class="mt-panel mt-editor">
            <div class="mt-panel-header"><h2><?= $form['id'] ? 'Edit Maintenance' : 'Add Maintenance' ?></h2><a href="maintenance.php">Close</a></div>
            <form method="post" action="maintenance.php" class="mt-editor-form">
                <input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
                <label>Turf *<select name="turf_id" required>
                    <option value="">Select turf</option>
                    <?php foreach ($turfs as $turf): ?>
                        <option value="<?= (int) $turf['id'] ?>" <?= (int) $form['turf_id'] === (int) $turf['id'] ? 'selected' : '' ?>><?= staffEscape($turf['facility_name'] . ' / ' . $turf['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Description *<textarea name="description" maxlength="255" required><?= staffEscape($form['description']) ?></textarea></label>
                <label>Start Date *<input type="date" name="start_date" required value="<?= staffEscape($form['start_date']) ?>"></label>
                <label>End Date<input type="date" name="end_date" value="<?= staffEscape($form['end_date']) ?>"></label>
                <label>Status<select name="status">
                    <?php foreach ($labels as $key => $label): ?>
                        <option value="<?= staffEscape($key) ?>" <?= $form['status'] === $key ? 'selected' : '' ?>><?= staffEscape($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="mt-action mt-primary" type="submit">Save Maintenance</button>
            </form>
        </div>
    <?php endif; ?>

    <form method="get" class="mt-filters">
        <label>Facility<select name="facility_id"><option value="0">All assigned facilities</option>
            <?php foreach ($facilities as $facility): ?>
                <option value="<?= (int) $facility['id'] ?>" <?= $facilityId === (int) $facility['id'] ? 'selected' : '' ?>><?= staffEscape($facility['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Status<select name="status"><option value="all">All statuses</option>
            <?php foreach ($labels as $key => $label): ?>
                <option value="<?= staffEscape($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= staffEscape($label) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button type="submit">Filter</button>
    </form>

    <div class="mt-panel">
        <div class="mt-panel-header"><h2>Maintenance Records</h2><span><?= count($records) ?> records</span></div>
        <?php if (!$records): ?><p class="mt-empty">No maintenance records found.</p><?php else: ?>
            <div class="mt-table-scroll"><table class="mt-table"><thead><tr>
                <th>Facility</th><th>Turf</th><th>Description</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th>
            </tr></thead><tbody>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= staffEscape($record['facility_name']) ?></td>
                    <td><?= staffEscape($record['turf_name']) ?></td>
                    <td><?= staffEscape($record['description']) ?></td>
                    <td><?= staffEscape($record['start_date']) ?></td>
                    <td><?= $record['end_date'] ? staffEscape($record['end_date']) : '—' ?></td>
                    <td><span class="mt-status mt-status-<?= staffEscape($record['status']) ?>"><?= staffEscape($labels[$record['status']] ?? $record['status']) ?></span></td>
                    <td class="mt-actions">
                        <a class="mt-action" href="maintenance.php?edit=<?= (int) $record['id'] ?>">Edit</a>
                        <form method="post" action="maintenance.php" onsubmit="return confirm('Delete this maintenance record?');">
                            <input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                            <button class="mt-action mt-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</section>
<?php staffLayoutEnd(); ?>
