<?php

require_once __DIR__ . '/../includes/auth.php';

requireRole(['owner']);

$name = htmlspecialchars(
    $_SESSION['name'],
    ENT_QUOTES,
    'UTF-8'
);

?>

<h1>Owner Dashboard</h1>

<h2>Welcome, <?= $name ?>!</h2>

<p>You are logged in as an Arena Owner.</p>

<p>Here you will manage facilities, turfs and bookings.</p>

<a href="../auth/logout.php">Logout</a>