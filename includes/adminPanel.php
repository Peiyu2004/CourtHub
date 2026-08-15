<?php
// Determine current page to highlight active link
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch pending order counts if function exists
$order_counts = function_exists('equipmentOrderStatusCounts') ? equipmentOrderStatusCounts($conn) : ['pending' => 0];
?>

<link rel="stylesheet" href="<?= h(asset_url('/css/admin.css')) ?>">

<aside class="sidebar-nav">
    <div class="sidebar-brand">
        <span class="brand-icon">⚡</span>
        <span class="brand-text">Admin Panel</span>
    </div>

    <nav class="sidebar-menu">
        <a href="<?= h(app_url('/admin/dashboard.php')) ?>" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="<?= h(app_url('/admin/courts.php')) ?>" class="nav-item <?= $current_page === 'courts.php' ? 'active' : '' ?>">
            <span class="nav-icon">🏸</span>
            <span class="nav-text">Courts</span>
        </a>
        <a href="<?= h(app_url('/admin/equipment.php')) ?>" class="nav-item <?= $current_page === 'equipment.php' ? 'active' : '' ?>">
            <span class="nav-icon">🎾</span>
            <span class="nav-text">Equipment</span>
        </a>
        <a href="<?= h(app_url('/admin/categories.php')) ?>" class="nav-item <?= $current_page === 'categories.php' ? 'active' : '' ?>">
            <span class="nav-icon">🏷️</span>
            <span class="nav-text">Categories</span>
        </a>
        <a href="<?= h(app_url('/admin/orders.php')) ?>" class="nav-item <?= $current_page === 'orders.php' ? 'active' : '' ?>">
            <span class="nav-icon">📦</span>
            <span class="nav-text">Orders</span>
            <?php if (!empty($order_counts['pending']) && $order_counts['pending'] > 0): ?>
                <span class="nav-badge"><?= (int)$order_counts['pending'] ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= h(app_url('/admin/messages.php')) ?>" class="nav-item <?= $current_page === 'messages.php' ? 'active' : '' ?>">
            <span class="nav-icon">📩</span>
            <span class="nav-text">Messages</span>
        </a>
    </nav>
</aside>

