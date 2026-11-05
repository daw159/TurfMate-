<?php
declare(strict_types=1);
require_once __DIR__ . '/_booking_common.php';
function tmDate(string $d): DateTimeImmutable { $x=DateTimeImmutable::createFromFormat('!Y-m-d',$d); if(!$x||$x->format('Y-m-d')!==$d)throw new RuntimeException('Invalid booking date.');return $x; }
function tmTime(string $t): string {if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$t))throw new RuntimeException('Select valid start and end times.');return $t;}
function tmWindow(string $date,string $start,string $end): array {
 tmDate($date);tmTime($start);tmTime($end);if($start===$end)throw new RuntimeException('Start and end cannot be the same.');
 $a=new DateTimeImmutable("$date $start:00");$b=new DateTimeImmutable("$date $end:00");if($b<=$a)$b=$b->modify('+1 day');
 $minutes=(int)(($b->getTimestamp()-$a->getTimestamp())/60);
 if($minutes<30||$minutes>720)throw new RuntimeException('Booking duration must be 30 minutes to 12 hours.');
 if($a<=new DateTimeImmutable())throw new RuntimeException('Choose a future booking time.');return [$a,$b];
}
function tmTurf(PDO $db,int $id): array {foreach(turfRows($db) as $t)if((int)$t['id']===$id)return $t;throw new RuntimeException('Turf not found or inactive.');}
function tmSegments(DateTimeImmutable $a,DateTimeImmutable $b): array {
 $out=[];$cur=$a;while($cur<$b){$next=$cur->setTime(0,0)->modify('+1 day');$end=$b<$next?$b:$next;$out[]=[$cur,$end];$cur=$end;}return $out;
}
function tmQuote(PDO $db,int $id,DateTimeImmutable $a,DateTimeImmutable $b): array {
 $t=tmTurf($db,$id);$base=(float)$t['price'];$q=$db->prepare('SELECT id,name,day_of_week,start_time,end_time,price_per_hour,valid_from,valid_to FROM pricing_rules WHERE turf_id=? AND is_active=1 ORDER BY id');$q->execute([$id]);$rules=$q->fetchAll();
 $items=[];$total=0.0;$applied=[];
 foreach(tmSegments($a,$b) as [$begin,$finish]){
  $day=$begin->format('Y-m-d');$weekday=(int)$begin->format('w');$mid=$begin->setTime(0,0);$edges=[$begin->getTimestamp(),$finish->getTimestamp()];$matches=[];
  foreach($rules as $r){
   if($r['day_of_week']!==null&&(int)$r['day_of_week']!==$weekday)continue;
   if($r['valid_from']!==null&&$day<$r['valid_from'])continue;
   if($r['valid_to']!==null&&$day>$r['valid_to'])continue;
   $rs=$mid->getTimestamp()+tmSeconds($r['start_time']);$re=$mid->getTimestamp()+tmSeconds($r['end_time']);
   // Owner's overnight rules are split at 23:59:59; interpret that boundary as midnight.
   if(substr($r['end_time'],0,8)==='23:59:59')$re=$mid->modify('+1 day')->getTimestamp();
   if($re<=$rs)throw new RuntimeException('Invalid active pricing rule #'.$r['id'].' (time range).');
   if($rs<$finish->getTimestamp()&&$re>$begin->getTimestamp()){$matches[]=['rule'=>$r,'a'=>$rs,'b'=>$re];$edges[]=max($begin->getTimestamp(),$rs);$edges[]=min($finish->getTimestamp(),$re);}
  }
  $edges=array_values(array_unique($edges));sort($edges);
  for($i=0;$i<count($edges)-1;$i++){
   $x=$edges[$i];$y=$edges[$i+1];if($x===$y)continue;
   $hit=array_values(array_filter($matches,fn($r)=>$r['a']<=$x&&$r['b']>=$y));
   if(count($hit)>1){$ids=implode(', ',array_map(fn($r)=>$r['rule']['id'],$hit));throw new RuntimeException('Conflicting active pricing rules (IDs '.$ids.') on '.$day.' at '.date('H:i',$x).'. Ask the owner to resolve the overlap.');}
   $r=$hit?$hit[0]['rule']:null;$rate=$r?(float)$r['price_per_hour']:$base;$cost=round($rate*($y-$x)/3600,4);$total+=$cost;
   if($r)$applied[(int)$r['id']]=true;
   $items[]=['start'=>date('Y-m-d H:i',$x),'end'=>date('Y-m-d H:i',$y),'rate'=>$rate,'amount'=>$cost,'rule'=>$r?$r['name'].' (#'.$r['id'].')':'Base hourly price'];
  }
 }
 return ['total'=>round($total,2),'items'=>$items,'active_rules'=>count($rules),'matched_rules'=>count($applied),'base'=>$base];
}
function tmSeconds(string $time): int {$p=array_map('intval',explode(':',$time));return $p[0]*3600+$p[1]*60+($p[2]??0);}
function tmAvailability(PDO $db,int $id,DateTimeImmutable $a,DateTimeImmutable $b): void {
 $q=$db->prepare("SELECT id,booking_date,start_time,end_time FROM bookings WHERE turf_id=? AND status IN ('pending','confirmed') AND booking_date BETWEEN ? AND ? FOR UPDATE");
 $q->execute([$id,$a->modify('-1 day')->format('Y-m-d'),$b->format('Y-m-d')]);
 foreach($q->fetchAll() as $r){$x=new DateTimeImmutable($r['booking_date'].' '.$r['start_time']);$y=new DateTimeImmutable($r['booking_date'].' '.$r['end_time']);if($a<$y&&$b>$x)throw new RuntimeException('This time is already booked or pending approval. Please select another slot.');}
 // Also respect requests from the earlier prototype, if that table exists.
 $exists=$db->query("SHOW TABLES LIKE 'customer_booking_requests'")->fetchColumn();
 if($exists){$z=$db->prepare("SELECT booking_date,start_time,end_time,ends_next_day FROM customer_booking_requests WHERE turf_id=? AND status IN ('pending','approved') AND booking_date BETWEEN ? AND ? FOR UPDATE");$z->execute([$id,$a->modify('-1 day')->format('Y-m-d'),$b->format('Y-m-d')]);foreach($z->fetchAll() as $r){$x=new DateTimeImmutable($r['booking_date'].' '.$r['start_time']);$y=new DateTimeImmutable($r['booking_date'].' '.$r['end_time']);if((int)$r['ends_next_day'])$y=$y->modify('+1 day');if($a<$y&&$b>$x)throw new RuntimeException('This slot conflicts with an existing booking request.');}}
}
function tmCustomer(PDO $db,int $uid): array {
 $q=$db->prepare('SELECT * FROM users WHERE id=? AND status=\'active\'');$q->execute([$uid]);$u=$q->fetch();if(!$u)throw new RuntimeException('Customer account not found.');return $u;
}
function tmBook(PDO $db,int $id,string $date,string $start,string $end,string $phone): array {
 [$a,$b]=tmWindow($date,$start,$end);$uid=bookingUserId();$phone=trim($phone);if(!preg_match('/^[+0-9 ()-]{7,30}$/',$phone))throw new RuntimeException('Enter a valid contact phone number.');
 $lockName='turfmate_booking_'.$id;$got=false;
 try{
  $q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Booking is busy. Please retry.');$got=true;
  $db->beginTransaction();$db->prepare('SELECT id FROM turfs WHERE id=? FOR UPDATE')->execute([$id]);$t=tmTurf($db,$id);tmAvailability($db,$id,$a,$b);$quote=tmQuote($db,$id,$a,$b);$u=tmCustomer($db,$uid);
  $name=trim((string)($u['name']??$u['full_name']??$_SESSION['name']??'Customer'));if($name==='')$name='Customer';
  $segments=tmSegments($a,$b);$group=null;
  $g=$db->prepare('INSERT INTO tm_booking_groups (customer_id,turf_id,start_at,end_at,total_amount,status) VALUES (?,?,?,?,?,\'pending\')');$g->execute([$uid,$id,$a->format('Y-m-d H:i:s'),$b->format('Y-m-d H:i:s'),$quote['total']]);$group=(int)$db->lastInsertId();
  $ins=$db->prepare("INSERT INTO bookings(turf_id,customer_id,customer_name,customer_phone,booking_date,start_time,end_time,total_amount,status,source,created_by,notes) VALUES(?,?,?,?,?,?,?,?, 'pending','online',?,?)");
  $link=$db->prepare('INSERT INTO tm_booking_group_items(group_id,booking_id) VALUES (?,?)');$allocated=0.0;
  foreach($segments as $i=>[$x,$y]){
   $last=$i===count($segments)-1;$segmentEnd=$y->format('H:i:s')==='00:00:00'?$y->modify('-1 second'):$y;
   // Existing bookings CHECK requires end_time > start_time. Midnight end is 23:59:59.
   $segQuote=tmQuote($db,$id,$x,$y);$amount=$last?round($quote['total']-$allocated,2):round($segQuote['total'],2);$allocated+=$amount;
   $ins->execute([$id,$uid,$name,$phone,$x->format('Y-m-d'),$x->format('H:i:s'),$segmentEnd->format('H:i:s'),$amount,$uid,'TurfMate group #'.$group]);$link->execute([$group,(int)$db->lastInsertId()]);
  }
  $db->commit();return ['group_id'=>$group,'quote'=>$quote];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{if($got){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}}
}
