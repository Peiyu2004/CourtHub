<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

// If already logged in, no need to see the register page again
if (isLoggedIn()) {
    header("Location: " . app_url('/index.php'));
    exit();
}

$errors = [];
$full_name = $email = $phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // ---- Validation ----
    if ($full_name === '') {
        $errors[] = "Full name is required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Check email is not already registered
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "This email is already registered. Please login instead.";
        }
        $stmt->close();
    }

    // ---- Insert new user ----
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare(
            "INSERT INTO users (full_name, email, password_hash, phone, role)
             VALUES (?, ?, ?, ?, 'customer')"
        );
        $stmt->bind_param("ssss", $full_name, $email, $password_hash, $phone);

        if ($stmt->execute()) {
            $_SESSION['register_success'] = true;
            header("Location: " . app_url('/auth/login.php'));
            exit();
        } else {
            $errors[] = "Something went wrong. Please try again.";
        }
        $stmt->close();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width: 480px; margin: 0 auto;">
    <h2>Create an Account</h2>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin-left: 18px;">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= h($full_name) ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= h($email) ?>" required>
        </div>

        <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone" value="<?= h($phone) ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="6">
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
        </div>

        <button type="submit" class="btn">Register</button>
    </form>

    <p style="margin-top: 14px;">Already have an account? <a href="login.php" style="color:#1b2a4a; font-weight:600;">Login here</a></p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
