<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();
finalizePendingCourtDeletions($conn);

$stats = [
    'active_courts' => 0,
    'equipment_items' => 0,
    'current_month_revenue' => 0,
    'current_month_orders' => 0,
    'current_month_reservations' => 0,
];

// Orders paid for but not yet picked up - the work waiting at the counter.
$order_counts = equipmentOrderStatusCounts($conn);

$result = $conn->query("SELECT COUNT(*) AS total FROM courts WHERE status = 'active'");
$stats['active_courts'] = (int)$result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) AS total FROM equipment");
$stats['equipment_items'] = (int)$result->fetch_assoc()['total'];

// Current month court reservations count
$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM booking_orders
     WHERE YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())"
);
$stats['current_month_reservations'] = (int)$result->fetch_assoc()['total'];

// Combined current month revenue (Reservations + Equipment Orders)
$result = $conn->query(
    "SELECT 
        (SELECT COALESCE(SUM(total_amount), 0) 
         FROM booking_orders 
         WHERE payment_status = 'paid' 
           AND YEAR(created_at) = YEAR(CURDATE()) 
           AND MONTH(created_at) = MONTH(CURDATE()))
        +
        (SELECT COALESCE(SUM(total_amount), 0) 
         FROM equipment_orders 
         WHERE payment_status = 'paid' 
           AND YEAR(created_at) = YEAR(CURDATE()) 
           AND MONTH(created_at) = MONTH(CURDATE())) AS total_revenue"
);
$stats['current_month_revenue'] = (float)$result->fetch_assoc()['total_revenue'];

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM equipment_orders
     WHERE YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())"
);
$stats['current_month_orders'] = (int)$result->fetch_assoc()['total'];

// Combined Monthly Revenue Query (Court Reservations + Equipment Orders)
$monthly_revenue = [];
$result = $conn->query(
    "SELECT revenue_month, 
            SUM(court_rev) AS court_revenue, 
            SUM(equip_rev) AS equipment_revenue, 
            (SUM(court_rev) + SUM(equip_rev)) AS total_revenue
     FROM (
         SELECT DATE_FORMAT(created_at, '%Y-%m') AS revenue_month, 
                SUM(total_amount) AS court_rev, 
                0 AS equip_rev
         FROM booking_orders
         WHERE payment_status = 'paid'
         GROUP BY revenue_month

         UNION ALL

         SELECT DATE_FORMAT(created_at, '%Y-%m') AS revenue_month, 
                0 AS court_rev, 
                SUM(total_amount) AS equip_rev
         FROM equipment_orders
         WHERE payment_status = 'paid'
         GROUP BY revenue_month
     ) combined_revenues
     GROUP BY revenue_month
     ORDER BY revenue_month DESC
     LIMIT 12"
);
while ($row = $result->fetch_assoc()) {
    $monthly_revenue[] = $row;
}

// Chart Data Arrays
$chart_months = [];
$court_revenue_data = [];
$equipment_revenue_data = [];

foreach (array_reverse($monthly_revenue) as $m) {
    $chart_months[] = date('M', strtotime($m['revenue_month'] . '-01'));
    $court_revenue_data[] = (float)$m['court_revenue'];
    $equipment_revenue_data[] = (float)$m['equipment_revenue'];
}

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
$page_title = 'Admin Dashboard';
$extra_css = ['admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Admin Dashboard</h1>
    <p class="muted">Court reservation and equipment order management analytics.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <div class="dashboard-main-content">
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
                    <span>Reservations (Month)</span>
                    <strong><?= (int)$stats['current_month_reservations'] ?></strong>
                </div>
            </div>

            <div class="card stat-card">
                <div class="stat-info">
                    <span>Orders (Month)</span>
                    <strong><?= (int)$stats['current_month_orders'] ?></strong>
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

        <!-- Total Monthly Revenue Analytics & Breakdown -->
        <div class="card analytics-card">
            <div class="split-row">
                <h2>Total Monthly Revenue</h2>
            </div>

            <!-- Legend for the 2 Bars -->
            <div class="chart-legend">
                <div class="legend-item">
                    <span class="legend-color court"></span>
                    <span>Court Revenue</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color equipment"></span>
                    <span>Equipment Revenue</span>
                </div>
            </div>

            <!-- Side-by-Side Bar Chart Container -->
            <div class="revenue-chart-container">
                <div class="bar-chart" id="doubleBarChart"></div>
            </div>

            <!-- Breakdown Table Below Chart -->
            <div class="split-row chart-table-divider">
                <h3>Monthly Revenue Breakdown</h3>
            </div>

            <?php if (empty($monthly_revenue)): ?>
                <div class="empty-state">No paid revenue available.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Court Revenue</th>
                                <th>Equipment Revenue</th>
                                <th>Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_revenue as $row): ?>
                                <tr>
                                    <td><strong><?= h(date('F Y', strtotime($row['revenue_month'] . '-01'))) ?></strong></td>
                                    <td><?= money($row['court_revenue']) ?></td>
                                    <td><?= money($row['equipment_revenue']) ?></td>
                                    <td class="revenue-amount"><strong><?= money($row['total_revenue']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Data for Chart Script -->
<script>
    window.chartMonths = <?= json_encode($chart_months) ?>;
    window.courtRevenueData = <?= json_encode($court_revenue_data) ?>;
    window.equipmentRevenueData = <?= json_encode($equipment_revenue_data) ?>;
</script>
<script src="<?= h(asset_url('/js/dashboard.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>