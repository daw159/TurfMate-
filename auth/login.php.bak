<?php

require_once __DIR__ . '/../config/database.php';

session_start();

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Dashboard paths for each role
$redirects = [
    'customer' => '../customer/dashboard.php',
    'owner' => '../owner/dashboard.php',
    'staff' => '../staff/dashboard.php',
    'super_admin' => '../super-admin/dashboard.php'
];

// Redirect already logged-in users
if (isset($_SESSION['user_id'])) {

    $role = $_SESSION['role'] ?? '';

    if (isset($redirects[$role])) {
        header('Location: ' . $redirects[$role]);
        exit;
    }

    // Clear invalid session
    $_SESSION = [];
    session_regenerate_id(true);
}

// Form variables
$errors = [];
$email = '';

// Display registration success message
$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);

// Process login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate password
    if ($password === '') {
        $errors[] = "Please enter your password.";
    }

    // Attempt authentication
    if (empty($errors)) {

        try {

            $pdo = getDBConnection();

            // Find user by email
            $stmt = $pdo->prepare(
                "SELECT
                    id,
                    name,
                    email,
                    password_hash,
                    role,
                    status
                 FROM users
                 WHERE email = ?
                 LIMIT 1"
            );

            $stmt->execute([$email]);

            $user = $stmt->fetch();

            // Verify credentials
            if (
                !$user ||
                !password_verify(
                    $password,
                    $user['password_hash']
                )
            ) {

                $errors[] = "Invalid email or password.";

            } elseif ($user['status'] !== 'active') {

                $errors[] = "Your account is currently inactive.";

            } elseif (!isset($redirects[$user['role']])) {

                $errors[] = "Invalid account role.";

            } else {

                // Prevent session fixation
                session_regenerate_id(true);

                // Store authenticated user information
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                // Generate fresh CSRF token after login
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirect based on role stored in MySQL
                header('Location: ' . $redirects[$user['role']]);
                exit;
            }

        } catch (PDOException $e) {

            error_log($e->getMessage());

            $errors[] = "Something went wrong. Please try again.";
        }
    }
}

// Escape HTML output
function escape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sign In | TurfMate</title>

    <link rel="stylesheet" href="../assets/css/auth.css">
</head>

<body>

<div class="auth-container">

    <div class="auth-card">

        <!-- Branding -->
        <div class="brand">
            <h1>TurfMate</h1>
            <p>Find Your Turf. Book Your Game.</p>
        </div>

        <h2>Welcome Back</h2>

        <p class="subtitle">
            Sign in to your TurfMate account.
        </p>

        <!-- Registration success message -->
        <?php if ($successMessage): ?>

            <div class="success-box">
                <?= escape($successMessage) ?>
            </div>

        <?php endif; ?>

        <!-- Error messages -->
        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <?php foreach ($errors as $error): ?>

                    <p><?= escape($error) ?></p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php">

            <!-- CSRF token -->
            <input
                type="hidden"
                name="csrf_token"
                value="<?= escape($_SESSION['csrf_token']) ?>"
            >

            <!-- Email -->
            <label for="email">Email Address</label>

            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email address"
                value="<?= escape($email) ?>"
                autocomplete="username"
                required
            >

            <!-- Password -->
            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <!-- Sign In -->
            <button type="submit" class="auth-btn">
                Sign In
            </button>

        </form>

        <!-- Signup Link -->
        <p class="auth-footer">
            Don't have an account?
            <a href="register.php">Sign Up</a>
        </p>

        <!-- Homepage Link -->
        <p class="back-home">
            <a href="../index.php">← Back to Homepage</a>
        </p>

    </div>

</div>

</body>
</html>