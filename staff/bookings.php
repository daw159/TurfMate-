<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$notice = '';
$error = '';
$filter = (string) ($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'rejected'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(staffCsrf(), (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid form token. Refresh the page.');
        }
        $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
        $action = (string) ($_POST['action'] ?? '');
        if (!$bookingId || !in_array($action, ['confirmed', 'rejected', 'completed', 'cancelled'], true)) {
            throw new RuntimeException('Invalid booking action.');
        }

        // Match the Owner lock order: group first, then booking rows.
        // This avoids conflicting status changes during simultaneous reviews.
        $db->beginTransaction();
        $groupQuery = $db->prepare('SELECT group_id FROM tm_booking_group_items WHERE booking_id = ? LIMIT 1');
        $groupQuery->execute([$bookingId]);
        $groupId = $groupQuery->fetchColumn();
        $groupStatus = null;
        if ($groupId !== false) {
            $groupLock = $db->prepare('SELECT status FROM tm_booking_groups WHERE id = ? FOR UPDATE');
            $groupLock->execute([(int) $groupId]);
            $groupStatus = $groupLock->fetchColumn();
        }

        $query = $db->prepare(<<<'SQL'
            SELECT b.id, b.status, t.facility_id
            FROM bookings b
            JOIN turfs t ON t.id = b.turf_id
            WHERE b.id = ? FOR UPDATE
        SQL);
        $query->execute([$bookingId]);
        $booking = $query->fetch(PDO::FETCH_ASSOC);
        if (!$booking || !staffAllowed($db, $staffId, (int) $booking['facility_id'])) {
            throw new RuntimeException('Booking not found in your assigned facilities.');
        }
        $transitions = [
            'pending' => ['confirmed', 'rejected'],
            'confirmed' => ['completed', 'cancelled'],
        ];
        if (!in_array($action, $transitions[$booking['status']] ?? [], true)) {
            throw new RuntimeException('This booking cannot be changed to that status.');
        }

        // Grouped online bookings must change together, including overnight segments.
        if ($groupId !== false) {
            if ($groupStatus !== $booking['status']) {
                throw new RuntimeException('Booking status changed. Refresh and try again.');
            }
            $update = $db->prepare(<<<'SQL'
                UPDATE bookings b
                JOIN tm_booking_group_items i ON i.booking_id = b.id
                SET b.status = ?
                WHERE i.group_id = ?
            SQL);
            $update->execute([$action, (int) $groupId]);
            $update = $db->prepare('UPDATE tm_booking_groups SET status = ? WHERE id = ?');
            $update->execute([$action, (int) $groupId]);
        } else {
            $update = $db->prepare('UPDATE bookings SET status = ? WHERE id = ?');
            $update->execute([$action, $bookingId]);
        }
        $db->commit();
        $notice = 'Booking updated successfully.';
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception instanceof PDOException
            ? 'Unable to update booking. Check that booking tables are installed.'
            : $exception->getMessage();
        error_log('Staff booking action: ' . $exception->getMessage());
    }
}

$rows = [];
try {
    // Show one row per online booking group; ungrouped bookings appear individually.
    $sql = <<<'SQL'
        SELECT b.id, b.customer_name, b.customer_phone, b.booking_date,
               b.start_time, b.end_time, b.status, b.total_amount,
               b.source, t.name AS turf_name, f.name AS facility_name,
               i.group_id, g.start_at, g.end_at,
               COALESCE(g.total_amount, b.total_amount) AS display_total
        FROM bookings b
        JOIN turfs t ON t.id = b.turf_id
        JOIN facilities f ON f.id = t.facility_id
        JOIN users owner ON owner.id = f.owner_id
        JOIN staff_assignments sa ON sa.facility_id = f.id
        LEFT JOIN tm_booking_group_items i ON i.booking_id = b.id
        LEFT JOIN tm_booking_groups g ON g.id = i.group_id
        WHERE sa.staff_id = ? AND sa.status = 'active'
          AND f.status = 'active' AND f.deleted_at IS NULL
          AND owner.status = 'active'
          AND (i.group_id IS NULL OR b.id = (
              SELECT MIN(i2.booking_id)
              FROM tm_booking_group_items i2 WHERE i2.group_id = i.group_id
          ))
    SQL;
    $params = [$staffId];
    if ($filter !== 'all') {
        $sql .= ' AND b.status = ?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY b.created_at DESC, b.id DESC LIMIT 150';
    $query = $db->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Staff booking list: ' . $exception->getMessage());
    $error = 'Unable to load bookings. Check the required database tables.';
}

staffLayoutStart('Bookings', 'bookings');
?>
<section class="heading"><div><p class="eyebrow">DAILY OPERATIONS</p><h1>Booking Management</h1>
    <p>Approve or reject requests, and complete or cancel confirmed bookings.</p></div></section>
<?php if ($notice): ?><div class="success"><?= staffEscape($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert"><?= staffEscape($error) ?></div><?php endif; ?>
<section class="card">
    <div class="filters">
        <?php foreach ($allowedFilters as $status): ?>
            <a class="filter <?= $filter === $status ? 'selected' : '' ?>" href="?status=<?= staffEscape($status) ?>">
                <?= staffEscape(ucfirst($status)) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-scroll"><table><thead><tr>
        <th>Booking</th><th>Customer</th><th>Facility / Turf</th><th>Schedule</th><th>Amount</th><th>Status</th><th>Actions</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td>#<?= (int) ($row['group_id'] ?: $row['id']) ?><small><?= staffEscape($row['source']) ?></small></td>
            <td><?= staffEscape($row['customer_name']) ?><small><?= staffEscape($row['customer_phone']) ?></small></td>
            <td><?= staffEscape($row['facility_name']) ?><small><?= staffEscape($row['turf_name']) ?></small></td>
            <td><?= staffEscape($row['start_at'] ?: $row['booking_date'] . ' ' . $row['start_time']) ?>
                <small>to <?= staffEscape($row['end_at'] ?: $row['end_time']) ?></small></td>
            <td>Rs. <?= number_format((float) $row['display_total'], 2) ?></td>
            <td><span class="pill"><?= staffEscape(ucfirst($row['status'])) ?></span></td>
            <td class="actions">
                <?php
                $actions = $row['status'] === 'pending'
                    ? ['confirmed' => 'Approve', 'rejected' => 'Reject']
                    : ($row['status'] === 'confirmed'
                        ? ['completed' => 'Complete', 'cancelled' => 'Cancel']
                        : []);
                ?>
                <?php foreach ($actions as $action => $label): ?>
                    <form method="post" onsubmit="return confirm('<?= staffEscape($label) ?> this booking?');">
                        <input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>">
                        <input type="hidden" name="booking_id" value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="action" value="<?= staffEscape($action) ?>">
                        <button class="small-button" type="submit"><?= staffEscape($label) ?></button>
                    </form>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7">No bookings found for your assigned facilities.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php staffLayoutEnd(); ?>
