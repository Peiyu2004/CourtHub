<footer class="site-footer">

    <div class="footer-container">

        <a href="<?= h(app_url('/index.php')) ?>" class="logo">

            <img 
                src="<?= h(app_url('/images/logo.png')) ?>" 
                alt="CourtHub Logo" 
                class="logo-img"
            >

            <span class="logo-text">ourtHub</span>

        </a>


        <a href="<?= h(app_url('/contact.php')) ?>" class="footer-contact">
            Contact Us
        </a>

    </div>


    <div class="footer-bottom">

        <p>
            &copy; <?= date('Y') ?> CourtHub Sport Center. All rights reserved.
        </p>

        <p>
            Badminton &bull; Pickleball &bull; Futsal
        </p>

    </div>

</footer>