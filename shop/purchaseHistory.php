<?php
/**
 * purchaseHistory.php
 * The equipment half of a customer's history: every order they have paid for
 * in the store, newest first, with the exact items, variant choices and price
 * that were charged at the time.
 *
 * The court half of the same feature lives in booking/history.php. Both pages
 * draw the same tab strip so the customer can move between the two.
 *
 * Prices come from equipment_order_items.price_at_purchase, not from the
 * equipment table, so an old receipt keeps showing what the customer actually
 * paid even after the shop changes its prices.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];

$orders = getEquipmentPurchaseHistory($conn, $user_id);
$summary = purchaseHistorySummary($orders);

/*
 * Equipment orders are shown by their real equipment_order_id, not by a
 * per-customer 1, 2, 3 sequence like court bookings are.
 *
 * These orders are collected in person, so the number stops being a private
 * label the moment the customer walks in and reads it out at the counter. The
 * admin's list works from the database id, and a customer's "Order #2" that is
 * really #37 would not find anything there.
 */

// Set when the customer has just come back from the payment provider. Checked
// against their own orders, so a number typed into the address bar cannot
// announce a payment that is not theirs.
$order_success = isset($_GET['order_success']) ? (int)$_GET['order_success'] : 0;
$paid_order = $orders[$order_success] ?? null;

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>My History</h1>
    <p class="muted">Your court reservations and your equipment orders, all in one place.</p>
    <?php renderHistoryTabs('purchases'); ?>
</section>

<?php if ($paid_order): ?>
    <div class="alert alert-success">
        Order #<?= (int)$order_success ?> has been paid successfully.
        Bring this order number to CourtHub Sport Center to collect your items.
    </div>
<?php endif; ?>

<?php if (empty($orders)): ?>
    <section class="card">
        <div class="empty-state">You have not purchased any equipment yet.</div>
        <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn">Browse Equipment</a>
    </section>
<?php else: ?>
    <section class="stats-grid three-up">
        <div class="card stat-card">
            <span>Orders</span>
            <strong><?= (int)$summary['orders'] ?></strong>
        </div>
        <div class="card stat-card">
            <span>Items Bought</span>
            <strong><?= (int)$summary['items'] ?></strong>
        </div>
        <div class="card stat-card">
            <span>Total Spent</span>
            <strong><?= money($summary['total_spent']) ?></strong>
        </div>
    </section>

    <?php foreach ($orders as $order): ?>
        <section class="card">
            <div class="split-row">
                <div>
                    <h2>Order #<?= (int)$order['equipment_order_id'] ?></h2>
                    <p class="muted">
                        Paid with <?= h(paymentMethodLabel($order['payment_method'])) ?>
                        on <?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                    </p>
                    <p class="muted">Transaction: <?= h($order['transaction_ref']) ?></p>
                </div>
                <div class="amount-pill"><?= money($order['total_amount']) ?></div>
            </div>

            <?php /* The order is only finished once the customer has been to
                     the counter, so where it has got to is shown on the order
                     itself rather than left to be guessed from the date. */ ?>
            <p>
                <span class="status <?= h($order['order_status']) ?>">
                    <?= h(equipmentOrderStatusLabel($order['order_status'])) ?>
                </span>
                <?php if ($order['order_status'] === 'completed'): ?>
                    <?php if ($order['collected_at']): ?>
                        <span class="muted">
                            Collected on <?= h(date('d M Y, h:i A', strtotime($order['collected_at']))) ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">
                        Show order #<?= (int)$order['equipment_order_id'] ?> at CourtHub Sport Center to collect these items.
                    </span>
                <?php endif; ?>
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Options</th>
                            <th>Unit Price</th>
                            <th>Quantity</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item): ?>
                            <?php $options = decodeSelectedOptions($item['selected_options']); ?>
                            <tr>
                                <td>
                                    <?php if ($item['equipment_id'] === null): ?>
                                        <?php /* Product was removed from the shop after this sale. */ ?>
                                        <strong>Item no longer available</strong>
                                    <?php else: ?>
                                        <strong>
                                            <a href="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$item['equipment_id'])) ?>">
                                                <?= h($item['equipment_name']) ?>
                                            </a>
                                        </strong><br>
                                        <span class="muted">
                                            <?= h($item['sport_name']) ?> / <?= h($item['brand']) ?> <?= h($item['category']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($options)): ?>
                                        <span class="muted">No options</span>
                                    <?php else: ?>
                                        <?php foreach ($options as $name => $value): ?>
                                            <span class="mini-pill"><?= h($name) ?>: <?= h($value) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= money($item['price_at_purchase']) ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td><?= money($item['line_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
