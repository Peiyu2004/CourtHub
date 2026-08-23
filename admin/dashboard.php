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

// Convert monthly revenue for JavaScript visual bars
$chart_months = [];
$chart_data = [];
foreach (array_reverse($monthly_revenue) as $m) {
    $chart_months[] = date('M', strtotime($m['revenue_month'] . '-01'));
    $chart_data[] = (float)$m['revenue'];
}

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
$page_title = 'Admin Dashboard';
$extra_css = ['admin'];
require_once __DIR__ . '/../includes/header.php';
?>


<section class="card">
    <h1>Admin Dashboard</h1>
    <p class="muted">Court reservation revenue and management shortcuts.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <main class="dashboard-main-content">
        <div class="card">
            <h1>Overview</h1>
        </div>

        <!-- Top Stat Overview Cards -->
        <div class="stats-grid">
            <div class="card stat-card">
                <div class="stat-info">
                    <span>Current Month Revenue</span>
                    <strong><?= money($stats['current_month_revenue']) ?></strong>
                </div>
            </div>

            <div class="card stat-card">
                <div class="stat-info">
                    <span>Active Courts</span>
                    <strong><?= (int)$stats['active_courts'] ?></strong>
                </div>
            </div>

            <div class="card stat-card">
                <div class="stat-info">
                    <span>Pending Deletions</span>
                    <strong><?= (int)$stats['pending_courts'] ?></strong>
                </div>
            </div>

            <div class="card stat-card">
                <div class="stat-info">
                    <span>Equipment Items</span>
                    <strong><?= (int)$stats['equipment_items'] ?></strong>
                </div>
            </div>

            <div class="card stat-card">
                <div class="stat-info">
                    <span>Orders To Collect</span>
                    <strong><?= (int)$order_counts['pending'] ?></strong>
                </div>
            </div>
        </div>

        <!-- Revenue Analytics & Table -->
        <div class="card analytics-card">
            <div class="split-row">
                <h2>Monthly Court Reservation Revenue</h2>
            </div>

            <!-- Visual Bar Graph Container -->
            <div class="revenue-chart-container">
                <div class="bar-chart" id="revenueBarChart"></div>
            </div>

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
                                    <td><strong><?= h(date('F Y', strtotime($row['revenue_month'] . '-01'))) ?></strong></td>
                                    <td class="revenue-amount"><?= money($row['revenue']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Data for Chart Script -->
<script>
    window.chartMonths = <?= json_encode($chart_months) ?>;
    window.chartData = <?= json_encode($chart_data) ?>;
</script>
<script src="<?= h(asset_url('/js/dashboard.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
