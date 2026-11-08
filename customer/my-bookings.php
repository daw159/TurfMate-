<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_engine.php';
requireRole(['customer']);

$db = bdb();
$rows = [];
$error = '';

try {
    $query = $db->prepare(
        'SELECT g.*, t.name AS turf_name
         FROM tm_booking_groups g
         JOIN turfs t ON t.id = g.turf_id
         WHERE g.customer_id = ?
         ORDER BY g.created_at DESC'
    );
    $query->execute([bookingUserId()]);
    $rows = $query->fetchAll();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

bookingHead('My Bookings');
?>
<main class="wrap">
    <div class="heading">
        <h1>My Bookings</h1>
        <p>Track your games and pending requests.</p>
        <a class="btn" href="turfs.php">Explore Turfs</a>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= esc($error) ?></div>
    <?php endif; ?>

    <div class="panel scroll">
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Turf</th>
                    <th>From</th>
                    <th>Until</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= esc($row['id']) ?></td>
                        <td><?= esc($row['turf_name']) ?></td>
                        <td><?= esc($row['start_at']) ?></td>
                        <td><?= esc($row['end_at']) ?></td>
                        <td>Rs. <?= number_format((float) $row['total_amount'], 2) ?></td>
                        <td>
    <span class="status status-<?= esc(strtolower($row['status'])) ?>">
        <?= esc(ucfirst($row['status'])) ?>
    </span>
</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$rows): ?>
            <p class="customer-empty-bookings">No bookings yet. <a href="turfs.php">Browse available turfs</a>.</p>
        <?php endif; ?>
    </div>
</main>
<?php bookingFoot(); ?>
