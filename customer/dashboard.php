<?php

require_once __DIR__ . '/../includes/auth.php';

requireRole(['customer']);

$name = htmlspecialchars(
    $_SESSION['name'],
    ENT_QUOTES,
    'UTF-8'
);

?>

<h1>Customer Dashboard</h1>

<h2>Welcome, <?= $name ?>!</h2>

<p>You are logged in as a Customer.</p>

<p>Here you will browse arenas and book turfs.</p>

<a href="../auth/logout.php">Logout</a>