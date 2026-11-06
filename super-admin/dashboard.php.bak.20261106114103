<?php

require_once __DIR__ . '/../includes/auth.php';

requireRole(['super_admin']);

$name = htmlspecialchars(
    $_SESSION['name'],
    ENT_QUOTES,
    'UTF-8'
);

?>

<h1>Super Admin Dashboard</h1>

<h2>Welcome, <?= $name ?>!</h2>

<p>You are logged in as Super Admin.</p>

<p>Here you will manage the TurfMate platform.</p>

<a href="../auth/logout.php">Logout</a>