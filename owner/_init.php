<?php
/** Shared authentication, PDO connection and security helpers for owner pages. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (function_exists('requireRole')) {
    requireRole(['owner']);
}

// Works with common config/database.php patterns: $pdo, $conn, $db, or returned PDO.
$connectionResult = require_once __DIR__ . '/../config/database.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if ($connectionResult instanceof PDO) {
        $pdo = $connectionResult;
    } elseif (isset($conn) && $conn instanceof PDO) {
        $pdo = $conn;
    } elseif (isset($db) && $db instanceof PDO) {
        $pdo = $db;
    } else {
        foreach (['getPDO', 'getDBConnection', 'getDatabaseConnection', 'getConnection'] as $factory) {
            if (function_exists($factory)) {
                $candidate = $factory();
                if ($candidate instanceof PDO) {
                    $pdo = $candidate;
                    break;
                }
            }
        }
    }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not found. Check config/database.php: it must provide a PDO connection.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tm_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tm_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function tm_csrf(): string
{
    if (empty($_SESSION['tm_owner_csrf'])) {
        $_SESSION['tm_owner_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['tm_owner_csrf'];
}

function tm_verify_csrf(): void
{
    if (!hash_equals(tm_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid form token. Refresh the page and try again.');
    }
}

function tm_flash(string $type, string $message): void
{
    $_SESSION['tm_owner_flash'] = ['type' => $type, 'message' => $message];
}

function tm_take_flash(): ?array
{
    $flash = $_SESSION['tm_owner_flash'] ?? null;
    unset($_SESSION['tm_owner_flash']);
    return is_array($flash) ? $flash : null;
}

function tm_money($amount): string
{
    return 'Rs. ' . number_format((float) $amount, 2);
}

// Accept common session formats; never accept an owner ID from the URL or a form.
$sessionUserId = $_SESSION['user_id']
    ?? $_SESSION['user']['id']
    ?? $_SESSION['user']['user_id']
    ?? $_SESSION['auth_user']['id']
    ?? $_SESSION['id']
    ?? null;

if (filter_var($sessionUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $ownerQuery = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ? LIMIT 1');
    $ownerQuery->execute([(int) $sessionUserId]);
} else {
    $sessionEmail = $_SESSION['email'] ?? $_SESSION['user_email'] ?? $_SESSION['user']['email'] ?? null;
    if (!is_string($sessionEmail) || $sessionEmail === '') {
        http_response_code(403);
        exit('Login session not recognized. Please log out, log in as Owner, and try again.');
    }
    $ownerQuery = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE email = ? LIMIT 1');
    $ownerQuery->execute([$sessionEmail]);
}
$owner = $ownerQuery->fetch(PDO::FETCH_ASSOC);
if (!$owner || $owner['role'] !== 'owner' || $owner['status'] !== 'active') {
    tm_redirect('../auth/login.php');
}
$ownerId = (int) $owner['id'];
