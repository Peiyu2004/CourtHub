<!-- As a back up navigation php for any further update -->

<?php 
if (!function_exists('app_url')) {
    require_once __DIR__ . '/../config/functions.php';
}
?>

<header class="site-header">
    <div class="nav-container">
        <!-- Logo Wrapper Component-->
        <a href="<?= h(app_url('/index.php')) ?>" class="logo">
            <img src="<?= h(app_url('/images/logo.png')) ?>" alt="CourtHub Logo" class="logo-img">
            <span class="logo-text">ourtHub</span>
        </a>

        <!-- Global Interactive Menu Layer -->
        <nav class="main-nav">
            <a href="<?= h(app_url('/booking/courtService.php')) ?>">Court</a>
            <a href="<?= h(app_url('/booking/search.php')) ?>">Book</a>
            <a href="<?= h(app_url('/shop/equipment.php')) ?>">Equipment</a>

            <?php if (isLoggedIn()): ?>
                <a href="<?= h(app_url('/booking/history.php')) ?>">Reservation</a>
                <a href="<?= h(app_url('/shop/cart.php')) ?>">Cart</a>

                <?php if (isAdmin()): ?>
                    <a href="<?= h(app_url('/admin/dashboard.php')) ?>">Dashboard</a>
                <?php endif; ?>

                <a href="<?= h(app_url('/auth/logout.php')) ?>">Logout</a>
            <?php else: ?>
                <a href="<?= h(app_url('/auth/login.php')) ?>">Login</a>
                <a href="<?= h(app_url('/auth/register.php')) ?>">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="site-main">