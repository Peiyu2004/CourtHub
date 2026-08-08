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

<link rel="stylesheet" href="<?= h(app_url('/css/auth.css')) ?>?v=1.0">

<div class="auth-page-wrapper">
    <div class="auth-card-container">
        
        <!-- Left Column: Image set as CSS Background -->
        <div class="auth-visual-side" style="background-image: url('<?= h(app_url('/images/signUp.png')) ?>');">
            <div class="auth-visual-overlay">
                <div class="auth-brand-badge">
                    <h2>CourtHub</h2>
                    <p>Sungai Long Sports Center</p>
                </div>
            </div>
        </div>

        <!-- Right Column: Register Form -->
        <div class="auth-form-side">
            <div class="auth-header">
                <h2>Create an Account</h2>
                <p class="auth-subtitle">Join us to book courts & buy gear.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="auth-form">
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= h($full_name) ?>" placeholder="Enter your full name" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= h($email) ?>" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= h($phone) ?>" placeholder="Enter your phone number">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a password (min. 6 chars)" required minlength="6">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" required minlength="6">
                </div>

                <button type="submit" class="btn btn-auth-submit">Register</button>
            </form>

            <p class="auth-footer-text">
                Already have an account? <a href="login.php">Login here</a>
            </p>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
