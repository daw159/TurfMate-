<?php
require_once __DIR__.'/browse_helpers.php';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$item=null;$error=null;
try {foreach(tm_load() as $t) if((string)$t['id']===(string)$id && $id!==false && $id!==null){$item=$t;break;}}catch(Throwable $e){error_log('TurfMate details: '.$e->getMessage());$error='Unable to load turf details.';}
if(!$item && !$error) http_response_code(404);
tm_header('Turf Details');
?>
<main class="tm-wrap tm-main"><p><a class="tm-back" href="turfs.php">← Back to all turfs</a></p>
<?php if($error): ?><div class="tm-notice"><?=tm_h($error)?></div><?php elseif(!$item): ?><div class="tm-empty"><h2>Turf not found</h2><p>This turf may have been removed or deactivated.</p></div><?php else: ?>
<div class="tm-details"><div><div class="tm-details-photo"></div><div class="tm-info"><p class="tm-kicker"><?=tm_h(ucfirst($item['sport']))?></p><h1><?=tm_h($item['name'])?></h1><p>📍 <?=tm_h($item['location'])?></p><h3>About this turf</h3><p><?=nl2br(tm_h($item['description']?:'A sports turf available at this facility.'))?></p><h3>Facility</h3><p><?=tm_h($item['facility_name'])?></p></div></div><aside class="tm-book-panel"><h2>Plan your game</h2><?php if($item['price']!=='' && is_numeric($item['price'])): ?><p class="tm-price">Rs <?=number_format((float)$item['price'],0)?> <small>/ hour · base rate</small></p><?php else: ?><p>Price depends on selected date and time.</p><?php endif; ?><p class="tm-hint">Final pricing and availability will be calculated during booking, using your existing pricing rules.</p><div class="tm-notice">Online booking is the next module. This page does not submit a booking yet.</div><a class="tm-btn tm-full" href="turfs.php">Browse More Turfs</a></aside></div>
<?php endif; ?></main><?php tm_footer(); ?>
