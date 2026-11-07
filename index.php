<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
$dashboards = [
    'customer' => 'customer/dashboard.php',
    'owner' => 'owner/dashboard.php',
    'staff' => 'staff/dashboard.php',
    'super_admin' => 'super-admin/dashboard.php',
];
$role = (string) ($_SESSION['role'] ?? '');
$dashboardLink = $dashboards[$role] ?? 'auth/login.php';
$browseLink = $isLoggedIn && $role === 'customer'
    ? 'customer/turfs.php'
    : 'auth/login.php';

function homeEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Illustrative examples, not live inventory or bookable listings.
$examples = [
    [
        'sport' => 'Football',
        'name' => 'KickOff Arena',
        'location' => 'DHA, Lahore',
        'price' => '3,500',
        'image' => 'football.jpg',
    ],
    [
        'sport' => 'Cricket',
        'name' => 'The Pavilion',
        'location' => 'Johar Town, Lahore',
        'price' => '2,500',
        'image' => 'cricket.jpg',
    ],
    [
        'sport' => 'Padel',
        'name' => 'Padel Point',
        'location' => 'Gulberg, Lahore',
        'price' => '4,000',
        'image' => 'padel.jpg',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173e2e">
    <meta name="description" content="Explore cricket, football and padel turfs with TurfMate. Find your game and manage bookings in one place.">
    <title>TurfMate | Find Your Turf. Book Your Game.</title>
    <link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
    <header class="site-header">
        <div class="site-container header-inner">
            <a href="index.php" class="site-brand" aria-label="TurfMate homepage">
                <span class="brand-mark" aria-hidden="true">T</span>
                <span>TurfMate</span>
            </a>

            <nav class="primary-nav" aria-label="Main navigation">
                <a href="#home">Home</a>
                <a href="#sports">Sports</a>
                <a href="#arenas">Explore Arenas</a>
                <a href="#how-it-works">How It Works</a>
            </nav>

            <div class="header-actions">
                <?php if ($isLoggedIn): ?>
                    <a class="button button-primary" href="<?= homeEscape($dashboardLink) ?>">My Dashboard <span aria-hidden="true">↗</span></a>
                <?php else: ?>
                    <a class="sign-in-link" href="auth/login.php">Sign In</a>
                    <a class="button button-primary" href="auth/register.php">Get Started <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="hero" id="home" aria-labelledby="hero-title">
            <div class="site-container hero-grid">
                <div class="hero-copy">
                    <span class="eyebrow eyebrow-light"><span class="eyebrow-line"></span> YOUR GAME STARTS HERE</span>
                    <h1 id="hero-title">Find Your Turf.<br><span>Book Your Game.</span></h1>
                    <p>From late-night football to weekend cricket and padel, discover a place to play and make your next match happen.</p>
                    <div class="hero-actions">
                        <a href="<?= homeEscape($browseLink) ?>" class="button button-white">Browse Available Turfs <span aria-hidden="true">→</span></a>
                        <a href="#how-it-works" class="button button-ghost">How It Works <span aria-hidden="true">↓</span></a>
                    </div>
                    <div class="hero-sports" aria-label="Supported sports">
                        <span>⚽ Football</span><span>🏏 Cricket</span><span>🎾 Padel</span>
                    </div>
                </div>
                <div class="hero-media">
                    <img src="assets/images/cricket.jpg" alt="Cricket ground with a prepared pitch" fetchpriority="high">
                    <div class="hero-media-tag"><span class="tag-icon" aria-hidden="true">✓</span><div><strong>Ready for game day</strong><small>Explore. Choose. Play.</small></div></div>
                </div>
            </div>
        </section>

        <section class="section sports-section" id="sports" aria-labelledby="sports-heading">
            <div class="site-container">
                <div class="section-heading">
                    <span class="eyebrow">CHOOSE YOUR GAME</span>
                    <h2 id="sports-heading">What are you playing today?</h2>
                    <p>Whatever the sport, your next match starts here.</p>
                </div>
                <div class="sports-grid">
                    <a class="sport-card" href="<?= homeEscape($browseLink) ?>">
                        <img src="assets/images/football.jpg" alt="Football turf illuminated for an evening match" loading="lazy">
                        <div class="sport-card-info"><div><span class="sport-number">01 / FIELD SPORTS</span><h3>Football</h3><p>Find your pitch. Bring your team.</p></div><span class="sport-arrow" aria-hidden="true">↗</span></div>
                    </a>
                    <a class="sport-card" href="<?= homeEscape($browseLink) ?>">
                        <img src="assets/images/cricket.jpg" alt="Cricket pitch and wicket" loading="lazy">
                        <div class="sport-card-info"><div><span class="sport-number">02 / TEAM SPORTS</span><h3>Cricket</h3><p>Make every over count.</p></div><span class="sport-arrow" aria-hidden="true">↗</span></div>
                    </a>
                    <a class="sport-card" href="<?= homeEscape($browseLink) ?>">
                        <img src="assets/images/padel.jpg" alt="Padel sports court" loading="lazy">
                        <div class="sport-card-info"><div><span class="sport-number">03 / RACKET SPORTS</span><h3>Padel</h3><p>Get on court and play.</p></div><span class="sport-arrow" aria-hidden="true">↗</span></div>
                    </a>
                </div>
            </div>
        </section>

        <section class="section arenas-section" id="arenas" aria-labelledby="arenas-heading">
            <div class="site-container">
                <div class="section-heading section-heading-row">
                    <div><span class="eyebrow">DISCOVER YOUR NEXT SPOT</span><h2 id="arenas-heading">Explore sports arenas</h2><p>See what a great game day could look like.</p></div>
                    <a class="button button-outline" href="<?= homeEscape($browseLink) ?>">Browse Live Turfs <span aria-hidden="true">→</span></a>
                </div>
                <div class="arena-grid">
                    <?php foreach ($examples as $arena): ?>
                        <article class="arena-card">
                            <div class="arena-image">
                                <img src="assets/images/<?= homeEscape($arena['image']) ?>" alt="<?= homeEscape($arena['sport']) ?> ground example" loading="lazy">
                                <span class="arena-badge"><?= homeEscape($arena['sport']) ?></span>
                            </div>
                            <div class="arena-details">
                                <span class="example-label">EXAMPLE LISTING</span>
                                <h3><?= homeEscape($arena['name']) ?></h3>
                                <p class="arena-location">📍 <?= homeEscape($arena['location']) ?></p>
                                <div class="arena-meta"><div><strong>Rs. <?= homeEscape($arena['price']) ?></strong><small> / hour · illustrative</small></div><span class="example-tag">Sample only</span></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="arena-disclaimer">The three cards above are illustrative examples, not actual bookable facilities. Select “Browse Live Turfs” to see current listings after signing in.</p>
            </div>
        </section>

        <section class="section how-section" id="how-it-works" aria-labelledby="how-heading">
            <div class="site-container">
                <div class="section-heading"><span class="eyebrow">THREE SIMPLE STEPS</span><h2 id="how-heading">From planning to playing.</h2><p>Finding your next game should be the easy part.</p></div>
                <div class="steps-grid">
                    <article class="step-card"><span class="step-number">01</span><span class="step-icon" aria-hidden="true">⌕</span><h3>Find your turf</h3><p>Explore available sports facilities and choose your preferred turf.</p></article>
                    <article class="step-card"><span class="step-number">02</span><span class="step-icon" aria-hidden="true">▦</span><h3>Pick a time</h3><p>Choose a date and playing slot that fits your team's schedule.</p></article>
                    <article class="step-card"><span class="step-number">03</span><span class="step-icon" aria-hidden="true">✓</span><h3>Request booking</h3><p>Send your request and track its confirmation in My Bookings.</p></article>
                </div>
            </div>
        </section>

        <section class="owner-section" aria-labelledby="owner-heading">
            <div class="site-container owner-inner"><div><span class="eyebrow eyebrow-light">BUILT FOR ARENA OWNERS TOO</span><h2 id="owner-heading">Your arena deserves a smarter way to run.</h2><p>Manage facilities, pricing, bookings, staff, and business operations in one place.</p></div><a class="button button-white" href="auth/register.php">Register as an Owner <span aria-hidden="true">→</span></a></div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-container footer-main">
            <div class="footer-about"><div class="footer-brand"><span class="brand-mark" aria-hidden="true">T</span><strong>TurfMate</strong></div><p>Find Your Turf. Book Your Game.<br>Good games start with great places.</p></div>
            <div class="footer-links"><h3>Explore</h3><a href="#home">Home</a><a href="#sports">Sports</a><a href="#arenas">Sample arenas</a><a href="<?= homeEscape($browseLink) ?>">Browse live turfs</a></div>
            <div class="footer-links"><h3>Account</h3><?php if ($isLoggedIn): ?><a href="<?= homeEscape($dashboardLink) ?>">My Dashboard</a><?php else: ?><a href="auth/login.php">Sign In</a><a href="auth/register.php">Create Account</a><?php endif; ?><a href="#how-it-works">How It Works</a></div>
        </div>
        <div class="site-container footer-bottom"><span>© <?= date('Y') ?> TurfMate. All rights reserved.</span><span>Your Game, Your Time.</span></div>
    </footer>
</body>
</html>
