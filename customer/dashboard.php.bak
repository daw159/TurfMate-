
<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);

$name = htmlspecialchars(
    (string)($_SESSION['name'] ?? 'Customer'),
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | TurfMate</title>

    <style>
        :root {
            --green: #216e48;
            --green-dark: #195a3a;
            --background: #f5f7f6;
            --text: #1f2937;
            --muted: #6b7280;
            --white: #ffffff;
            --border: #e5e7eb;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: var(--background);
            color: var(--text);
            line-height: 1.6;
        }

        .top {
            background: white;
            padding: 18px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }

        .brand {
            color: var(--green);
            font-size: 25px;
            font-weight: 800;
            text-decoration: none;
        }

        .nav {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav a {
            color: #374151;
            text-decoration: none;
            font-weight: 600;
            padding: 10px 15px;
            border-radius: 8px;
            transition: all .2s ease;
        }

        .nav a:hover {
            background: #e8f3ed;
            color: var(--green);
        }

        .nav a.active {
            background: var(--green);
            color: white;
        }

        .hero {
            background: linear-gradient(
                120deg,
                #195a3a,
                #287b51
            );
            color: white;
            padding: 75px 7%;
        }

        .hero-inner {
            max-width: 1150px;
            margin: auto;
        }

        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 13px;
            opacity: .85;
            margin-bottom: 12px;
        }

        .hero h1 {
            font-size: clamp(30px, 5vw, 48px);
            line-height: 1.2;
            margin-bottom: 15px;
        }

        .hero p {
            max-width: 620px;
            opacity: .9;
            margin-bottom: 25px;
        }

        .container {
            max-width: 1150px;
            margin: 45px auto;
            padding: 0 20px;
        }

        .section-title {
            font-size: 27px;
            margin-bottom: 8px;
        }

        .section-subtitle {
            color: var(--muted);
            margin-bottom: 28px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }

        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 28px;
            transition: all .25s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(0,0,0,.09);
            border-color: #b7d7c5;
        }

        .card-icon {
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f3ed;
            border-radius: 14px;
            font-size: 25px;
            margin-bottom: 20px;
        }

        .card h3 {
            font-size: 21px;
            margin-bottom: 10px;
        }

        .card p {
            color: var(--muted);
            margin-bottom: 22px;
            min-height: 50px;
        }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            background: var(--green);
            color: white;
            text-decoration: none;
            border-radius: 9px;
            font-weight: 700;
            transition: all .2s ease;
        }

        .btn:hover {
            background: var(--green-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33,110,72,.2);
        }

        .btn-outline {
            background: white;
            color: var(--green);
            border: 2px solid var(--green);
        }

        .btn-outline:hover {
            background: #e8f3ed;
            color: var(--green-dark);
        }

        .hero .btn {
            background: white;
            color: var(--green-dark);
        }

        .hero .btn:hover {
            background: #e8f3ed;
        }

        .footer {
            margin-top: 70px;
            padding: 25px;
            text-align: center;
            color: var(--muted);
            border-top: 1px solid var(--border);
        }

        @media (max-width: 850px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .top {
                flex-direction: column;
            }

            .nav {
                justify-content: center;
            }

            .hero {
                padding: 55px 25px;
            }
        }
    </style>
</head>

<body>

<header class="top">
    <a class="brand" href="dashboard.php">
        ⬡ TurfMate
    </a>

    <nav class="nav" aria-label="Customer navigation">
        <a href="dashboard.php" class="active">Home</a>
        <a href="turfs.php">Explore Turfs</a>
        <a href="my-bookings.php">My Bookings</a>
        <a href="profile.php">My Profile</a>
    </nav>
</header>

<section class="hero">
    <div class="hero-inner">
        <p class="eyebrow">WELCOME TO TURFMATE</p>

        <h1>Book Your Next Game.</h1>

        <p>
            Discover quality sports turfs, check availability,
            and book your favourite playing slots with ease.
        </p>

        <a href="turfs.php" class="btn">
            Explore Available Turfs →
        </a>
    </div>
</section>

<main class="container">

    <h2 class="section-title">
        Welcome back, <?= $name ?>!
    </h2>

    <p class="section-subtitle">
        Manage your turf bookings and personal information
        from one place.
    </p>

    <div class="cards">

        <div class="card">
            <div class="card-icon">🏟️</div>

            <h3>Explore Turfs</h3>

            <p>
                Browse cricket, football, and padel turfs.
                Find your preferred venue and playing time.
            </p>

            <a href="turfs.php" class="btn">
                View Turfs →
            </a>
        </div>

        <div class="card">
            <div class="card-icon">📅</div>

            <h3>My Bookings</h3>

            <p>
                View your booking history, upcoming games,
                and booking confirmation status.
            </p>

            <a href="my-bookings.php" class="btn">
                View My Bookings →
            </a>
        </div>

        <div class="card">
            <div class="card-icon">👤</div>

            <h3>My Profile</h3>

            <p>
                View and update your personal details,
                including your name and phone number.
            </p>

            <a href="profile.php" class="btn">
                View My Profile →
            </a>
        </div>

    </div>

</main>

<footer class="footer">
    © <?= date('Y') ?> TurfMate.
    Your Game, Your Time.
</footer>

</body>
</html>
