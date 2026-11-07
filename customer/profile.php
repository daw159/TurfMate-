<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['customer']);
require_once __DIR__ . '/_booking_common.php';
require_once __DIR__ . '/../config/database.php';
function pe($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
$db=getDBConnection();
$userId=(int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($userId <= 0) { http_response_code(401); exit('Please log in again.'); }
$columns=array_column($db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC),'Field');
$pick=function(array $candidates) use ($columns): ?string {foreach($candidates as $c){if(in_array($c,$columns,true))return $c;}return null;};
$nameCol=$pick(['name','full_name','username']);
$phoneCol=$pick(['phone','phone_number','mobile','contact_number']);
$emailCol=$pick(['email','email_address']);
$stmt=$db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');$stmt->execute([$userId]);$user=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$user){http_response_code(404);exit('Account not found.');}
if(empty($_SESSION['profile_csrf']))$_SESSION['profile_csrf']=bin2hex(random_bytes(32));
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['profile_csrf'],(string)($_POST['csrf']??''))){$error='Security check failed. Refresh and try again.';}
 else {
  $name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));
  if($nameCol && (mb_strlen($name)<2 || mb_strlen($name)>150))$error='Name must contain 2–150 characters.';
  elseif($phoneCol && $phone!=='' && !preg_match('/^[0-9+() .-]{7,30}$/',$phone))$error='Enter a valid phone number (7–30 characters).';
  else {
   $changes=[];$values=[];
   if($nameCol){$changes[]='`'.$nameCol.'` = ?';$values[]=$name;}
   if($phoneCol){$changes[]='`'.$phoneCol.'` = ?';$values[]=$phone;}
   if(!$changes)$error='Your users table has no supported editable name or phone columns.';
   else {try{$values[]=$userId;$q=$db->prepare('UPDATE users SET '.implode(', ',$changes).' WHERE id = ?');$q->execute($values);$stmt->execute([$userId]);$user=$stmt->fetch(PDO::FETCH_ASSOC);if($nameCol)$_SESSION['name']=$user[$nameCol];$success='Profile updated successfully.';}catch(PDOException $e){error_log('Profile update: '.$e->getMessage());$error='Unable to save changes. Please try again.';}}
  }
 }
}
$displayName=$nameCol?($user[$nameCol]??'Customer'):($_SESSION['name']??'Customer');
$initial=strtoupper(substr(trim((string)$displayName),0,1));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile | TurfMate</title>
    <link rel="stylesheet" href="../assets/css/profile.css?v=3">
    <link rel="stylesheet" href="../assets/css/customer-layout.css?v=2">
</head>
<body>
<?php bookingNav(); ?>

<main class="profile-wrap">
    <div class="profile-title">
        <div>
            <span class="profile-eyebrow">YOUR ACCOUNT</span>
            <h1>My Profile</h1>
            <p>Manage your TurfMate account information.</p>
        </div>
        <a class="profile-back" href="my-bookings.php">View My Bookings →</a>
    </div>

    <div class="profile-grid">
        <aside class="profile-card profile-aside">
            <div class="profile-avatar"><?= pe($initial) ?></div>
            <h2><?= pe($displayName) ?></h2>
            <p><?= pe($emailCol ? ($user[$emailCol] ?? '') : 'Customer account') ?></p>
            <span class="profile-role">Customer account</span>

            <div class="profile-side-links">
                <a class="selected" href="profile.php">◉ Personal details</a>
                <a href="my-bookings.php">▦ Booking history</a>
                <a href="turfs.php">⌕ Explore turfs</a>
            </div>
        </aside>

        <section class="profile-card profile-form">
            <div class="profile-section-title">
                <div>
                    <h2>Personal information</h2>
                    <p>Keep your contact details up to date.</p>
                </div>
                <span class="profile-status">● Active</span>
            </div>

            <?php if ($success): ?>
                <div class="profile-message success" role="status"><?= pe($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="profile-message error" role="alert"><?= pe($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= pe($_SESSION['profile_csrf']) ?>">

                <div class="profile-fields">
                    <?php if ($nameCol): ?>
                        <label class="profile-field">
                            Full name
                            <input type="text" name="name" maxlength="150" minlength="2"
                                   required autocomplete="name" value="<?= pe($user[$nameCol] ?? '') ?>">
                        </label>
                    <?php endif; ?>

                    <?php if ($phoneCol): ?>
                        <label class="profile-field">
                            Phone number
                            <input type="tel" name="phone" maxlength="30" autocomplete="tel"
                                   placeholder="03xx-xxxxxxx" value="<?= pe($user[$phoneCol] ?? '') ?>">
                        </label>
                    <?php endif; ?>

                    <?php if ($emailCol): ?>
                        <label class="profile-field profile-wide">
                            Email address
                            <input type="email" value="<?= pe($user[$emailCol] ?? '') ?>" readonly>
                            <small>Email is read-only to protect your account.</small>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="profile-actions">
                    <button type="submit" class="profile-save">Save changes</button>
                    <a class="profile-cancel" href="dashboard.php">Back to dashboard</a>
                </div>
            </form>
        </section>
    </div>
</main>

<?php customerFooter(); ?>
</body>
</html>
