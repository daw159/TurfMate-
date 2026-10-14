<?php

require_once __DIR__ . '/../config/database.php';

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$errors = [];
$name = '';
$email = '';
$phone = '';
$role = 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        http_response_code(403);
        exit('Invalid form submission. Please refresh and try again.');
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'customer';

    // Validate name
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = "Enter a valid name (maximum 100 characters).";
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = "Enter a valid email address.";
    }

    // Validate phone
    if ($phone !== '' && (strlen($phone) > 20 || !preg_match('/^[0-9+\-\s()]+$/', $phone))) {
        $errors[] = "Enter a valid phone number.";
    }

    // Validate password
    if (strlen($password) < 8) {
        $errors[] = "Password must contain at least 8 characters.";
    }

    // Confirm password
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    // Only customer and owner can register publicly
    if (!in_array($role, ['customer', 'owner'], true)) {
        $errors[] = "Invalid account type.";
    }

    if (empty($errors)) {

        try {
            $pdo = getDBConnection();

            // Check whether email already exists
            $stmt = $pdo->prepare(
                "SELECT id FROM users WHERE email = ?"
            );

            $stmt->execute([$email]);

            if ($stmt->fetch()) {

                $errors[] = "This email is already registered.";

            } else {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    "INSERT INTO users
                    (name, email, phone, password_hash, role, status)
                    VALUES (?, ?, ?, ?, ?, 'active')"
                );

                $stmt->execute([
                    $name,
                    $email,
                    $phone ?: null,
                    $hashedPassword,
                    $role
                ]);

                // Redirect to login after successful registration
                $_SESSION['registration_success'] =
                    "Account created successfully. Please log in.";

                header("Location: login.php");
                exit;
            }

        } catch (PDOException $e) {

            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = "This email is already registered.";
            } else {
                $errors[] = "Something went wrong. Please try again.";
            }
        }
    }
}

// Escape user input before displaying it
function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sign Up | TurfMate</title>

    <link rel="stylesheet" href="../assets/css/auth.css">
</head>

<body>

<div class="auth-container">

    <div class="auth-card">

        <div class="brand">
            <h1>TurfMate</h1>
            <p>Find Your Turf. Book Your Game.</p>
        </div>

        <h2>Create Account</h2>

        <p class="subtitle">
            Join TurfMate and get started today.
        </p>

        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <?php foreach ($errors as $error): ?>

                    <p><?= escape($error) ?></p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <form method="POST" action="register.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escape($_SESSION['csrf_token']) ?>"
            >

            <label>Register As</label>

            <div class="role-selection">

                <label class="role-option">
                    <input
                        type="radio"
                        name="role"
                        value="customer"
                        <?= $role === 'customer' ? 'checked' : '' ?>
                    >
                    Customer
                </label>

                <label class="role-option">
                    <input
                        type="radio"
                        name="role"
                        value="owner"
                        <?= $role === 'owner' ? 'checked' : '' ?>
                    >
                    Arena Owner
                </label>

            </div>

            <label>Full Name</label>

            <input
                type="text"
                name="name"
                placeholder="Enter your full name"
                value="<?= escape($name) ?>"
                maxlength="100"
                required
            >

            <label>Email Address</label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email"
                value="<?= escape($email) ?>"
                maxlength="150"
                required
            >

            <label>Phone Number</label>

            <input
                type="tel"
                name="phone"
                placeholder="03XX XXXXXXX"
                value="<?= escape($phone) ?>"
                maxlength="20"
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                placeholder="Minimum 8 characters"
                minlength="8"
                required
            >

            <label>Confirm Password</label>

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm your password"
                minlength="8"
                required
            >

            <button type="submit" class="auth-btn">
                Create Account
            </button>

        </form>

        <p class="auth-footer">
            Already have an account?
            <a href="login.php">Sign In</a>
        </p>

        <p class="back-home">
            <a href="../index.php">← Back to Homepage</a>
        </p>

    </div>

</div>

</body>
</html>