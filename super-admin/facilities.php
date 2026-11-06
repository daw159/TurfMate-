<?php
/**
 * TurfMate — Facility approval management.
 * Only Super Admins can review facilities. All state-changing requests use CSRF.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin']);
require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function approvalEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['superadmin_approval_csrf'])) {
    $_SESSION['superadmin_approval_csrf'] = bin2hex(random_bytes(32));
}

$message = (string) ($_SESSION['superadmin_approval_message'] ?? '');
unset($_SESSION['superadmin_approval_message']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $facilityId = filter_var($_POST['facility_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if (!hash_equals($_SESSION['superadmin_approval_csrf'], $token)) {
        $error = 'Invalid security token. Refresh and try again.';
    } elseif (!$facilityId || $facilityId < 1) {
        $error = 'Invalid facility.';
    } elseif (!in_array($action, ['approve', 'reject', 'suspend'], true)) {
        $error = 'Invalid action.';
    } elseif ($action === 'reject' && ($reason === '' || mb_strlen($reason) > 1000)) {
        $error = 'A rejection reason is required (maximum 1000 characters).';
    } else {
        $status = [
            'approve' => 'approved',
            'reject' => 'rejected',
            'suspend' => 'suspended',
        ][$action];

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT approval_status FROM facilities WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
            );
            $statement->execute([$facilityId]);
            $previous = $statement->fetchColumn();

            if ($previous === false) {
                throw new RuntimeException('Facility not found or removed.');
            }
            if ($previous === $status) {
                throw new RuntimeException('Facility already has that approval status.');
            }
            if ($status === 'suspended' && $previous !== 'approved') {
                throw new RuntimeException('Only approved facilities can be suspended.');
            }

            $statement = $pdo->prepare(
                'UPDATE facilities
                 SET approval_status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
                 WHERE id = ?'
            );
            $statement->execute([
                $status,
                (int) $_SESSION['user_id'],
                $status === 'rejected' ? $reason : null,
                $facilityId,
            ]);
            $pdo->commit();
            $_SESSION['superadmin_approval_message'] = 'Facility ' . $status . ' successfully.';
            header('Location: facilities.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Could not update the facility. Check server logs.';
            if (!$exception instanceof RuntimeException) {
                error_log('Super Admin facility approval: ' . $exception->getMessage());
            }
        }
    }
}

$allowedFilters = ['all', 'pending', 'approved', 'rejected', 'suspended'];
$filter = (string) ($_GET['status'] ?? 'all');
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$where = ['f.deleted_at IS NULL'];
$parameters = [];
if ($filter !== 'all') {
    $where[] = 'f.approval_status = ?';
    $parameters[] = $filter;
}
if ($search !== '') {
    $where[] = '(f.name LIKE ? OR u.name LIKE ? OR f.city LIKE ?)';
    $term = '%' . $search . '%';
    array_push($parameters, $term, $term, $term);
}

$sql = 'SELECT f.id, f.name, f.city, f.address, f.status, f.approval_status,
               f.rejection_reason, f.created_at, u.name AS owner_name, u.email AS owner_email
        FROM facilities f
        INNER JOIN users u ON u.id = f.owner_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY FIELD(f.approval_status, \'pending\', \'approved\', \'rejected\', \'suspended\'), f.id DESC
        LIMIT 200';
$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$facilities = $statement->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facility Approvals | TurfMate</title>
    <link rel="stylesheet" href="../assets/css/super-admin.css?v=1">
    <link rel="stylesheet" href="../assets/css/facility-approvals.css?v=1">
</head>
<body>
    <main class="approval-page">
        <nav class="approval-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="facilities.php">Facility Approvals</a>
            <a href="owners.php">Owner Management</a>
            <a href="users.php">User Management</a>
            <a href="../auth/logout.php">Logout</a>
        </nav>

        <header class="approval-header">
            <div>
                <p class="eyebrow">PLATFORM MODERATION</p>
                <h1>Facility approvals</h1>
                <p>Review venues before they become available to customers.</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice success"><?= approvalEscape($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="notice error"><?= approvalEscape($error) ?></div>
        <?php endif; ?>

        <form class="approval-filters" method="get">
            <label>
                Approval status
                <select name="status">
                    <?php foreach ($allowedFilters as $option): ?>
                        <option value="<?= approvalEscape($option) ?>" <?= $option === $filter ? 'selected' : '' ?>>
                            <?= approvalEscape(ucfirst($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Search facility, owner, or city
                <input name="q" value="<?= approvalEscape($search) ?>" placeholder="Search...">
            </label>
            <button class="action-button" type="submit">Apply filters</button>
        </form>

        <section class="approval-list" aria-label="Facilities">
            <?php if (!$facilities): ?>
                <div class="approval-card">No facilities match your filters.</div>
            <?php endif; ?>

            <?php foreach ($facilities as $facility): ?>
                <article class="approval-card">
                    <div class="approval-card-heading">
                        <div>
                            <h2><?= approvalEscape($facility['name']) ?></h2>
                            <p><?= approvalEscape($facility['city'] ?: $facility['address']) ?></p>
                            <p>Owner: <?= approvalEscape($facility['owner_name']) ?> · <?= approvalEscape($facility['owner_email']) ?></p>
                        </div>
                        <span class="approval-badge <?= approvalEscape($facility['approval_status']) ?>">
                            <?= approvalEscape(ucfirst($facility['approval_status'])) ?>
                        </span>
                    </div>
                    <p class="approval-meta">Owner status: <?= approvalEscape($facility['status']) ?> · Created: <?= approvalEscape($facility['created_at']) ?></p>

                    <?php if ($facility['approval_status'] === 'rejected' && $facility['rejection_reason']): ?>
                        <p class="rejection-reason">Previous rejection: <?= approvalEscape($facility['rejection_reason']) ?></p>
                    <?php endif; ?>

                    <div class="approval-actions">
                        <?php if ($facility['approval_status'] !== 'approved'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= approvalEscape($_SESSION['superadmin_approval_csrf']) ?>">
                                <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="action-button" type="submit">Approve</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($facility['approval_status'] === 'approved'): ?>
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= approvalEscape($_SESSION['superadmin_approval_csrf']) ?>">
                                <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
                                <input type="hidden" name="action" value="suspend">
                                <button class="action-button warning" type="submit">Suspend</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($facility['approval_status'] !== 'rejected'): ?>
                            <form method="post" class="reject-form">
                                <input type="hidden" name="csrf" value="<?= approvalEscape($_SESSION['superadmin_approval_csrf']) ?>">
                                <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <input name="reason" maxlength="1000" required placeholder="Reason for rejection">
                                <button class="action-button danger" type="submit">Reject</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
