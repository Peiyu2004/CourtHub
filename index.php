<?php
require_once __DIR__ . '/config/db_connect.php';

$page_title = 'Home';
$extra_css = ['home'];
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Banner Section -->
<header class="hero-section">
    <div class="hero-container">
        <div class="hero-content">
            <h1 class="hero-title">TRAIN.<br>PLAY.<br>GROW.</h1>
            <p class="hero-subtitle">Premium courts, top-tier equipment, and an active sports community. Elevate your game with us today.</p>
            <div class="hero-buttons">
                <a href="<?= h(app_url('/booking/search.php')) ?>" class="btn-primary">BOOK A COURT NOW</a>
                <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn-secondary">EXPLORE STORE</a>
            </div>
        </div>
    </div>
</header>

<!-- About Section -->
<section class="about-section">
    <div class="about-container">
        
        <!-- Court Section -->
        <h2 class="section-main-title">OUR RECREATIONAL AMENITIES</h2>
        
        <div class="grid">
            <!-- Card 1: Badminton -->
            <div class="info-card">
                <a class="card-image" href="<?= h(app_url('/booking/viewCourt.php?sport=1')) ?>">
                    <img src="<?= h(app_url('/images/badmintonCourt.jpg')) ?>" alt="Badminton Hall">
                </a>
                <div class="card-body">
                    <h3>BADMINTON COURTS</h3>
                    <p>6 premium indoor rubber mats equipped with optimal professional lighting setups. Ideal for competitive matches.</p>
                    <a href="<?= h(app_url('/booking/viewCourt.php?sport=1')) ?>" class="card-link">View Details &rarr;</a>
                </div>
            </div>

            <!-- Card 2: Pickleball -->
            <div class="info-card">
                <a class="card-image" href="<?= h(app_url('/booking/viewCourt.php?sport=2')) ?>">
                    <img src="<?= h(app_url('/images/pickleballCourt.jpg')) ?>" alt="Pickleball Arena">
                </a>
                <div class="card-body">
                    <h3>PICKLEBALL ZONE</h3>
                    <p>6 dedicated, state-of-the-art indoor courts curated for maximum bounce control and rapid gameplay tracking.</p>
                    <a href="<?= h(app_url('/booking/viewCourt.php?sport=2')) ?>" class="card-link">View Details &rarr;</a>
                </div>
            </div>

            <!-- Card 3: Futsal -->
            <div class="info-card">
                <a class="card-image" href="<?= h(app_url('/booking/viewCourt.php?sport=3')) ?>">
                    <img src="<?= h(app_url('/images/futsalCourt.jpg')) ?>" alt="Futsal Pitch">
                </a>
                <div class="card-body">
                    <h3>FUTSAL PITCHES</h3>
                    <p>2 full-sized premium interlock court options designed for ideal traction control, acceleration, and player safety.</p>
                    <a href="<?= h(app_url('/booking/viewCourt.php?sport=3')) ?>" class="card-link">View Details &rarr;</a>
                </div>
            </div>
        </div>

        <!-- Equipment Store Section -->
        <div class="store-banner-wrapper">
            <div class="store-banner-container">
                
                <!-- Left Column: Energetic Branding & Content Info -->
                <div class="store-banner-left">
                    <h2 class="store-tagline">MISSING</h2>
                    <h1 class="store-main-title">EQUIPMENTS?</h1>

                    <p class="store-desc">Check out our equipment store and gear up like a pro. Find top-tier racquets, paddles, balls, and high-performance sports apparel directly inside our center.</p>
                    
                    <div class="store-action-row">
                        <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn-store-visit">Shop Collection Now</a>
                    </div>
                </div>
                
                <!-- Right Column: Product Image Frame Showcase (Matching the image border cutout) -->
                <div class="store-banner-right">
                    <div class="store-showcase-frame">
                        <!-- Replace with your choice of equipment display graphic -->
                        <img src="<?= h(app_url('/images/equipment.jpg')) ?>" alt="Sports Equipment Gear">
                    </div>
                </div>
            </div>
        </div>
        <section class="home-contact">
            <div class="home-contact-content">
                <div class="home-contact-text">
                    <span class="section-label">CONTACT US</span>
                    <h2>We're here to help</h2>
                    <p>
                        Have questions about court reservations, equipment
                        or our services? Get in touch with CourtHub Sport Center.
                    </p>
                    <a href="<?= h(app_url('contact.php')) ?>" class="btn-primary">
                        Contact Us
                    </a>
                </div>
                <div class="home-contact-info">
                    <div class="contact-info-item">
                        <strong>📍 Address</strong>
                        <p>CourtHub Sport Center<br>Kuala Lumpur, Malaysia</p>
                    </div>
                    <div class="contact-info-item">
                        <strong>📞 Phone</strong>
                        <p>+60 12-566 6710</p>
                    </div>
                    <div class="contact-info-item">
                        <strong>✉ Email</strong>
                        <p>support@courthub.com</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>