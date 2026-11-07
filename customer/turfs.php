<?php
declare(strict_types=1);
require_once __DIR__ . '/_booking_common.php';
requireRole(['customer']);

try {
    $rows = turfRows(bdb());
    $error = null;
} catch (Throwable $exception) {
    $rows = [];
    $error = $exception->getMessage();
}

$search = trim((string) ($_GET['q'] ?? ''));
$sport = trim((string) ($_GET['sport'] ?? ''));
$rows = array_values(array_filter($rows, static function ($row) use ($search, $sport): bool {
    $matchesSearch = $search === '' || stripos(implode(' ', [
        $row['name'], $row['facility'], $row['location'],
    ]), $search) !== false;
    $matchesSport = $sport === '' || stripos((string) $row['sport'], $sport) !== false;
    return $matchesSearch && $matchesSport;
}));

bookingHead('Explore Turfs');
?>
<main class="wrap">
    <div class="heading">
        <h1>Available Turfs</h1>
        <p>Find your next game and book your favorite sports ground.</p>
    </div>

    <form class="filters" method="get" action="turfs.php">
        <input name="q" aria-label="Search turfs" placeholder="Search turf, facility, location" value="<?= esc($search) ?>">
        <select name="sport" aria-label="Select sport">
            <option value="">All sports</option>
            <?php foreach (['Football', 'Cricket', 'Padel', 'Tennis'] as $option): ?>
                <option value="<?= esc($option) ?>" <?= $sport === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Apply Filters</button>
    </form>

    <?php if ($error): ?>
        <div class="alert">Cannot load turfs: <?= esc($error) ?></div>
    <?php endif; ?>

    <div class="grid">
        <?php foreach ($rows as $turf): ?>
            <article class="card">
                <img src="../assets/images/<?= esc(sportImage((string) $turf['sport'])) ?>" alt="<?= esc($turf['sport']) ?> turf">
                <div class="pad">
                    <span class="chip"><?= esc($turf['sport'] ?: 'Sports turf') ?></span>
                    <h2><?= esc($turf['name']) ?></h2>
                    <p>📍 <?= esc($turf['location'] ?: $turf['facility']) ?></p>
                    <div class="between">
                        <strong><?= is_numeric($turf['price']) ? 'From Rs. ' . number_format((float) $turf['price']) . ' / hour' : 'Price on request' ?></strong>
                        <a class="btn" href="turf-details.php?id=<?= urlencode((string) $turf['id']) ?>">View Details</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows && !$error): ?>
        <div class="panel"><p>No matching active turfs found.</p></div>
    <?php endif; ?>
</main>
<?php bookingFoot(); ?>
