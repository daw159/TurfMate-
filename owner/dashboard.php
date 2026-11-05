<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout.php';

function tm_scalar(PDO $pdo, string $sql, int $ownerId)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ownerId]);
    return $stmt->fetchColumn();
}

$facilityCount = (int) tm_scalar($pdo, 'SELECT COUNT(*) FROM facilities WHERE owner_id = ?', $ownerId);
$turfCount = (int) tm_scalar($pdo, 'SELECT COUNT(*) FROM turfs t INNER JOIN facilities f ON f.id = t.facility_id WHERE f.owner_id = ?', $ownerId);
$bookingCount = (int) tm_scalar($pdo, 'SELECT COUNT(*) FROM bookings b INNER JOIN turfs t ON t.id = b.turf_id INNER JOIN facilities f ON f.id = t.facility_id WHERE f.owner_id = ?', $ownerId);
$pendingCount = (int) tm_scalar($pdo, "SELECT COUNT(*) FROM bookings b INNER JOIN turfs t ON t.id = b.turf_id INNER JOIN facilities f ON f.id = t.facility_id WHERE f.owner_id = ? AND b.status = 'pending'", $ownerId);
$bookedValue = (float) tm_scalar($pdo, "SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b INNER JOIN turfs t ON t.id = b.turf_id INNER JOIN facilities f ON f.id = t.facility_id WHERE f.owner_id = ? AND b.status IN ('confirmed','completed')", $ownerId);

$recentQuery = $pdo->prepare('SELECT b.id, b.customer_name, b.booking_date, b.start_time, b.end_time, b.total_amount, b.status, t.name AS turf_name, f.name AS facility_name FROM bookings b INNER JOIN turfs t ON t.id = b.turf_id INNER JOIN facilities f ON f.id = t.facility_id WHERE f.owner_id = ? ORDER BY b.created_at DESC, b.id DESC LIMIT 8');
$recentQuery->execute([$ownerId]);
$recentBookings = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

tm_page_header('Dashboard', 'dashboard', $owner);
?>
<div class="intro-row"><div><h2>Welcome back, <?= tm_e(explode(' ', (string) $owner['name'])[0]) ?>!</h2><p>Here's what's happening across your sports facilities.</p></div><a class="btn btn-primary" href="facilities.php?new=1">+ Add Facility</a></div>
<div class="stats-grid">
    <div class="stat-card"><span class="stat-icon">▤</span><div class="stat-label">Total facilities</div><div class="stat-number"><?= $facilityCount ?></div><a href="facilities.php">Manage facilities →</a></div>
    <div class="stat-card"><span class="stat-icon">◫</span><div class="stat-label">Total turfs</div><div class="stat-number"><?= $turfCount ?></div><a href="turfs.php">Manage turfs →</a></div>
    <div class="stat-card"><span class="stat-icon">▣</span><div class="stat-label">All bookings</div><div class="stat-number"><?= $bookingCount ?></div><span class="stat-note"><?= $pendingCount ?> pending approval</span></div>
    <div class="stat-card"><span class="stat-icon">Rs</span><div class="stat-label">Confirmed booking value</div><div class="stat-number money"><?= tm_money($bookedValue) ?></div><span class="stat-note">Booking totals, not cash collected</span></div>
</div>
<section class="panel">
    <div class="panel-heading"><div><h2>Recent bookings</h2><p>Your eight most recently created bookings.</p></div></div>
    <?php if (!$recentBookings): ?>
        <div class="empty-state"><div class="empty-symbol">▣</div><strong>No bookings yet</strong><p>Once bookings are created, they will appear here.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Facility / Turf</th><th>Date & Time</th><th>Amount</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recentBookings as $booking): ?>
            <tr><td><strong><?= tm_e($booking['customer_name']) ?></strong></td><td><?= tm_e($booking['facility_name']) ?><span class="subline"><?= tm_e($booking['turf_name']) ?></span></td><td><?= tm_e($booking['booking_date']) ?><span class="subline"><?= tm_e(substr($booking['start_time'], 0, 5)) ?> – <?= tm_e(substr($booking['end_time'], 0, 5)) ?></span></td><td><?= tm_money($booking['total_amount']) ?></td><td><span class="pill status-<?= tm_e($booking['status']) ?>"><?= tm_e(ucfirst($booking['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php tm_page_footer(); ?>
