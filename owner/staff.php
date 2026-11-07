<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

/** Owner staff management: all assignment mutations are scoped to this owner. */
$errors = [];
$facilitiesStmt = $pdo->prepare(
    "SELECT id, name
     FROM facilities
     WHERE owner_id = ?
       AND deleted_at IS NULL
       AND status = 'active'
       AND approval_status = 'approved'
     ORDER BY name"
);
$facilitiesStmt->execute([$ownerId]);
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    try {
        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $selected = array_unique(array_map('intval', (array) ($_POST['facilities'] ?? [])));

            if ($name === '' || mb_strlen($name) > 100) {
                $errors[] = 'Enter a name of at most 100 characters.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                $errors[] = 'Enter a valid email address.';
            }
            if (strlen($phone) > 20) {
                $errors[] = 'Phone number is too long.';
            }
            if (strlen($password) < 12) {
                $errors[] = 'Temporary password must contain at least 12 characters.';
            }
            if (!$selected || array_diff($selected, $facilityIds)) {
                $errors[] = 'Select at least one of your facilities.';
            }

            if (!$errors) {
                $pdo->beginTransaction();
                $create = $pdo->prepare(
                    "INSERT INTO users (name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, 'staff', 'active')"
                );
                $create->execute([
                    $name,
                    $email,
                    $phone !== '' ? $phone : null,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
                $newStaffId = (int) $pdo->lastInsertId();

                $assign = $pdo->prepare(
    "INSERT INTO staff_assignments (facility_id, staff_id, status)
     SELECT id, ?, 'active'
     FROM facilities
     WHERE id = ?
       AND owner_id = ?
       AND deleted_at IS NULL
       AND status = 'active'
       AND approval_status = 'approved'"
);
                foreach ($selected as $facilityId) {
                    $assign->execute([$newStaffId, $facilityId, $ownerId]);
                    if ($assign->rowCount() !== 1) {
                        throw new RuntimeException('Facility assignment failed.');
                    }
                }
                $pdo->commit();
                tm_flash('success', 'Staff account created. Share the temporary password privately.');
                tm_redirect('staff.php');
            }
        } elseif (in_array($action, ['save', 'deactivate', 'activate'], true)) {
            if ($staffId < 1) {
                throw new RuntimeException('Invalid staff account.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare(
                "SELECT u.id, u.status FROM users u
                 WHERE u.id = ? AND u.role = 'staff'
                   AND EXISTS (
                       SELECT 1 FROM staff_assignments sa
                       JOIN facilities f ON f.id = sa.facility_id
                       WHERE sa.staff_id = u.id AND f.owner_id = ?
                   ) FOR UPDATE"
            );
            $check->execute([$staffId, $ownerId]);
            $staff = $check->fetch(PDO::FETCH_ASSOC);
            if (!$staff) {
                throw new RuntimeException('Staff account not found for this owner.');
            }

            if ($action === 'save') {
                $selected = array_unique(array_map('intval', (array) ($_POST['facilities'] ?? [])));
                if (array_diff($selected, $facilityIds)) {
                    throw new RuntimeException('Invalid facility selection.');
                }
                // This interface manages only this owner's facility assignments.
                $disable = $pdo->prepare(
                    "UPDATE staff_assignments sa
                     JOIN facilities f ON f.id = sa.facility_id
                     SET sa.status = 'inactive'
                     WHERE sa.staff_id = ? AND f.owner_id = ?"
                );
                $disable->execute([$staffId, $ownerId]);
                $upsert = $pdo->prepare(
    "INSERT INTO staff_assignments (facility_id, staff_id, status)
     SELECT id, ?, 'active'
     FROM facilities
     WHERE id = ?
       AND owner_id = ?
       AND deleted_at IS NULL
       AND status = 'active'
       AND approval_status = 'approved'
     ON DUPLICATE KEY UPDATE status = 'active'"
);
                foreach ($selected as $facilityId) {
    $upsert->execute([
        $staffId,
        $facilityId,
        $ownerId
    ]);

    if ($upsert->rowCount() === 0) {
        throw new RuntimeException(
            'Facility assignment failed or facility is not approved.'
        );
    }
}
                tm_flash('success', 'Facility assignments updated.');
            } else {
                // Disable this owner's assignments only; do not disable a shared account.
                $status = $action === 'activate' ? 'active' : 'inactive';
                $update = $pdo->prepare(
    "UPDATE staff_assignments sa
     JOIN facilities f ON f.id = sa.facility_id
     SET sa.status = ?
     WHERE sa.staff_id = ?
       AND f.owner_id = ?
       AND (
           ? = 'inactive'
           OR (
               f.deleted_at IS NULL
               AND f.status = 'active'
               AND f.approval_status = 'approved'
           )
       )"
);

$update->execute([$status, $staffId, $ownerId, $status]);                $update->execute([$status, $staffId, $ownerId]);
                tm_flash('success', 'Staff access updated for your facilities.');
            }
            $pdo->commit();
            tm_redirect('staff.php');
        } else {
            $errors[] = 'Unknown action.';
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Staff management database error: ' . $exception->getMessage());
        $errors[] = $exception->getCode() === '23000'
            ? 'This email address is already registered.'
            : 'Could not save staff information. Please try again.';
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $exception->getMessage();
    }
}

$list = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.phone, u.status AS account_status,
            GROUP_CONCAT(CONCAT(f.name, ' [', sa.status, ']')
                         ORDER BY f.name SEPARATOR ', ') AS assignments,
            GROUP_CONCAT(CASE WHEN sa.status = 'active' THEN f.id END) AS active_facility_ids,
            SUM(sa.status = 'active') AS active_count
     FROM users u
     JOIN staff_assignments sa ON sa.staff_id = u.id
     JOIN facilities f ON f.id = sa.facility_id
     WHERE u.role = 'staff' AND f.owner_id = ?
     GROUP BY u.id, u.name, u.email, u.phone, u.status
     ORDER BY u.name"
);
$list->execute([$ownerId]);
$staffMembers = $list->fetchAll(PDO::FETCH_ASSOC);

tm_page_header('Staff Management', 'staff', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-staff.css?v=1">
<section class="staff-intro">
    <div>
        <h2>Manage your team</h2>
        <p>Create employee accounts and choose which facilities they can access.</p>
    </div>
    <span class="staff-counter"><?= count($staffMembers) ?> staff accounts</span>
</section>

<?php foreach ($errors as $error): ?>
    <div class="staff-error" role="alert"><?= tm_e($error) ?></div>
<?php endforeach; ?>

<section class="staff-panel">
    <h3>Add Staff Member</h3>
    <?php if (!$facilities): ?>
           <?php else: ?>
        <form method="post" class="staff-create-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Full name <input name="name" maxlength="100" required></label>
            <label>Email <input name="email" type="email" maxlength="150" required></label>
            <label>Phone (optional) <input name="phone" maxlength="20"></label>
            <label>Temporary password <input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
            <fieldset class="staff-facility-options">
                <legend>Assign facilities</legend>
                <?php foreach ($facilities as $facility): ?>
                    <label>
                        <input type="checkbox" name="facilities[]" value="<?= (int) $facility['id'] ?>">
                        <?= tm_e($facility['name']) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button type="submit" class="staff-primary">Create Staff Account</button>
        </form>
    <?php endif; ?>
</section>

<section class="staff-panel">
    <h3>Team Members</h3>
    <?php if (!$staffMembers): ?>
        <p>No staff accounts assigned to your facilities yet.</p>
    <?php else: ?>
        <div class="staff-table-wrap">
            <table class="staff-table">
                <thead><tr><th>Employee</th><th>Assigned facilities</th><th>Access</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($staffMembers as $member): ?>
                    <?php $activeIds = array_filter(array_map('intval', explode(',', (string) ($member['active_facility_ids'] ?? '')))); ?>
                    <tr>
                        <td>
                            <strong><?= tm_e($member['name']) ?></strong><br>
                            <small><?= tm_e($member['email']) ?></small>
                        </td>
                        <td><?= tm_e($member['assignments']) ?></td>
                        <td><?= (int) $member['active_count'] > 0 ? 'Assigned' : 'Inactive' ?></td>
                        <td>
                            <details class="staff-edit">
                                <summary>Edit assignments</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                                    <input type="hidden" name="action" value="save">
                                    <input type="hidden" name="staff_id" value="<?= (int) $member['id'] ?>">
                                    <?php foreach ($facilities as $facility): ?>
                                        <label>
                                            <input type="checkbox" name="facilities[]"
                                                value="<?= (int) $facility['id'] ?>"
                                                <?= in_array((int) $facility['id'], $activeIds, true) ? 'checked' : '' ?>>
                                            <?= tm_e($facility['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <button class="staff-primary" type="submit">Save Assignments</button>
                                </form>
                            </details>
                            <form method="post" class="staff-inline-form">
                                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                                <input type="hidden" name="staff_id" value="<?= (int) $member['id'] ?>">
                                <?php if ((int) $member['active_count'] > 0): ?>
                                    <input type="hidden" name="action" value="deactivate">
                                    <button class="staff-danger" type="submit">Disable access</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="activate">
                                    <button class="staff-secondary" type="submit">Enable access</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php tm_page_footer(); ?>
