<?php

declare(strict_types=1);

/** TurfMate owner inventory management - integrates with existing owner/_init.php and _layout.php. */

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_inv_positive_id($value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? 0 : $id;
}

function tm_inv_nonneg_int(string $value): ?int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value)) {
        return null;
    }

    $number = (int) $value;

    return $number <= 100000000 ? $number : null;
}

function tm_inv_cost(string $value): bool
{
    return $value === '' || (bool) preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value);
}

function tm_inv_owns_facility(PDO $pdo, int $ownerId, int $facilityId): bool
{
    $q = $pdo->prepare('SELECT 1 FROM facilities WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([$facilityId, $ownerId]);

    return (bool) $q->fetchColumn();
}

$errors = [];
$default = [
    'id' => 0,
    'facility_id' => 0,
    'name' => '',
    'category' => '',
    'unit' => 'pcs',
    'reorder_level' => '5',
    'cost_per_unit' => '',
    'status' => 'active',
];
$form = $default;

$facilityQuery = $pdo->prepare('SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name');
$facilityQuery->execute([$ownerId]);
$facilities = $facilityQuery->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

if ($facilityIds) {
    $form['facility_id'] = $facilityIds[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id = tm_inv_positive_id($_POST['id'] ?? null);

    if ($action === 'toggle') {
        $q = $pdo->prepare(
            "UPDATE inventory_items i
             JOIN facilities f ON f.id = i.facility_id
             SET i.status = IF(i.status = 'active', 'inactive', 'active')
             WHERE i.id = ? AND f.owner_id = ? AND f.deleted_at IS NULL"
        );
        $q->execute([$id, $ownerId]);
        tm_flash(
            $q->rowCount() ? 'success' : 'error',
            $q->rowCount() ? 'Item status updated.' : 'Item not found or not yours.'
        );
        tm_redirect('inventory.php');
    }

    if ($action === 'save') {
        $form = [
            'id' => $id,
            'facility_id' => tm_inv_positive_id($_POST['facility_id'] ?? null),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'category' => trim((string) ($_POST['category'] ?? '')),
            'unit' => trim((string) ($_POST['unit'] ?? '')),
            'reorder_level' => trim((string) ($_POST['reorder_level'] ?? '')),
            'cost_per_unit' => trim((string) ($_POST['cost_per_unit'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'active')),
        ];

        if (!tm_inv_owns_facility($pdo, $ownerId, (int) $form['facility_id'])) {
            $errors[] = 'Select one of your facilities.';
        }

        if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
            $errors[] = 'Item name is required (max 150 characters).';
        }

        if (mb_strlen($form['category']) > 100) {
            $errors[] = 'Category must be 100 characters or fewer.';
        }

        if ($form['unit'] === '' || mb_strlen($form['unit']) > 30) {
            $errors[] = 'Unit is required (max 30 characters).';
        }

        $reorder = tm_inv_nonneg_int($form['reorder_level']);

        if ($reorder === null) {
            $errors[] = 'Reorder level must be a non-negative whole number (max 100,000,000).';
        }

        if (!tm_inv_cost($form['cost_per_unit'])) {
            $errors[] = 'Unit cost must be non-negative and have at most 2 decimal places.';
        }

        if (!in_array($form['status'], ['active', 'inactive'], true)) {
            $errors[] = 'Invalid item status.';
        }

        if (!$errors) {
            try {
                if ($id) {
                    // Ensure the existing item belongs to this owner before moving it to another owned facility.
                    $q = $pdo->prepare(
                        'SELECT 1
                         FROM inventory_items i
                         JOIN facilities f ON f.id = i.facility_id
                         WHERE i.id = ? AND f.owner_id = ? AND f.deleted_at IS NULL'
                    );
                    $q->execute([$id, $ownerId]);

                    if (!$q->fetchColumn()) {
                        throw new RuntimeException('Item not found or not yours.');
                    }

                    $q = $pdo->prepare(
                        'UPDATE inventory_items
                         SET facility_id = ?, name = ?, category = ?, unit = ?, reorder_level = ?, cost_per_unit = ?, status = ?
                         WHERE id = ?'
                    );
                    $q->execute([
                        $form['facility_id'],
                        $form['name'],
                        $form['category'] ?: null,
                        $form['unit'],
                        $reorder,
                        $form['cost_per_unit'] === '' ? null : $form['cost_per_unit'],
                        $form['status'],
                        $id,
                    ]);

                    tm_flash('success', 'Inventory item updated.');
                } else {
                    // Initial quantity is always zero. Stock-in must be recorded as a transaction.
                    $q = $pdo->prepare(
                        'INSERT INTO inventory_items (facility_id, name, category, quantity, unit, reorder_level, cost_per_unit, status)
                         VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
                    );
                    $q->execute([
                        $form['facility_id'],
                        $form['name'],
                        $form['category'] ?: null,
                        $form['unit'],
                        $reorder,
                        $form['cost_per_unit'] === '' ? null : $form['cost_per_unit'],
                        $form['status'],
                    ]);

                    tm_flash('success', 'Inventory item created with zero stock. Record opening stock in Stock Transactions once that page is installed.');
                }

                tm_redirect('inventory.php');
            } catch (Throwable $e) {
                error_log('TurfMate inventory save: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not save item. Check server logs.';
            }
        }
    } else {
        $errors[] = 'Invalid action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = tm_inv_positive_id($_GET['edit']);

    $q = $pdo->prepare(
        'SELECT i.*
         FROM inventory_items i
         JOIN facilities f ON f.id = i.facility_id
         WHERE i.id = ? AND f.owner_id = ? AND f.deleted_at IS NULL'
    );
    $q->execute([$id, $ownerId]);
    $existing = $q->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        tm_flash('error', 'Inventory item not found or not yours.');
        tm_redirect('inventory.php');
    }

    $form = $existing;
}

$facilityFilter = tm_inv_positive_id($_GET['facility_id'] ?? null);

if (!in_array($facilityFilter, $facilityIds, true)) {
    $facilityFilter = 0;
}

$statusFilter = (string) ($_GET['status'] ?? 'active');

if (!in_array($statusFilter, ['active', 'inactive', 'all'], true)) {
    $statusFilter = 'active';
}

$lowOnly = (string) ($_GET['low'] ?? '') === '1';
$search = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$where = ['f.owner_id = ?', 'f.deleted_at IS NULL'];
$params = [$ownerId];

if ($facilityFilter) {
    $where[] = 'i.facility_id = ?';
    $params[] = $facilityFilter;
}

if ($statusFilter !== 'all') {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}

if ($lowOnly) {
    $where[] = 'i.quantity <= i.reorder_level';
}

if ($search !== '') {
    $where[] = '(i.name LIKE ? OR i.category LIKE ? OR f.name LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

$whereSql = implode(' AND ', $where);

$q = $pdo->prepare(
    "SELECT i.id, i.facility_id, i.name, i.category, i.quantity, i.unit, i.reorder_level, i.cost_per_unit, i.status, f.name facility_name
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE $whereSql
     ORDER BY (i.quantity <= i.reorder_level) DESC, i.name ASC, i.id DESC
     LIMIT 300"
);
$q->execute($params);
$items = $q->fetchAll(PDO::FETCH_ASSOC);

$q = $pdo->prepare(
    "SELECT COUNT(*) AS item_count,
            COALESCE(SUM(CASE WHEN i.status = 'active' AND i.quantity <= i.reorder_level THEN 1 ELSE 0 END), 0) AS low_count,
            COALESCE(SUM(CASE WHEN i.status = 'active' THEN i.quantity ELSE 0 END), 0) AS total_units,
            COALESCE(SUM(CASE WHEN i.status = 'active' THEN i.quantity * COALESCE(i.cost_per_unit, 0) ELSE 0 END), 0) AS stock_value
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL AND i.status = 'active'"
);
$q->execute([$ownerId]);
$stats = $q->fetch(PDO::FETCH_ASSOC);

$showForm = isset($_GET['new'])
    || isset($_GET['edit'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save');

tm_page_header('Inventory Management', 'inventory', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-inventory.css?v=1">

<div class="inv-page">
    <div class="inv-heading">
        <div>
            <h2>Inventory & equipment</h2>
            <p>Track facility items, unit costs and low-stock alerts.</p>
        </div>
        <a class="inv-button inv-primary" href="inventory.php?new=1">+ Add Item</a>
    </div>

 <div class="inv-tabs">

    <span>Overview (next)</span>

    <a href="expenses.php">
        Expenses
    </a>

    <a href="inventory.php" class="selected">
        Inventory
    </a>

    <a href="inventory-transactions.php">
        Stock Transactions
    </a>

    <span>Maintenance (next)</span>

</div>

    <?php if ($errors): ?>
        <div class="inv-error" role="alert">
            <strong>Please fix:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= tm_e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="inv-error">Create a facility first: <a href="facilities.php">Facilities</a>.</div>
    <?php endif; ?>

    <div class="inv-stats">
        <div>
            <small>Active items</small>
            <strong><?= (int) $stats['item_count'] ?></strong>
        </div>
        <div>
            <small>Low-stock items</small>
            <strong class="inv-warn"><?= (int) $stats['low_count'] ?></strong>
        </div>
        <div>
            <small>Total units</small>
            <strong><?= (int) $stats['total_units'] ?></strong>
        </div>
        <div>
            <small>Estimated stock value</small>
            <strong><?= tm_money($stats['stock_value']) ?></strong>
        </div>
    </div>

    <?php if ($showForm): ?>
        <section class="inv-card">
            <div class="inv-card-top">
                <h3><?= (int) ($form['id'] ?? 0) ? 'Edit inventory item' : 'Add inventory item' ?></h3>
                <a href="inventory.php">Close</a>
            </div>

            <form method="post" action="inventory.php" class="inv-form">
                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">

                <label>Facility *
                    <select name="facility_id" required>
                        <option value="">Select facility</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (int) $form['facility_id'] === (int) $facility['id'] ? 'selected' : '' ?>>
                                <?= tm_e((string) $facility['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Item name *
                    <input name="name" maxlength="150" required placeholder="e.g. Cricket bats" value="<?= tm_e((string) $form['name']) ?>">
                </label>

                <label>Category
                    <input name="category" maxlength="100" placeholder="e.g. Sports Equipment" value="<?= tm_e((string) ($form['category'] ?? '')) ?>">
                </label>

                <label>Unit *
                    <input name="unit" maxlength="30" required placeholder="e.g. pcs, boxes" value="<?= tm_e((string) $form['unit']) ?>">
                </label>

                <label>Reorder alert at *
                    <input type="number" name="reorder_level" min="0" max="100000000" step="1" required value="<?= tm_e((string) $form['reorder_level']) ?>">
                </label>

                <label>Cost per unit (Rs.)
                    <input type="number" name="cost_per_unit" min="0" max="99999999.99" step="0.01" value="<?= tm_e((string) ($form['cost_per_unit'] ?? '')) ?>" placeholder="Optional">
                </label>

                <label>Status
                    <select name="status">
                        <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $form['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>

                <div class="inv-wide inv-help">
                    Stock quantity starts at <strong>0</strong> for new items. Quantity is changed only through auditable Stock Transactions (next module).
                </div>

                <div class="inv-wide inv-actions">
                    <button class="inv-button inv-primary" type="submit" <?= !$facilities ? 'disabled' : '' ?>>
                        <?= (int) ($form['id'] ?? 0) ? 'Save Changes' : 'Create Item' ?>
                    </button>
                    <a class="inv-button inv-secondary" href="inventory.php">Cancel</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="inv-card">
        <div class="inv-card-top">
            <div>
                <h3>Inventory list</h3>
                <p>Showing up to 300 matching items. Low-stock items appear first.</p>
            </div>
        </div>

        <form class="inv-filters" method="get" action="inventory.php">
            <label>Facility
                <select name="facility_id">
                    <option value="0">All facilities</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= $facilityFilter === (int) $facility['id'] ? 'selected' : '' ?>>
                            <?= tm_e((string) $facility['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Status
                <select name="status">
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </label>

            <label>Search
                <input name="q" maxlength="100" value="<?= tm_e($search) ?>" placeholder="Item or category">
            </label>

            <label class="inv-check">
                <input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>> Low stock only
            </label>

            <div class="inv-filter-buttons">
                <button type="submit" class="inv-button inv-primary">Filter</button>
                <a class="inv-button inv-secondary" href="inventory.php">Reset</a>
            </div>
        </form>

        <?php if (!$items): ?>
            <div class="inv-empty">No inventory items found. <a href="inventory.php?new=1">Create your first item</a>.</div>
        <?php else: ?>
            <div class="inv-table-wrap">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Facility</th>
                            <th>Quantity</th>
                            <th>Reorder at</th>
                            <th>Unit cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php $low = (int) $item['quantity'] <= (int) $item['reorder_level']; ?>
                            <tr>
                                <td>
                                    <strong><?= tm_e((string) $item['name']) ?></strong>
                                    <small><?= tm_e((string) ($item['category'] ?: 'Uncategorized')) ?></small>
                                </td>
                                <td><?= tm_e((string) $item['facility_name']) ?></td>
                                <td>
                                    <strong><?= (int) $item['quantity'] ?> <?= tm_e((string) $item['unit']) ?></strong>
                                    <?php if ($low && $item['status'] === 'active'): ?>
                                        <small class="inv-low-label">Low stock</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) $item['reorder_level'] ?></td>
                                <td><?= $item['cost_per_unit'] === null ? '—' : tm_money($item['cost_per_unit']) ?></td>
                                <td>
                                    <span class="inv-badge <?= $item['status'] === 'active' ? 'inv-active' : 'inv-inactive' ?>">
                                        <?= tm_e(ucfirst((string) $item['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="inv-row-actions">
                                        <a href="inventory.php?edit=<?= (int) $item['id'] ?>">Edit</a>
                                        <form method="post" action="inventory.php" onsubmit="return confirm('Change this item status?');">
                                            <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit"><?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php tm_page_footer(); ?>
