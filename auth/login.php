<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

if (isLoggedIn()) {
    header("Location: " . app_url('/index.php'));
    exit();
}

$errors = [];
$email = '';
$show_register_success = isset($_SESSION['register_success']);
unset($_SESSION['register_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($email === '' || $password === '') {
        $errors[] = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, password_hash, role FROM users WHERE email = ?"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Use a generic error message on purpose - don't reveal whether
        // the email exists or the password was wrong, as a basic security practice
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = "Invalid email or password.";
        } else {
            // Regenerate session ID to prevent session fixation attacks
            session_regenerate_id(true);

            // Login successful - store what we need in the session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            // Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: " . app_url('/admin/dashboard.php'));
            } else {
                header("Location: " . app_url('/index.php'));
            }
            exit();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= h(app_url('/css/auth.css')) ?>?v=1.0">

<div class="auth-page-wrapper">
    <div class="auth-card-container">
        
        <!-- Left Column: Image set as CSS Background -->
        <div class="auth-visual-side" style="background-image: url('<?= h(app_url('/images/login.jpg')) ?>');">
            <div class="auth-visual-overlay">
                <div class="auth-brand-badge">
                    <h2>CourtHub</h2>
                    <p>Sungai Long Sports Center</p>
                </div>
            </div>
        </div>

        <!-- Right Column: Login Form -->
        <div class="auth-form-side">
            <div class="auth-header">
                <h2>Welcome to CourtHub</h2>
                <p class="auth-subtitle">Train. Play. Grow.</p>
            </div>

            <?php if ($show_register_success): ?>
                <div class="alert alert-success">Registration successful! Please login below.</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="auth-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= h($email) ?>" placeholder="Enter your email" required>
                </div>

                <!-- <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div> -->
                <div class="form-group password-group">
                    <label for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">👁️</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-auth-submit">Login</button>
            </form>

            <p class="auth-footer-text">
                Don't have an account? <a href="register.php">Register here</a>
            </p>
        </div>

    </div>
</div>

<script src="<?= h(app_url('/js/auth.js')) ?>?v=1.0"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
