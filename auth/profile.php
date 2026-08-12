<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

// Protect page access
requireLogin();

$user_id = $_SESSION['user_id'];
$errors_profile = [];
$errors_password = [];
$success_profile = '';
$success_password = '';

// Handle Profile Info Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone']);

    if ($full_name === '') {
        $errors_profile[] = "Full name is required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors_profile[] = "Please enter a valid email address.";
    }

    // Check if email is taken by another user
    if (empty($errors_profile)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors_profile[] = "This email is already in use by another account.";
        }
        $stmt->close();
    }

    // Update database
    if (empty($errors_profile)) {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
        $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);

        if ($stmt->execute()) {
            $_SESSION['full_name'] = $full_name; // Sync session name
            $success_profile = "Profile information updated successfully.";
        } else {
            $errors_profile[] = "Failed to update profile. Please try again.";
        }
        $stmt->close();
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'];
    $new_password     = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $errors_password[] = "All password fields are required.";
    } elseif (strlen($new_password) < 6) {
        $errors_password[] = "New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $errors_password[] = "New passwords do not match.";
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            $errors_password[] = "Incorrect current password.";
        } else {
            // Re-hash new password and update
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param("si", $new_hash, $user_id);

            if ($stmt->execute()) {
                $success_password = "Password changed successfully.";
            } else {
                $errors_password[] = "Failed to update password. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Fetch current user data for pre-filling fields
$stmt = $conn->prepare("SELECT full_name, email, phone, role, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>My Profile</h1>
    <p class="muted">Manage your profile details and account security.</p>
    <div>
        <span class="tag">Role: <?= h(ucfirst($current_user['role'])) ?></span>
    </div>
</section>

    <div class="grid two-col">
        <!-- Profile Details Form -->
        <div class="card">
            <h2>Personal Information</h2>

            <?php if (!empty($success_profile)): ?>
                <div class="alert alert-success">
                    <p><?= h($success_profile) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors_profile)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors_profile as $err): ?>
                        <p><?= h($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= h($current_user['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= h($current_user['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= h($current_user['phone']) ?>" placeholder="e.g. 012-3456789">
                </div>

                <button type="submit" class="btn">Update Information</button>
            </form>
        </div>

        <!-- Change Password Form -->
        <div class="card">
            <h2>Change Password</h2>

            <?php if (!empty($success_password)): ?>
                <div class="alert alert-success">
                    <p><?= h($success_password) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors_password)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors_password as $err): ?>
                        <p><?= h($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="passwordForm">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>

                <button type="submit" class="btn btn-secondary">Update Password</button>
            </form>
        </div>
    </div>
</div>

<script src="<?= h(asset_url('/js/auth.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>