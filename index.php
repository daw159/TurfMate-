<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']);

$dashboards = [
    'customer' => 'customer/dashboard.php',
    'owner' => 'owner/dashboard.php',
    'staff' => 'staff/dashboard.php',
    'super_admin' => 'super-admin/dashboard.php'
];

$role = $_SESSION['role'] ?? '';

$dashboardLink = $dashboards[$role] ?? 'auth/login.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Sample data only. We will load real facilities from MySQL later.
$arenas = [
    [
        'name' => 'KickOff Arena',
        'location' => 'DHA, Lahore',
        'sport' => 'Football',
        'price' => '3,500',
        'icon' => '⚽',
        'class' => 'football'
    ],
    [
        'name' => 'The Pavilion',
        'location' => 'Johar Town, Lahore',
        'sport' => 'Cricket',
        'price' => '2,500',
        'icon' => '🏏',
        'class' => 'cricket'
    ],
    [
        'name' => 'Padel Point',
        'location' => 'Gulberg, Lahore',
        'sport' => 'Padel',
        'price' => '4,000',
        'icon' => '🎾',
        'class' => 'padel'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>TurfMate | Find Your Turf. Book Your Game.</title>

    <link rel="stylesheet" href="/turfmate-team/assets/css/style.css">
</head>

<body>

<!-- NAVBAR -->
<header class="navbar">
    <div class="container nav-content">

        <a href="index.php" class="logo">
            <span class="logo-icon">⚽</span>
            Turf<span>Mate</span>
        </a>

        <nav class="nav-links" aria-label="Main navigation">
            <a href="#home">Home</a>
            <a href="#arenas">Explore Arenas</a>
            <a href="#sports">Sports</a>
            <a href="#how-it-works">How It Works</a>
        </nav>

        <div class="nav-actions">

            <?php if ($isLoggedIn): ?>

                <a href="<?= e($dashboardLink) ?>"
                   class="btn btn-primary">
                    My Dashboard
                </a>

            <?php else: ?>

                <a href="auth/login.php"
                   class="btn btn-outline">
                    Sign In
                </a>

                <a href="auth/register.php"
                   class="btn btn-primary">
                    Get Started
                </a>

            <?php endif; ?>

        </div>

    </div>
</header>


<!-- HERO -->
<section id="home" class="hero">

    <div class="container hero-content">

        <div class="hero-text">

            <span class="eyebrow">
                YOUR GAME STARTS HERE
            </span>

            <h1>
                Find Your Turf.<br>
                <span>Book Your Game.</span>
            </h1>

            <p>
                Discover sports arenas near you.
                Explore football turfs, cricket grounds,
                and padel courts, all in one place.
            </p>

            <div class="hero-buttons">

                <a href="#arenas" class="btn btn-primary btn-large">
                    Explore Arenas →
                </a>

                <a href="#how-it-works"
                   class="btn btn-light btn-large">
                    How It Works
                </a>

            </div>

            <p class="hero-note">
                ⚽ Football &nbsp; • &nbsp;
                🏏 Cricket &nbsp; • &nbsp;
                🎾 Padel
            </p>

        </div>

        <div class="hero-visual">

           <div class="cricket-ground">
    <div class="cricket-boundary"></div>
    <div class="cricket-pitch">
        <div class="crease crease-top"></div>
        <div class="crease crease-bottom"></div>

        <div class="stumps stumps-top">
            <span></span><span></span><span></span>
        </div>

        <div class="stumps stumps-bottom">
            <span></span><span></span><span></span>
        </div>
    </div>
    <span class="cricket-ball">🏏</span>
</div>

            <div class="hero-floating-card">
                <span>🏟️</span>
                <div>
                    <strong>Discover Your Arena</strong>
                    <small>Your next match starts here</small>
                </div>
            </div>

        </div>

    </div>

</section>


<!-- SPORTS -->
<section id="sports" class="section">

    <div class="container">

        <div class="section-heading">
            <span class="section-label">PLAY YOUR SPORT</span>
            <h2>What Are You Playing Today?</h2>
            <p>Explore facilities for your favorite sport.</p>
        </div>

        <div class="sports-grid">

            <a href="#arenas" class="sport-card">
                <div class="sport-icon">⚽</div>
                <h3>Football</h3>
                <p>Explore football turfs</p>
                <span>Explore →</span>
            </a>

            <a href="#arenas" class="sport-card">
                <div class="sport-icon">🏏</div>
                <h3>Cricket</h3>
                <p>Explore cricket arenas</p>
                <span>Explore →</span>
            </a>

            <a href="#arenas" class="sport-card">
                <div class="sport-icon">🎾</div>
                <h3>Padel</h3>
                <p>Explore padel courts</p>
                <span>Explore →</span>
            </a>

        </div>

    </div>

</section>


<!-- FEATURED ARENAS -->
<section id="arenas" class="section arenas-section">

    <div class="container">

        <div class="section-heading">
            <span class="section-label">DISCOVER PLACES</span>
            <h2>Featured Sports Arenas</h2>
            <p>
                Find the right place for your next game.
            </p>
        </div>

        <div class="arena-grid">

            <?php foreach ($arenas as $arena): ?>

                <article class="arena-card">

                    <div class="arena-image <?= e($arena['class']) ?>">

                        <span class="arena-sport-badge">
                            <?= e($arena['sport']) ?>
                        </span>

                        <span class="arena-image-icon">
                            <?= e($arena['icon']) ?>
                        </span>

                    </div>

                    <div class="arena-info">

                        <h3><?= e($arena['name']) ?></h3>

                        <p class="arena-location">
                            📍 <?= e($arena['location']) ?>
                        </p>

                        <div class="arena-bottom">

                            <div class="arena-price">
                                <strong>
                                    Rs. <?= e($arena['price']) ?>
                                </strong>
                                <span>/ hour</span>
                            </div>

                            <span class="coming-soon">
                                Details Soon
                            </span>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

        <p class="sample-note">
            These are sample arena listings.
            Real facilities and booking will be added soon.
        </p>

    </div>

</section>


<!-- HOW IT WORKS -->
<section id="how-it-works" class="section">

    <div class="container">

        <div class="section-heading">
            <span class="section-label">SIMPLE PROCESS</span>
            <h2>How TurfMate Works</h2>
            <p>Getting ready for your next game is easy.</p>
        </div>

        <div class="steps-grid">

            <div class="step-card">

                <div class="step-number">01</div>
                <div class="step-icon">🔎</div>

                <h3>Find an Arena</h3>

                <p>
                    Browse available sports facilities
                    and choose where you want to play.
                </p>

            </div>

            <div class="step-card">

                <div class="step-number">02</div>
                <div class="step-icon">📅</div>

                <h3>Choose Your Slot</h3>

                <p>
                    Select your preferred date, time,
                    and sports turf.
                </p>

            </div>

            <div class="step-card">

                <div class="step-number">03</div>
                <div class="step-icon">✅</div>

                <h3>Request Booking</h3>

                <p>
                    Submit your booking request
                    and wait for confirmation.
                </p>

            </div>

        </div>

    </div>

</section>


<!-- OWNER SECTION -->
<section class="owner-section">

    <div class="container owner-content">

        <div>
            <span class="owner-label">
                FOR SPORTS FACILITY OWNERS
            </span>

            <h2>Own an Arena? Join TurfMate.</h2>

            <p>
                Manage your sports facilities, turfs,
                bookings, staff, and business operations
                from one platform.
            </p>
        </div>

        <a href="auth/register.php" class="btn btn-white">
            Register as an Owner →
        </a>

    </div>

</section>


<!-- FOOTER -->
<footer class="footer">

    <div class="container">

        <div class="footer-content">

            <div>
                <h2>TurfMate</h2>
                <p>Find Your Turf. Book Your Game.</p>
            </div>

            <div class="footer-links">
                <a href="#home">Home</a>
                <a href="#arenas">Arenas</a>
                <a href="#sports">Sports</a>
                <a href="auth/login.php">Login</a>
            </div>

        </div>

        <div class="footer-bottom">
            <p>
                &copy; <?= date('Y') ?> TurfMate.
                All rights reserved.
            </p>
        </div>

    </div>

</footer>

</body>
</html>
