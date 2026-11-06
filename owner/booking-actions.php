<?php

declare(strict_types=1);

require_once __DIR__ . '/_booking_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

tm_verify_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = (string) ($_POST['action'] ?? '');

if (!$id || !in_array($action, ['confirmed', 'rejected', 'completed', 'cancelled'], true)) {
    http_response_code(400);
    exit('Invalid request');
}

$turf = 0;
$locked = false;

try {
    $b = ob_get_booking($pdo, $ownerId, (int) $id);
    $turf = (int) $b['turf_id'];

    ob_lock($pdo, $turf);
    $locked = true;

    $pdo->beginTransaction();
    $b = ob_get_booking($pdo, $ownerId, (int) $id, true);

    $transitions = [
        'pending' => ['confirmed', 'rejected'],
        'confirmed' => ['completed', 'cancelled'],
    ];

    if (!in_array($action, $transitions[$b['status']] ?? [], true)) {
        throw new RuntimeException('Status change is not allowed.');
    }

    if ($action === 'confirmed') {
        $q = $pdo->prepare(
            "SELECT id
             FROM bookings
             WHERE turf_id = ?
               AND booking_date = ?
               AND id <> ?
               AND status = 'confirmed'
               AND start_time < ?
               AND end_time > ?
             LIMIT 1
             FOR UPDATE"
        );
        $q->execute([$turf, $b['booking_date'], $id, $b['end_time'], $b['start_time']]);

        if ($q->fetch()) {
            throw new RuntimeException('A confirmed booking already occupies this slot.');
        }
    }

    $q = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?');
    $q->execute([$action, $id]);

    $pdo->commit();
    tm_flash('success', 'Booking #' . $id . ' updated to ' . $action . '.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    tm_flash('error', $e->getMessage());
} finally {
    if ($locked) {
        ob_unlock($pdo, $turf);
    }
}

tm_redirect('booking-details.php?id=' . (int) $id);