<?php
/**
 * TurfMate — Super Admin Dashboard
 * Read-only platform overview. Facility approval is implemented separately.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin']);
require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Escape any database/user content before displaying it. */
function sa_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Execute a count query without accepting SQL from user input. */
function sa_count(PDO $pdo, string $query): int
{
    return (int) $pdo->query($query)->fetchColumn();
}

/** Determine whether a facility approval migration has been applied. */
function sa_has_approval_column(PDO $pdo): bool
{
    $statement = $pdo->query("SHOW COLUMNS FROM facilities LIKE 'approval_status'");
    return (bool) $statement->fetch(PDO::FETCH_ASSOC);
}

try {
    $hasApproval = sa_has_approval_column($pdo);

    $stats = [
        'owners' => sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'owner'"),
        'customers' => sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'customer'"),
        'staff' => sa_count($pdo, "SELECT COUNT(*) FROM users WHERE role = 'staff'"),
        'facilities' => sa_count($pdo, 'SELECT COUNT(*) FROM facilities WHERE deleted_at IS NULL'),
        'bookings' => sa_count($pdo, 'SELECT COUNT(*) FROM bookings'),
        'pending_bookings' => sa_count($pdo, "SELECT COUNT(*) FROM bookings WHERE status = 'pending'"),
    ];

    $stats['pending_facilities'] = $hasApproval
        ? sa_count($pdo, "SELECT COUNT(*) FROM facilities WHERE approval_status = 'pending' AND deleted_at IS NULL")
        : null;

    $facilityStatusColumn = $hasApproval ? 'f.approval_status' : "'not_configured'";

    $facilities = $pdo->query(
        "SELECT f.id, f.name, f.city, f.status, f.created_at,
                {$facilityStatusColumn} AS approval_status,
                u.name AS owner_name
         FROM facilities AS f
         INNER JOIN users AS u ON u.id = f.owner_id
         WHERE f.deleted_at IS NULL
         ORDER BY f.created_at DESC, f.id DESC
         LIMIT 7"
    )->fetchAll(PDO::FETCH_ASSOC);

    $bookings = $pdo->query(
        "SELECT b.id, b.customer_name, b.booking_date, b.status,
                b.total_amount, t.name AS turf_name, f.name AS facility_name
         FROM bookings AS b
         INNER JOIN turfs AS t ON t.id = b.turf_id
         INNER JOIN facilities AS f ON f.id = t.facility_id
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 7"
    )->fetchAll(PDO::FETCH_ASSOC);

    $registrations = $pdo->query(
        "SELECT DATE(created_at) AS registration_day, COUNT(*) AS total
         FROM users
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(created_at)
         ORDER BY registration_day ASC"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $exception) {
    error_log('Super Admin dashboard: ' . $exception->getMessage());
    http_response_code(503);
    exit('Dashboard data is temporarily unavailable. Please check the server logs.');
}

$dailyRegistrations = [];
for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
    $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $dailyRegistrations[] = [
        'label' => date('D', strtotime($date)),
        'count' => (int) ($registrations[$date] ?? 0),
    ];
}

$maxRegistrations = max(1, ...array_column($dailyRegistrations, 'count'));
$adminName = (string) ($_SESSION['name'] ?? 'Super Admin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Super Admin Dashboard | TurfMate</title>
    <link rel="stylesheet" href="../assets/css/super-admin.css?v=2">
</head>
<body>
    <div class="app-layout">
        <aside class="sidebar" aria-label="Super Admin navigation">
            <a class="brand" href="dashboard.php">
                <span class="brand-icon">T</span>
                <span>TurfMate <small>Platform Administration</small></span>
            </a>

            <p class="sidebar-label">PLATFORM MANAGEMENT</p>
            <nav class="sidebar-nav">
                <a class="nav-link active" href="dashboard.php">▦ <span>Dashboard</span></a>
                <a class="nav-link " href="facilities.php">▤ <span>Facility Approvals</span></a>
                <a class="nav-link " href="owners.php">♙ <span>Owner Management</span></a>
                <a class="nav-link " href="users.php">♧ <span>User Management</span></a>
                <a class="nav-link" href="reports.php">▥ <span>Platform Reports</span></a>
            </nav>

            <div class="sidebar-footer">
                <span class="avatar">SA</span>
                <div><strong>Super Admin</strong><small>Platform Administration</small></div>
            </div>
        </aside>

        <div class="main-area">
            <header class="topbar">
                <div>
                    <p class="eyebrow">TURFMATE / PLATFORM OVERVIEW</p>
                    <h1>Super Admin Dashboard</h1>
                </div>
                <a class="logout-link" href="../auth/logout.php">Log out ↗</a>
            </header>

            <main class="content">
                <div class="welcome-row">
                    <div>
                        <h2>Platform at a glance</h2>
                        <p>Monitor accounts, facilities and bookings across TurfMate.</p>
                    </div>
                    <span class="date-chip"><?= sa_escape(date('d M Y')) ?></span>
                </div>

                <?php if (!$hasApproval): ?>
                    <div class="notice" role="status">
                        <strong>Facility approval is not configured yet.</strong>
                        The approval migration and review actions will be added in the next development step.
                        No existing facility statuses have been changed.
                    </div>
                <?php endif; ?>

                <section class="stats-grid" aria-label="Platform statistics">
                    <article class="stat-card">
                        <span class="stat-icon">♙</span>
                        <p>Total owners</p>
                        <strong><?= number_format($stats['owners']) ?></strong>
                        <small>Registered owner accounts</small>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon">♧</span>
                        <p>Total customers</p>
                        <strong><?= number_format($stats['customers']) ?></strong>
                        <small>Registered customers</small>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon">▤</span>
                        <p>Total facilities</p>
                        <strong><?= number_format($stats['facilities']) ?></strong>
                        <small>Not soft-deleted</small>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon">▣</span>
                        <p>Total bookings</p>
                        <strong><?= number_format($stats['bookings']) ?></strong>
                        <small><?= number_format($stats['pending_bookings']) ?> pending booking requests</small>
                    </article>
                    <article class="stat-card emphasis">
                        <span class="stat-icon">◷</span>
                        <p>Pending facility approvals</p>
                        <strong><?= $stats['pending_facilities'] === null ? '—' : number_format($stats['pending_facilities']) ?></strong>
                        <small><?= $hasApproval ? 'Awaiting Super Admin review' : 'Available after migration' ?></small>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon">♟</span>
                        <p>Staff accounts</p>
                        <strong><?= number_format($stats['staff']) ?></strong>
                        <small>Across the platform</small>
                    </article>
                </section>

                <section class="panel chart-panel">
                    <div class="panel-heading">
                        <div><h3>New user registrations</h3><p>Last 7 days, including all account roles</p></div>
                        <span class="subtle-tag">Daily totals</span>
                    </div>
                    <div class="chart" role="img" aria-label="User registrations during the last seven days">
                        <?php foreach ($dailyRegistrations as $day): ?>
                            <div class="chart-column">
                                <span class="chart-count"><?= $day['count'] ?></span>
                                <div class="chart-track">
                                    <div class="chart-bar" style="height: <?= max(3, round($day['count'] / $maxRegistrations * 100)) ?>%"></div>
                                </div>
                                <span class="chart-day"><?= sa_escape($day['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><h3>Recently created facilities</h3><p>Latest facilities across all owners</p></div>
                        <a class="action-link" href="facilities.php?status=pending">Manage approvals →</a>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Facility</th><th>Owner</th><th>City</th><th>Approval</th><th>Created</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$facilities): ?>
                                    <tr><td class="empty-row" colspan="5">No facilities have been created yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($facilities as $facility): ?>
                                        <?php
                                        $approval = (string) $facility['approval_status'];
                                        $approvalClass = in_array($approval, ['pending', 'approved', 'rejected', 'suspended'], true)
                                            ? $approval
                                            : 'neutral';
                                        ?>
                                        <tr>
                                            <td><strong><?= sa_escape($facility['name']) ?></strong></td>
                                            <td><?= sa_escape($facility['owner_name']) ?></td>
                                            <td><?= sa_escape($facility['city'] ?: '—') ?></td>
                                            <td><span class="status status-<?= $approvalClass ?>"><?= sa_escape(ucwords(str_replace('_', ' ', $approval))) ?></span></td>
                                            <td><?= sa_escape(date('d M Y', strtotime($facility['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><h3>Recent platform bookings</h3><p>Read-only view of recent bookings across all facilities</p></div>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr><th>Booking</th><th>Customer</th><th>Facility / Turf</th><th>Date</th><th>Amount</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (!$bookings): ?>
                                    <tr><td class="empty-row" colspan="6">No bookings have been made yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bookings as $booking): ?>
                                        <?php
                                        $bookingStatus = (string) $booking['status'];
                                        $bookingClass = in_array($bookingStatus, ['pending', 'confirmed', 'completed', 'cancelled', 'rejected'], true)
                                            ? $bookingStatus
                                            : 'neutral';
                                        ?>
                                        <tr>
                                            <td><strong>#<?= (int) $booking['id'] ?></strong></td>
                                            <td><?= sa_escape($booking['customer_name']) ?></td>
                                            <td>
                                                <?= sa_escape($booking['facility_name']) ?>
                                                <small class="subline"><?= sa_escape($booking['turf_name']) ?></small>
                                            </td>
                                            <td><?= sa_escape($booking['booking_date']) ?></td>
                                            <td>Rs. <?= number_format((float) $booking['total_amount'], 0) ?></td>
                                            <td><span class="status status-<?= $bookingClass ?>"><?= sa_escape(ucfirst($bookingStatus)) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
            <footer class="page-footer">TurfMate · Platform Administration · Read-only dashboard</footer>
        </div>
    </div>
</body>
</html>
