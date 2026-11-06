<?php
declare(strict_types=1);

function tm_page_header(string $pageTitle, string $active, array $owner): void
{
    $flash = tm_take_flash();
    $name = tm_e((string) $owner['name']);
    $title = tm_e($pageTitle);

    $dashboardClass = $active === 'dashboard' ? 'active' : '';
    $facilitiesClass = $active === 'facilities' ? 'active' : '';
    $turfsClass = $active === 'turfs' ? 'active' : '';
    $bookingsClass = $active === 'bookings' ? 'active' : '';
    $reportsClass = $active === 'reports' ? 'active' : '';

    // Expenses and Inventory section
    $expensesClass = in_array($active, ['expenses', 'inventory', 'maintenance'], true)
        ? 'active'
        : '';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?> | TurfMate</title>

    <link rel="stylesheet" href="../assets/css/owner.css">

    <?php if (in_array($active, ['expenses', 'inventory', 'maintenance'], true)): ?>
        <link rel="stylesheet" href="../assets/css/owner-expenses.css">
    <?php endif; ?>
</head>

<body>
<div class="app-shell">

    <aside class="sidebar">

        <a class="brand" href="dashboard.php">
            <span class="brand-mark">T</span>
            <span>
                TurfMate
                <small>Owner workspace</small>
            </span>
        </a>

        <div class="nav-label">MANAGEMENT</div>

        <nav aria-label="Owner navigation">

            <a class="nav-link <?= $dashboardClass ?>"
               href="dashboard.php">
                ▦ <span>Dashboard</span>
            </a>

            <a class="nav-link <?= $facilitiesClass ?>"
               href="facilities.php">
                ▤ <span>Facilities</span>
            </a>

            <a class="nav-link <?= $turfsClass ?>"
               href="turfs.php">
                ◫ <span>Turfs & Pricing</span>
            </a>

            <a class="nav-link <?= $bookingsClass ?>"
               href="bookings.php">
                ▣ <span>Bookings</span>
            </a>

            <!-- EXPENSES & INVENTORY -->
            <a class="nav-link <?= $expensesClass ?>"
               href="expenses.php">
                ▥ <span>Expenses & Inventory</span>
            </a>

            <a class="nav-link <?= $reportsClass ?>"
   href="reports.php">
    ◉ <span>Reports & Analytics</span>
</a>

        </nav>



        <div class="sidebar-bottom">
            <span class="user-dot"></span>

            <div>
                <strong><?= $name ?></strong>
                <small>Owner</small>
            </div>
        </div>

    </aside>

    <div class="main-area">

        <header class="topbar">

            <div>
                <div class="eyebrow">TURFMATE / OWNER</div>
                <h1><?= $title ?></h1>
            </div>

            <a class="logout" href="../auth/logout.php">
                Log out
            </a>

        </header>

        <main class="content">

            <?php if ($flash): ?>
                <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>"
                     role="status">
                    <?= tm_e((string) $flash['message']) ?>
                </div>
            <?php endif; ?>

    <?php
}

function tm_page_footer(): void
{
    ?>
        </main>

        <footer class="footer">
            TurfMate · Owner Management System
        </footer>

    </div>
</div>

</body>
</html>
    <?php
}
