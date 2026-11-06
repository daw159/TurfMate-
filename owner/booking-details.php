<?php

declare(strict_types=1);

require_once __DIR__ . '/_booking_helpers.php';
require_once __DIR__ . '/_layout.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id) {
    http_response_code(400);
    exit('Invalid booking ID');
}

try {
    $b = ob_get_booking($pdo, $ownerId, (int) $id);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Booking not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    tm_verify_csrf();

    try {
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($amount === false || $amount <= 0) {
            throw new RuntimeException('Payment must be greater than zero.');
        }

        $method = ob_method((string) ($_POST['method'] ?? ''));

        $pdo->beginTransaction();
        $b = ob_get_booking($pdo, $ownerId, (int) $id, true);

        if (in_array($b['status'], ['cancelled', 'rejected'], true)) {
            throw new RuntimeException('Cannot add payments to cancelled or rejected bookings.');
        }

        $paid = ob_paid($pdo, (int) $id);

        if (round($paid + (float) $amount, 2) > round((float) $b['total_amount'], 2)) {
            throw new RuntimeException('Payment is higher than remaining balance.');
        }

        $q = $pdo->prepare(
            "INSERT INTO payments (booking_id, amount, method, status, notes)
             VALUES (?, ?, ?, 'received', ?)"
        );
        $q->execute([$id, $amount, $method, substr(trim((string) ($_POST['notes'] ?? '')), 0, 255)]);

        $pdo->commit();
        tm_flash('success', 'Payment recorded successfully.');
        tm_redirect('booking-details.php?id=' . $id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

$q = $pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY paid_at DESC, id DESC');
$q->execute([$id]);
$payments = $q->fetchAll(PDO::FETCH_ASSOC);

$paid = ob_paid($pdo, (int) $id);
$remaining = max(0, round((float) $b['total_amount'] - $paid, 2));

tm_page_header('Booking #' . (int) $id, 'bookings', $owner);
?>
<link rel="stylesheet" href="../assets/css/owner-bookings.css">

<div class="ob-heading">
    <div>
        <h2>Booking #<?= (int) $id ?></h2>
        <p><?= tm_e($b['customer_name']) ?> · <?= tm_e($b['facility_name']) ?> / <?= tm_e($b['turf_name']) ?></p>
    </div>
    <a class="ob-secondary" href="bookings.php">← All Bookings</a>
</div>

<?php if ($error): ?>
    <div class="ob-error"><?= tm_e($error) ?></div>
<?php endif; ?>

<div class="ob-stats">
    <div class="ob-stat">
        <span>Booking total</span>
        <strong><?= tm_money($b['total_amount']) ?></strong>
    </div>
    <div class="ob-stat">
        <span>Received</span>
        <strong><?= tm_money($paid) ?></strong>
    </div>
    <div class="ob-stat">
        <span>Remaining</span>
        <strong><?= tm_money($remaining) ?></strong>
    </div>
    <div class="ob-stat">
        <span>Booking status</span>
        <strong>
            <span class="ob-badge ob-<?= tm_e($b['status']) ?>"><?= tm_e(ucfirst($b['status'])) ?></span>
        </strong>
    </div>
</div>

<div class="ob-panel ob-detail">
    <h3>Reservation details</h3>
    <dl>
        <dt>Customer</dt>
        <dd><?= tm_e($b['customer_name']) ?></dd>
        <dt>Phone</dt>
        <dd><?= tm_e($b['customer_phone']) ?></dd>
        <dt>Facility</dt>
        <dd><?= tm_e($b['facility_name']) ?></dd>
        <dt>Turf</dt>
        <dd><?= tm_e($b['turf_name']) ?></dd>
        <dt>Date</dt>
        <dd><?= tm_e($b['booking_date']) ?></dd>
        <dt>Time</dt>
        <dd><?= tm_e(substr($b['start_time'], 0, 5) . ' – ' . substr($b['end_time'], 0, 5)) ?></dd>
        <dt>Source</dt>
        <dd><?= tm_e(ucfirst($b['source'])) ?></dd>
        <dt>Notes</dt>
        <dd><?= nl2br(tm_e($b['notes'])) ?></dd>
    </dl>

    <div class="ob-actions">
        <?php
        $actions = $b['status'] === 'pending'
            ? ['confirmed' => 'Approve', 'rejected' => 'Reject']
            : ($b['status'] === 'confirmed'
                ? ['completed' => 'Complete', 'cancelled' => 'Cancel']
                : []);

        foreach ($actions as $a => $label):
        ?>
            <form method="post" action="booking-actions.php" onsubmit="return confirm('<?= tm_e($label) ?> this booking?')">
                <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="action" value="<?= tm_e($a) ?>">
                <button class="ob-btn <?= in_array($a, ['rejected', 'cancelled'], true) ? 'ob-danger' : '' ?>" type="submit">
                    <?= tm_e($label) ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="ob-panel ob-detail">
    <h3>Payment history</h3>
    <div class="ob-scroll">
        <table class="ob-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= tm_e($p['paid_at']) ?></td>
                        <td><?= tm_e($p['method']) ?></td>
                        <td><?= tm_e($p['status']) ?></td>
                        <td><?= tm_e($p['notes']) ?></td>
                        <td><?= tm_money($p['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$payments): ?>
        <p class="ob-empty">No payments recorded yet.</p>
    <?php endif; ?>
</div>

<?php if ($remaining > 0 && !in_array($b['status'], ['cancelled', 'rejected'], true)): ?>
    <form class="ob-panel ob-form" method="post">
        <h3>Record a payment</h3>
        <input type="hidden" name="csrf" value="<?= tm_e(tm_csrf()) ?>">
        <input type="hidden" name="action" value="payment">

        <div class="ob-grid">
            <label>Amount (Rs.)
                <input type="number" step="0.01" min="0.01" max="<?= number_format($remaining, 2, '.', '') ?>" name="amount" required>
            </label>

            <label>Payment Method
                <select name="method">
                    <?php
                    $methods = [
                        'cash' => 'Cash',
                        'jazzcash' => 'JazzCash',
                        'easypaisa' => 'Easypaisa',
                        'bank_transfer' => 'Bank transfer',
                        'other' => 'Other',
                    ];

                    foreach ($methods as $val => $title):
                    ?>
                        <option value="<?= $val ?>"><?= $title ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ob-wide">Notes
                <input name="notes" maxlength="255" placeholder="Optional reference or note">
            </label>
        </div>

        <button class="ob-btn" type="submit">Save Payment</button>
    </form>
<?php endif; ?>

<?php tm_page_footer(); ?>