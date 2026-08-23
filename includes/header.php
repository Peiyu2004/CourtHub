<?php
require_once __DIR__ . '/../config/functions.php';

$site_name = 'CourtHub Sport Center';
$head_title = isset($page_title) && trim($page_title) !== ''
    ? trim($page_title) . ' | ' . $site_name
    : $site_name;

$head_styles = ['style'];
if (isset($extra_css)) {
    foreach ((array)$extra_css as $sheet) {
        $sheet = preg_replace('/[^a-z0-9_-]/i', '', $sheet);
        if ($sheet !== '' && !in_array($sheet, $head_styles, true)) {
            $head_styles[] = $sheet;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($head_title) ?></title>
    <?php foreach ($head_styles as $sheet): ?>
    <link rel="stylesheet" href="<?= h(asset_url('/css/' . $sheet . '.css')) ?>">
    <?php endforeach; ?>
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
            <a href="<?= h(app_url('/index.php')) ?>">Home</a>
            <a href="<?= h(app_url('/contact.php')) ?>">Contact</a>
            <div class="nav-dropdown">
                <button
                    type="button"
                    class="dropdown-btn"
                    aria-label="Services menu">Services<span class="arrow">▼</span>
                </button>

                <div class="dropdown-menu">
                    <a href="<?= h(app_url('/booking/courtService.php')) ?>">View Facilities</a>
                    <a href="<?= h(app_url('/booking/search.php')) ?>">Book a Court</a>
                    <a href="<?= h(app_url('/shop/equipment.php')) ?>">Equipment Store</a>
                </div>
            </div>

            <?php if (isLoggedIn()): ?>
                <!-- One entry for both halves of the history; the page's own
                     tabs switch between court bookings and equipment orders. -->
                <a href="<?= h(app_url('/shop/cart.php')) ?>">Cart</a>
                
                <div class="nav-dropdown account-dropdown">
                    <button 
                        type="button"
                        class="dropdown-btn"
                        aria-label="Account menu">Account<span class="arrow">▼</span>
                    </button>

                    <div class="dropdown-menu">
                        <a href="<?= h(app_url('/auth/profile.php')) ?>">My Profile</a>
                        <a href="<?= h(app_url('/booking/history.php')) ?>">My History</a>

                        <?php if (isAdmin()): ?>
                            <div class="dropdown-divider"></div>
                            <a href="<?= h(app_url('/admin/dashboard.php')) ?>"class="admin-link">Admin Dashboard</a>
                        <?php endif; ?>

                        <div class="dropdown-divider"></div>

                        <a href="<?= h(app_url('/auth/logout.php')) ?>"class="logout-link">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= h(app_url('/auth/login.php')) ?>">Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php
/*
 * The admin pages set $wide_layout = true before including this file.
 *
 * 1100px is a comfortable reading width for the customer-facing pages, which
 * are mostly one column of text and cards. The admin pages are not: they lose
 * 240px of it to the sidebar and then put a ten-column table in what is left,
 * so the equipment list had to be scrolled sideways to reach the Action
 * buttons even on a 1536px screen. They get a wider container instead of
 * every admin table growing its own horizontal scrollbar.
 */
?>
<main class="site-main<?= !empty($wide_layout) ? ' site-main-wide' : '' ?>">