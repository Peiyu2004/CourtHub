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

<div class="card" style="max-width: 420px; margin: 0 auto;">
    <h2>Login</h2>

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

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= h($email) ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn">Login</button>
    </form>

    <p style="margin-top: 14px;">Don't have an account? <a href="register.php" style="color:#1b2a4a; font-weight:600;">Register here</a></p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
