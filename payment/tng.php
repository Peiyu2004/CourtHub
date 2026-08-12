<?php
/**
 * payment/tng.php
 * The Touch 'n Go e-wallet screen of the simulated payment provider.
 *
 * Shared by both things a customer can pay for. It never needs to know whether
 * it is taking money for a court booking or an equipment order - it asks
 * payment_functions.php what the payment comes to and what to call it, shows
 * the form, and hands the details back. That is why there is one of these
 * rather than one per module.
 *
 * The site header and footer are deliberately absent. This page stands in for
 * the e-wallet application, so it uses the plain gateway shell instead - see
 * includes/gatewayHeader.php.
 *
 * The payment is a simulation. There is no e-wallet behind it: the only rule
 * applied to the phone number and PIN is that they are not left empty. Neither
 * value is stored - the order row keeps the payment method and a generated
 * reference and nothing else, so what is typed here lives for one request and
 * is then gone.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/payment_functions.php';

requireLogin();

// Sends the customer back to the review page unless they really did choose TNG
// there and confirm the purchase.
$pending = requirePendingPayment('tng_ewallet');

$user_id = (int)$_SESSION['user_id'];
$errors = [];
$phone = $_POST['tng_phone'] ?? '';

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

$gateway_title = "Touch 'n Go eWallet Payment";
require_once __DIR__ . '/../includes/gatewayHeader.php';
?>

<section class="tng-page">
    <div class="tng-card">

        <div class="tng-header">
            <img src="<?= h(app_url('/images/tng.png')) ?>" alt="Touch 'n Go eWallet" class="tng-logo">
            <h1 class="tng-title">Touch &rsquo;n Go eWallet</h1>
            <p class="tng-subtitle">Paying CourtHub Sport Center</p>
        </div>

        <div class="tng-body">

            <span class="tng-amount-label">Amount to Pay</span>
            <span class="tng-amount"><?= money($review['amount']) ?></span>

            <p class="tng-summary">
                <?= h($review['headline']) ?><br>
                <?= h($review['detail']) ?>
            </p>

            <?php if (!empty($errors)): ?>
                <div class="tng-alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($review['errors'])): ?>
                <!-- js-payment-form is a behaviour hook for js/payment.js (the
                     "are you sure" prompt), not a styling hook. -->
                <form method="POST" action="<?= h(app_url('/payment/tng.php')) ?>" class="js-payment-form">
                    <input type="hidden" name="action" value="pay">

                    <div class="tng-field">
                        <label for="tng_phone" class="tng-label">Phone Number</label>
                        <input type="tel" id="tng_phone" name="tng_phone" class="tng-input"
                               value="<?= h($phone) ?>" placeholder="012-3456789"
                               autocomplete="off" required>
                    </div>

                    <div class="tng-field">
                        <label for="tng_pin" class="tng-label">6-Digit PIN</label>
                        <!-- Never re-filled after a rejected submit, so a PIN is
                             not written back into the page. -->
                        <input type="password" id="tng_pin" name="tng_pin" class="tng-input tng-pin"
                               maxlength="6" inputmode="numeric" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;"
                               autocomplete="off" required>
                    </div>

                    <button type="submit" class="tng-pay">Pay <?= money($review['amount']) ?></button>
                </form>

                <p class="tng-note">
                    <?= $pending['type'] === PAYMENT_TYPE_EQUIPMENT
                        ? 'Your order will be confirmed as soon as payment is made.'
                        : 'This booking cannot be changed or cancelled once payment is made.' ?>
                </p>
            <?php endif; ?>

            <a href="<?= h(app_url(paymentReviewUrl($pending['type']))) ?>" class="tng-back">
                &larr; Back to payment methods
            </a>

        </div>
    </div>
</section>

<script src="<?= h(asset_url('/js/payment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/gatewayFooter.php'; ?>
