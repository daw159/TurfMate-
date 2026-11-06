<?php

declare(strict_types=1);

/** TurfMate - Owner stock movements. Uses existing _init.php and _layout.php. */

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_st_id(mixed $value): int
{
    $v = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $v === false ? 0 : (int) $v;
}

$errors = [];

$facilityStmt = $pdo->prepare('SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name');
$facilityStmt->execute([$ownerId]);
$facilities = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

$itemStmt = $pdo->prepare(
    "SELECT i.id, i.name, i.quantity, i.unit, i.facility_id, f.name AS facility_name
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL AND i.status = 'active'
     ORDER BY f.name, i.name"
);
$itemStmt->execute([$ownerId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$form = [
    'item_id' => (string) tm_st_id($_GET['item_id'] ?? null),
    'transaction_type' => 'purchase',
    'quantity' => '',
    'direction' => 'add',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();

    $form = [
        'item_id' => trim((string) ($_POST['item_id'] ?? '')),
        'transaction_type' => trim((string) ($_POST['transaction_type'] ?? '')),
        'quantity' => trim((string) ($_POST['quantity'] ?? '')),
        'direction' => trim((string) ($_POST['direction'] ?? 'add')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ];

    $itemId = tm_st_id($form['item_id']);
    $type = $form['transaction_type'];
    $quantity = filter_var(
        $form['quantity'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100000000]]
    );

    if (!$itemId) {
        $errors[] = 'Choose an inventory item.';
    }

    if (!in_array($type, ['purchase', 'usage', 'adjustment'], true)) {
        $errors[] = 'Choose a valid transaction type.';
    }

    if ($quantity === false) {
        $errors[] = 'Quantity must be a positive whole number (maximum 100,000,000).';
    }

    if (!in_array($form['direction'], ['add', 'remove'], true)) {
        $errors[] = 'Choose Add or Remove for adjustments.';
    }

    if (mb_strlen($form['notes']) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    if (!$errors) {
        $change = $type === 'usage' || ($type === 'adjustment' && $form['direction'] === 'remove')
            ? -(int) $quantity
            : (int) $quantity;

        try {
            $pdo->beginTransaction();

            // Row lock protects against simultaneous updates; facility JOIN enforces owner access.
            $locked = $pdo->prepare(
                "SELECT i.id, i.quantity, i.name
                 FROM inventory_items i
                 JOIN facilities f ON f.id = i.facility_id
                 WHERE i.id = ? AND f.owner_id = ? AND f.deleted_at IS NULL AND i.status = 'active'
                 FOR UPDATE"
            );
            $locked->execute([$itemId, $ownerId]);
            $item = $locked->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new RuntimeException('Item not found, inactive, or not owned by you.');
            }

            $newQuantity = (int) $item['quantity'] + $change;

            if ($newQuantity < 0) {
                throw new RuntimeException('Insufficient stock. Available: ' . $item['quantity'] . '.');
            }

            if ($newQuantity > 2147483647) {
                throw new RuntimeException('Stock quantity would exceed the database limit.');
            }

            $update = $pdo->prepare('UPDATE inventory_items SET quantity = ? WHERE id = ?');
            $update->execute([$newQuantity, $itemId]);

            $insert = $pdo->prepare(
                'INSERT INTO inventory_transactions (item_id, created_by, transaction_type, quantity_change, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $itemId,
                $ownerId,
                $type,
                $change,
                $form['notes'] === '' ? null : $form['notes'],
            ]);

            $pdo->commit();
            tm_flash('success', 'Stock updated for ' . $item['name'] . '. New balance: ' . $newQuantity . '.');
            tm_redirect('inventory-transactions.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                error_log('TurfMate stock transaction failed: ' . $e->getMessage());
                $errors[] = 'Unable to save stock transaction. Check server logs.';
            }
        }
    }
}

$facilityFilter = tm_st_id($_GET['facility_id'] ?? null);

if (!in_array($facilityFilter, $facilityIds, true)) {
    $facilityFilter = 0;
}

$typeFilter = (string) ($_GET['type'] ?? 'all');

if (!in_array($typeFilter, ['all', 'purchase', 'usage', 'adjustment'], true)) {
    $typeFilter = 'all';
}

$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');

foreach (['from', 'to'] as $dateField) {
    $dateValue = $$dateField;

    if (
        $dateValue !== ''
        && (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateValue)
            || !DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue)
            || DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue)->format('Y-m-d') !== $dateValue
        )
    ) {
        $$dateField = '';
    }
}

$where = ['f.owner_id = ?', 'f.deleted_at IS NULL'];
$params = [$ownerId];

if ($facilityFilter) {
    $where[] = 'f.id = ?';
    $params[] = $facilityFilter;
}

if ($typeFilter !== 'all') {
    $where[] = 't.transaction_type = ?';
    $params[] = $typeFilter;
}

if ($from !== '') {
    $where[] = 't.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}

if ($to !== '') {
    $where[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $to;
}

$whereSql = implode(' AND ', $where);

$historyStmt = $pdo->prepare(
    "SELECT t.id, t.transaction_type, t.quantity_change, t.notes, t.created_at,
            i.name AS item_name, i.unit, f.name AS facility_name, u.name AS recorded_by
     FROM inventory_transactions t
     JOIN inventory_items i ON i.id = t.item_id
     JOIN facilities f ON f.id = i.facility_id
     JOIN users u ON u.id = t.created_by
     WHERE $whereSql
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 250"
);
$historyStmt->execute($params);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN t.quantity_change > 0 THEN t.quantity_change ELSE 0 END), 0) AS units_in,
            COALESCE(SUM(CASE WHEN t.quantity_change < 0 THEN -t.quantity_change ELSE 0 END), 0) AS units_out,
            COUNT(*) AS movements
     FROM inventory_transactions t
     JOIN inventory_items i ON i.id = t.item_id
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL"
);
$statsStmt->execute([$ownerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$balanceStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(i.quantity), 0)
     FROM inventory_items i
     JOIN facilities f ON f.id = i.facility_id
     WHERE f.owner_id = ? AND f.deleted_at IS NULL AND i.status = 'active'"
);
$balanceStmt->execute([$ownerId]);
$totalStock = (int) $balanceStmt->fetchColumn();

tm_page_header('Stock Transactions', 'inventory', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-stock.css?v=1">

<div class="st-page">
    <div class="st-heading">
        <div>
            <h2>Stock Transactions</h2>
            <p>Record purchases, usage and adjustments for your facilities.</p>
        </div>
        <a href="#stock-form" class="st-btn st-primary">+ New Transaction</a>
    </div>

    <nav class="st-tabs" aria-label="Expenses and inventory modules">
        <span>Overview (next)</span>
        <a href="expenses.php">Expenses</a>
        <a href="inventory.php">Inventory</a>
        <a href="inventory-transactions.php" class="selected" aria-current="page">Stock Transactions</a>
        
    <a href="maintenance.php">
        Maintenance
    </a>
    </nav>

    <?php if ($errors): ?>
        <div class="st-error" role="alert">
            <strong>Please fix:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= tm_e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="st-stats">
        <article>
            <small>Stock In (all time)</small>
            <strong><?= (int) $stats['units_in'] ?> units</strong>
        </article>
        <article>
            <small>Stock Out (all time)</small>
            <strong><?= (int) $stats['units_out'] ?> units</strong>
        </article>
        <article>
            <small>Total Transactions</small>
            <strong><?= (int) $stats['movements'] ?></strong>
        </article>
        <article>
            <small>Current Stock (active items)</small>
            <strong><?= $totalStock ?> units</strong>
        </article>
    </div>

    <section class="st-card" id="stock-form">
        <div class="st-section-heading">
            <h3>Record stock movement</h3>
            <p>Transactions update item quantities immediately and save an audit history.</p>
        </div>

        <?php if (!$items): ?>
            <div class="st-hint">No active items available. <a href="inventory.php?new=1">Add an inventory item first.</a></div>
        <?php endif; ?>

        <form class="st-form" method="post" action="inventory-transactions.php#stock-form">
            <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">

            <label>Inventory Item *
                <select name="item_id" required>
                    <option value="">Select item</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= (int) $form['item_id'] === (int) $item['id'] ? 'selected' : '' ?>>
                            <?= tm_e((string) $item['facility_name'] . ' — ' . (string) $item['name'] . ' (' . (int) $item['quantity'] . ' ' . (string) $item['unit'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Transaction Type *
                <select name="transaction_type" required>
                    <option value="purchase" <?= $form['transaction_type'] === 'purchase' ? 'selected' : '' ?>>Stock In (purchase / opening stock)</option>
                    <option value="usage" <?= $form['transaction_type'] === 'usage' ? 'selected' : '' ?>>Stock Out (used / damaged)</option>
                    <option value="adjustment" <?= $form['transaction_type'] === 'adjustment' ? 'selected' : '' ?>>Adjustment (correction)</option>
                </select>
            </label>

            <label>Quantity *
                <input type="number" name="quantity" required min="1" max="100000000" step="1" placeholder="e.g. 10" value="<?= tm_e($form['quantity']) ?>">
            </label>

            <label>Adjustment Direction
                <select name="direction">
                    <option value="add" <?= $form['direction'] === 'add' ? 'selected' : '' ?>>Add (+)</option>
                    <option value="remove" <?= $form['direction'] === 'remove' ? 'selected' : '' ?>>Remove (-)</option>
                </select>
                <small>Only used when Transaction Type is Adjustment.</small>
            </label>

            <label class="st-wide">Notes
                <textarea name="notes" maxlength="255" rows="2" placeholder="e.g. Purchased new cricket balls, received from supplier"><?= tm_e($form['notes']) ?></textarea>
            </label>

            <div class="st-wide st-actions">
                <button class="st-btn st-primary" type="submit" <?= !$items ? 'disabled' : '' ?>>Save Transaction</button>
                <span>Stock Out cannot exceed available quantity.</span>
            </div>
        </form>

        <div class="st-hint">
            Stock purchases are <strong>not automatically added to Expenses</strong>. Record any money spent in Expenses separately for now. Do not enter the same expense twice.
        </div>
    </section>

    <section class="st-card">
        <div class="st-section-heading">
            <h3>Transaction History</h3>
            <p>Latest 250 matching movements, newest first.</p>
        </div>

        <form class="st-filters" method="get" action="inventory-transactions.php">
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

            <label>Type
                <select name="type">
                    <option value="all">All types</option>
                    <?php
                    $typeLabels = [
                        'purchase' => 'Stock In',
                        'usage' => 'Stock Out',
                        'adjustment' => 'Adjustment',
                    ];

                    foreach ($typeLabels as $key => $label):
                    ?>
                        <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>From
                <input type="date" name="from" value="<?= tm_e($from) ?>">
            </label>

            <label>To
                <input type="date" name="to" value="<?= tm_e($to) ?>">
            </label>

            <div class="st-filter-actions">
                <button type="submit" class="st-btn st-primary">Filter</button>
                <a class="st-btn st-secondary" href="inventory-transactions.php">Reset</a>
            </div>
        </form>

        <div class="st-table-scroll">
            <table class="st-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Facility</th>
                        <th>Type</th>
                        <th>Change</th>
                        <th>Notes</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$history): ?>
                        <tr>
                            <td colspan="7" class="st-empty">No stock transactions found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($history as $tx): ?>
                        <tr>
                            <td><?= tm_e((string) $tx['created_at']) ?></td>
                            <td><strong><?= tm_e((string) $tx['item_name']) ?></strong></td>
                            <td><?= tm_e((string) $tx['facility_name']) ?></td>
                            <td>
                                <span class="st-badge st-<?= tm_e((string) $tx['transaction_type']) ?>">
                                    <?= tm_e(match ($tx['transaction_type']) {
                                        'purchase' => 'Stock In',
                                        'usage' => 'Stock Out',
                                        default => 'Adjustment',
                                    }) ?>
                                </span>
                            </td>
                            <td class="<?= (int) $tx['quantity_change'] >= 0 ? 'st-plus' : 'st-minus' ?>">
                                <?= (int) $tx['quantity_change'] > 0 ? '+' : '' ?><?= (int) $tx['quantity_change'] ?> <?= tm_e((string) $tx['unit']) ?>
                            </td>
                            <td><?= tm_e((string) ($tx['notes'] ?? '—')) ?></td>
                            <td><?= tm_e((string) $tx['recorded_by']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php tm_page_footer(); ?>
