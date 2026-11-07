<?php
/** Staff-only bootstrap. Never trust facility IDs supplied by the browser. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['staff']);
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$staffId = (int) $_SESSION['user_id'];

function staffEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function staffCsrf(): string
{
    if (empty($_SESSION['staff_csrf'])) {
        $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['staff_csrf'];
}

function staffAllowed(PDO $db, int $staffId, int $facilityId): bool
{
    $query = $db->prepare(<<<'SQL'
        SELECT 1
        FROM staff_assignments sa
        JOIN facilities f ON f.id = sa.facility_id
        JOIN users owner ON owner.id = f.owner_id
        WHERE sa.staff_id = ?
          AND sa.facility_id = ?
          AND sa.status = 'active'
          AND f.deleted_at IS NULL
          AND f.status = 'active'
          AND f.approval_status = 'approved'
          AND owner.status = 'active'
        LIMIT 1
    SQL);
    $query->execute([$staffId, $facilityId]);
    return (bool) $query->fetchColumn();
}

function staffLayoutStart(string $title, string $active): void
{
    $title = staffEscape($title);
    $dashboardClass = $active === 'dashboard' ? 'active' : '';
    $bookingClass = $active === 'bookings' ? 'active' : '';
    $facilityClass = $active === 'facilities' ? 'active' : '';
    $inventoryClass = $active === 'inventory' ? 'active' : '';
    $manualClass = $active === 'manual' ? 'active' : '';
    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title} | TurfMate Staff</title>
        <link rel="stylesheet" href="../assets/css/staff.css?v=1">
        <link rel="stylesheet" href="../assets/css/staff-extra.css?v=1">
        <link rel="stylesheet" href="../assets/css/staff-manual.css?v=1">
    </head>
    <body>
        <div class="app">
            <aside class="sidebar">
                <a class="brand" href="dashboard.php">◈ TurfMate <span>STAFF</span></a>
                <div class="nav-label">WORKSPACE</div>
                <a class="nav-link {$dashboardClass}" href="dashboard.php">▦ Dashboard</a>
                <a class="nav-link {$bookingClass}" href="bookings.php">▤ Bookings</a>
                <a class="nav-link {$manualClass}" href="manual-booking.php">＋ Manual Booking</a>
                <a class="nav-link {$facilityClass}" href="facilities.php">▧ Facilities &amp; Pricing</a>
                <a class="nav-link {$inventoryClass}" href="inventory.php">▣ Inventory</a>
                <div class="sidebar-bottom"><a class="nav-link" href="../auth/logout.php">↪ Log out</a></div>
            </aside>
            <main class="main">
                <header class="topbar"><span>Staff workspace</span><span class="badge">Assigned facilities only</span></header>
    HTML;
}

function staffLayoutEnd(): void
{
    echo '</main></div></body></html>';
}

function staffAssignments(PDO $db, int $staffId): array
{
    $query = $db->prepare(<<<'SQL'
        SELECT f.id, f.name, f.city
        FROM staff_assignments sa
        JOIN facilities f ON f.id = sa.facility_id
        JOIN users owner ON owner.id = f.owner_id
        WHERE sa.staff_id = ?
          AND sa.status = 'active'
          AND f.status = 'active'
          AND f.approval_status = 'approved'
          AND f.deleted_at IS NULL
          AND owner.status = 'active'
        ORDER BY f.name
    SQL);
    $query->execute([$staffId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
