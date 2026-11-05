<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

$errors = [];
$editing = null;
$form = ['id' => 0, 'name' => '', 'description' => '', 'address' => '', 'city' => '', 'phone' => '', 'status' => 'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    // SOFT DELETE AND RESTORE FACILITY
if (in_array($action, ['remove', 'restore'], true) && $id > 0) {

    $stmt = $pdo->prepare(
        "UPDATE facilities
         SET deleted_at = ?
         WHERE id = ? AND owner_id = ?
         AND " . ($action === 'remove'
             ? "deleted_at IS NULL"
             : "deleted_at IS NOT NULL")
    );

    $deletedAt = $action === 'remove'
        ? date('Y-m-d H:i:s')
        : null;

    $stmt->execute([$deletedAt, $id, $ownerId]);

    tm_flash(
        $stmt->rowCount() ? 'success' : 'error',
        $stmt->rowCount()
            ? ($action === 'remove'
                ? 'Facility removed successfully.'
                : 'Facility restored successfully.')
            : 'Facility not found or already in the requested state.'
    );

    tm_redirect('facilities.php');
}
    if ($action === 'toggle' && $id > 0) {
        $stmt = $pdo->prepare(
    "UPDATE facilities
     SET status = IF(status = 'active', 'inactive', 'active')
     WHERE id = ? AND owner_id = ?
     AND deleted_at IS NULL"
);
        $stmt->execute([$id, $ownerId]);
        tm_flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Facility status updated.' : 'Facility not found or not yours.');
        tm_redirect('facilities.php');
    }
    if ($action === 'save') {
        $form = [
            'id' => $id,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
        if ($form['name'] === '' || mb_strlen($form['name']) > 150) $errors[] = 'Facility name is required (maximum 150 characters).';
        if ($form['address'] === '' || mb_strlen($form['address']) > 255) $errors[] = 'Address is required (maximum 255 characters).';
        if (mb_strlen($form['city']) > 100) $errors[] = 'City is too long.';
        if (mb_strlen($form['phone']) > 30) $errors[] = 'Phone number is too long.';
        if (!in_array($form['status'], ['active', 'inactive'], true)) $errors[] = 'Invalid facility status.';
        if (!$errors) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE facilities SET name=?, description=?, address=?, city=?, phone=?, status=? WHERE id=? AND owner_id=?');
                    $stmt->execute([$form['name'], $form['description'] ?: null, $form['address'], $form['city'] ?: null, $form['phone'] ?: null, $form['status'], $id, $ownerId]);
                    // rowCount can be 0 when no values changed; check ownership separately.
                    $check = $pdo->prepare('SELECT id FROM facilities WHERE id=? AND owner_id=?');
                    $check->execute([$id, $ownerId]);
                    if (!$check->fetchColumn()) throw new RuntimeException('Facility not found or not yours.');
                    tm_flash('success', 'Facility updated successfully.');
                } else {
                    $stmt = $pdo->prepare('INSERT INTO facilities (owner_id, name, description, address, city, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$ownerId, $form['name'], $form['description'] ?: null, $form['address'], $form['city'] ?: null, $form['phone'] ?: null, $form['status']]);
                    tm_flash('success', 'Facility created successfully.');
                }
                tm_redirect('facilities.php');
            } catch (PDOException $e) {
                error_log('TurfMate facility save: ' . $e->getMessage());
                $errors[] = 'Could not save facility. Check your database and try again.';
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Invalid form action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $stmt = $pdo->prepare('SELECT * FROM facilities WHERE id=? AND owner_id=?');
    $stmt->execute([$id, $ownerId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) {
        tm_flash('error', 'Facility not found or not yours.');
        tm_redirect('facilities.php');
    }
    $form = $editing;
}

// Show normal or removed facilities
$view = ($_GET['view'] ?? '') === 'removed' ? 'removed' : 'active';

$condition = $view === 'removed'
    ? 'f.deleted_at IS NOT NULL'
    : 'f.deleted_at IS NULL';

$listQuery = $pdo->prepare(
    "SELECT f.*,
        (SELECT COUNT(*) FROM turfs t
         WHERE t.facility_id = f.id
         AND t.deleted_at IS NULL) AS turf_count
     FROM facilities f
     WHERE f.owner_id = ?
     AND $condition
     ORDER BY f.id DESC"
);

$listQuery->execute([$ownerId]);
$facilities = $listQuery->fetchAll(PDO::FETCH_ASSOC);
$showForm = isset($_GET['new']) || isset($_GET['edit']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') === 'save');
tm_page_header('Facilities', 'facilities', $owner);
?>
<div class="intro-row"><div><h2>Manage your facilities</h2><p>Add locations, update contact details, and control availability.</p></div><a class="btn btn-primary" href="facilities.php?new=1">+ Add Facility</a></div>
<?php if ($errors): ?><div class="alert alert-error" role="alert"><strong>Please fix:</strong><ul><?php foreach ($errors as $error): ?><li><?= tm_e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($showForm): ?>
<section class="panel form-panel"><div class="panel-heading"><h2><?= (int) ($form['id'] ?? 0) > 0 ? 'Edit facility' : 'Add a facility' ?></h2><a class="text-link" href="facilities.php">Close</a></div>
<form method="post" action="facilities.php" class="form-grid">
    <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">
    <label>Facility name <span>*</span><input name="name" maxlength="150" required value="<?= tm_e($form['name']) ?>" placeholder="e.g. Greenfield Sports Arena"></label>
    <label>City<input name="city" maxlength="100" value="<?= tm_e($form['city']) ?>" placeholder="e.g. Bahawalnagar"></label>
    <label class="full">Address <span>*</span><input name="address" maxlength="255" required value="<?= tm_e($form['address']) ?>" placeholder="Street, area, landmark"></label>
    <label>Contact phone<input name="phone" maxlength="30" value="<?= tm_e($form['phone']) ?>" placeholder="03xx-xxxxxxx"></label>
    <label>Status<select name="status"><option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
    <label class="full">Description<textarea name="description" rows="3" placeholder="Optional details about this facility"><?= tm_e($form['description']) ?></textarea></label>
    <div class="full form-actions"><button class="btn btn-primary" type="submit">Save Facility</button><a class="btn btn-secondary" href="facilities.php">Cancel</a></div>
</form></section>
<?php endif; ?>
<!-- Facility Filters -->
<div class="form-actions" style="margin-bottom: 20px;">

    <a href="facilities.php"
       class="btn <?= $view === 'active' ? 'btn-primary' : 'btn-secondary' ?>">
        All Facilities
    </a>

    <a href="facilities.php?view=removed"
       class="btn <?= $view === 'removed' ? 'btn-primary' : 'btn-secondary' ?>">
        Removed Facilities
    </a>

</div>
<section class="panel"><div class="panel-heading"><div><h2>All facilities <span class="count-tag"><?= count($facilities) ?></span></h2><p>Only facilities owned by your account appear here.</p></div></div>
<?php if (!$facilities): ?><div class="empty-state"><div class="empty-symbol">▤</div><strong>No facilities yet</strong><p>Create your first facility to start adding turfs.</p><a class="btn btn-primary" href="facilities.php?new=1">+ Add Facility</a></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>Facility</th><th>Location</th><th>Turfs</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($facilities as $facility): ?>
<tr>

    <!-- Facility Name -->
    <td>
        <strong><?= tm_e($facility['name']) ?></strong>
        <span class="subline">
            <?= tm_e($facility['phone'] ?: 'No phone') ?>
        </span>
    </td>

    <!-- Location -->
    <td>
        <?= tm_e($facility['address']) ?>
        <span class="subline">
            <?= tm_e($facility['city'] ?: '—') ?>
        </span>
    </td>

    <!-- Turf Count -->
    <td>
        <?= (int) $facility['turf_count'] ?>
    </td>

    <!-- Status -->
    <td>
        <?php if ($view === 'removed'): ?>
            <span class="pill status-inactive">Removed</span>
        <?php else: ?>
            <span class="pill status-<?= tm_e($facility['status']) ?>">
                <?= tm_e(ucfirst($facility['status'])) ?>
            </span>
        <?php endif; ?>
    </td>

    <!-- Actions -->
    <td>
        <div class="table-actions">

            <?php if ($view === 'removed'): ?>

                <!-- Restore Button -->
                <form method="post" action="facilities.php"
                      onsubmit="return confirm('Restore this facility?');">

                    <input type="hidden" name="csrf"
                           value="<?= tm_e(tm_csrf()) ?>">

                    <input type="hidden" name="action" value="restore">

                    <input type="hidden" name="id"
                           value="<?= (int) $facility['id'] ?>">

                    <button type="submit" class="btn btn-small btn-primary">
                        Restore
                    </button>

                </form>

            <?php else: ?>

                <!-- Edit Button -->
                <a class="btn btn-small btn-secondary"
                   href="facilities.php?edit=<?= (int) $facility['id'] ?>">
                    Edit
                </a>

                <!-- Activate / Deactivate Button -->
                <form method="post" action="facilities.php">

                    <input type="hidden" name="csrf"
                           value="<?= tm_e(tm_csrf()) ?>">

                    <input type="hidden" name="action" value="toggle">

                    <input type="hidden" name="id"
                           value="<?= (int) $facility['id'] ?>">

                    <button type="submit" class="btn btn-small btn-outline">
                        <?= $facility['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                    </button>

                </form>

                <!-- Remove Button -->
                <form method="post" action="facilities.php"
                      onsubmit="return confirm('Remove this facility? You can restore it later.');">

                    <input type="hidden" name="csrf"
                           value="<?= tm_e(tm_csrf()) ?>">

                    <input type="hidden" name="action" value="remove">

                    <input type="hidden" name="id"
                           value="<?= (int) $facility['id'] ?>">

                    <button type="submit" class="btn btn-small btn-outline"
                            style="color: #b42318; border-color: #f5c2c0;">
                        Remove
                    </button>

                </form>

            <?php endif; ?>

        </div>
    </td>

</tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php tm_page_footer(); ?>
