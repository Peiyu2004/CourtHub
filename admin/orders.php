<?php
/**
 * orders.php (admin)
 * The counter's list of equipment orders, and the one place an order is marked
 * as collected.
 *
 * The store is walk-in collection only - nothing is posted to anybody - so
 * paying does not finish a sale. It reserves the goods and puts the order on
 * this list, where it stays until the customer comes in, takes the items and an
 * admin presses "Mark as Collected".
 *
 * The customer sees the same status on shop/purchaseHistory.php, against the
 * same order number, so both sides of the counter are talking about one order.
 *
 * Flow:
 *   GET  ?status=pending|completed|all  <- the list, newest first
 *   GET  ?q=12                          <- one order, looked up by its number
 *   POST action=collect                 <- hands the goods over
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();

$errors = [];
$notice = '';

if (($_POST['action'] ?? '') === 'collect') {
    $order_id = (int)($_POST['equipment_order_id'] ?? 0);
    $error = markEquipmentOrderCollected($conn, $order_id);

    if ($error === '') {
        $notice = "Order #" . $order_id . " has been marked as collected.";
    } else {
        $errors[] = $error;
    }
}

/*
 * Which orders to show. The page opens on the ones still waiting, because
 * that is the list the counter actually works from; the other two views are
 * for looking something up afterwards.
 */
$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'completed', 'all'], true)) {
    $status = 'pending';
}

/*
 * Searching by order number.
 *
 * A customer at the counter reads out the number on their history page, so
 * that number is the whole search. A leading "#" is accepted because it is
 * printed with one everywhere else and it would be strange to reject it.
 *
 * Anything that is not a number is refused here rather than being passed on
 * as 0, so a mistyped search says what to type instead of quietly reporting
 * that no such order exists.
 */
$search = trim($_GET['q'] ?? '');
$searching = ($search !== '');
$search_id = 0;

if ($searching) {
    $digits = trim(ltrim($search, '#'));

    if (ctype_digit($digits) && (int)$digits > 0) {
        $search_id = (int)$digits;
    } else {
        $errors[] = "Search for an order by its number, for example 12.";
    }
}

if ($searching) {
    // A search is answered on its own, ignoring the tabs - see
    // findEquipmentOrderForAdmin(). An unusable search finds nothing.
    $orders = $search_id > 0 ? findEquipmentOrderForAdmin($conn, $search_id) : [];
} else {
    $orders = getEquipmentOrdersForAdmin($conn, $status);
}

$counts = equipmentOrderStatusCounts($conn);

$filters = [
    'pending'   => 'Waiting for Collection (' . $counts['pending'] . ')',
    'completed' => 'Collected (' . $counts['completed'] . ')',
    'all'       => 'All Orders (' . $counts['all'] . ')',
];

// Where "Mark as Collected" comes back to, so the admin keeps the search
// result or the tab they were working from instead of being sent to the top.
$view_query = $searching
    ? '?q=' . urlencode($search)
    : '?status=' . $status;

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
$page_title = 'Equipment Orders';
$extra_css = ['shop', 'admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Admin Dashboard</h1>
    <p class="muted">Court reservation revenue and management shortcuts.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <main class="dashboard-main-content">
        <section class="card">
            <h1>Equipment Orders</h1>
            <p class="muted">
                Customers collect their items at the counter. Check the order number, hand the
                items over, then mark the order as collected.
            </p>
        </section>

        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <form method="GET" action="<?= h(app_url('/admin/orders.php')) ?>" class="search-bar">
                <label for="q">Order number</label>
                <input type="search" id="q" name="q" value="<?= h($search) ?>"
                    placeholder="e.g. 12" inputmode="numeric" autocomplete="off">
                <button type="submit" class="btn">Search</button>
                <?php if ($searching): ?>
                    <a class="btn btn-secondary" href="<?= h(app_url('/admin/orders.php')) ?>">Clear Search</a>
                <?php endif; ?>
            </form>

            <?php /* A search answers itself, so no tab is the current view while one
                    is running and none of them is highlighted. */ ?>
            <div class="row-actions">
                <?php foreach ($filters as $key => $label): ?>
                    <a class="btn <?= (!$searching && $key === $status) ? '' : 'btn-secondary' ?>"
                    href="<?= h(app_url('/admin/orders.php?status=' . $key)) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <h2>
                <?php if ($search_id > 0): ?>
                    Order #<?= (int)$search_id ?>
                <?php elseif ($searching): ?>
                    Search Results
                <?php else: ?>
                    <?= h($filters[$status]) ?>
                <?php endif; ?>
            </h2>

            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <?php if ($searching): ?>
                        <?= $search_id > 0
                            ? 'No paid order has the number #' . (int)$search_id . '.'
                            : 'Type an order number to search, for example 12.' ?>
                    <?php else: ?>
                        <?= $status === 'pending'
                            ? 'No orders are waiting to be collected.'
                            : 'There are no orders to show here yet.' ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <?php /* The database id, which is the number the
                                                customer's own history shows them, so
                                                they can be read out and matched. */ ?>
                                        <strong>#<?= (int)$order['equipment_order_id'] ?></strong><br>
                                        <span class="muted">
                                            <?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <strong><?= h($order['full_name']) ?></strong><br>
                                        <span class="muted"><?= h($order['email']) ?></span><br>
                                        <span class="muted"><?= h($order['phone'] ?: 'No phone') ?></span>
                                    </td>

                                    <td>
                                        <?php foreach ($order['items'] as $item): ?>
                                            <?php $options = decodeSelectedOptions($item['selected_options']); ?>
                                            <div>
                                                <?= (int)$item['quantity'] ?> &times;
                                                <?php /* A product deleted after the sale
                                                        leaves its line behind with no
                                                        name, so the line still has to
                                                        say something. */ ?>
                                                <?= h($item['equipment_name'] ?? 'Item no longer in the shop') ?>
                                                <?php foreach ($options as $name => $value): ?>
                                                    <span class="mini-pill"><?= h($name) ?>: <?= h($value) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <span class="muted"><?= (int)$order['item_count'] ?> item(s) in total</span>
                                    </td>

                                    <td>
                                        <?= money($order['total_amount']) ?><br>
                                        <span class="muted"><?= h(paymentMethodLabel($order['payment_method'])) ?></span>
                                    </td>

                                    <td>
                                        <span class="status <?= h($order['order_status']) ?>">
                                            <?= h(equipmentOrderStatusLabel($order['order_status'])) ?>
                                        </span>
                                        <?php if ($order['collected_at']): ?>
                                            <span class="muted">
                                                <?= h(date('d M Y, h:i A', strtotime($order['collected_at']))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($order['order_status'] === 'pending'): ?>
                                            <?php /* js-confirm asks first (js/equipment.js).
                                                    Marking an order collected cannot be
                                                    undone from this page. */ ?>
                                            <form method="POST" action="<?= h(app_url('/admin/orders.php' . $view_query)) ?>"
                                                class="js-confirm"
                                                data-confirm="Mark order #<?= (int)$order['equipment_order_id'] ?> as collected by <?= h($order['full_name']) ?>?">
                                                <input type="hidden" name="action" value="collect">
                                                <input type="hidden" name="equipment_order_id"
                                                    value="<?= (int)$order['equipment_order_id'] ?>">
                                                <button type="submit" class="btn">Mark as Collected</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">Done</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="<?= h(asset_url('/js/equipment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
