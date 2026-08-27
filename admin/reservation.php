<?php

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

/*
 * Admin only.
 *
 * This page lists court reservations made by customers using booking_orders 
 * and booking_order_items based on date and time slots.
 * Status auto-updates from Pending to Completed once reservation time has passed.
 *
 * Must execute before includes/header.php to support redirects.
 */
requireAdmin();

// AUTOMATIC SYSTEM UPDATE
// Align with SQL event logic: Auto-complete past bookings where end_time < NOW()
$auto_update_sql = "UPDATE booking_orders bo
                    JOIN (
                        SELECT booking_order_id,
                               MAX(TIMESTAMP(booking_date, end_time)) AS ends_at
                        FROM booking_order_items
                        GROUP BY booking_order_id
                    ) last ON last.booking_order_id = bo.booking_order_id
                    SET bo.booking_status = 'Completed'
                    WHERE bo.booking_status = 'Pending'
                      AND bo.payment_status = 'paid'
                      AND last.ends_at < NOW()";
$conn->query($auto_update_sql);

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
} elseif ($filter === 'Pending') {
    $sql = $base_sql . " WHERE bo.booking_status = 'Pending' ORDER BY boi.booking_date DESC, boi.start_time ASC";
} else {
    $sql = $base_sql . " ORDER BY boi.booking_date DESC, boi.start_time ASC";
}

$result = $conn->query($sql);

$wide_layout = true;
$page_title = 'Manage Reservations';
$extra_css = ['shop', 'admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Court Reservations</h1>
    <p class="muted">Review timeslot bookings and system-managed reservation statuses.</p>
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
                    <p class="muted">Review customer court bookings and automatically tracked usage status.</p>
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
                                        <!-- Read-only automated system status -->
                                        <span class="badge badge-<?= $row['booking_status'] === 'Completed' ? 'success' : 'secondary' ?>">
                                            <?= htmlspecialchars(ucfirst($row['booking_status'] ?? 'Pending')) ?>
                                        </span>
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