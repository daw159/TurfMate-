<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Restrict a page to authenticated users
 * having one of the allowed roles.
 */
function requireRole(array $allowedRoles): void
{
    // Check whether user is logged in
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {

        header('Location: /turfmate/auth/login.php');
        exit;
    }

    // Session inactivity timeout: 30 minutes
    $timeout = 1800;

    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > $timeout
    ) {

        $_SESSION = [];
        session_destroy();

        header('Location: /turfmate/auth/login.php');
        exit;
    }

    // Check account status and current role in database
    require_once __DIR__ . '/../config/database.php';

    try {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare(
            "SELECT role, status FROM users WHERE id = ? LIMIT 1"
        );

        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            $_SESSION = [];
            session_destroy();

            header('Location: /turfmate/auth/login.php');
            exit;
        }

        $_SESSION['role'] = $user['role'];

    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(503);
        exit('Service temporarily unavailable.');
    }

    // Check permitted roles
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {

        http_response_code(403);

        exit('403 Forbidden: You do not have permission to access this page.');
    }

    $_SESSION['last_activity'] = time();
}