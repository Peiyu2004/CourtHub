<?php

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

/*
 * Admin only.
 *
 * This page lists court reservations made by customers using booking_orders 
 * and booking_order_items based on date and time slots.
 * Admins can update the booking_status (Pending / Completed).
 *
 * Must execute before includes/header.php to support redirects.
 */
requireAdmin();

// UPDATE BOOKING STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_order_id'], $_POST['booking_status'])) {
    $booking_order_id = (int) $_POST['booking_order_id'];
    $status = $_POST['booking_status'];

    $allowed_statuses = ['Pending', 'Completed'];

    if (in_array($status, $allowed_statuses, true)) {
        $stmt = $conn->prepare(
            "UPDATE booking_orders
             SET booking_status = ?
             WHERE booking_order_id = ?"
        );
        $stmt->bind_param("si", $status, $booking_order_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: " . app_url('/admin/reservation.php'));
    exit;
}

// Get selected filter
$filter = $_GET['filter'] ?? 'All';

$allowedFilters = ['All', 'Pending', 'Completed'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

// Base SQL query joining booking_orders, booking_order_items, users, courts, and sport_types
$base_sql = "SELECT bo.booking_order_id, bo.total_amount, bo.payment_method, bo.payment_status, bo.booking_status, bo.transaction_ref, bo.created_at,
                    boi.booking_item_id, boi.booking_date, boi.start_time, boi.end_time, boi.price AS item_price,
                    u.full_name, u.email, u.phone,
                    c.court_number, s.name AS sport_name
             FROM booking_order_items boi
             INNER JOIN booking_orders bo ON boi.booking_order_id = bo.booking_order_id
             LEFT JOIN users u ON bo.user_id = u.user_id
             LEFT JOIN courts c ON boi.court_id = c.court_id
             LEFT JOIN sport_types s ON c.sport_type_id = s.sport_type_id";

if ($filter === 'Completed') {
    $sql = $base_sql . " WHERE bo.booking_status = 'Completed' ORDER BY boi.booking_date DESC, boi.start_time ASC";
    $result = $conn->query($sql);
} elseif ($filter === 'Pending') {
    $sql = $base_sql . " WHERE bo.booking_status = 'Pending' ORDER BY boi.booking_date DESC, boi.start_time ASC";
    $result = $conn->query($sql);
} else {
    $sql = $base_sql . " ORDER BY boi.booking_date DESC, boi.start_time ASC";
    $result = $conn->query($sql);
}

$wide_layout = true;
$page_title = 'Manage Reservations';
$extra_css = ['shop', 'admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Court Reservations</h1>
    <p class="muted">Manage and review timeslot bookings for court reservations.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content -->
    <div class="dashboard-main-content">
        <section class="card">
            <div class="split-row">
                <div>
                    <h1>Court Reservations</h1>
                    <p class="muted">Review customer court bookings and update usage status.</p>
                </div>

                <div class="message-filter">
                    <form method="GET">
                        <select name="filter" class="status-select" onchange="this.form.submit()">
                            <option value="All" <?= $filter === 'All' ? 'selected' : '' ?>>All Reservations</option>
                            <option value="Pending" <?= $filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Completed" <?= $filter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </form>
                </div>
            </div>

            <?php if (!$result || $result->num_rows === 0): ?>
                <div class="empty-state">No court reservations found.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="messages-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Customer</th>
                                <th>Sport & Court</th>
                                <th>Date & Timeslot</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Booking Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="message-date">
                                        <strong>#<?= (int) $row['booking_order_id'] ?></strong><br>
                                        <small class="muted"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($row['created_at']))) ?></small>
                                        <?php if (!empty($row['transaction_ref'])): ?>
                                            <br><small class="muted"><?= htmlspecialchars($row['transaction_ref']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['full_name'] ?? 'Guest') ?></strong><br>
                                        <small><?= h($row['email'] ?? '') ?></small><br>
                                        <small><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['sport_name'] ?? 'Court') ?></strong><br>
                                        <small class="muted"><?= htmlspecialchars($row['court_number'] ?? 'N/A') ?></small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars(date('d M Y', strtotime($row['booking_date']))) ?></strong><br>
                                        <small class="muted">
                                            <?= htmlspecialchars(date('g:i A', strtotime($row['start_time']))) ?> - <?= htmlspecialchars(date('g:i A', strtotime($row['end_time']))) ?>
                                        </small>
                                    </td>
                                    <td>RM <?= number_format((float)($row['item_price'] ?? 0), 2) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['payment_status'] === 'paid' ? 'success' : 'warning' ?>">
                                            <?= htmlspecialchars(ucfirst($row['payment_status'] ?? 'pending')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="status-form">
                                            <input type="hidden" name="booking_order_id" value="<?= (int)$row['booking_order_id'] ?>">
                                            <select name="booking_status" class="status-select" onchange="this.form.submit()">
                                                <option value="Pending" <?= ($row['booking_status'] ?? 'Pending') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="Completed" <?= ($row['booking_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>