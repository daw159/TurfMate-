<?php
declare(strict_types=1);
require_once __DIR__ . '/_booking_common.php';
requireRole(['customer']);
$name = (string) ($_SESSION['name'] ?? 'Customer');
bookingHead('Customer Dashboard');
?>
<main class="customer-dashboard">
    <section class="customer-hero">
        <div class="customer-hero-inner">
            <p class="customer-eyebrow">WELCOME TO TURFMATE</p>
            <h1>Book Your Next Game.</h1>
            <p>Discover quality sports turfs, check availability, and book your favourite playing slots with ease.</p>
            <a class="btn customer-hero-button" href="turfs.php">Explore Available Turfs &rarr;</a>
        </div>
    </section>

    <section class="customer-dashboard-content">
        <h2>Welcome back, <?= esc($name) ?>!</h2>
        <p class="customer-dashboard-subtitle">Manage your turf bookings and personal information from one place.</p>

        <div class="customer-dashboard-cards">
            <article class="customer-dashboard-card">
                <div class="customer-dashboard-icon" aria-hidden="true">🏟️</div>
                <h3>Explore Turfs</h3>
                <p>Browse cricket, football, and padel turfs. Find your preferred venue and playing time.</p>
                <a class="btn" href="turfs.php">View Turfs &rarr;</a>
            </article>
            <article class="customer-dashboard-card">
                <div class="customer-dashboard-icon" aria-hidden="true">📅</div>
                <h3>My Bookings</h3>
                <p>View your booking history, upcoming games, and booking confirmation status.</p>
                <a class="btn" href="my-bookings.php">View My Bookings &rarr;</a>
            </article>
            <article class="customer-dashboard-card">
                <div class="customer-dashboard-icon" aria-hidden="true">👤</div>
                <h3>My Profile</h3>
                <p>View and update your personal details, including your name and phone number.</p>
                <a class="btn" href="profile.php">View My Profile &rarr;</a>
            </article>
        </div>
    </section>
</main>
<?php bookingFoot(); ?>
