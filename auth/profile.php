<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

// Protect page access
requireLogin();

$user_id = $_SESSION['user_id'];
$errors_profile = [];
$errors_password = [];
$errors_delete = [];
$success_profile = '';
$success_password = '';

// Fetch current user data first so we have access to user details/role
$stmt = $conn->prepare("SELECT full_name, email, phone, role, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

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
    if ($phone === '') {
        $errors_profile[] = "Phone number is required.";
    } elseif (!preg_match('/^[0-9+\s\-()]{7,20}$/', $phone)) {
        $errors_profile[] = "Please enter a valid phone number format.";
    }

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

    if (empty($errors_profile)) {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
        $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);

        if ($stmt->execute()) {
            $_SESSION['full_name'] = $full_name;
            $current_user['full_name'] = $full_name;
            $current_user['email'] = $email;
            $current_user['phone'] = $phone;
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
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            $errors_password[] = "Incorrect current password.";
        } else {
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

// Handle Delete Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    // RESTRICTION: Block Admins from deleting their own account via Profile page
    if (($current_user['role'] ?? '') === 'admin') {
        $errors_delete[] = "Admin accounts cannot be self-deleted. Please contact another administrator.";
    } else {
        $delete_password = $_POST['delete_password'] ?? '';

        if ($delete_password === '') {
            $errors_delete[] = "Please enter your password to confirm account deletion.";
        } else {
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($delete_password, $user['password_hash'])) {
                $errors_delete[] = "Incorrect password. Account deletion canceled.";
            } else {
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);

                if ($stmt->execute()) {
                    $stmt->close();
                    $_SESSION = array();
                    if (ini_get("session.use_cookies")) {
                        $params = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000,
                            $params["path"], $params["domain"],
                            $params["secure"], $params["httponly"]
                        );
                    }
                    session_destroy();
                    header("Location: login.php?msg=account_deleted");
                    exit();
                } else {
                    $errors_delete[] = "Failed to delete account. Please try again or contact support.";
                    $stmt->close();
                }
            }
        }
    }
}

$page_title = 'My Profile';
$extra_css = ['auth'];
require_once __DIR__ . '/../includes/header.php';

// Active tab resolution
$active_tab = 'tab-info';
if (!empty($errors_password) || !empty($success_password)) {
    $active_tab = 'tab-security';
} elseif (!empty($errors_delete)) {
    $active_tab = 'tab-delete';
}
?>

<div class="profile-layout">
    <!-- Sidebar -->
    <aside class="profile-sidebar card">
        <div class="user-avatar">
            <?= h(strtoupper(substr($current_user['full_name'], 0, 1))) ?>
        </div>
        <h3 class="user-name"><?= h($current_user['full_name']) ?></h3>
        <p class="user-email"><?= h($current_user['email']) ?></p>
        <span class="tag">Role: <?= h(ucfirst($current_user['role'])) ?></span>

        <nav class="profile-nav">
            <button type="button" class="nav-tab <?= $active_tab === 'tab-info' ? 'active' : '' ?>" data-target="tab-info">
                <span class="nav-icon">📋</span>
                <span class="nav-text">Personal Info</span>
            </button>

            <button type="button" class="nav-tab <?= $active_tab === 'tab-security' ? 'active' : '' ?>" data-target="tab-security">
                <span class="nav-icon">🔒</span>
                <span class="nav-text">Security Settings</span>
            </button>

            <?php if ($current_user['role'] !== 'admin'): ?>
                <button type="button" class="nav-tab nav-tab-danger <?= $active_tab === 'tab-delete' ? 'active' : '' ?>" data-target="tab-delete">
                    <span class="nav-icon">⚠️</span>
                    <span class="nav-text">Delete Account</span>
                </button>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <div class="profile-content">
        <!-- 1. Personal Information Tab Pane -->
        <div class="card tab-pane <?= $active_tab === 'tab-info' ? 'active' : '' ?>" id="tab-info">
            <h2>Personal Information</h2>
            <p class="muted">Manage your profile details and primary contact information.</p>

            <div id="profileClientAlert" class="alert" style="display: none;"></div>

            <?php if (!empty($success_profile)): ?>
                <div class="alert alert-success server-alert"><p><?= h($success_profile) ?></p></div>
            <?php endif; ?>

            <?php if (!empty($errors_profile)): ?>
                <div class="alert alert-error server-alert">
                    <?php foreach ($errors_profile as $err): ?>
                        <p><?= h($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="profileInfoForm" class="auth-form" novalidate>
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= h($current_user['full_name']) ?>" data-original="<?= h($current_user['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= h($current_user['email']) ?>" data-original="<?= h($current_user['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= h($current_user['phone']) ?>" data-original="<?= h($current_user['phone']) ?>" placeholder="e.g. 012-3456789" required>
                </div>

                <button type="submit" class="btn btn-auth-submit">Update Information</button>
            </form>
        </div>

        <!-- 2. Security Settings Tab Pane -->
        <div class="card tab-pane <?= $active_tab === 'tab-security' ? 'active' : '' ?>" id="tab-security">
            <h2>Change Password</h2>
            <p class="muted">Update your account password for enhanced security.</p>

            <div id="passwordClientAlert" class="alert" style="display: none;"></div>

            <?php if (!empty($success_password)): ?>
                <div class="alert alert-success server-alert"><p><?= h($success_password) ?></p></div>
            <?php endif; ?>

            <?php if (!empty($errors_password)): ?>
                <div class="alert alert-error server-alert">
                    <?php foreach ($errors_password as $err): ?>
                        <p><?= h($err) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="passwordForm" class="auth-form" novalidate>
                <input type="hidden" name="action" value="change_password">

                <div class="form-group password-group">
                    <label for="current_password">Current Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="current_password" name="current_password" placeholder="Enter your current password" required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="new_password" name="new_password" placeholder="Enter your new password" required minlength="6">
                        <button type="button" class="toggle-password" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your new password" required minlength="6">
                        <button type="button" class="toggle-password" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-auth-submit">Update Password</button>
            </form>
        </div>

        <!-- 3. Dedicated Delete Account Tab Pane -->
        <div class="card tab-pane <?= $active_tab === 'tab-delete' ? 'active' : '' ?>" id="tab-delete">
            <h2 class="danger-zone-title">Delete Account</h2>
            
            <?php if ($current_user['role'] === 'admin'): ?>
                <div class="alert alert-error" style="margin-top: 15px;">
                    <p><strong>Admin Restrictions Applied:</strong> Admin accounts cannot be self-deleted from the profile page. To manage or remove administrative accounts, please utilize the <a href="<?= h(app_url('/admin/users.php')) ?>">Admin Panel User Management</a> system using another superadmin account.</p>
                </div>
            <?php else: ?>
                <p class="muted">Deleting your account is permanent. All your data will be permanently removed.</p>

                <div id="deleteClientAlert" class="alert" style="display: none;"></div>

                <?php if (!empty($errors_delete)): ?>
                    <div class="alert alert-error server-alert">
                        <?php foreach ($errors_delete as $err): ?>
                            <p><?= h($err) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="profile.php" id="deleteAccountForm" class="auth-form" novalidate>
                    <input type="hidden" name="action" value="delete_account">

                    <div class="form-group">
                        <label for="delete_password">Current Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="delete_password" name="delete_password" placeholder="Enter password to confirm deletion" required>
                            <button type="button" class="toggle-password" aria-label="Toggle password visibility">Show</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger">Confirm Delete Account</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= h(asset_url('/js/auth.js')) ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>