<?php

/** TurfMate owner expense management. Uses existing expenses + facilities tables. */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

$errors = [];
$categories = ['Utilities', 'Salaries', 'Maintenance', 'Equipment', 'Cleaning', 'Rent', 'Marketing', 'Supplies', 'Other'];
$defaultForm = [
    'id' => 0,
    'facility_id' => 0,
    'category' => 'Utilities',
    'description' => '',
    'amount' => '',
    'expense_date' => date('Y-m-d'),
];
$form = $defaultForm;

$facilitiesStmt = $pdo->prepare('SELECT id, name FROM facilities WHERE owner_id = ? AND deleted_at IS NULL ORDER BY name');
$facilitiesStmt->execute([$ownerId]);
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);
$facilityIds = array_map('intval', array_column($facilities, 'id'));

if ($facilities) {
    $form['facility_id'] = (int) $facilities[0]['id'];
}

function tm_expense_id($raw): int
{
    return filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
}

function tm_expense_owns_facility(PDO $pdo, int $ownerId, int $facilityId): bool
{
    $q = $pdo->prepare('SELECT 1 FROM facilities WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([$facilityId, $ownerId]);

    return (bool) $q->fetchColumn();
}

function tm_expense_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();

    $action = (string) ($_POST['action'] ?? '');
    $id = tm_expense_id($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($id < 1) {
            tm_flash('error', 'Invalid expense selected.');
        } else {
            $stmt = $pdo->prepare(
                'DELETE e FROM expenses e
                 INNER JOIN facilities f ON f.id = e.facility_id
                 WHERE e.id = ? AND f.owner_id = ?'
            );
            $stmt->execute([$id, $ownerId]);
            tm_flash(
                $stmt->rowCount() ? 'success' : 'error',
                $stmt->rowCount() ? 'Expense deleted.' : 'Expense not found or not yours.'
            );
        }

        tm_redirect('expenses.php');
    }

    if ($action === 'save') {
        $form = [
            'id' => $id,
            'facility_id' => tm_expense_id($_POST['facility_id'] ?? 0),
            'category' => trim((string) ($_POST['category'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'amount' => trim((string) ($_POST['amount'] ?? '')),
            'expense_date' => trim((string) ($_POST['expense_date'] ?? '')),
        ];

        if (!tm_expense_owns_facility($pdo, $ownerId, (int) $form['facility_id'])) {
            $errors[] = 'Choose one of your active facilities.';
        }

        if ($form['category'] === '' || strlen($form['category']) > 100) {
            $errors[] = 'Category is required (up to 100 characters).';
        }

        if (strlen($form['description']) > 255) {
            $errors[] = 'Description cannot exceed 255 characters.';
        }

        if (
            !preg_match('/^(?:\d{1,8})(?:\.\d{1,2})?$/D', $form['amount'])
            || (float) $form['amount'] <= 0
            || (float) $form['amount'] > 99999999.99
        ) {
            $errors[] = 'Enter an amount greater than zero, with at most two decimals.';
        }

        if (!tm_expense_valid_date($form['expense_date'])) {
            $errors[] = 'Choose a valid expense date.';
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $q = $pdo->prepare(
                        'UPDATE expenses e
                         INNER JOIN facilities f ON f.id = e.facility_id
                         SET e.facility_id = ?, e.category = ?, e.description = ?, e.amount = ?, e.expense_date = ?
                         WHERE e.id = ? AND f.owner_id = ?'
                    );
                    $q->execute([
                        $form['facility_id'],
                        $form['category'],
                        $form['description'] ?: null,
                        $form['amount'],
                        $form['expense_date'],
                        $id,
                        $ownerId,
                    ]);

                    $check = $pdo->prepare(
                        'SELECT 1
                         FROM expenses e
                         JOIN facilities f ON f.id = e.facility_id
                         WHERE e.id = ? AND f.owner_id = ?'
                    );
                    $check->execute([$id, $ownerId]);

                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Expense not found or not yours.');
                    }

                    tm_flash('success', 'Expense updated successfully.');
                } else {
                    $q = $pdo->prepare(
                        'INSERT INTO expenses (facility_id, created_by, category, description, amount, expense_date)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $q->execute([
                        $form['facility_id'],
                        $ownerId,
                        $form['category'],
                        $form['description'] ?: null,
                        $form['amount'],
                        $form['expense_date'],
                    ]);

                    tm_flash('success', 'Expense recorded successfully.');
                }

                tm_redirect('expenses.php');
            } catch (Throwable $e) {
                error_log('TurfMate expense save: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not save expense. Please check your database and try again.';
            }
        }
    } else {
        $errors[] = 'Invalid form action.';
    }
} elseif (isset($_GET['edit'])) {
    $id = tm_expense_id($_GET['edit']);

    $q = $pdo->prepare(
        'SELECT e.*
         FROM expenses e
         JOIN facilities f ON f.id = e.facility_id
         WHERE e.id = ? AND f.owner_id = ?
         LIMIT 1'
    );
    $q->execute([$id, $ownerId]);
    $record = $q->fetch(PDO::FETCH_ASSOC);

    if ($record) {
        $form = $record;
    } else {
        tm_flash('error', 'Expense not found or not yours.');
        tm_redirect('expenses.php');
    }
}

// The same filters apply to summary totals and table records.
$from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
$to = trim((string) ($_GET['to'] ?? date('Y-m-t')));

if (!tm_expense_valid_date($from)) {
    $from = date('Y-m-01');
}

if (!tm_expense_valid_date($to)) {
    $to = date('Y-m-t');
}

if ($to < $from) {
    [$from, $to] = [$to, $from];
}

$selectedFacility = tm_expense_id($_GET['facility_id'] ?? 0);

if ($selectedFacility && !in_array($selectedFacility, $facilityIds, true)) {
    $selectedFacility = 0;
}

$selectedCategory = trim((string) ($_GET['category'] ?? ''));

if (strlen($selectedCategory) > 100) {
    $selectedCategory = '';
}

$search = trim((string) ($_GET['q'] ?? ''));

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

$where = ['f.owner_id = ?', 'e.expense_date BETWEEN ? AND ?'];
$params = [$ownerId, $from, $to];

if ($selectedFacility) {
    $where[] = 'e.facility_id = ?';
    $params[] = $selectedFacility;
}

if ($selectedCategory !== '') {
    $where[] = 'e.category = ?';
    $params[] = $selectedCategory;
}

if ($search !== '') {
    $where[] = '(e.description LIKE ? OR e.category LIKE ? OR f.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);

$summary = $pdo->prepare(
    "SELECT COUNT(*) expense_count, COALESCE(SUM(e.amount), 0) total_amount, COUNT(DISTINCT e.facility_id) facility_count
     FROM expenses e
     JOIN facilities f ON f.id = e.facility_id
     WHERE $whereSql"
);
$summary->execute($params);
$stats = $summary->fetch(PDO::FETCH_ASSOC);

$recordsStmt = $pdo->prepare(
    "SELECT e.id, e.facility_id, e.category, e.description, e.amount, e.expense_date, e.created_at, f.name facility_name
     FROM expenses e
     JOIN facilities f ON f.id = e.facility_id
     WHERE $whereSql
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 200"
);
$recordsStmt->execute($params);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->prepare(
    'SELECT DISTINCT e.category
     FROM expenses e
     JOIN facilities f ON f.id = e.facility_id
     WHERE f.owner_id = ?
     ORDER BY e.category'
);
$categoryStmt->execute([$ownerId]);
$filterCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

$showForm = isset($_GET['new'], $_GET['new'])
    || isset($_GET['edit'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save');

// Layout CSS is intentionally included after owner.css.
tm_page_header('Expense Management', 'expenses', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-expenses.css?v=1">

<div class="ex-page">
    <div class="intro-row">
        <div>
            <h2>Track business expenses</h2>
            <p>Manage costs by facility and keep a clear spending history.</p>
        </div>

        <a class="btn btn-primary" href="expenses.php?new=1">
            + Add Expense
        </a>
    </div>

    <!-- Expenses & Inventory Navigation -->
    <div class="module-tabs">
        <a href="expenses.php" class="module-tab active">
            Expenses
        </a>

        <a href="inventory.php" class="module-tab">
            Inventory
        </a>

        <span class="module-tab disabled">
            Stock Transactions (next)
        </span>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <strong>Please correct:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= tm_e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$facilities): ?>
        <div class="ex-notice">You need to <a href="facilities.php?new=1">create a facility</a> before recording expenses.</div>
    <?php endif; ?>

    <?php if ($showForm): ?>
        <section class="panel ex-form-panel">
            <div class="panel-heading">
                <div>
                    <h2><?= (int) ($form['id'] ?? 0) ? 'Edit expense' : 'Add an expense' ?></h2>
                    <p>All fields marked * are required.</p>
                </div>
                <a class="text-link" href="expenses.php">Close</a>
            </div>

            <form method="post" action="expenses.php" class="ex-form">
                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($form['id'] ?? 0) ?>">

                <label>Facility *
                    <select name="facility_id" required>
                        <option value="">Select facility</option>
                        <?php foreach ($facilities as $facility): ?>
                            <option value="<?= (int) $facility['id'] ?>" <?= (int) $form['facility_id'] === (int) $facility['id'] ? 'selected' : '' ?>>
                                <?= tm_e($facility['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Category *
                    <input name="category" list="expense-categories" maxlength="100" required value="<?= tm_e((string) $form['category']) ?>" placeholder="e.g. Electricity">
                    <datalist id="expense-categories">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= tm_e($category) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </label>

                <label>Amount (Rs.) *
                    <input name="amount" type="number" step="0.01" min="0.01" max="99999999.99" required value="<?= tm_e((string) $form['amount']) ?>" placeholder="e.g. 15000">
                </label>

                <label>Expense date *
                    <input name="expense_date" type="date" required value="<?= tm_e((string) $form['expense_date']) ?>">
                </label>

                <label class="ex-full">Description
                    <textarea name="description" maxlength="255" rows="3" placeholder="e.g. November electricity bill for main facility"><?= tm_e((string) $form['description']) ?></textarea>
                </label>

                <div class="ex-full ex-form-buttons">
                    <button type="submit" class="btn btn-primary" <?= !$facilities ? 'disabled' : '' ?>>
                        <?= (int) ($form['id'] ?? 0) ? 'Save Changes' : 'Record Expense' ?>
                    </button>
                    <a href="expenses.php" class="btn ex-muted-btn">Cancel</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="ex-metrics" aria-label="Expense summary">
        <div class="ex-metric">
            <span>Total expenses</span>
            <strong><?= tm_money($stats['total_amount']) ?></strong>
            <small>Within selected filters</small>
        </div>
        <div class="ex-metric">
            <span>Expense records</span>
            <strong><?= (int) $stats['expense_count'] ?></strong>
            <small>Transactions recorded</small>
        </div>
        <div class="ex-metric">
            <span>Facilities with expenses</span>
            <strong><?= (int) $stats['facility_count'] ?></strong>
            <small>Within selected period</small>
        </div>
    </section>

    <section class="panel ex-history">
        <div class="panel-heading">
            <div>
                <h2>Expense history</h2>
                <p>Filter by date, facility, and category. Up to 200 latest matching records are shown.</p>
            </div>
        </div>

        <form class="ex-filters" method="get" action="expenses.php">
            <label>From
                <input type="date" name="from" value="<?= tm_e($from) ?>">
            </label>

            <label>To
                <input type="date" name="to" value="<?= tm_e($to) ?>">
            </label>

            <label>Facility
                <select name="facility_id">
                    <option value="0">All facilities</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= $selectedFacility === (int) $facility['id'] ? 'selected' : '' ?>>
                            <?= tm_e($facility['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Category
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($filterCategories as $category): ?>
                        <option value="<?= tm_e($category) ?>" <?= $selectedCategory === $category ? 'selected' : '' ?>>
                            <?= tm_e($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ex-search">Search
                <input name="q" value="<?= tm_e($search) ?>" maxlength="100" placeholder="Description or category">
            </label>

            <div class="ex-filter-actions">
                <button class="btn btn-primary" type="submit">Apply Filters</button>
                <a class="btn ex-muted-btn" href="expenses.php">Reset</a>
            </div>
        </form>

        <?php if (!$records): ?>
            <div class="empty-state">
                <div class="empty-symbol">▤</div>
                <strong>No expenses found</strong>
                <p>Add your first expense or adjust the date filters.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="ex-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category / Description</th>
                            <th>Facility</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= tm_e($record['expense_date']) ?></td>
                                <td>
                                    <strong><?= tm_e($record['category']) ?></strong>
                                    <span class="subline"><?= tm_e($record['description'] ?: 'No description') ?></span>
                                </td>
                                <td><?= tm_e($record['facility_name']) ?></td>
                                <td class="ex-amount"><?= tm_money($record['amount']) ?></td>
                                <td class="ex-actions">
                                    <a class="ex-edit" href="expenses.php?edit=<?= (int) $record['id'] ?>">Edit</a>
                                    <form method="post" action="expenses.php" onsubmit="return confirm('Delete this expense permanently?');">
                                        <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                        <button type="submit" class="ex-delete">Delete</button>
                                    </form>
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
