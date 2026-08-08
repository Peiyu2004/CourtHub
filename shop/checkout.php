<?php
/**
 * checkout.php
 * The step between the cart and paying for it: shows what is about to be
 * bought and asks how the customer wants to pay.
 *
 * This is the shop's equivalent of booking/payment.php, and it works the same
 * way. It never collects any payment details itself - choosing a method sends
 * the customer to the provider screen for that method, payment/tng.php or
 * payment/card.php, which is where the order is finally written.
 *
 * The cart itself is the pending order. Nothing is snapshotted into the
 * session, so the prices and stock shown here are read fresh from the database
 * every time, and the order that gets written is priced from the cart again at
 * the moment payment is taken.
 *
 * Flow:
 *   GET                 <- the cart, its total, and the two payment methods
 *   POST action=choose  <- remembers the method and forwards to its screen
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/payment_functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$errors = [];
$payment_method = $_POST['payment_method'] ?? '';

$review = reviewPendingEquipmentOrder($conn, $user_id);

/*
 * The customer picked how they want to pay.
 *
 * The choice is written into the session before forwarding, so the provider
 * screen can confirm it was made here rather than trusting whoever asked for
 * it. The cart is checked again first, because a payment screen must never be
 * shown for an order that could not go through anyway.
 */
if (($_POST['action'] ?? '') === 'choose') {
    $errors = $review['errors'];

    $payment_page = paymentPageFor($payment_method);
    if (!$payment_page) {
        $errors[] = "Please choose a payment method.";
    }

    if (empty($errors)) {
        storePendingPayment([
            'type' => PAYMENT_TYPE_EQUIPMENT,
            'payment_method' => $payment_method,
            'confirmed' => true,
        ]);

        header("Location: " . app_url($payment_page));
        exit();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Checkout</h1>
    <p class="muted">Check your order, then choose how you would like to pay.</p>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($review['items'])): ?>
    <section class="card">
        <div class="empty-state">Your cart is empty.</div>
        <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn">Browse Equipment</a>
    </section>
<?php else: ?>

    <div class="alert alert-warning">
        <strong>Please check your order before you pay.</strong>
        <p>The items and quantities cannot be changed once payment is made.</p>
    </div>

    <?php /* Equipment is collected in person - there is no delivery anywhere in
             the shop - so the customer is told before they pay rather than
             after, when they can still decide against the order. */ ?>
    <div class="alert alert-info">
        <strong>Walk-in collection only.</strong>
        <p>
            Your items are not delivered. Once you have placed your order, walk in to
            CourtHub Sport Center and show your order to collect them at the counter.
        </p>
    </div>

    <section class="card">
        <h2>Order Summary</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Options</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($review['items'] as $item): ?>
                        <?php $options = decodeSelectedOptions($item['selected_options']); ?>
                        <tr>
                            <td><strong><?= h($item['name']) ?></strong></td>
                            <td>
                                <?php if (empty($options)): ?>
                                    <span class="muted">No options</span>
                                <?php else: ?>
                                    <?php foreach ($options as $name => $value): ?>
                                        <span class="mini-pill"><?= h($name) ?>: <?= h($value) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= money($item['price']) ?></td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td><?= money($item['line_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="split-row" style="margin-top: 18px;">
            <p class="muted">
                <?= (int)$review['item_count'] ?> item<?= $review['item_count'] === 1 ? '' : 's' ?>
                across <?= count($review['items']) ?> line<?= count($review['items']) === 1 ? '' : 's' ?>
            </p>
            <div class="amount-pill"><?= money($review['total_amount']) ?></div>
        </div>

        <p style="margin-top: 14px;">
            <a href="<?= h(app_url('/shop/cart.php')) ?>">&larr; Change my cart</a>
        </p>
    </section>

    <?php /* An order with outstanding problems - something out of stock or
             withdrawn - cannot be paid for, so the method choice is only
             offered once the summary above is clean. */ ?>
    <?php if (empty($review['errors'])): ?>
        <section class="card">
            <h2>Choose Payment Method</h2>
            <p class="muted">
                You will enter your payment details on the next page.
                This is a simulation - no real bank, card network or e-wallet is contacted.
            </p>

            <form method="POST" action="<?= h(app_url('/shop/checkout.php')) ?>">
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

                <div class="checkout-bar">
                    <strong>Total: <?= money($review['total_amount']) ?></strong>
                    <button type="submit" class="btn">Continue</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="empty-state">Please fix the problems above before paying.</div>
            <a href="<?= h(app_url('/shop/cart.php')) ?>" class="btn">Back to Cart</a>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
