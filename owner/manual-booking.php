<?php

declare(strict_types=1);

require_once __DIR__ . '/_booking_helpers.php';
require_once __DIR__ . '/_layout.php';

$turfs = ob_turfs($pdo, $ownerId);
$error = '';

$v = [
    'turf_id' => '',
    'customer_name' => '',
    'customer_phone' => '',
    'booking_date' => '',
    'start_time' => '',
    'end_time' => '',
    'source' => 'walk_in',
    'advance' => '0',
    'method' => 'cash',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_verify_csrf();

    foreach ($v as $k => $default) {
        $v[$k] = trim((string) ($_POST[$k] ?? $default));
    }

    $turf = (int) $v['turf_id'];
    $locked = false;

    try {
        if (!in_array($turf, array_map(fn($x) => (int) $x['id'], $turfs), true)) {
            throw new RuntimeException('Select a turf belonging to your active facilities.');
        }

        if ($v['customer_name'] === '' || mb_strlen($v['customer_name']) > 150) {
            throw new RuntimeException('Customer name is required (max 150 characters).');
        }

        if (!preg_match('/^[+0-9 ()-]{7,30}$/', $v['customer_phone'])) {
            throw new RuntimeException('Enter a valid customer phone number.');
        }

        if (!in_array($v['source'], ['walk_in', 'phone', 'whatsapp'], true)) {
            throw new RuntimeException('Invalid booking source.');
        }

        $a = tmDate($v['booking_date']);
        $start = tmTime($v['start_time']);
        $end = tmTime($v['end_time']);

        if ($end <= $start) {
            throw new RuntimeException('For the first version, manual bookings must end on the same day. Overnight bookings require a separate multi-segment flow.');
        }

        [$from, $until] = tmWindow($v['booking_date'], $start, $end);

        $advance = filter_var($v['advance'], FILTER_VALIDATE_FLOAT);

        if ($advance === false) {
            throw new RuntimeException('Enter a valid advance amount.');
        }

        $method = ob_method($v['method']);

        ob_lock($pdo, $turf);
        $locked = true;

        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM turfs WHERE id = ? FOR UPDATE')->execute([$turf]);

        tmAvailability($pdo, $turf, $from, $until);

        $quote = tmQuote($pdo, $turf, $from, $until);
        $total = (float) $quote['total'];

        ob_validate_payment((float) $advance, $total);

        $ins = $pdo->prepare(
            "INSERT INTO bookings (turf_id, customer_id, customer_name, customer_phone, booking_date, start_time, end_time, total_amount, status, source, created_by, notes)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        );
        $ins->execute([
            $turf,
            $v['customer_name'],
            $v['customer_phone'],
            $v['booking_date'],
            $start . ':00',
            $end . ':00',
            $total,
            $v['source'],
            $ownerId,
            substr($v['notes'], 0, 1000),
        ]);

        $id = (int) $pdo->lastInsertId();

        if ((float) $advance > 0) {
            $p = $pdo->prepare(
                "INSERT INTO payments (booking_id, amount, method, status, notes)
                 VALUES (?, ?, ?, 'received', 'Advance at booking')"
            );
            $p->execute([$id, $advance, $method]);
        }

        $pdo->commit();
        tm_flash('success', 'Manual booking #' . $id . ' created. Total: ' . tm_money($total));
        tm_redirect('booking-details.php?id=' . $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    } finally {
        if ($locked) {
            ob_unlock($pdo, $turf);
        }
    }
}

tm_page_header('Add Manual Booking', 'bookings', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-bookings.css">

<div class="ob-heading">
    <div>
        <h2>Create a booking</h2>
        <p>Manual bookings are confirmed immediately. Price is calculated from your pricing rules.</p>
    </div>
    <a class="ob-secondary" href="bookings.php">← All Bookings</a>
</div>

<?php if ($error): ?>
    <div class="ob-error"><?= tm_e($error) ?></div>
<?php endif; ?>

<?php if (!$turfs): ?>
    <div class="ob-error">No active turfs found. Please add an active facility and turf first.</div>
<?php endif; ?>

<form class="ob-panel ob-form" method="post">
    <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">

    <div class="ob-grid">
        <label>Turf *
            <select required name="turf_id">
                <option value="">Select turf</option>
                <?php foreach ($turfs as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (string) $t['id'] === $v['turf_id'] ? 'selected' : '' ?>>
                        <?= tm_e($t['facility_name'] . ' — ' . $t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Booking Source *
            <select name="source">
                <?php
                $sources = [
                    'walk_in' => 'Walk-in',
                    'phone' => 'Phone',
                    'whatsapp' => 'WhatsApp',
                ];

                foreach ($sources as $x => $label):
                ?>
                    <option value="<?= $x ?>" <?= $v['source'] === $x ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Customer Name *
            <input required maxlength="150" name="customer_name" value="<?= tm_e($v['customer_name']) ?>">
        </label>

        <label>Customer Phone *
            <input required maxlength="30" name="customer_phone" value="<?= tm_e($v['customer_phone']) ?>">
        </label>

        <label>Booking Date *
            <input required type="date" min="<?= date('Y-m-d') ?>" name="booking_date" value="<?= tm_e($v['booking_date']) ?>">
        </label>

        <label>Start Time *
            <input required type="time" name="start_time" step="1800" value="<?= tm_e($v['start_time']) ?>">
        </label>

        <label>End Time *
            <input required type="time" name="end_time" step="1800" value="<?= tm_e($v['end_time']) ?>">
        </label>

        <label>Advance Received (Rs.)
            <input required type="number" min="0" step="0.01" name="advance" value="<?= tm_e($v['advance']) ?>">
        </label>

        <label>Advance Method
            <select name="method">
                <?php
                $methods = [
                    'cash' => 'Cash',
                    'jazzcash' => 'JazzCash',
                    'easypaisa' => 'Easypaisa',
                    'bank_transfer' => 'Bank transfer',
                    'other' => 'Other',
                ];

                foreach ($methods as $x => $label):
                ?>
                    <option value="<?= $x ?>" <?= $v['method'] === $x ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="ob-wide">Notes
            <textarea name="notes" rows="3"><?= tm_e($v['notes']) ?></textarea>
        </label>
    </div>

    <p class="ob-hint">Availability, final price, and payment limits are validated when you save. A pending customer request also reserves its time slot.</p>

    <button class="ob-btn" type="submit" <?= !$turfs ? 'disabled' : '' ?>>Create Confirmed Booking</button>
</form>

<?php tm_page_footer(); ?>