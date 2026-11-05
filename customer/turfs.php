<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);
require_once __DIR__ . '/../includes/db.php';
function esc($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function chooseColumn(array $cols, array $candidates): ?string { foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c; return null; }
$items = []; $error = null; $filter = trim((string)($_GET['sport'] ?? ''));
try {
    $pdo = getDBConnection();
    $cols = $pdo->query('SHOW COLUMNS FROM turfs')->fetchAll(PDO::FETCH_COLUMN);
    $nameCol = chooseColumn($cols, ['name', 'turf_name', 'title']);
    $sportCol = chooseColumn($cols, ['sport_type', 'sport', 'type']);
    $priceCol = chooseColumn($cols, ['base_price', 'hourly_price', 'price_per_hour', 'price']);
    $statusCol = chooseColumn($cols, ['status', 'is_active', 'active']);
    $facilityCol = chooseColumn($cols, ['facility_id']);
    $idCol = chooseColumn($cols, ['id', 'turf_id']);
    $conditions = [];
    if (in_array('deleted_at', $cols, true)) $conditions[] = 't.deleted_at IS NULL';
    if ($statusCol === 'is_active' || $statusCol === 'active') $conditions[] = "t.`$statusCol` = 1";
    if ($statusCol === 'status') $conditions[] = "LOWER(t.status) IN ('active', 'available', 'enabled', '1')";
    $fields = ['t.*']; $join = '';
    if ($facilityCol) {
        $fcols = $pdo->query('SHOW COLUMNS FROM facilities')->fetchAll(PDO::FETCH_COLUMN);
        $fid = chooseColumn($fcols, ['id','facility_id']);
        $fn = chooseColumn($fcols, ['name','facility_name']);
        if ($fid && $fn) {
            $fields[] = "f.`$fn` AS display_facility";
            $join = " LEFT JOIN facilities f ON f.`$fid` = t.`$facilityCol`";
            if (in_array('deleted_at', $fcols, true)) $conditions[] = 'f.deleted_at IS NULL';
            $fs = chooseColumn($fcols, ['is_active','active','status']);
            if ($fs === 'is_active' || $fs === 'active') $conditions[] = "f.`$fs` = 1";
            if ($fs === 'status') $conditions[] = "LOWER(f.status) IN ('active', 'available', 'enabled', '1')";
        }
    }
    if ($sportCol && $filter !== '') $conditions[] = "t.`$sportCol` LIKE :sport";
    $sql = 'SELECT '.implode(', ', $fields).' FROM turfs t'.$join.($conditions ? ' WHERE '.implode(' AND ', $conditions) : '').' ORDER BY '.($idCol ? "t.`$idCol` DESC" : '1').' LIMIT 100';
    $stmt = $pdo->prepare($sql);
    if ($sportCol && $filter !== '') $stmt->bindValue(':sport', '%'.$filter.'%');
    $stmt->execute(); $rows = $stmt->fetchAll();
    foreach ($rows as $r) $items[] = ['name'=>$nameCol ? ($r[$nameCol] ?? 'Turf') : 'Turf', 'sport'=>$sportCol ? ($r[$sportCol] ?? 'Sports') : 'Sports', 'price'=>$priceCol ? ($r[$priceCol] ?? null) : null, 'facility'=>$r['display_facility'] ?? '', 'id'=>$idCol ? ($r[$idCol] ?? '') : ''];
} catch (Throwable $e) { error_log('TurfMate browse error: '.$e->getMessage()); $error = 'Could not load turfs. Check your database connection and schema with your teammate.'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Browse Turfs | TurfMate</title><link rel="stylesheet" href="../assets/css/customer.css"></head><body><header class="top"><a class="brand" href="dashboard.php">⬡ TurfMate</a><nav><a href="dashboard.php">Home</a><a href="turfs.php">Turfs</a><a href="../auth/logout.php">Logout</a></nav></header><main class="container"><h1>Available Turfs</h1><p class="muted">Explore active sports grounds from your TurfMate database.</p><form class="filter" method="get"><label for="sport">Sport</label><select id="sport" name="sport"><option value="">All sports</option><?php foreach (['Football','Cricket','Padel','Tennis'] as $s): ?><option value="<?= esc($s) ?>" <?= strcasecmp($filter,$s)===0?'selected':'' ?>><?= esc($s) ?></option><?php endforeach ?></select><button class="button" type="submit">Apply Filter</button><a href="turfs.php">Reset</a></form><?php if ($error): ?><p class="notice"><?= esc($error) ?></p><?php elseif (!$items): ?><p class="notice">No active turfs found for this filter.</p><?php else: ?><div class="cards turf-grid"><?php foreach ($items as $item): ?><article class="tile turf-card"><div class="turf-photo"></div><div class="turf-body"><span class="pill"><?= esc($item['sport']) ?></span><h3><?= esc($item['name']) ?></h3><p class="muted"><?= esc($item['facility']) ?></p><p><strong><?= $item['price'] !== null ? 'Rs. '.esc(number_format((float)$item['price'], 0)).' / hour (base rate)' : 'Price calculated during booking' ?></strong></p><span class="muted">Booking and live pricing coming next</span></div></article><?php endforeach ?></div><?php endif ?></main></body></html>
