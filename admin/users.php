<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireAdmin();

$errors = [];
$notice = '';

// Current logged-in admin ID to prevent self-deletion or demotion
$current_admin_id = $_SESSION['user_id'] ?? 0;

// -------------------------------------------------------------
// POST ACTIONS (Add, Edit, Reset Password, Delete)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- ACTION: ADD USER ---
    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'customer';
        $password = $_POST['password'] ?? '';

        if ($full_name === '') $errors[] = "Full name is required.";
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
        if ($password === '' || strlen($password) < 6) $errors[] = "Password must be at least 6 characters long.";
        if (!in_array($role, ['customer', 'admin'], true)) $role = 'customer';

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = "An account with this email address already exists.";
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $full_name, $email, $phone, $role, $pass_hash);
            if ($stmt->execute()) {
                $notice = "User account created successfully.";
            } else {
                $errors[] = "User account could not be created.";
            }
            $stmt->close();
        }
    }

    // --- ACTION: UPDATE USER ---
    if ($action === 'update_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'customer';

        if ($user_id <= 0) $errors[] = "Invalid user specified.";
        if ($full_name === '') $errors[] = "Full name is required.";
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
        if (!in_array($role, ['customer', 'admin'], true)) $role = 'customer';

        if ($user_id === $current_admin_id && $role !== 'admin') {
            $errors[] = "You cannot remove admin privileges from your own logged-in account.";
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ?");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = "This email is already in use by another user.";
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, role = ? WHERE user_id = ?");
            $stmt->bind_param("ssssi", $full_name, $email, $phone, $role, $user_id);
            if ($stmt->execute()) {
                $notice = "User details updated successfully.";
            } else {
                $errors[] = "User details could not be updated.";
            }
            $stmt->close();
        }
    }

    // --- ACTION: RESET PASSWORD ---
    if ($action === 'reset_password') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if ($user_id <= 0) $errors[] = "Invalid user specified.";
        if ($new_password === '' || strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long.";
        }

        if (empty($errors)) {
            $pass_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param("si", $pass_hash, $user_id);
            if ($stmt->execute()) {
                $notice = "Password reset successfully.";
            } else {
                $errors[] = "Password could not be updated.";
            }
            $stmt->close();
        }
    }

    // --- ACTION: DELETE USER ---
    if ($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id === $current_admin_id) {
            $errors[] = "You cannot delete your own admin account while logged in.";
        } elseif ($user_id > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $notice = "User account has been deleted.";
            } else {
                $errors[] = "User could not be deleted. They may have related orders or reservations linked to their account.";
            }
            $stmt->close();
        }
    }
}

// -------------------------------------------------------------
// FETCH USERS (With Filtering & Search)
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? '';

$sql = "SELECT user_id, full_name, email, phone, role, created_at FROM users WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

if (in_array($role_filter, ['customer', 'admin'], true)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users_result = $stmt->get_result();
$users = [];
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

// Get order count for sidebar badge indicator
$order_counts = function_exists('equipmentOrderStatusCounts') ? equipmentOrderStatusCounts($conn) : ['pending' => 0];

// Admin pages use the wider container
$wide_layout = true;
$page_title = 'Manage Users';
$extra_css = ['admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>User Management</h1>
    <p class="muted">Manage user accounts, roles, access permissions, and authentication details.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <div class="dashboard-main-content">
        <section class="card">
            <h1>Manage Users</h1>
            <p class="muted">
                Create new accounts, edit customer profiles, toggle roles between Customer and Admin, or reset passwords.
            </p>
        </section>

        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="grid two-col">
            <!-- Add User Form -->
            <div class="card">
                <h2>Add User</h2>
                <form method="POST" action="<?= h(app_url('/admin/users.php')) ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" placeholder="John Doe" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="john@example.com" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" placeholder="+60123456789">
                    </div>

                    <div class="form-group">
                        <label for="role">Role</label>
                        <select id="role" name="role" required>
                            <option value="customer">Customer</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="password">Initial Password</label>
                        <input type="password" id="password" name="password" minlength="6" placeholder="At least 6 characters" required>
                    </div>

                    <button type="submit" class="btn">Add User Account</button>
                </form>
            </div>

            <!-- Search & Filter Options -->
            <div class="card">
                <h2>Filter Users</h2>
                <p class="muted">Filter the account list by user role or query specific names, emails, and phone numbers.</p>
                
                <form method="GET" action="<?= h(app_url('/admin/users.php')) ?>">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" value="<?= h($search) ?>" placeholder="Search name, email, phone...">
                    </div>

                    <div class="form-group">
                        <label for="role_filter">Role</label>
                        <select id="role_filter" name="role">
                            <option value="">All Roles</option>
                            <option value="customer" <?= $role_filter === 'customer' ? 'selected' : '' ?>>Customer</option>
                            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="submit" class="btn btn-secondary">Apply Filters</button>
                        <?php if ($search !== '' || $role_filter !== ''): ?>
                            <a href="<?= h(app_url('/admin/users.php')) ?>" class="muted">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <!-- Registered Users Table -->
        <section class="card">
            <h2>Registered Users</h2>
            <?php if (empty($users)): ?>
                <div class="empty-state">No users match your current criteria.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name & Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Joined Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php $user_form_id = 'user_form_' . (int)$user['user_id']; ?>
                                <tr>
                                    <td>
                                        <div class="form-group">
                                            <form id="<?= h($user_form_id) ?>" method="POST" action="<?= h(app_url('/admin/users.php')) ?>"></form>
                                            <input type="hidden" name="action" value="update_user" form="<?= h($user_form_id) ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>" form="<?= h($user_form_id) ?>">

                                            <input type="text" name="full_name" value="<?= h($user['full_name']) ?>" form="<?= h($user_form_id) ?>" required placeholder="Full Name">
                                            <input type="email" name="email" value="<?= h($user['email']) ?>" form="<?= h($user_form_id) ?>" required placeholder="Email" style="margin-top: 5px;">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="form-group">
                                            <input type="text" name="phone" value="<?= h($user['phone'] ?? '') ?>" form="<?= h($user_form_id) ?>" placeholder="Phone Number">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="form-group">
                                            <select name="role" form="<?= h($user_form_id) ?>" required>
                                                <option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                            </select>
                                        </div>
                                    </td>
                                    <td><?= date('j M Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                            <button type="submit" class="btn btn-secondary" form="<?= h($user_form_id) ?>">Save</button>

                                            <!-- Reset Password Dialog Toggle Button -->
                                            <button type="button" class="btn btn-secondary" onclick="togglePasswordForm(<?= (int)$user['user_id'] ?>)">Password</button>

                                            <?php if ((int)$user['user_id'] !== $current_admin_id): ?>
                                                <form method="POST" action="<?= h(app_url('/admin/users.php')) ?>" onsubmit="return confirm('Are you sure you want to delete <?= h($user['full_name']) ?>?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                                                    <button type="submit" class="btn btn-danger">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted">(Current Admin)</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Expandable Password Reset Form -->
                                        <div id="password_box_<?= (int)$user['user_id'] ?>" style="display: none; margin-top: 10px; padding: 8px; background: rgba(0,0,0,0.03); border-radius: 4px;">
                                            <form method="POST" action="<?= h(app_url('/admin/users.php')) ?>">
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">
                                                <div class="form-group" style="margin-bottom: 6px;">
                                                    <input type="password" name="new_password" placeholder="New password" minlength="6" required style="font-size: 0.85rem;">
                                                </div>
                                                <button type="submit" class="btn btn-secondary" style="font-size: 0.8rem; padding: 4px 8px;">Update Password</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
function togglePasswordForm(userId) {
    const box = document.getElementById('password_box_' + userId);
    if (box) {
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>