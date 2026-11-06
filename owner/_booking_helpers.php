<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../customer/booking_engine.php';

function ob_turfs(PDO $db, int $owner): array
{
    $q = $db->prepare(
        "SELECT t.id, t.name, t.hourly_price, f.name facility_name, f.id facility_id
         FROM turfs t
         JOIN facilities f ON f.id = t.facility_id
         WHERE f.owner_id = ?
           AND f.deleted_at IS NULL
           AND t.deleted_at IS NULL
           AND t.status = 'active'
           AND f.status = 'active'
         ORDER BY f.name, t.name"
    );
    $q->execute([$owner]);

    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function ob_get_booking(PDO $db, int $owner, int $id, bool $lock = false): array
{
    $q = $db->prepare(
        'SELECT b.*, t.name turf_name, f.name facility_name
         FROM bookings b
         JOIN turfs t ON t.id = b.turf_id
         JOIN facilities f ON f.id = t.facility_id
         WHERE b.id = ? AND f.owner_id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $q->execute([$id, $owner]);
    $r = $q->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        throw new RuntimeException('Booking not found for your facilities.');
    }

    return $r;
}

function ob_paid(PDO $db, int $booking): float
{
    $q = $db->prepare(
        "SELECT COALESCE(SUM(CASE WHEN status = 'received' THEN amount ELSE -amount END), 0)
         FROM payments
         WHERE booking_id = ?"
    );
    $q->execute([$booking]);

    return (float) $q->fetchColumn();
}

function ob_lock(PDO $db, int $turf): void
{
    $q = $db->prepare('SELECT GET_LOCK(?, 10)');
    $q->execute(['turfmate_booking_' . $turf]);

    if ((int) $q->fetchColumn() !== 1) {
        throw new RuntimeException('Could not lock booking schedule. Try again.');
    }
}

function ob_unlock(PDO $db, int $turf): void
{
    $q = $db->prepare('SELECT RELEASE_LOCK(?)');
    $q->execute(['turfmate_booking_' . $turf]);
}

function ob_validate_payment(float $amount, float $total): void
{
    if ($amount < 0 || $amount > $total) {
        throw new RuntimeException('Payment cannot be negative or exceed total price.');
    }
}

function ob_method(string $method): string
{
    if (!in_array($method, ['cash', 'jazzcash', 'easypaisa', 'bank_transfer', 'other'], true)) {
        throw new RuntimeException('Invalid payment method.');
    }

    return $method;
}