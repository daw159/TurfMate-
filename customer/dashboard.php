<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);
$name = htmlspecialchars((string)($_SESSION['name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TurfMate | Customer</title><link rel="stylesheet" href="../assets/css/customer.css"></head><body>
<header class="top"><a class="brand" href="dashboard.php">⬡ TurfMate</a><nav><a href="dashboard.php">Home</a><a href="turfs.php">Turfs</a><a href="../auth/logout.php">Logout</a></nav></header>
<section class="hero"><div class="hero-inner"><p class="eyebrow">WELCOME TO TURFMATE</p><h1>Book Sports Turfs<br>Anytime, <span>Anywhere</span></h1><p>Hi, <?= $name ?>! Discover sports grounds and find your next game.</p><a class="button" href="turfs.php">Explore Turfs →</a></div></section>
<main class="container"><h2>Welcome back, <?= $name ?>!</h2><p class="muted">Your sports booking hub</p><div class="cards"><a class="tile" href="turfs.php"><span class="tile-icon">⚽</span><h3>Browse Turfs</h3><p>Find football, cricket and padel venues.</p><strong>Explore →</strong></a><div class="tile"><span class="tile-icon">📅</span><h3>My Bookings</h3><p>Booking history and upcoming matches.</p><span class="muted">Coming in the booking module</span></div><div class="tile"><span class="tile-icon">👤</span><h3>Profile</h3><p>Manage your customer information.</p><span class="muted">Coming soon</span></div></div></main>
</body></html>
