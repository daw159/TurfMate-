<?php
/**
 * TurfMate Super Admin management shared functions.
 * This file must only be included after requireRole(['super_admin']).
 */
declare(strict_types=1);

function adminEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminToken(): string
{
    if (empty($_SESSION['superadmin_management_csrf'])) {
        $_SESSION['superadmin_management_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['superadmin_management_csrf'];
}

function adminCheckToken(string $token): bool
{
    return hash_equals(adminToken(), $token);
}

function adminFlash(string $message): void
{
    $_SESSION['superadmin_management_flash'] = $message;
}

function adminTakeFlash(): string
{
    $message = (string) ($_SESSION['superadmin_management_flash'] ?? '');
    unset($_SESSION['superadmin_management_flash']);
    return $message;
}

function adminPageStart(string $title, string $section, string $description): void
{
    $active = [
        'dashboard' => 'Dashboard',
        'facilities' => 'Facility Approvals',
        'owners' => 'Owner Management',
        'users' => 'User Management',
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= adminEscape($title) ?> | TurfMate</title>
    <link rel="stylesheet" href="../assets/css/super-admin.css?v=2">
    <link rel="stylesheet" href="../assets/css/admin-management.css?v=1">
</head>
<body>
<div class="app-layout">
    <aside class="sidebar" aria-label="Super Admin navigation">
        <a class="brand" href="dashboard.php">
            <span class="brand-icon">T</span>
            <span>TurfMate <small>Platform Administration</small></span>
        </a>
        <p class="sidebar-label">PLATFORM MANAGEMENT</p>
        <nav class="sidebar-nav">
            <?php foreach ($active as $path => $label): ?>
                <a class="nav-link <?= $section === $path ? 'active' : '' ?>"
                   href="<?= adminEscape($path) ?>.php">
                    <?= $section === $path ? '●' : '○' ?> <span><?= adminEscape($label) ?></span>
                </a>
            <?php endforeach; ?>
            <span class="nav-link disabled">▥ <span>Platform Reports (soon)</span></span>
        </nav>
        <div class="sidebar-footer">
            <span class="avatar">SA</span>
            <div><strong>Super Admin</strong><small>Platform Administration</small></div>
        </div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <div>
                <p class="eyebrow">TURFMATE / PLATFORM ADMINISTRATION</p>
                <h1><?= adminEscape($title) ?></h1>
            </div>
            <a class="logout-link" href="../auth/logout.php">Log out ↗</a>
        </header>
        <main class="content">
            <div class="welcome-row">
                <div><h2><?= adminEscape($title) ?></h2><p><?= adminEscape($description) ?></p></div>
            </div>
    <?php
}

function adminPageEnd(): void
{
    ?>
        </main>
    </div>
</div>
</body>
</html>
    <?php
}
