<?php
require_once __DIR__.'/browse_helpers.php';
$error=null;$items=[];
try {$items=tm_load();} catch(Throwable $e) {error_log('TurfMate browse: '.$e->getMessage());$error='Unable to load turfs. Check database connection and schema.';}
$sport=trim((string)($_GET['sport']??''));$search=trim((string)($_GET['q']??''));
$sports=array_values(array_unique(array_map(fn($t)=>$t['sport'],$items)));sort($sports);
$filtered=array_values(array_filter($items,fn($t)=>(!$sport||strcasecmp($t['sport'],$sport)===0)&&(!$search||stripos($t['name'].' '.$t['location'].' '.$t['facility_name'].' '.$t['sport'],$search)!==false)));
tm_header('Explore Turfs');
?>
<section class="tm-banner"><div class="tm-wrap"><p class="tm-kicker">FIND YOUR GAME</p><h1>Explore Sports Turfs</h1><p>Discover great venues for cricket, football, padel and more.</p></div></section>
<main class="tm-wrap tm-main"><form class="tm-filters" method="get" action="turfs.php"><label>Search venues<input type="search" name="q" value="<?=tm_h($search)?>" placeholder="Search turf, facility or location"></label><label>Sport<select name="sport"><option value="">All sports</option><?php foreach($sports as $s): ?><option value="<?=tm_h($s)?>" <?=$sport===$s?'selected':''?>><?=tm_h(ucfirst($s))?></option><?php endforeach; ?></select></label><button class="tm-btn" type="submit">Find Turfs</button><a class="tm-reset" href="turfs.php">Clear</a></form>
<div class="tm-section-heading"><div><h2>Available Turfs</h2><p><?=count($filtered)?> venue(s) found</p></div></div>
<?php if($error): ?><div class="tm-notice"><?=tm_h($error)?></div><?php elseif(!$filtered): ?><div class="tm-empty"><h3>No turfs found</h3><p>Try another sport or search term. Only active, non-deleted turfs are displayed.</p></div><?php else: ?><div class="tm-grid"><?php foreach($filtered as $t): ?><article class="tm-card"><div class="tm-photo"><span class="tm-sport"><?=tm_h(ucfirst($t['sport']))?></span></div><div class="tm-card-body"><p class="tm-facility"><?=tm_h($t['facility_name'])?></p><h3><?=tm_h($t['name'])?></h3><p class="tm-location">📍 <?=tm_h($t['location'])?></p><div class="tm-card-foot"><div><?php if($t['price']!=='' && is_numeric($t['price'])): ?><strong>Rs <?=number_format((float)$t['price'],0)?></strong><small> / hour · base rate</small><?php else: ?><small>Pricing shown on booking</small><?php endif; ?></div><a class="tm-btn" href="turf-details.php?id=<?=urlencode((string)$t['id'])?>">View Details</a></div></div></article><?php endforeach; ?></div><?php endif; ?>
</main><?php tm_footer(); ?>
