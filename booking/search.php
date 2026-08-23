<?php
/**
 * search.php
 * The booking page: pick a sport, a date and an hour range, see which courts
 * are free for exactly that window, and tick as many of them as you want.
 *
 * The page only ever reads. Nothing is written until the customer has paid on
 * booking/payment.php, so an abandoned booking never holds a court.
 *
 * Courts are booked in whole hours anywhere inside the 8:00 AM to 11:00 PM
 * operating window, with one hour as the shortest booking. Both dropdowns are
 * built from the same constants validateBookingWindow() checks against, so
 * what the page offers and what the server accepts cannot drift apart.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/booking_functions.php';

requireLogin();

$sports = getSportTypes($conn);

list($default_date, $default_start, $default_end) = defaultBookingSlot();

$sport_type_id = isset($_GET['sport']) ? (int)$_GET['sport'] : 0;
$booking_date = $_GET['booking_date'] ?? $default_date;
$start_time = $_GET['start_time'] ?? $default_start;
$end_time = $_GET['end_time'] ?? $default_end;

// An unknown sport in the query string falls back to the first one rather than
// leaving the page with nothing to price, which is what /index.php's "Book Now"
// links rely on when they pass ?sport=1.
$selected_sport = null;
foreach ($sports as $sport) {
    if ((int)$sport['sport_type_id'] === $sport_type_id) {
        $selected_sport = $sport;
        break;
    }
}
if (!$selected_sport && count($sports) > 0) {
    $selected_sport = $sports[0];
    $sport_type_id = (int)$selected_sport['sport_type_id'];
}

// The hours each dropdown offers. Nothing can start in the last hour before
// closing, and nothing can end in the first hour after opening, because a
// booking is at least one hour long.
$start_options = bookingHourOptions(BOOKING_OPENS_AT, BOOKING_CLOSES_AT - BOOKING_STEP_MINUTES);
$end_options = bookingHourOptions(BOOKING_OPENS_AT + BOOKING_STEP_MINUTES, BOOKING_CLOSES_AT);

$slot_errors = validateBookingWindow($booking_date, $start_time, $end_time);

$available_courts = [];
$duration_hours = 0;
$price_per_court = 0;
if (empty($slot_errors) && $selected_sport) {
    $available_courts = getAvailableCourts(
        $conn,
        $sport_type_id,
        $booking_date,
        normalizeTime($start_time),
        normalizeTime($end_time)
    );
    $duration_hours = bookingDurationHours($start_time, $end_time);
    $price_per_court = (float)$selected_sport['price_per_hour'] * $duration_hours;
}

$page_title = 'Book a Court';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Book a Court</h1>
    <p class="muted">
        Open 8:00 AM to 11:00 PM. Book any hours inside that window, from 1 hour
        upwards, and take as many courts as you need in a single payment.
    </p>
</section>

<?php if (!empty($slot_errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($slot_errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card">
    <form method="GET" action="<?= h(app_url('/booking/search.php')) ?>" class="form-grid">
        <div class="form-group">
            <label for="sport">Sport</label>
            <select id="sport" name="sport">
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= (int)$sport['sport_type_id'] ?>" <?= (int)$sport['sport_type_id'] === $sport_type_id ? 'selected' : '' ?>>
                        <?= h($sport['name']) ?> - <?= money($sport['price_per_hour']) ?>/hour
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="booking_date">Date</label>
            <input type="date" id="booking_date" name="booking_date"
                   value="<?= h($booking_date) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>

        <!-- Dropdowns rather than <input type="time"> so only whole hours can
             be chosen at all. js/booking.js keeps the end ahead of the start. -->
        <div class="form-group">
            <label for="start_time">Start Time</label>
            <select id="start_time" name="start_time" class="js-start-time">
                <?php foreach ($start_options as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $value === $start_time ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="end_time">End Time</label>
            <select id="end_time" name="end_time" class="js-end-time">
                <?php foreach ($end_options as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $value === $end_time ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Check Availability</button>
        </div>
    </form>
</section>

<?php if (empty($slot_errors) && $selected_sport): ?>
    <section class="card">
        <h2>Available Courts</h2>
        <p class="muted">
            <?= h($selected_sport['name']) ?> on <?= h(date('d M Y', strtotime($booking_date))) ?>,
            <?= h(date('g:i A', strtotime($start_time))) ?> to <?= h(date('g:i A', strtotime($end_time))) ?>
            (<?= h(bookingDurationLabel($duration_hours)) ?>).
            <?= money($price_per_court) ?> per court.
        </p>

        <?php
        /*
         * There was a floor plan picture here, one PNG per sport, drawn with a
         * fixed set of courts on it.
         *
         * It has been removed because it could only ever be a drawing of one
         * moment in time. Courts are managed by the admin in admin/courts.php -
         * a new one can be added and an existing one retired at any point - and
         * a PNG cannot follow that. The picture would keep showing six
         * badminton courts after a seventh was built, or keep showing a court
         * that had been taken out of service, while the checkboxes underneath
         * it said something different. Two sources of truth on the same screen,
         * and the wrong one is the more eye-catching.
         *
         * The list of courts below is generated from the courts table, so it is
         * right by construction. That is the only picture of the hall this page
         * needs.
         */
        ?>

        <!-- Amenities -->
        <div class="amenities-card">

            <h2>Amenities</h2>

            <div class="amenities-list">

                <div class="amenity">
                    <span>Ⓟ Parking</span>
                </div>

                <div class="amenity">
                    <span>🚿 Shower</span>
                </div>

                <div class="amenity">
                    <span>🛍 Pro Shop</span>
                </div>

                <div class="amenity">
                    <span>🥤 Drinks</span>
                </div>

                <div class="amenity">
                    <span>🕌 Surau</span>
                </div>

            </div>

        </div>

        <?php if (empty($available_courts)): ?>
            <div class="empty-state">
                Every <?= h($selected_sport['name']) ?> court is taken for this slot. Try another time or date.
            </div>
        <?php else: ?>
            <!-- data-price-per-court lets js/booking.js keep the running total
                 in step as courts are ticked. The figure that gets charged is
                 worked out again in PHP on the payment page. -->
            <form method="POST" action="<?= h(app_url('/booking/payment.php')) ?>"
                  class="js-court-form" data-price-per-court="<?= h(number_format($price_per_court, 2, '.', '')) ?>">
                <input type="hidden" name="action" value="start">
                <input type="hidden" name="sport" value="<?= (int)$sport_type_id ?>">
                <input type="hidden" name="booking_date" value="<?= h($booking_date) ?>">
                <input type="hidden" name="start_time" value="<?= h($start_time) ?>">
                <input type="hidden" name="end_time" value="<?= h($end_time) ?>">

                <div class="selection-grid">
                    <?php foreach ($available_courts as $court): ?>
                        <label class="select-card">
                            <input type="checkbox" name="court_ids[]" value="<?= (int)$court['court_id'] ?>" class="js-court-check">
                            <span><?= h($court['court_number']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php /* Warned here, before payment, and again on the payment
                         page itself - which is the last screen before it
                         becomes true. */ ?>
                <div class="alert alert-warning">
                    <strong>Bookings are final once paid.</strong>
                    <p>
                        You will not be able to change the date, time or courts
                        after payment, so please check your selection before you
                        continue.
                    </p>
                </div>

                <div class="checkout-bar">
                    <strong>Total: <span class="js-booking-total"><?= money(0) ?></span></strong>
                    <button type="submit" class="btn">Continue to Payment</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script src="<?= h(asset_url('/js/booking.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
