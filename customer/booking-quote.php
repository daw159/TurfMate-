<?php
require_once __DIR__.'/booking_engine.php';
header('Content-Type: application/json; charset=utf-8');
try{
 requireRole(['customer']);if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
 if(!hash_equals($_SESSION['tm_booking_csrf']??'',(string)($_POST['csrf']??'')))throw new RuntimeException('Invalid session token. Refresh the page.');
 $id=filter_var($_POST['turf_id']??null,FILTER_VALIDATE_INT);if(!$id)throw new RuntimeException('Invalid turf.');
 [$a,$b]=tmWindow((string)($_POST['date']??''),(string)($_POST['start']??''),(string)($_POST['end']??''));
 $db=bdb();$quote=tmQuote($db,(int)$id,$a,$b);
 // A read-only availability check (no lock); submission repeats check under a lock.
 tmAvailability($db,(int)$id,$a,$b);
 echo json_encode(['ok'=>true,'quote'=>$quote],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
