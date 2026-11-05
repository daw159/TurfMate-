<?php
require_once __DIR__.'/_booking_common.php';requireRole(['customer']);
try {$rows=turfRows(bdb());$err=null;}catch(Throwable $e){$rows=[];$err=$e->getMessage();}
$search=trim($_GET['q']??'');$sport=trim($_GET['sport']??'');
$rows=array_values(array_filter($rows,fn($r)=>(!$search||stripos(implode(' ',[$r['name'],$r['facility'],$r['location']]),$search)!==false)&&(!$sport||stripos((string)$r['sport'],$sport)!==false)));
bookingHead('Explore Turfs');
?><main class="wrap"><div class="heading"><h1>Available Turfs</h1><p>Find your next game and book your favorite sports ground.</p></div>
<form class="filters" method="get"><input name="q" placeholder="Search turf, facility, location" value="<?=esc($search)?>"><select name="sport"><option value="">All sports</option><?php foreach(['Football','Cricket','Padel','Tennis'] as $s):?><option value="<?=esc($s)?>" <?=$sport===$s?'selected':''?>><?=esc($s)?></option><?php endforeach;?></select><button>Apply Filters</button></form>
<?php if($err):?><div class="alert">Cannot load turfs: <?=esc($err)?></div><?php endif;?><div class="grid"><?php foreach($rows as $r):?><article class="card"><img src="../assets/images/<?=esc(sportImage((string)$r['sport']))?>" alt="<?=esc($r['sport'])?> turf"><div class="pad"><span class="chip"><?=esc($r['sport']?:'Sports turf')?></span><h2><?=esc($r['name'])?></h2><p>📍 <?=esc($r['location']?:$r['facility'])?></p><div class="between"><strong><?=is_numeric($r['price'])?'From Rs. '.number_format((float)$r['price']).' / hour':'Price on request'?></strong><a class="btn" href="turf-details.php?id=<?=urlencode((string)$r['id'])?>">View Details</a></div></div></article><?php endforeach;?></div><?php if(!$rows&&!$err):?><p>No matching active turfs found.</p><?php endif;?></main><?php bookingFoot(); ?>
