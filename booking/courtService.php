<?php require_once __DIR__ . '/../includes/header.php'; ?>

<section class="card">
    <h1>Welcome to CourtHub Sport Center</h1>
    <p>Book badminton, pickleball, and futsal courts, or shop for sports equipment - all in one place.</p>
</section>

<section class="grid" style="margin-top: 24px;">
    <div class="card">
        <div class="card-pic">
                <img src="<?= h(app_url('/images/badmintonCourt.jpg')) ?>" alt="Badminton Hall">
        </div>
        <h3>Badminton</h3>
        <p>6 indoor courts available.</p>
        <p><strong>RM30 / hour</strong></p>
        <a href="<?= h(app_url('/booking/search.php?sport=1')) ?>" class="btn">Book Now</a>
    </div>

    <div class="card">
        <div class="card-pic">
                <img src="<?= h(app_url('/images/pickleballCourt.jpg')) ?>" alt="Pickleball Arena">
        </div>
        <h3>Pickleball</h3>
        <p>6 indoor courts available.</p>
        <p><strong>RM50 / hour</strong></p>
        <a href="<?= h(app_url('/booking/search.php?sport=2')) ?>" class="btn">Book Now</a>
    </div>

    <div class="card">
        <div class="card-pic">
            <img src="<?= h(app_url('/images/futsalCourt.jpg')) ?>" alt="Futsal Pitch">
        </div>
        <h3>Futsal</h3>
        <p>2 full-size courts available.</p>
        <p><strong>RM120 / hour</strong></p>
        <a href="<?= h(app_url('/booking/search.php?sport=3')) ?>" class="btn">Book Now</a>
    </div>
</section>

<section class="card" style="margin-top: 24px; text-align: center;">
    <h3>Need new gear?</h3>
    <p>Check out our equipment store for racquets, paddles, balls, and more.</p>
    <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn btn-secondary">Visit Equipment Store</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>