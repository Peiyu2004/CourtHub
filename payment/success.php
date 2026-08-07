<?php
/**
 * payment/success.php
 * The receipt the simulated payment provider shows once a payment has gone
 * through, before handing the customer back to CourtHub.
 *
 * Shared by both payment types: which order it is reading, what to call it and
 * where to send the customer afterwards all come from paymentType() in
 * payment_functions.php.
 *
 * It is the last of the three provider screens, so it uses the plain gateway
 * shell rather than the site header and footer - the customer has not returned
 * to the merchant yet. Five seconds later it sends them back to their own
 * history, by <meta refresh> in the shell and by an on-screen countdown in
 * js/payment.js, so the return happens with or without JavaScript.
 *
 * The page only reads. The order was written when payment was taken, so
 * refreshing this page cannot pay twice.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/payment_functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$type = $_GET['type'] ?? '';
$order_id = (int)($_GET['order'] ?? 0);

$config = paymentType($type);

// Scoped to this customer, so changing the number in the address bar shows
// nothing rather than somebody else's receipt.
$order = $config ? findPaidOrder($conn, $user_id, $type, $order_id) : null;
if (!$order) {
    header("Location: " . app_url('/index.php'));
    exit();
}

$order_number = paidOrderDisplayNumber($conn, $user_id, $type, $order_id);
$return_url = merchantReturnUrl($type, $order_id);
$return_seconds = 5;

$gateway_title = 'Payment Received';
$gateway_refresh = ['seconds' => $return_seconds, 'url' => $return_url];

require_once __DIR__ . '/../includes/gatewayHeader.php';
?>

<div class="receipt-card">

    <div class="receipt-tick" aria-hidden="true"></div>

    <h1 class="receipt-title">Payment Received</h1>
    <p class="receipt-subtitle">Your payment has been approved.</p>

    <div class="receipt-amount"><?= money($order['total_amount']) ?></div>

    <dl class="receipt-details">
        <div>
            <dt><?= h($config['noun']) ?></dt>
            <dd>#<?= (int)$order_number ?></dd>
        </div>
        <div>
            <dt>Paid With</dt>
            <dd><?= h(paymentMethodLabel($order['payment_method'])) ?></dd>
        </div>
        <div>
            <dt>Reference</dt>
            <dd><?= h($order['transaction_ref']) ?></dd>
        </div>
        <div>
            <dt>Date</dt>
            <dd><?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?></dd>
        </div>
    </dl>

    <!-- data-return-url / data-seconds drive the countdown in js/payment.js.
         The <meta refresh> in the shell does the same job without it. -->
    <p class="receipt-return js-payment-return"
       data-return-url="<?= h($return_url) ?>"
       data-seconds="<?= (int)$return_seconds ?>">
        Returning to CourtHub in
        <span class="js-return-countdown"><?= (int)$return_seconds ?></span> seconds&hellip;
    </p>

    <a href="<?= h($return_url) ?>" class="btn receipt-button">Return Now</a>

</div>

<script src="<?= h(app_url('/js/payment.js')) ?>?v=1.0"></script>

<?php require_once __DIR__ . '/../includes/gatewayFooter.php'; ?>
