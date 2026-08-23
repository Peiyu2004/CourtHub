<?php
/**
 * payment.php
 * The step between choosing courts and paying for them: shows what is about to
 * be booked, warns that it cannot be changed afterwards, and asks how the
 * customer wants to pay.
 *
 * It never collects any payment details itself. Choosing a method sends the
 * customer to the provider screen for that method - payment/tng.php or
 * payment/card.php - which is where the booking is finally written. Those
 * screens are shared with the equipment shop, which has its own version of
 * this page in shop/checkout.php.
 *
 * Flow:
 *   POST action=start   <- "Continue to Payment" on booking/search.php
 *                          checks the slot, keeps it in the session, then
 *                          redirects so a refresh cannot resubmit it
 *   GET                 <- summary, warning, and the two payment methods
 *   POST action=choose  <- remembers the method and forwards to its page
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/payment_functions.php';

requireLogin();

$action = $_POST['action'] ?? '';
$errors = [];
$payment_method = $_POST['payment_method'] ?? '';
$acknowledged = isset($_POST['acknowledge']);

/*
 * Step 1: arriving from the booking page with a set of ticked courts.
 *
 * The slot is checked before anything is remembered, so this page is never
 * drawn for a booking that could not be made anyway. On success the request is
 * redirected rather than rendered, which is what stops a browser refresh from
 * resubmitting the selection.
 */
if ($action === 'start') {
    $pending = [
        'type' => PAYMENT_TYPE_BOOKING,
        'sport_type_id' => (int)($_POST['sport'] ?? 0),
        'booking_date' => $_POST['booking_date'] ?? '',
        'start_time' => $_POST['start_time'] ?? '',
        'end_time' => $_POST['end_time'] ?? '',
        // array_unique stops a hand-edited form from booking - and charging
        // for - the same court twice in one order.
        'court_ids' => array_values(array_unique(array_map('intval', (array)($_POST['court_ids'] ?? [])))),
    ];

    $review = reviewPendingBooking($conn, $pending);
    if (empty($review['errors'])) {
        storePendingPayment($pending);
        header("Location: " . app_url('/booking/payment.php'));
        exit();
    }

    // The selection cannot be paid for, so nothing is remembered.
    clearPendingPayment();
    $errors = $review['errors'];
    $failed_selection = $pending;
}

$pending = getPendingPayment();

/*
 * Step 2: the customer picked how they want to pay.
 *
 * The choice and the acknowledgement are written into the pending booking
 * before forwarding, so the payment page can confirm both were made here
 * rather than trusting whoever asked for it.
 */
if ($action === 'choose' && $pending) {
    $review = reviewPendingBooking($conn, $pending);
    $errors = $review['errors'];

    $payment_page = paymentPageFor($payment_method);
    if (!$payment_page) {
        $errors[] = "Please choose a payment method.";
    }
    // Checked here and not only in the browser, because this is the copy of
    // the check the customer cannot skip.
    if (!$acknowledged) {
        $errors[] = "Please confirm you understand this booking cannot be changed after payment.";
    }

    if (empty($errors)) {
        $pending['payment_method'] = $payment_method;
        $pending['confirmed'] = true;
        storePendingPayment($pending);

        header("Location: " . app_url($payment_page));
        exit();
    }
}

// Priced fresh for the summary below, so what is shown is what would be
// charged rather than a figure carried over from the previous page.
$review = $pending ? reviewPendingBooking($conn, $pending) : null;

$page_title = 'Confirm Booking';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Confirm Your Booking</h1>
    <p class="muted">Check the details, then choose how you would like to pay.</p>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$pending): ?>
    <section class="card">
        <div class="empty-state">There is no booking waiting to be paid for.</div>
        <a href="<?= h(isset($failed_selection) ? bookingSearchUrl($failed_selection) : app_url('/booking/search.php')) ?>" class="btn">
            Back to Booking
        </a>
    </section>
<?php else: ?>

    <?php /* Said before payment, not after. */ ?>
    <div class="alert alert-warning">
        <strong>Please check your booking before you pay.</strong>
        <p>
            Once payment goes through, this booking is final. The date, time and
            courts cannot be changed, and the booking cannot be cancelled.
        </p>
    </div>

    <section class="card">
        <h2>Booking Summary</h2>

        <?php if ($review && $review['sport']): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sport</th>
                            <th>Court</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($review['courts'] as $court): ?>
                            <tr>
                                <td><?= h($review['sport']['name']) ?></td>
                                <td><?= h($court['court_number']) ?></td>
                                <td><?= h(date('d M Y', strtotime($pending['booking_date']))) ?></td>
                                <td>
                                    <?= h(date('g:i A', strtotime($pending['start_time']))) ?>
                                    - <?= h(date('g:i A', strtotime($pending['end_time']))) ?>
                                </td>
                                <td><?= money($review['price_per_court']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="split-row stack-md">
                <p class="muted">
                    <?= count($review['courts']) ?> court<?= count($review['courts']) === 1 ? '' : 's' ?>
                    &times; <?= h(bookingDurationLabel($review['duration_hours'])) ?>
                    at <?= money($review['sport']['price_per_hour']) ?>/hour
                </p>
                <div class="amount-pill"><?= money($review['total_amount']) ?></div>
            </div>
        <?php endif; ?>

        <p class="stack-sm">
            <a href="<?= h(bookingSearchUrl($pending)) ?>">&larr; Change my selection</a>
        </p>
    </section>

    <?php /* A booking with outstanding problems cannot be paid for, so the
             method choice is only offered once the summary above is clean. */ ?>
    <?php if ($review && empty($review['errors'])): ?>
        <section class="card">
            <h2>Choose Payment Method</h2>
            <p class="muted">
                You will enter your payment details on the next page.
                This is a simulation - no real bank, card network or e-wallet is contacted.
            </p>

            <form method="POST" action="<?= h(app_url('/booking/payment.php')) ?>">
                <input type="hidden" name="action" value="choose">

                <div class="selection-grid">
                    <label class="select-card">
                        <input type="radio" name="payment_method" value="tng_ewallet"
                               <?= $payment_method !== 'credit_debit_card' ? 'checked' : '' ?>>
                        <span>TNG E-Wallet</span>
                    </label>
                    <label class="select-card">
                        <input type="radio" name="payment_method" value="credit_debit_card"
                               <?= $payment_method === 'credit_debit_card' ? 'checked' : '' ?>>
                        <span>Credit / Debit Card</span>
                    </label>
                </div>

                <label class="acknowledge-row">
                    <input type="checkbox" name="acknowledge" value="1" <?= $acknowledged ? 'checked' : '' ?> required>
                    <span>
                        I understand this booking is final and cannot be modified
                        or cancelled once payment is made.
                    </span>
                </label>

                <div class="checkout-bar">
                    <strong>Total: <?= money($review['total_amount']) ?></strong>
                    <button type="submit" class="btn">Continue</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="empty-state">This booking can no longer be paid for.</div>
            <a href="<?= h(bookingSearchUrl($pending)) ?>" class="btn">Choose Another Slot</a>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
