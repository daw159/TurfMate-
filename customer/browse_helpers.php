<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);
require_once __DIR__ . '/../config/database.php';
function tm_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function tm_columns(PDO $db, string $table): array {
    $q=$db->query('SHOW COLUMNS FROM `'.$table.'`');
    return array_column($q->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
function tm_pick(array $row, array $keys, $default='') {
    foreach($keys as $k) if(array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='') return $row[$k];
    return $default;
}
function tm_is_active(array $row): bool {
    if (array_key_exists('deleted_at',$row) && $row['deleted_at']!==null) return false;
    if (array_key_exists('is_active',$row) && !in_array(strtolower((string)$row['is_active']),['1','true','yes'],true)) return false;
    if (array_key_exists('status',$row) && in_array(strtolower((string)$row['status']),['inactive','disabled','deleted','archived'],true)) return false;
    return true;
}
function tm_load(): array {
    $db=getDBConnection();
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('turfs',$tables,true)) throw new RuntimeException('The turfs table was not found.');
    $turfs=$db->query('SELECT * FROM `turfs` ORDER BY `id` DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    $facilities=[];
    if(in_array('facilities',$tables,true)) {
        foreach($db->query('SELECT * FROM `facilities`')->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $key=tm_pick($f,['id','facility_id']);
            if($key!=='') $facilities[(string)$key]=$f;
        }
    }
    $out=[];
    foreach($turfs as $t) {
        if(!tm_is_active($t)) continue;
        $f=$facilities[(string)tm_pick($t,['facility_id'])] ?? [];
        if($f && !tm_is_active($f)) continue;
        $out[]=['raw'=>$t,'facility'=>$f,'id'=>tm_pick($t,['id','turf_id']),
          'name'=>tm_pick($t,['name','turf_name','title'],'Sports Turf'),
          'sport'=>tm_pick($t,['sport_type','sport','type','category'],'Sports'),
          'location'=>tm_pick($f,['location','address','city'],tm_pick($t,['location','address','city'],'Location not listed')),
          'facility_name'=>tm_pick($f,['name','facility_name'],'TurfMate Venue'),
          'price'=>tm_pick($t,['base_price','hourly_price','price_per_hour','price','base_hourly_price'],''),
          'description'=>tm_pick($t,['description','details'],'')];
    }
    return $out;
}
function tm_header(string $title): void { $name=tm_h($_SESSION['name']??'Customer'); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=tm_h($title)?> | TurfMate</title><link rel="stylesheet" href="/turfmate/assets/css/customer-browse.css?v=2"></head><body>
<header class="tm-header"><a class="tm-logo" href="/turfmate/customer/dashboard.php">⬡ TurfMate</a><nav><a href="/turfmate/customer/dashboard.php">Dashboard</a><a href="/turfmate/customer/turfs.php">Explore Turfs</a><a href="/turfmate/auth/logout.php">Logout</a></nav><span class="tm-user">Hi, <?=$name?></span></header>
<?php }
function tm_footer(): void { echo '<footer class="tm-footer">TurfMate · Find your next game</footer></body></html>'; }
