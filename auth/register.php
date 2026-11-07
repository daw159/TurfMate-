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
    <link rel="stylesheet" href="../assets/css/register.css?v=1">
</head>
<body class="register-page">
    <main class="auth-container">
        <div class="auth-card register-card">
            <div class="register-brand">
                <span class="register-logo" aria-hidden="true">T</span>
                <div class="register-brand-copy">
                    <strong>TurfMate</strong>
                    <span>Find Your Turf. Book Your Game.</span>
                </div>
            </div>

            <div class="register-intro">
                <span class="register-eyebrow">JOIN THE COMMUNITY</span>
                <h1>Create your account</h1>
                <p>Book your next game or manage your sports facility with TurfMate.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-box" role="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= escape($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="register-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <fieldset class="register-role-group">
                    <legend>Register as</legend>
                    <div class="register-roles">
                        <label class="register-role">
                            <input type="radio" name="role" value="customer" <?= $role === 'customer' ? 'checked' : '' ?> >
                            <span class="register-role-icon" aria-hidden="true">◈</span>
                            <span class="register-role-copy"><strong>Customer</strong><small>Explore & book turfs</small></span>
                            <span class="register-role-check" aria-hidden="true">✓</span>
                        </label>
                        <label class="register-role">
                            <input type="radio" name="role" value="owner" <?= $role === 'owner' ? 'checked' : '' ?> >
                            <span class="register-role-icon" aria-hidden="true">▦</span>
                            <span class="register-role-copy"><strong>Arena Owner</strong><small>Manage your facilities</small></span>
                            <span class="register-role-check" aria-hidden="true">✓</span>
                        </label>
                    </div>
                </fieldset>

                <div class="register-fields">
                    <div class="register-field">
                        <label for="name">Full name</label>
                        <input type="text" id="name" name="name" placeholder="Enter your full name" value="<?= escape($name) ?>" maxlength="100" autocomplete="name" required>
                    </div>
                    <div class="register-field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" value="<?= escape($email) ?>" maxlength="150" autocomplete="email" required>
                    </div>
                    <div class="register-field">
                        <label for="phone">Phone number <span class="optional">(optional)</span></label>
                        <input type="tel" id="phone" name="phone" placeholder="03XX XXXXXXX" value="<?= escape($phone) ?>" maxlength="20" autocomplete="tel">
                    </div>
                    <div class="register-field">
                        <label for="password">Password</label>
                        <div class="password-input-wrap">
                            <input type="password" id="password" name="password" placeholder="Minimum 8 characters" minlength="8" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" aria-label="Show password" aria-controls="password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                    <div class="register-field full-width">
                        <label for="confirm_password">Confirm password</label>
                        <div class="password-input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" minlength="8" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" aria-label="Show confirm password" aria-controls="confirm_password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="auth-btn register-submit">Create account <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
            <p class="back-home"><a href="../index.php">← Back to homepage</a></p>
        </div>
    </main>
    <script>
    document.querySelectorAll('.password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            button.textContent = isHidden ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(isHidden));
            button.setAttribute('aria-label', (isHidden ? 'Hide ' : 'Show ') + (input.id === 'confirm_password' ? 'confirm password' : 'password'));
        });
    });
    </script>
</body>
</html>
