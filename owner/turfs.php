<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

$facilitiesStmt = $pdo->prepare('SELECT id, name, status FROM facilities WHERE owner_id = ? ORDER BY name');
$facilitiesStmt->execute([$ownerId]);
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map(static fn($f) => (int) $f['id'], $facilities);
$errors = [];
$form = ['id' => 0, 'facility_id' => $facilityIds[0] ?? 0, 'name' => '', 'sport_type' => 'cricket', 'hourly_price' => '0.00', 'capacity' => '', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($action === 'toggle' && $id > 0) {
        $stmt = $pdo->prepare("UPDATE turfs t INNER JOIN facilities f ON f.id = t.facility_id SET t.status = IF(t.status = 'active', 'inactive', 'active') WHERE t.id = ? AND f.owner_id = ?");
        $stmt->execute([$id, $ownerId]);
        tm_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Turf status updated.' : 'Turf not found or not yours.');
        tm_redirect('turfs.php');
    }
    if ($action === 'save') {
        $form = [
            'id' => $id,
            'facility_id' => (int) ($_POST['facility_id'] ?? 0),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'sport_type' => (string) ($_POST['sport_type'] ?? ''),
            'hourly_price' => trim((string) ($_POST['hourly_price'] ?? '')),
            'capacity' => trim((string) ($_POST['capacity'] ?? '')),
            'status' => (string) ($_POST['status'] ?? ''),
        ];
        if (!in_array($form['facility_id'], $facilityIds, true)) $errors[] = 'Choose one of your own facilities.';
        if ($form['name'] === '' || mb_strlen($form['name']) > 150) $errors[] = 'Turf name is required (maximum 150 characters).';
        if (!in_array($form['sport_type'], ['cricket', 'football', 'padel', 'other'], true)) $errors[] = 'Choose a valid sport.';
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $form['hourly_price']) || (float) $form['hourly_price'] > 99999999.99) $errors[] = 'Enter a valid hourly price (0 to 99,999,999.99).';
        if ($form['capacity'] !== '' && (!ctype_digit($form['capacity']) || (int) $form['capacity'] < 1 || (int) $form['capacity'] > 65535)) $errors[] = 'Capacity must be a whole number from 1 to 65535.';
        if (!in_array($form['status'], ['active', 'inactive', 'maintenance'], true)) $errors[] = 'Invalid turf status.';
        if (!$errors) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE turfs t INNER JOIN facilities f ON f.id = t.facility_id SET t.facility_id=?, t.name=?, t.sport_type=?, t.hourly_price=?, t.capacity=?, t.status=? WHERE t.id=? AND f.owner_id=?');
                    $stmt->execute([$form['facility_id'], $form['name'], $form['sport_type'], $form['hourly_price'], $form['capacity'] === '' ? null : (int) $form['capacity'], $form['status'], $id, $ownerId]);
                    $check = $pdo->prepare('SELECT t.id FROM turfs t INNER JOIN facilities f ON f.id=t.facility_id WHERE t.id=? AND f.owner_id=?');
                    $check->execute([$id, $ownerId]);
                    if (!$check->fetchColumn()) throw new RuntimeException('Turf not found or not yours.');
                    tm_flash('success', 'Turf updated successfully.');
                } else {
                    $stmt = $pdo->prepare('INSERT INTO turfs (facility_id, name, sport_type, hourly_price, capacity, status) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$form['facility_id'], $form['name'], $form['sport_type'], $form['hourly_price'], $form['capacity'] === '' ? null : (int) $form['capacity'], $form['status']]);
                    tm_flash('success', 'Turf created successfully.');
                }
                tm_redirect('turfs.php');
            } catch (PDOException $e) {
                error_log('TurfMate turf save: ' . $e->getMessage());
                $errors[] = 'Could not save turf. Check your database and try again.';
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Invalid form action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $stmt = $pdo->prepare('SELECT t.* FROM turfs t INNER JOIN facilities f ON f.id = t.facility_id WHERE t.id=? AND f.owner_id=?');
    $stmt->execute([$id, $ownerId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        tm_flash('error', 'Turf not found or not yours.');
        tm_redirect('turfs.php');
    }
    $form = $existing;
}

$listStmt = $pdo->prepare('SELECT t.*, f.name AS facility_name FROM turfs t INNER JOIN facilities f ON f.id=t.facility_id WHERE f.owner_id=? ORDER BY t.id DESC');
$listStmt->execute([$ownerId]);
$turfs = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$showForm = isset($_GET['new']) || isset($_GET['edit']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') === 'save');
tm_page_header('Turfs & Pricing', 'turfs', $owner);
?>
<div class="intro-row"><div><h2>Your sports turfs</h2><p>Assign turfs to facilities and set their base hourly prices.</p></div><?php if ($facilities): ?><a class="btn btn-primary" href="turfs.php?new=1">+ Add Turf</a><?php endif; ?></div>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><strong>Please fix:</strong><ul><?php foreach ($errors as $error): ?><li><?= tm_e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$facilities): ?><section class="panel"><div class="empty-state"><div class="empty-symbol">▤</div><strong>Add a facility first</strong><p>Every turf must belong to one of your facilities.</p><a class="btn btn-primary" href="facilities.php?new=1">+ Add Facility</a></div></section><?php endif; ?>
<?php if ($showForm && $facilities): ?>
<section class="panel form-panel"><div class="panel-heading"><h2><?= (int) ($form['id'] ?? 0) > 0 ? 'Edit turf' : 'Add a turf' ?></h2><a class="text-link" href="turfs.php">Close</a></div>
<form method="post" action="turfs.php" class="form-grid">
    <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">
    <label>Facility <span>*</span><select name="facility_id" required><?php foreach ($facilities as $facility): ?><option value="<?= (int) $facility['id'] ?>" <?= (int) $form['facility_id'] === (int) $facility['id'] ? 'selected' : '' ?>><?= tm_e($facility['name']) ?></option><?php endforeach; ?></select></label>
    <label>Turf name <span>*</span><input name="name" maxlength="150" required value="<?= tm_e($form['name']) ?>" placeholder="e.g. Cricket Ground A"></label>
    <label>Sport <span>*</span><select name="sport_type"><?php foreach (['cricket' => 'Cricket', 'football' => 'Football', 'padel' => 'Padel', 'other' => 'Other'] as $key => $label): ?><option value="<?= $key ?>" <?= $form['sport_type'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label>Hourly price (PKR) <span>*</span><input name="hourly_price" type="number" step="0.01" min="0" max="99999999.99" required value="<?= tm_e((string) $form['hourly_price']) ?>"></label>
    <label>Player capacity<input name="capacity" type="number" min="1" max="65535" value="<?= tm_e((string) $form['capacity']) ?>" placeholder="Optional"></label>
    <label>Status<select name="status"><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'maintenance' => 'Maintenance'] as $key => $label): ?><option value="<?= $key ?>" <?= $form['status'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <div class="full form-actions"><button class="btn btn-primary" type="submit">Save Turf</button><a class="btn btn-secondary" href="turfs.php">Cancel</a></div>
</form></section>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>All turfs <span class="count-tag"><?= count($turfs) ?></span></h2><p>Base prices can be updated at any time.</p></div></div>
<?php if (!$turfs): ?><div class="empty-state"><div class="empty-symbol">◫</div><strong>No turfs yet</strong><p>Add a turf after creating your first facility.</p></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Turf</th><th>Facility</th><th>Sport</th><th>Price / Hour</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($turfs as $turf): ?><tr><td><strong><?= tm_e($turf['name']) ?></strong><span class="subline"><?= $turf['capacity'] !== null ? (int) $turf['capacity'] . ' players' : 'Capacity not set' ?></span></td><td><?= tm_e($turf['facility_name']) ?></td><td><?= tm_e(ucfirst($turf['sport_type'])) ?></td><td><?= tm_money($turf['hourly_price']) ?></td><td><span class="pill status-<?= tm_e($turf['status']) ?>"><?= tm_e(ucfirst($turf['status'])) ?></span></td><td><div class="table-actions"><a class="btn btn-small btn-secondary" href="turfs.php?edit=<?= (int) $turf['id'] ?>">Edit</a><form method="post" action="turfs.php"><input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $turf['id'] ?>"><button class="btn btn-small btn-outline" type="submit"><?= $turf['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php tm_page_footer(); ?>
