</main>

<footer class="site-footer">
    <div class="footer-container">
        <div class="footer-grid">
            <div class="footer-col">
                <div class="footer-brand">
                    <img src="<?= h(app_url('/images/logo.png')) ?>" alt="CourtHub Logo" class="logo-img">
                    <span class="logo-text">ourtHub</span>
                </div>
                <p>Badminton, pickleball and futsal courts by the hour, plus a shop stocked for every one of them.</p>
            </div>

            <div class="footer-col">
                <h3>Play</h3>
                <ul>
                    <li><a href="<?= h(app_url('/booking/search.php')) ?>">Book a Court</a></li>
                    <li><a href="<?= h(app_url('/shop/equipment.php')) ?>">Equipment Store</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h3>Account</h3>
                <ul>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="<?= h(app_url('/auth/profile.php')) ?>">My Profile</a></li>
                        <li><a href="<?= h(app_url('/shop/cart.php')) ?>">My Cart</a></li>
                        <li><a href="<?= h(app_url('/booking/history.php')) ?>">My History</a></li>
                    <?php else: ?>
                        <li><a href="<?= h(app_url('/auth/login.php')) ?>">Login</a></li>
                        <li><a href="<?= h(app_url('/auth/register.php')) ?>">Create Account</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="footer-col">
                <h3>Visit</h3>
                <p>
                    CourtHub Sport Center,<br>
                    50000, Kuala Lumpur, Malaysia<br>
                    Open daily, 8:00 AM to 11:00 PM
                </p>
                <ul>
                    <li><a href="<?= h(app_url('/contact.php')) ?>">Contact Us</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> CourtHub Sport Center. All rights reserved.</span>
            <span>Badminton &bull; Pickleball &bull; Futsal</span>
        </div>
    </div>
</footer>

</body>
</html>
