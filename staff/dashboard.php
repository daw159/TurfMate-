<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$facilities = staffAssignments($db, $staffId);
$stats = ['today' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0];
$recent = [];
$error = '';

try {
    $query = $db->prepare(<<<'SQL'
        SELECT
            SUM(b.booking_date = CURRENT_DATE() AND b.status <> 'rejected') AS today,
            SUM(b.status = 'pending') AS pending,
            SUM(b.status = 'confirmed') AS confirmed,
            SUM(b.status = 'completed') AS completed
        FROM bookings b
        JOIN turfs t ON t.id = b.turf_id
        JOIN staff_assignments sa ON sa.facility_id = t.facility_id
        JOIN facilities f ON f.id = sa.facility_id
        JOIN users owner ON owner.id = f.owner_id
        WHERE sa.staff_id = ? AND sa.status = 'active'
          AND f.status = 'active' AND f.deleted_at IS NULL
          AND owner.status = 'active'
    SQL);
    $query->execute([$staffId]);
    $stats = array_map('intval', $query->fetch(PDO::FETCH_ASSOC) ?: $stats);

    $query = $db->prepare(<<<'SQL'
        SELECT b.id, b.customer_name, b.booking_date, b.start_time,
               b.status, t.name AS turf_name, f.name AS facility_name
        FROM bookings b
        JOIN turfs t ON t.id = b.turf_id
        JOIN facilities f ON f.id = t.facility_id
        JOIN users owner ON owner.id = f.owner_id
        JOIN staff_assignments sa ON sa.facility_id = f.id
        WHERE sa.staff_id = ? AND sa.status = 'active'
          AND f.status = 'active' AND f.deleted_at IS NULL
          AND owner.status = 'active'
        ORDER BY b.created_at DESC LIMIT 8
    SQL);
    $query->execute([$staffId]);
    $recent = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Staff dashboard: ' . $exception->getMessage());
    $error = 'Unable to load booking statistics. Check that the migration was applied.';
}

staffLayoutStart('Dashboard', 'dashboard');
?>
<section class="heading">
    <div><p class="eyebrow">OVERVIEW</p><h1>Staff Dashboard</h1><p>Monitor bookings for your assigned facilities.</p></div>
    <a class="button" href="bookings.php">Manage bookings →</a>
</section>
<?php if ($error): ?><div class="alert"><?= staffEscape($error) ?></div><?php endif; ?>
<div class="stats">
    <?php foreach (['today' => "Today's bookings", 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed'] as $key => $label): ?>
        <div class="card stat"><span><?= staffEscape($label) ?></span><strong><?= $stats[$key] ?? 0 ?></strong></div>
    <?php endforeach; ?>
</div>
<div class="grid">
    <section class="card">
        <div class="card-title"><h2>Recent bookings</h2><a href="bookings.php">View all →</a></div>
        <div class="table-scroll"><table><thead><tr><th>Customer</th><th>Turf</th><th>Date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recent as $booking): ?>
            <tr><td><?= staffEscape($booking['customer_name']) ?></td><td><?= staffEscape($booking['turf_name']) ?></td>
                <td><?= staffEscape($booking['booking_date']) ?></td><td><span class="pill"><?= staffEscape(ucfirst($booking['status'])) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="4">No bookings yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="card"><div class="card-title"><h2>Assigned facilities</h2></div>
        <?php foreach ($facilities as $facility): ?>
            <div class="facility"><strong><?= staffEscape($facility['name']) ?></strong><small><?= staffEscape($facility['city'] ?: 'Location not set') ?></small></div>
        <?php endforeach; ?>
        <?php if (!$facilities): ?><p class="muted">No facilities assigned yet. Ask your owner to assign you.</p><?php endif; ?>
    </section>
</div>
<?php staffLayoutEnd(); ?>
