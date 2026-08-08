<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();
finalizePendingCourtDeletions($conn);

$stats = [
    'active_courts' => 0,
    'pending_courts' => 0,
    'equipment_items' => 0,
    'current_month_revenue' => 0,
];

// Orders paid for but not yet picked up - the work waiting at the counter.
$order_counts = equipmentOrderStatusCounts($conn);

$result = $conn->query("SELECT COUNT(*) AS total FROM courts WHERE status = 'active'");
$stats['active_courts'] = (int)$result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) AS total FROM courts WHERE status = 'pending_deletion'");
$stats['pending_courts'] = (int)$result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) AS total FROM equipment");
$stats['equipment_items'] = (int)$result->fetch_assoc()['total'];

$result = $conn->query(
    "SELECT COALESCE(SUM(total_amount), 0) AS total
     FROM booking_orders
     WHERE payment_status = 'paid'
       AND YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())"
);
$stats['current_month_revenue'] = (float)$result->fetch_assoc()['total'];

$monthly_revenue = [];
$result = $conn->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS revenue_month,
            SUM(total_amount) AS revenue
     FROM booking_orders
     WHERE payment_status = 'paid'
     GROUP BY revenue_month
     ORDER BY revenue_month DESC
     LIMIT 12"
);
while ($row = $result->fetch_assoc()) {
    $monthly_revenue[] = $row;
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Admin Dashboard</h1>
    <p class="muted">Court reservation revenue and management shortcuts.</p>
</section>

<section class="stats-grid">
    <div class="card stat-card">
        <span>Current Month Revenue</span>
        <strong><?= money($stats['current_month_revenue']) ?></strong>
    </div>
    <div class="card stat-card">
        <span>Active Courts</span>
        <strong><?= (int)$stats['active_courts'] ?></strong>
    </div>
    <div class="card stat-card">
        <span>Pending Court Deletions</span>
        <strong><?= (int)$stats['pending_courts'] ?></strong>
    </div>
    <div class="card stat-card">
        <span>Equipment Items</span>
        <strong><?= (int)$stats['equipment_items'] ?></strong>
    </div>
    <div class="card stat-card">
        <span>Orders To Collect</span>
        <strong><?= (int)$order_counts['pending'] ?></strong>
    </div>
</section>

<section class="grid two-col">
    <div class="card">
        <h2>Monthly Court Reservation Revenue</h2>
        <?php if (empty($monthly_revenue)): ?>
            <div class="empty-state">No paid reservation revenue yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_revenue as $row): ?>
                            <tr>
                                <td><?= h($row['revenue_month']) ?></td>
                                <td><?= money($row['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Manage</h2>
        <div class="button-list">
            <a href="<?= h(app_url('/admin/courts.php')) ?>" class="btn">Add or Delete Courts</a>
            <a href="<?= h(app_url('/admin/equipment.php')) ?>" class="btn btn-secondary">Add or Delete Equipment</a>
            <a href="<?= h(app_url('/admin/orders.php')) ?>" class="btn btn-secondary">Equipment Orders to Collect</a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
