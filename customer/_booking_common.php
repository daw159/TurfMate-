<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
function bdb(): PDO { return getDBConnection(); }
function esc($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function cols(PDO $db, string $table): array { $q=$db->query('SHOW COLUMNS FROM `'.$table.'`');return array_column($q->fetchAll(),'Field'); }
function pick(array $cols, array $options): ?string {foreach($options as $x) if(in_array($x,$cols,true))return $x;return null;}
function qid(string $s): string {return '`'.str_replace('`','``',$s).'`';}
function turfRows(PDO $db): array {
 $tc=cols($db,'turfs'); $fc=cols($db,'facilities');
 $tid=pick($tc,['id','turf_id']); $fid=pick($tc,['facility_id']);$fpk=pick($fc,['id','facility_id']);
 $tn=pick($tc,['name','turf_name','title']);$sport=pick($tc,['sport_type','sport','type','category']);
 $price=pick($tc,['base_price','hourly_price','price_per_hour','price','base_hourly_price']);
 $desc=pick($tc,['description','details']);$fn=pick($fc,['name','facility_name']);$loc=pick($fc,['location','address','city']);
 if(!$tid || !$tn) throw new RuntimeException('Unknown turfs table columns: '.implode(', ',$tc));
 $fields=['t.'.qid($tid).' AS id','t.'.qid($tn).' AS name', $sport?'t.'.qid($sport).' AS sport':"'' AS sport",$price?'t.'.qid($price).' AS price':'NULL AS price',$desc?'t.'.qid($desc).' AS description':"'' AS description"];
 $join='';
 if($fid && $fpk){$join=' LEFT JOIN facilities f ON f.'.qid($fpk).'=t.'.qid($fid);$fields[]=$fn?'f.'.qid($fn).' AS facility':"'' AS facility";$fields[]=$loc?'f.'.qid($loc).' AS location':"'' AS location";}
 else {$fields[]="'' AS facility";$fields[]="'' AS location";}
 $where=[];foreach(['deleted_at'] as $c)if(in_array($c,$tc,true))$where[]='t.'.qid($c).' IS NULL';
 $active=pick($tc,['is_active','active']);if($active)$where[]='t.'.qid($active).'=1';
 $status=pick($tc,['status']);if($status)$where[]="LOWER(t.".qid($status).") IN ('active','available')";
 if($join){if(in_array('deleted_at',$fc,true))$where[]='f.deleted_at IS NULL';$fa=pick($fc,['is_active','active']);if($fa)$where[]='f.'.qid($fa).'=1';}
 $sql='SELECT '.implode(',',$fields).' FROM turfs t'.$join.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.'.qid($tid).' DESC';
 return $db->query($sql)->fetchAll();
}
function sportImage(string $sport): string {$s=strtolower($sport);return str_contains($s,'cricket')?'cricket.jpg':(str_contains($s,'padel')||str_contains($s,'paddle')||str_contains($s,'tennis')?'padel.jpg':'football.jpg');}
function bookingNav(): void {$page=basename($_SERVER['SCRIPT_NAME']??'');echo '<header class="nav"><a class="logo" href="dashboard.php">⬡ TurfMate</a><nav>';foreach(['dashboard.php'=>'Home','turfs.php'=>'Explore Turfs','my-bookings.php'=>'My Bookings'] as $url=>$label){$active=$page===$url||($page==='turf-details.php'&&$url==='turfs.php')?' class="active"':'';echo '<a'.$active.' href="'.$url.'">'.$label.'</a>';}echo '<a href="../auth/logout.php">Logout</a></nav></header>';}
function bookingHead(string $title): void {echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($title).' | TurfMate</title><link rel="stylesheet" href="../assets/css/booking.css"></head><body>';bookingNav();}
function bookingFoot(): void {echo '</body></html>';}
function bookingUserId(): int {foreach(['user_id','id'] as $k)if(isset($_SESSION[$k]))return (int)$_SESSION[$k];throw new RuntimeException('Customer session ID missing. Inspect includes/auth.php');}
