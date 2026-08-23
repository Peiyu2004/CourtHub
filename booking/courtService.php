<?php
/**
 * courtService.php
 * The facilities page: one card per sport, with how many courts it has and
 * what an hour on one costs.
 *
 * Both of those numbers used to be typed into the page - "6 indoor courts
 * available", "RM30 / hour". Neither is the page's to know. Courts are added
 * and retired in admin/courts.php and the hourly rate is edited on that same
 * screen, so the moment an admin touched either, this page started telling
 * customers something that was no longer true, with no sign anything was
 * wrong. They are read from the database now, so the page cannot drift.
 *
 * The sports themselves are read too, rather than three cards being written
 * out by hand, which is what lets the count and the price live in one loop
 * instead of being repeated per sport.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

/*
 * Only 'active' courts are counted.
 *
 * The other two statuses exist for courts on their way out: a
 * 'pending_deletion' court still honours the bookings already on it but
 * accepts no new ones, and a 'deleted' one is gone from display entirely.
 * Neither can be booked from here, so counting them would promise a court
 * that booking/search.php will not offer - it filters on status = 'active'
 * too, and the two numbers have to agree.
 */
$facilities = [];
$result = $conn->query(
    "SELECT st.sport_type_id, st.name, st.price_per_hour,
            (SELECT COUNT(*)
               FROM courts c
              WHERE c.sport_type_id = st.sport_type_id
                AND c.status = 'active') AS court_count
     FROM sport_types st
     ORDER BY st.sport_type_id"
);
while ($row = $result->fetch_assoc()) {
    $facilities[] = $row;
}
$result->close();

$page_title = 'Our Facilities';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Welcome to CourtHub Sport Center</h1>
    <p>Book badminton, pickleball, and futsal courts, or shop for sports equipment - all in one place.</p>
</section>

<?php if (empty($facilities)): ?>
    <section class="card stack-lg">
        <div class="empty-state">No facilities have been set up yet. Please check back soon.</div>
    </section>
<?php else: ?>
    <section class="grid stack-lg">
        <?php foreach ($facilities as $facility): ?>
            <?php $court_count = (int)$facility['court_count']; ?>
            <div class="card">
                <div class="card-pic">
                    <img src="<?= h(courtImage($facility['name'])) ?>"
                         alt="<?= h($facility['name']) ?> court">
                </div>

                <h3><?= h($facility['name']) ?></h3>

                <p>
                    <?php if ($court_count === 0): ?>
                        <span class="muted">No courts available at the moment.</span>
                    <?php else: ?>
                        <?= $court_count ?> court<?= $court_count === 1 ? '' : 's' ?> available.
                    <?php endif; ?>
                </p>

                <p><strong><?= money($facility['price_per_hour']) ?> / hour</strong></p>

                <?php /* A sport with nothing bookable gets no Book Now button.
                         The link would only land the customer on a search page
                         with an empty result and no explanation. */ ?>
                <?php if ($court_count === 0): ?>
                    <button type="button" class="btn" disabled>Book Now</button>
                <?php else: ?>
                    <a href="<?= h(app_url('/booking/search.php?sport=' . (int)$facility['sport_type_id'])) ?>"
                       class="btn">Book Now</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="card stack-lg text-center">
    <h3>Need new gear?</h3>
    <p>Check out our equipment store for racquets, paddles, balls, and more.</p>
    <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn btn-secondary">Visit Equipment Store</a>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
