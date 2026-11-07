<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../customer/booking_engine.php';

$turfsStmt = $db->prepare(
    "SELECT t.id, t.name, f.name AS facility_name, f.id AS facility_id
     FROM turfs t
     JOIN facilities f ON f.id = t.facility_id
     JOIN staff_assignments sa ON sa.facility_id = f.id
     JOIN users owner ON owner.id = f.owner_id
     WHERE sa.staff_id = ? AND sa.status = 'active'
       AND f.deleted_at IS NULL AND f.status = 'active'
       AND f.approval_status = 'approved' AND owner.status = 'active'
       AND t.deleted_at IS NULL AND t.status = 'active'
     ORDER BY f.name, t.name"
);
$turfsStmt->execute([$staffId]);
$turfs = $turfsStmt->fetchAll(PDO::FETCH_ASSOC);
$validTurfs = array_map('intval', array_column($turfs, 'id'));
$error = '';
$values = [
    'turf_id' => '', 'customer_name' => '', 'customer_phone' => '',
    'booking_date' => '', 'start_time' => '', 'end_time' => '',
    'source' => 'walk_in', 'advance' => '0', 'method' => 'cash', 'notes' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }
    $turfId = (int) $values['turf_id'];
    $locked = false;
    try {
        if (!hash_equals(staffCsrf(), (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session expired. Refresh and retry.');
        }
        if (!in_array($turfId, $validTurfs, true)) {
            throw new RuntimeException('Select an active turf in your assigned facilities.');
        }
        if ($values['customer_name'] === '' || mb_strlen($values['customer_name']) > 150) {
            throw new RuntimeException('Enter a customer name (up to 150 characters).');
        }
        if (!preg_match('/^[+0-9 ()-]{7,30}$/', $values['customer_phone'])) {
            throw new RuntimeException('Enter a valid customer phone number.');
        }
        if (!in_array($values['source'], ['walk_in', 'phone', 'whatsapp'], true)) {
            throw new RuntimeException('Invalid booking source.');
        }
        $start = tmTime($values['start_time']);
        $end = tmTime($values['end_time']);
        if ($end <= $start) {
            throw new RuntimeException('Manual bookings must start and finish on the same date.');
        }
        [$from, $until] = tmWindow($values['booking_date'], $start, $end);
        $advance = filter_var($values['advance'], FILTER_VALIDATE_FLOAT);
        if ($advance === false || !is_finite((float) $advance)) {
            throw new RuntimeException('Invalid advance amount.');
        }
        if (!in_array($values['method'], ['cash', 'jazzcash', 'easypaisa', 'bank_transfer', 'other'], true)) {
            throw new RuntimeException('Invalid payment method.');
        }
        $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute(['turfmate_booking_' . $turfId]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Schedule is busy. Please retry.');
        }
        $locked = true;
        $db->beginTransaction();
        $rowLock = $db->prepare('SELECT facility_id FROM turfs WHERE id = ? FOR UPDATE');
        $rowLock->execute([$turfId]);
        $facilityId = (int) $rowLock->fetchColumn();
        if (!$facilityId || !staffAllowed($db, $staffId, $facilityId)) {
            throw new RuntimeException('Facility access is no longer available.');
        }
        tmAvailability($db, $turfId, $from, $until);
        $quote = tmQuote($db, $turfId, $from, $until);
        $total = (float) $quote['total'];
        if ((float) $advance < 0 || (float) $advance > $total) {
            throw new RuntimeException('Advance cannot exceed the booking price.');
        }
        $insert = $db->prepare(
            "INSERT INTO bookings
             (turf_id, customer_id, customer_name, customer_phone, booking_date,
              start_time, end_time, total_amount, status, source, created_by, notes)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        );
        $insert->execute([
            $turfId, $values['customer_name'], $values['customer_phone'],
            $values['booking_date'], $start . ':00', $end . ':00',
            $total, $values['source'], $staffId, substr($values['notes'], 0, 1000),
        ]);
        $bookingId = (int) $db->lastInsertId();
        if ((float) $advance > 0) {
            $payment = $db->prepare(
                "INSERT INTO payments (booking_id, amount, method, status, notes)
                 VALUES (?, ?, ?, 'received', 'Advance at staff booking')"
            );
            $payment->execute([$bookingId, $advance, $values['method']]);
        }
        $db->commit();
        header('Location: bookings.php?created=' . $bookingId);
        exit;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Staff manual booking: ' . $exception->getMessage());
        $error = $exception instanceof PDOException
            ? 'Booking could not be saved. Check server logs.'
            : $exception->getMessage();
    } finally {
        if ($locked) {
            $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
            $unlock->execute(['turfmate_booking_' . $turfId]);
        }
    }
}
staffLayoutStart('Manual Booking', 'manual');
?>
<div class="staff-page">
    <h1>New Manual Booking</h1>
    <p>Create a confirmed walk-in, phone or WhatsApp booking at your assigned facility.</p>
    <?php if ($error): ?><div class="staff-alert" role="alert"><?= staffEscape($error) ?></div><?php endif; ?>
    <?php if (!$turfs): ?><div class="staff-alert">No approved assigned facilities with active turfs.</div><?php endif; ?>
    <form method="post" class="staff-manual-form">
        <input type="hidden" name="csrf" value="<?= staffEscape(staffCsrf()) ?>">
        <label>Turf<select name="turf_id" required>
            <option value="">Choose a turf</option>
            <?php foreach ($turfs as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (string) $t['id'] === $values['turf_id'] ? 'selected' : '' ?>><?= staffEscape($t['facility_name'] . ' — ' . $t['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Source<select name="source">
            <?php foreach (['walk_in' => 'Walk-in', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $values['source'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Customer name<input name="customer_name" maxlength="150" required value="<?= staffEscape($values['customer_name']) ?>"></label>
        <label>Customer phone<input name="customer_phone" maxlength="30" required value="<?= staffEscape($values['customer_phone']) ?>"></label>
        <label>Booking date<input type="date" name="booking_date" min="<?= date('Y-m-d') ?>" required value="<?= staffEscape($values['booking_date']) ?>"></label>
        <label>Start time<input type="time" name="start_time" required value="<?= staffEscape($values['start_time']) ?>"></label>
        <label>End time<input type="time" name="end_time" required value="<?= staffEscape($values['end_time']) ?>"></label>
        <label>Advance received (Rs.)<input type="number" min="0" step="0.01" name="advance" required value="<?= staffEscape($values['advance']) ?>"></label>
        <label>Payment method<select name="method">
            <?php foreach (['cash' => 'Cash', 'jazzcash' => 'JazzCash', 'easypaisa' => 'Easypaisa', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $values['method'] === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select></label>
        <label class="wide">Notes<textarea name="notes" rows="3"><?= staffEscape($values['notes']) ?></textarea></label>
        <p class="wide">The price is calculated automatically using the existing pricing engine. Same-day time ranges only.</p>
        <button type="submit" <?= !$turfs ? 'disabled' : '' ?>>Create Confirmed Booking</button>
    </form>
</div>
<?php staffLayoutEnd(); ?>
