<?php

require_once __DIR__ . '/../includes/auth.php';

requireRole(['staff']);

$name = htmlspecialchars(
    $_SESSION['name'],
    ENT_QUOTES,
    'UTF-8'
);

?>

<h1>Staff Dashboard</h1>

<h2>Welcome, <?= $name ?>!</h2>

<p>You are logged in as Staff.</p>

<p>Here you will manage assigned facility bookings.</p>

<a href="../auth/logout.php">Logout</a>