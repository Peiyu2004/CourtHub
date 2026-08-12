<?php
/**
 * payment/card.php
 * The credit / debit card screen of the simulated payment provider.
 *
 * Shared by both things a customer can pay for - see the note at the top of
 * payment/tng.php; this page works exactly the same way and only differs in
 * which fields it collects.
 *
 * The site header and footer are deliberately absent. This page stands in for
 * the card provider's own screen, so it uses the plain gateway shell instead -
 * see includes/gatewayHeader.php.
 *
 * The payment is a simulation. There is no card network behind it: the only
 * rule applied to the four fields is that none is left empty, so any card
 * number is accepted. Nothing typed here is stored, and the card number and
 * CVV are never written back into the page after a rejected submit - which is
 * the only responsible way to handle card fields, simulation or not.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/payment_functions.php';

requireLogin();

// Sends the customer back to the review page unless they really did choose
// card there and confirm the purchase.
$pending = requirePendingPayment('credit_debit_card');

$user_id = (int)$_SESSION['user_id'];
$errors = [];

// Only the two harmless fields are kept for a redraw. The card number and CVV
// are deliberately dropped.
$card_name = $_POST['card_name'] ?? '';
$card_expiry = $_POST['card_expiry'] ?? '';

if (($_POST['action'] ?? '') === 'pay') {
    $result = takePendingPayment($conn, $user_id, $pending, $_POST);

    if ($result['order_id']) {
        // The provider shows its own receipt first, then hands the customer
        // back to the merchant.
        header("Location: " . app_url(paymentReceiptUrl($pending['type'], $result['order_id'])));
        exit();
    }
    $errors = $result['errors'];
}

// Priced again so the amount shown is the amount that would be charged.
$review = reviewPendingPayment($conn, $user_id, $pending);

$gateway_title = 'Card Payment';
require_once __DIR__ . '/../includes/gatewayHeader.php';
?>

<section class="card">
    <h1>Card Payment</h1>
    <p class="muted">Paying CourtHub Sport Center &bull; simulation only</p>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-warning">
    <?php if ($pending['type'] === PAYMENT_TYPE_EQUIPMENT): ?>
        <strong>Your order is confirmed once paid.</strong>
        <p>The items and quantities cannot be changed after payment.</p>
    <?php else: ?>
        <strong>This booking is final once paid.</strong>
        <p>The date, time and courts cannot be changed after payment.</p>
    <?php endif; ?>
</div>

<section class="card card-payment">
    <!-- The card face is decoration that mirrors what is being typed, so the
         customer can see their entry take shape. js/payment.js fills it in;
         with JavaScript off it simply keeps showing the placeholders below. -->
    <div class="card-face" aria-hidden="true">
        <div class="card-face-top">
            <span class="card-face-chip"></span>
            <span class="card-face-brand">DEBIT / CREDIT</span>
        </div>
        <div class="card-face-number js-card-face-number">&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;</div>
        <div class="card-face-row">
            <div>
                <span class="card-face-label">Card Holder</span>
                <span class="card-face-value js-card-face-name">YOUR NAME</span>
            </div>
            <div>
                <span class="card-face-label">Expires</span>
                <span class="card-face-value js-card-face-expiry">MM/YY</span>
            </div>
        </div>
    </div>

    <?php if (empty($review['errors'])): ?>
        <form method="POST" action="<?= h(app_url('/payment/card.php')) ?>"
              class="card-payment-form js-payment-form js-card-form">
            <input type="hidden" name="action" value="pay">

            <div class="form-group">
                <label for="card_name">Name on Card</label>
                <input type="text" id="card_name" name="card_name"
                       value="<?= h($card_name) ?>" placeholder="LIM WEI JIE"
                       autocomplete="off" required>
            </div>

            <div class="form-group">
                <label for="card_number">Card Number</label>
                <input type="text" id="card_number" name="card_number"
                       maxlength="19" inputmode="numeric" placeholder="4111 1111 1111 1111"
                       autocomplete="off" required>
            </div>

            <div class="card-field-pair">
                <div class="form-group">
                    <label for="card_expiry">Expiry Date</label>
                    <input type="text" id="card_expiry" name="card_expiry"
                           value="<?= h($card_expiry) ?>" maxlength="5" placeholder="MM/YY"
                           autocomplete="off" required>
                </div>
                <div class="form-group">
                    <label for="card_cvv">CVV</label>
                    <input type="password" id="card_cvv" name="card_cvv"
                           maxlength="4" inputmode="numeric" placeholder="&bull;&bull;&bull;"
                           autocomplete="off" required>
                </div>
            </div>

            <div class="card-payment-summary">
                <p class="muted">
                    <?= h($review['headline']) ?> &bull; <?= h($review['detail']) ?>
                </p>
            </div>

            <div class="checkout-bar">
                <strong>Total: <?= money($review['amount']) ?></strong>
                <button type="submit" class="btn">Pay <?= money($review['amount']) ?></button>
            </div>
        </form>
    <?php endif; ?>

    <p class="card-payment-back">
        <a href="<?= h(app_url(paymentReviewUrl($pending['type']))) ?>">&larr; Back to payment methods</a>
    </p>
</section>

<script src="<?= h(asset_url('/js/payment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/gatewayFooter.php'; ?>
