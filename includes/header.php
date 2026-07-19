<?php require_once __DIR__ . '/../config/functions.php';?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CourtHub Sport Center</title>
    <link rel="stylesheet" href="<?= h(app_url('/css/style.css')) ?>?v=1.0">
</head>
<body>

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