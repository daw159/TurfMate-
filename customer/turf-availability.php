<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    requireRole(['customer']);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new RuntimeException('GET required.');
    }

    $id = filter_var(
        $_GET['turf_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $date = (string) ($_GET['date'] ?? '');

    if (!$id) {
        throw new RuntimeException('Select a valid turf.');
    }

    $day = tmDate($date);
    $now = new DateTimeImmutable();
    if ($day < $now->setTime(0, 0, 0)) {
        throw new RuntimeException('Choose today or a future date.');
    }

    $db = bdb();
    tmTurf($db, (int) $id);

    // No fixed operating hours: generate 24 one-hour slots for the selected
    // date, including 11:00 PM -> 12:00 AM on the following date.
    // Existing pending/confirmed bookings still block overlapping slots.
    $rangeStart = $day->modify('-1 day')->format('Y-m-d');
    $rangeEnd = $day->format('Y-m-d');
    $blocked = [];

    $q = $db->prepare(
        "SELECT booking_date, start_time, end_time, status
         FROM bookings
         WHERE turf_id = ?
           AND status IN ('pending', 'confirmed')
           AND booking_date BETWEEN ? AND ?"
    );
    $q->execute([(int) $id, $rangeStart, $rangeEnd]);

    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        $start = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['start_time']);
        $end = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['end_time']);
        $blocked[] = [
            $start,
            $end,
            $booking['status'] === 'confirmed' ? 'booked' : 'pending',
        ];
    }

    // Support legacy booking requests if the older table is installed.
    if ($db->query("SHOW TABLES LIKE 'customer_booking_requests'")->fetchColumn()) {
        $q = $db->prepare(
            "SELECT booking_date, start_time, end_time, ends_next_day, status
             FROM customer_booking_requests
             WHERE turf_id = ?
               AND status IN ('pending', 'approved')
               AND booking_date BETWEEN ? AND ?"
        );
        $q->execute([(int) $id, $rangeStart, $rangeEnd]);

        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $request) {
            $start = new DateTimeImmutable($request['booking_date'] . ' ' . $request['start_time']);
            $end = new DateTimeImmutable($request['booking_date'] . ' ' . $request['end_time']);
            if ((int) $request['ends_next_day'] === 1) {
                $end = $end->modify('+1 day');
            }
            $blocked[] = [
                $start,
                $end,
                $request['status'] === 'approved' ? 'booked' : 'pending',
            ];
        }
    }

    $slots = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $start = $day->modify('+' . $hour . ' hours');
        $end = $start->modify('+1 hour');

        // tmWindow() rejects a booking that starts in the past or now.
        $status = $start <= $now ? 'past' : 'available';

        if ($status !== 'past') {
            foreach ($blocked as [$busyStart, $busyEnd, $busyStatus]) {
                if ($start < $busyEnd && $end > $busyStart) {
                    // Confirmed takes priority over pending if both exist.
                    if ($busyStatus === 'booked') {
                        $status = 'booked';
                        break;
                    }
                    $status = 'pending';
                }
            }
        }

        $slots[] = [
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'status' => $status,
            'label' => $start->format('g:i A') . ' – ' . $end->format('g:i A'),
        ];
    }

    echo json_encode(
        ['ok' => true, 'slots' => $slots, 'message' => ''],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
