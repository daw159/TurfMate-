<?php

declare(strict_types=1);

require_once __DIR__ . '/_booking_helpers.php';
require_once __DIR__ . '/_layout.php';

$status = (string) ($_GET['status'] ?? 'all');
$allowed = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'rejected'];

if (!in_array($status, $allowed, true)) {
    $status = 'all';
}

$sql = "SELECT b.*, t.name turf_name, f.name facility_name,
               (SELECT COALESCE(SUM(CASE WHEN p.status = 'received' THEN p.amount ELSE -p.amount END), 0)
                FROM payments p
                WHERE p.booking_id = b.id) received
        FROM bookings b
        JOIN turfs t ON t.id = b.turf_id
        JOIN facilities f ON f.id = t.facility_id
        WHERE f.owner_id = ?";
$args = [$ownerId];

if ($status !== 'all') {
    $sql .= ' AND b.status = ?';
    $args[] = $status;
}

$sql .= ' ORDER BY b.booking_date DESC, b.start_time DESC, b.id DESC LIMIT 300';

$q = $pdo->prepare($sql);
$q->execute($args);
$rows = $q->fetchAll();

$stats = $pdo->prepare(
    'SELECT b.status, COUNT(*) n
     FROM bookings b
     JOIN turfs t ON t.id = b.turf_id
     JOIN facilities f ON f.id = t.facility_id
     WHERE f.owner_id = ?
     GROUP BY b.status'
);
$stats->execute([$ownerId]);

$counts = array_fill_keys(array_slice($allowed, 1), 0);

foreach ($stats->fetchAll() as $r) {
    $counts[$r['status']] = (int) $r['n'];
}

tm_page_header('Booking Management', 'bookings', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-bookings.css">

<div class="ob-heading">
    <div>
        <h2>Manage reservations</h2>
        <p>Review customer requests and manage walk-in, phone and WhatsApp bookings.</p>
    </div>
    <a class="ob-btn" href="manual-booking.php">+ Add Booking</a>
</div>

<div class="ob-stats">
    <?php
    $statCards = [
        'pending' => 'Pending requests',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    foreach ($statCards as $key => $label):
    ?>
        <a class="ob-stat" href="bookings.php?status=<?= tm_e($key) ?>">
            <span><?= tm_e($label) ?></span>
            <strong><?= $counts[$key] ?? 0 ?></strong>
        </a>
    <?php endforeach; ?>
</div>

<div class="ob-panel">
    <div class="ob-tabs">
        <?php foreach ($allowed as $s): ?>
            <a class="<?= $status === $s ? 'selected' : '' ?>" href="bookings.php?status=<?= tm_e($s) ?>"><?= ucfirst($s) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="ob-scroll">
        <table class="ob-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Facility / Turf</th>
                    <th>Date / Time</th>
                    <th>Source</th>
                    <th>Total</th>
                    <th>Received</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $b): ?>
                    <tr>
                        <td>#<?= (int) $b['id'] ?></td>
                        <td>
                            <b><?= tm_e($b['customer_name']) ?></b>
                            <small><?= tm_e($b['customer_phone']) ?></small>
                        </td>
                        <td>
                            <?= tm_e($b['facility_name']) ?>
                            <small><?= tm_e($b['turf_name']) ?></small>
                        </td>
                        <td>
                            <?= tm_e($b['booking_date']) ?>
                            <small><?= substr($b['start_time'], 0, 5) ?>–<?= substr($b['end_time'], 0, 5) ?></small>
                        </td>
                        <td><?= tm_e($b['source']) ?></td>
                        <td><?= tm_money($b['total_amount']) ?></td>
                        <td><?= tm_money($b['received']) ?></td>
                        <td>
                            <span class="ob-badge ob-<?= tm_e($b['status']) ?>"><?= tm_e(ucfirst($b['status'])) ?></span>
                        </td>
                        <td>
                            <a class="ob-link" href="booking-details.php?id=<?= (int) $b['id'] ?>">Details →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$rows): ?>
        <p class="ob-empty">No bookings found. Use “Add Booking” to create your first reservation.</p>
    <?php endif; ?>
</div>

<?php tm_page_footer(); ?>