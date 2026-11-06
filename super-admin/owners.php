<?php
/** TurfMate — Owner Management (Super Admin only). */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin']);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/_management.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$error = '';
$notice = adminTakeFlash();

// POST is the only allowed method for changing account status.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = (string) ($_POST['new_status'] ?? '');

    if (!adminCheckToken($token)) {
        $error = 'Security token expired. Refresh the page and try again.';
    } elseif (!$userId || !in_array($newStatus, ['active', 'inactive'], true)) {
        $error = 'Invalid account or status.';
    } elseif ($userId === (int) $_SESSION['user_id']) {
        $error = 'You cannot deactivate your own account.';
    } else {
        try {
            // Role predicate prevents changing any Super Admin account, even via crafted POST.
            $statement = $pdo->prepare(
                "UPDATE users SET status = ?
                 WHERE id = ? AND role = 'owner' AND status <> ?"
            );
            $statement->execute([$newStatus, $userId, $newStatus]);

            if ($statement->rowCount() !== 1) {
                $error = 'Account not found, already updated, or not permitted.';
            } else {
                adminFlash('Account status updated successfully.');
                header('Location: owners.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Super Admin account status: ' . $exception->getMessage());
            $error = 'Unable to update this account right now.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);
$status = (string) ($_GET['status'] ?? 'all');
$role = (string) ($_GET['role'] ?? 'all');
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}
if (!in_array($role, ['all', 'customer', 'owner', 'staff'], true)) {
    $role = 'all';
}

$where = [];
$parameters = [];
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $term = '%' . $search . '%';
    array_push($parameters, $term, $term, $term);
}
if ($status !== 'all') {
    $where[] = 'u.status = ?';
    $parameters[] = $status;
}

$sql = "SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at,
           COUNT(DISTINCT f.id) AS facility_count,
           COUNT(DISTINCT CASE WHEN f.approval_status = 'approved' THEN f.id END) AS approved_count
    FROM users AS u
    LEFT JOIN facilities AS f ON f.owner_id = u.id AND f.deleted_at IS NULL
    WHERE u.role = 'owner' ";
if ($where) {
    $sql .= ' AND ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at  ORDER BY u.created_at DESC, u.id DESC LIMIT 200';

try {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $accounts = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Super Admin account listing: ' . $exception->getMessage());
    http_response_code(503);
    exit('Account listing is temporarily unavailable. Check server logs.');
}

adminPageStart('Owner Management', 'owners', 'Review owner accounts and their facilities. Account restrictions are enforced on login.');
?>
<?php if ($notice !== ''): ?>
    <div class="management-alert success" role="status"><?= adminEscape($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="management-alert error" role="alert"><?= adminEscape($error) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h3>Registered owners</h3>
            <p>Showing up to 200 accounts. Narrow the results with filters.</p>
        </div>
    </div>
    <form method="get" class="management-filters">
        <label>
            Search name, email or phone
            <input name="q" maxlength="100" value="<?= adminEscape($search) ?>" placeholder="Search accounts...">
        </label>
        <label>
            Account status
            <select name="status">
                <?php foreach (['all', 'active', 'inactive'] as $choice): ?>
                    <option value="<?= $choice ?>" <?= $status === $choice ? 'selected' : '' ?>>
                        <?= ucfirst($choice) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        
        <button class="management-button" type="submit">Apply filters</button>
        <a class="management-clear" href="owners.php">Clear</a>
    </form>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Name</th><th>Email</th><th>Phone</th>
                    
                    <th>Status</th><th>Registered</th><th>Facilities</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$accounts): ?>
                    <tr><td class="empty-row" colspan="8">No matching accounts found.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td>#<?= (int) $account['id'] ?></td>
                        <td><strong><?= adminEscape($account['name']) ?></strong></td>
                        <td><?= adminEscape($account['email']) ?></td>
                        <td><?= adminEscape($account['phone'] ?: '—') ?></td>
                        
                        <td><span class="status status-<?= adminEscape($account['status']) ?>">
                            <?= adminEscape(ucfirst($account['status'])) ?>
                        </span></td>
                        <td><?= adminEscape(date('d M Y', strtotime($account['created_at']))) ?></td>
                        <td>
                            <strong><?= (int) $account['facility_count'] ?></strong> total
                            <span class="subline"><?= (int) $account['approved_count'] ?> approved</span>
                            <a class="management-detail" href="facilities.php?q=<?= urlencode($account['name']) ?>">View facilities →</a>
                        </td>
                        <td>
                            <form method="post" class="management-inline-form">
                                <input type="hidden" name="csrf" value="<?= adminEscape(adminToken()) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <input type="hidden" name="new_status"
                                       value="<?= $account['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button class="management-button <?= $account['status'] === 'active' ? 'danger' : '' ?>"
                                        type="submit"
                                        onclick="return confirm('Change this account status?');">
                                    <?= $account['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php adminPageEnd(); ?>
