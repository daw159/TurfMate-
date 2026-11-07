<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$errors = [];
$success = isset($_GET['saved']) ? 'Inventory updated successfully.' : '';
$facilities = staffAssignments($db, $staffId);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals(staffCsrf(), (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Security token expired. Refresh and try again.';
    } elseif ($action === 'create') {
        $facilityId = filter_var($_POST['facility_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? 'pcs'));
        $reorder = filter_var($_POST['reorder_level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000000]]);
        if (!$facilityId || !in_array((int) $facilityId, $facilityIds, true) || !staffAllowed($db, $staffId, (int) $facilityId)) $errors[] = 'Choose an approved assigned facility.';
        if ($name === '' || mb_strlen($name) > 150) $errors[] = 'Item name is required (up to 150 characters).';
        if (mb_strlen($category) > 100 || $unit === '' || mb_strlen($unit) > 30) $errors[] = 'Invalid category or unit.';
        if ($reorder === false) $errors[] = 'Invalid reorder level.';
        if (!$errors) {
            $stmt = $db->prepare("INSERT INTO inventory_items (facility_id, name, category, quantity, unit, reorder_level, cost_per_unit, status) VALUES (?, ?, ?, 0, ?, ?, NULL, 'active')");
            $stmt->execute([$facilityId, $name, $category ?: null, $unit, $reorder]);
            header('Location: inventory.php?saved=1'); exit;
        }
    } elseif ($action === 'edit') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $reorder = filter_var($_POST['reorder_level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000000]]);
        if (!$itemId || $name === '' || mb_strlen($name) > 150 || mb_strlen($category) > 100 || $unit === '' || mb_strlen($unit) > 30 || $reorder === false) $errors[] = 'Invalid item details.';
        if (!$errors) {
            $stmt = $db->prepare(<<<'SQL'
                UPDATE inventory_items i
                JOIN facilities f ON f.id = i.facility_id
                JOIN staff_assignments sa ON sa.facility_id = f.id
                JOIN users owner ON owner.id = f.owner_id
                SET i.name = ?, i.category = ?, i.unit = ?, i.reorder_level = ?
                WHERE i.id = ? AND sa.staff_id = ? AND sa.status = 'active'
                  AND f.deleted_at IS NULL AND f.status = 'active'
                  AND f.approval_status = 'approved' AND owner.status = 'active'
            SQL);
            $stmt->execute([$name, $category ?: null, $unit, $reorder, $itemId, $staffId]);
            header('Location: inventory.php?saved=1'); exit;
        }
    } elseif ($action === 'stock') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $type = (string) ($_POST['transaction_type'] ?? '');
        $direction = (string) ($_POST['direction'] ?? 'add');
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000000]]);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (!$itemId || !in_array($type, ['purchase', 'usage', 'adjustment'], true) || $quantity === false || !in_array($direction, ['add', 'remove'], true) || mb_strlen($notes) > 255) {
            $errors[] = 'Invalid stock transaction.';
        } else {
            $change = $type === 'usage' || ($type === 'adjustment' && $direction === 'remove') ? -(int) $quantity : (int) $quantity;
            try {
                $db->beginTransaction();
                $lock = $db->prepare(<<<'SQL'
                    SELECT i.quantity FROM inventory_items i
                    JOIN facilities f ON f.id = i.facility_id
                    JOIN staff_assignments sa ON sa.facility_id = f.id
                    JOIN users owner ON owner.id = f.owner_id
                    WHERE i.id = ? AND i.status = 'active'
                      AND sa.staff_id = ? AND sa.status = 'active'
                      AND f.deleted_at IS NULL AND f.status = 'active'
                      AND f.approval_status = 'approved' AND owner.status = 'active'
                    FOR UPDATE
                SQL);
                $lock->execute([$itemId, $staffId]);
                $current = $lock->fetchColumn();
                if ($current === false) throw new RuntimeException('Item is unavailable or not assigned to you.');
                $newQuantity = (int) $current + $change;
                if ($newQuantity < 0 || $newQuantity > 2147483647) throw new RuntimeException('Insufficient stock or quantity limit exceeded.');
                $update = $db->prepare('UPDATE inventory_items SET quantity = ? WHERE id = ?');
                $update->execute([$newQuantity, $itemId]);
                $insert = $db->prepare('INSERT INTO inventory_transactions (item_id, created_by, transaction_type, quantity_change, notes) VALUES (?, ?, ?, ?, ?)');
                $insert->execute([$itemId, $staffId, $type, $change, $notes ?: null]);
                $db->commit();
                header('Location: inventory.php?saved=1'); exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Stock update failed. Check server logs.';
                if (!$e instanceof RuntimeException) error_log('Staff inventory: ' . $e->getMessage());
            }
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$itemsStmt = $db->prepare(<<<'SQL'
    SELECT i.id, i.facility_id, i.name, i.category, i.quantity, i.unit,
           i.reorder_level, i.status, f.name AS facility_name
    FROM inventory_items i
    JOIN facilities f ON f.id = i.facility_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    JOIN users owner ON owner.id = f.owner_id
    WHERE sa.staff_id = ? AND sa.status = 'active'
      AND f.deleted_at IS NULL AND f.status = 'active'
      AND f.approval_status = 'approved' AND owner.status = 'active'
    ORDER BY f.name, i.name
SQL);
$itemsStmt->execute([$staffId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
$historyStmt = $db->prepare(<<<'SQL'
    SELECT x.created_at, x.transaction_type, x.quantity_change, x.notes,
           i.name AS item_name, f.name AS facility_name, u.name AS recorded_by
    FROM inventory_transactions x
    JOIN inventory_items i ON i.id = x.item_id
    JOIN facilities f ON f.id = i.facility_id
    JOIN staff_assignments sa ON sa.facility_id = f.id
    JOIN users owner ON owner.id = f.owner_id
    JOIN users u ON u.id = x.created_by
    WHERE sa.staff_id = ? AND sa.status = 'active'
      AND f.deleted_at IS NULL AND f.status = 'active'
      AND f.approval_status = 'approved' AND owner.status = 'active'
    ORDER BY x.created_at DESC, x.id DESC LIMIT 100
SQL);
$historyStmt->execute([$staffId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
staffLayoutStart('Inventory Management', 'inventory');
?>
<section class="heading"><div><p class="eyebrow">STAFF OPERATIONS</p><h1>Inventory Management</h1><p>Manage stock for approved facilities assigned to you. Costs remain owner-only.</p></div></section>
<?php foreach ($errors as $error): ?><div class="alert"><?= staffEscape($error) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="success"><?= staffEscape($success) ?></div><?php endif; ?>
<section class="card"><h2>Add inventory item</h2><form method="post" class="staff-form">
<input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>"><input type="hidden" name="action" value="create">
<label>Facility<select name="facility_id" required><?php foreach ($facilities as $f): ?><option value="<?= (int) $f['id'] ?>"><?= staffEscape($f['name']) ?></option><?php endforeach; ?></select></label>
<label>Item name<input name="name" maxlength="150" required></label><label>Category<input name="category" maxlength="100"></label>
<label>Unit<input name="unit" value="pcs" maxlength="30" required></label><label>Reorder level<input name="reorder_level" type="number" min="0" value="5" required></label>
<button class="button" <?= !$facilities ? 'disabled' : '' ?>>Add item</button></form></section>
<section class="card"><h2>Items & stock</h2><div class="table-scroll"><table><thead><tr><th>Facility</th><th>Item</th><th>Category</th><th>Stock</th><th>Reorder</th><th>Status</th><th>Edit details</th><th>Record stock</th></tr></thead><tbody>
<?php foreach ($items as $i): ?><tr><td><?= staffEscape($i['facility_name']) ?></td><td><?= staffEscape($i['name']) ?></td><td><?= staffEscape($i['category']) ?></td><td><?= (int) $i['quantity'] ?> <?= staffEscape($i['unit']) ?></td><td><?= (int) $i['reorder_level'] ?></td><td><?= staffEscape($i['status']) ?></td>
<td><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>"><input name="name" value="<?= staffEscape($i['name']) ?>" maxlength="150" aria-label="Name" required><input name="category" value="<?= staffEscape($i['category']) ?>" maxlength="100" aria-label="Category"><input name="unit" value="<?= staffEscape($i['unit']) ?>" maxlength="30" aria-label="Unit" required><input name="reorder_level" type="number" min="0" value="<?= (int) $i['reorder_level'] ?>" aria-label="Reorder level" required><button class="small-button">Save</button></form></td>
<td><?php if ($i['status'] === 'active'): ?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>"><input type="hidden" name="action" value="stock"><input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>"><select name="transaction_type" aria-label="Transaction type"><option value="purchase">Purchase (+)</option><option value="usage">Usage (-)</option><option value="adjustment">Adjustment</option></select><select name="direction" aria-label="Adjustment direction"><option value="add">Add</option><option value="remove">Remove</option></select><input type="number" name="quantity" min="1" max="100000000" placeholder="Qty" required><input name="notes" maxlength="255" placeholder="Notes"><button class="small-button">Record</button></form><?php else: ?>Inactive<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="8">No inventory items found.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="card"><h2>Recent stock transactions</h2><div class="table-scroll"><table><thead><tr><th>Date</th><th>Facility</th><th>Item</th><th>Type</th><th>Change</th><th>Recorded by</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($history as $h): ?><tr><td><?= staffEscape($h['created_at']) ?></td><td><?= staffEscape($h['facility_name']) ?></td><td><?= staffEscape($h['item_name']) ?></td><td><?= staffEscape($h['transaction_type']) ?></td><td><?= (int) $h['quantity_change'] ?></td><td><?= staffEscape($h['recorded_by']) ?></td><td><?= staffEscape($h['notes']) ?></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="7">No stock transactions.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php staffLayoutEnd(); ?>
