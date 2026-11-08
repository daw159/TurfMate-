<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

function bdb(): PDO
{
    return getDBConnection();
}

function esc($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cols(PDO $db, string $table): array
{
    $query = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    return array_column($query->fetchAll(), 'Field');
}

function pick(array $columns, array $options): ?string
{
    foreach ($options as $option) {
        if (in_array($option, $columns, true)) {
            return $option;
        }
    }
    return null;
}

function qid(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function turfRows(PDO $db): array
{
    $tc = cols($db, 'turfs');
    $fc = cols($db, 'facilities');

    $tid = pick($tc, ['id', 'turf_id']);
    $fid = pick($tc, ['facility_id']);
    $fpk = pick($fc, ['id', 'facility_id']);
    $tn = pick($tc, ['name', 'turf_name', 'title']);
    $sport = pick($tc, ['sport_type', 'sport', 'type', 'category']);
    $price = pick($tc, ['base_price', 'hourly_price', 'price_per_hour', 'price', 'base_hourly_price']);
    $desc = pick($tc, ['description', 'details']);
    $fn = pick($fc, ['name', 'facility_name']);
    $loc = pick($fc, ['location', 'address', 'city']);

    if (!$tid || !$tn) {
        throw new RuntimeException('Unknown turfs table columns: ' . implode(', ', $tc));
    }

    $fields = [
        't.' . qid($tid) . ' AS id',
        't.' . qid($tn) . ' AS name',
        $sport ? 't.' . qid($sport) . ' AS sport' : "'' AS sport",
        $price ? 't.' . qid($price) . ' AS price' : 'NULL AS price',
        $desc ? 't.' . qid($desc) . ' AS description' : "'' AS description",
    ];

    $join = '';
    if ($fid && $fpk) {
        $join = ' LEFT JOIN facilities f ON f.' . qid($fpk) . '=t.' . qid($fid);
        $fields[] = $fn ? 'f.' . qid($fn) . ' AS facility' : "'' AS facility";
        $fields[] = $loc ? 'f.' . qid($loc) . ' AS location' : "'' AS location";
    } else {
        $fields[] = "'' AS facility";
        $fields[] = "'' AS location";
    }

    $where = [];
    if (in_array('deleted_at', $tc, true)) {
        $where[] = 't.`deleted_at` IS NULL';
    }
    $active = pick($tc, ['is_active', 'active']);
    if ($active) {
        $where[] = 't.' . qid($active) . '=1';
    }
    $status = pick($tc, ['status']);
    if ($status) {
        $where[] = "LOWER(t." . qid($status) . ") IN ('active','available')";
    }
    if ($join) {
        $where[] = "f.approval_status = 'approved'";
        $where[] = "f.status = 'active'";
        if (in_array('deleted_at', $fc, true)) {
            $where[] = 'f.deleted_at IS NULL';
        }
        $fa = pick($fc, ['is_active', 'active']);
        if ($fa) {
            $where[] = 'f.' . qid($fa) . '=1';
        }
    }

    $sql = 'SELECT ' . implode(',', $fields)
         . ' FROM turfs t' . $join
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY t.' . qid($tid) . ' DESC';

    return $db->query($sql)->fetchAll();
}

function sportImage(string $sport): string
{
    $sport = strtolower($sport);
    if (str_contains($sport, 'cricket')) {
        return 'cricket.jpg';
    }
    if (str_contains($sport, 'padel') || str_contains($sport, 'paddle') || str_contains($sport, 'tennis')) {
        return 'padel.jpg';
    }
    return 'football.jpg';
}

function bookingNav(): void
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $links = [
        'dashboard.php' => 'Home',
        'turfs.php' => 'Explore Turfs',
        'my-bookings.php' => 'My Bookings',
        'profile.php' => 'My Profile',
    ];
    ?>
    <header class="customer-header">
        <div class="customer-header-inner">
            <a class="customer-logo" href="dashboard.php">
                <span class="customer-logo-icon">T</span>
                <span>TurfMate</span>
            </a>
            <nav class="customer-navigation" aria-label="Customer navigation">
                <?php foreach ($links as $url => $label): ?>
                    <?php $isActive = $page === $url || ($page === 'turf-details.php' && $url === 'turfs.php'); ?>
                    <a href="<?= esc($url) ?>" <?= $isActive ? 'class="active" aria-current="page"' : '' ?>><?= esc($label) ?></a>
                <?php endforeach; ?>
                <a class="customer-logout" href="../auth/logout.php">Logout</a>
            </nav>
        </div>
    </header>
    <?php
}

function bookingHead(string $title): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= esc($title) ?> | TurfMate</title>
        <link rel="stylesheet" href="../assets/css/booking.css?v=20261108-2">
        <link rel="stylesheet" href="../assets/css/customer-layout.css?v=20261108-3">
    </head>
    <body class="customer-app">
    <?php
    bookingNav();
}

function customerFooter(): void
{
    ?>
    <footer class="customer-footer">
        <div class="customer-footer-inner">
            <div class="customer-footer-about">
                <h3>TurfMate</h3>
                <p>Your Game, Your Time. Find sports facilities, explore available turfs, and book your next game with ease.</p>
            </div>
            <div class="customer-footer-links">
                <h4>Quick Links</h4>
                <a href="dashboard.php">Home</a>
                <a href="turfs.php">Explore Turfs</a>
                <a href="my-bookings.php">My Bookings</a>
            </div>
            <div class="customer-footer-links">
                <h4>My Account</h4>
                <a href="profile.php">My Profile</a>
                <a href="my-bookings.php">Booking History</a>
                <a href="../auth/logout.php">Logout</a>
            </div>
        </div>
        <div class="customer-footer-bottom">
            <span>&copy; <?= date('Y') ?> TurfMate. All rights reserved.</span>
            <span>Find Your Turf. Book Your Game.</span>
        </div>
    </footer>
    <?php
}

function bookingFoot(): void
{
    customerFooter();
    echo '</body></html>';
}

function bookingUserId(): int
{
    foreach (['user_id', 'id'] as $key) {
        if (isset($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    throw new RuntimeException('Customer session ID missing. Inspect includes/auth.php');
}
