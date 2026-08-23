<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
// Needed for decodeVariantKey(): a cart line names a variant now, and the
// choices it stands for are read back out of that variant's key.
require_once __DIR__ . '/../config/equipment_functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cart_id = (int)($_POST['cart_id'] ?? 0);

    if ($action === 'remove' && $cart_id > 0) {
        $stmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $cart_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $notice = "Item removed from cart.";
    }

    if ($action === 'update' && $cart_id > 0) {
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        // The cap comes from the variant this line points at, not from the
        // product. Capping against the product total would let a customer put
        // 20 racquets in the cart when only 4 exist in the colour they chose,
        // and checkout would then refuse an order the cart said was fine.
        $stmt = $conn->prepare(
            "UPDATE cart_items ci
             JOIN equipment_variants v ON v.variant_id = ci.variant_id
             SET ci.quantity = LEAST(?, v.stock)
             WHERE ci.cart_id = ? AND ci.user_id = ?"
        );
        $stmt->bind_param("iii", $quantity, $cart_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $notice = "Cart updated.";
    }
}

$items = [];
$stmt = $conn->prepare(
    "SELECT ci.cart_id, ci.quantity,
            v.variant_id, v.variant_key, v.stock,
            e.equipment_id, e.name, e.price, e.brand, e.category, e.status,
            st.name AS sport_name
     FROM cart_items ci
     JOIN equipment_variants v ON ci.variant_id = v.variant_id
     JOIN equipment e          ON v.equipment_id = e.equipment_id
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id
     WHERE ci.user_id = ?
     ORDER BY ci.added_at DESC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total = 0;
/*
 * Every reason this cart could not be paid for, worked out here rather than
 * being discovered on the payment screen.
 *
 * Stock moves while a cart sits open - somebody else can buy the last one, and
 * an admin can retire a colour - so a cart that was fine when it was filled
 * can be unbuyable by the time it is looked at again. cartLineProblem() is the
 * same function reviewPendingEquipmentOrder() uses to refuse the payment, so
 * what is written here is exactly what would stop the sale.
 */
$blocking_problems = [];
while ($row = $result->fetch_assoc()) {
    $row['line_total'] = (float)$row['price'] * (int)$row['quantity'];
    $row['problem']    = cartLineProblem($row);
    $total += $row['line_total'];

    if ($row['problem'] !== '') {
        $blocking_problems[] = $row['problem'];
    }

    $items[] = $row;
}
$stmt->close();

$cart_is_payable = empty($blocking_problems);

$page_title = 'Your Cart';
$extra_css = ['shop'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Shopping Cart</h1>
    <p class="muted">Review your selected equipment before simulated payment.</p>
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

<?php
/*
 * The box that says why the cart cannot be paid for.
 *
 * It is printed by PHP with whatever is wrong right now, and js/equipment.js
 * rewrites it from the same rules when the shopper edits a quantity, so the
 * reason is never one step behind what is on screen. It is left in the page
 * even when the cart is fine - hidden - so the script has somewhere to write
 * to without having to build the element first.
 */
?>
<div class="alert alert-error js-cart-blocker" id="cartBlocker" role="alert"
     <?= $cart_is_payable ? 'hidden' : '' ?>>
    <p><strong>This cart cannot be paid for yet.</strong></p>
    <div class="js-cart-blocker-list">
        <?php foreach ($blocking_problems as $problem): ?>
            <p><?= h($problem) ?></p>
        <?php endforeach; ?>
    </div>
    <p class="muted">
        Lower the quantity and press Update, or remove the item, and the cart
        will be ready to pay for.
    </p>
</div>

<?php if (empty($items)): ?>
    <section class="card">
        <div class="empty-state">Your cart is empty.</div>
        <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn">Continue Shopping</a>
    </section>
<?php else: ?>
    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Options</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                            $options = decodeVariantKey($item['variant_key']);
                            // The name the messages use, spelled once here so the
                            // PHP sentence and the JavaScript one match.
                            $item_label = $item['variant_key'] === ''
                                ? $item['name']
                                : $item['name'] . ' (' . variantLabel($item['variant_key']) . ')';
                        ?>
                        <!-- data-* is what js/equipment.js re-checks the line
                             against while a quantity is being typed. -->
                        <tr class="js-cart-row<?= $item['problem'] !== '' ? ' is-unbuyable' : '' ?>"
                            data-stock="<?= (int)$item['stock'] ?>"
                            data-status="<?= h($item['status']) ?>"
                            data-item-label="<?= h($item_label) ?>"
                            data-saved-quantity="<?= (int)$item['quantity'] ?>">
                            <td>
                                <strong><?= h($item['name']) ?></strong><br>
                                <span class="muted"><?= h($item['sport_name']) ?> / <?= h($item['brand']) ?> <?= h($item['category']) ?></span>
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
                            <td><?= money($item['price']) ?></td>
                            <td>
                                <form method="POST" action="<?= h(app_url('/shop/cart.php')) ?>" class="inline-form">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item['cart_id'] ?>">
                                    <input type="number" name="quantity" class="qty-input js-cart-qty"
                                           value="<?= (int)$item['quantity'] ?>" min="1"
                                           max="<?= max(1, (int)$item['stock']) ?>"
                                           aria-label="Quantity of <?= h($item_label) ?>">
                                    <button type="submit" class="btn btn-secondary">Update</button>
                                </form>
                                <?php
                                    /*
                                     * The little line under the quantity box. The limit
                                     * is this combination's own stock, so it is worth
                                     * spelling out - the shopper may be looking at a
                                     * product the store still lists as having plenty,
                                     * just not in this colour.
                                     *
                                     * js/equipment.js rewrites it from the same three
                                     * rules as the number in the box changes, so the
                                     * warning shows while the quantity is being typed
                                     * rather than only after Update has been pressed.
                                     */
                                    $line_stock = (int)$item['stock'];
                                    $line_qty   = (int)$item['quantity'];

                                    if ($line_stock <= 0) {
                                        $note_text  = 'Out of stock';
                                        $note_class = 'stock-out';
                                    } elseif ($line_qty > $line_stock) {
                                        $note_text  = 'Only ' . $line_stock . ' left';
                                        $note_class = 'stock-out';
                                    } elseif ($line_stock <= 5) {
                                        // Not a problem, just worth knowing.
                                        $note_text  = 'Only ' . $line_stock . ' left';
                                        $note_class = 'muted';
                                    } else {
                                        $note_text  = '';
                                        $note_class = 'muted';
                                    }
                                ?>
                                <span class="js-cart-line-note <?= h($note_class) ?>"><?= h($note_text) ?></span>
                            </td>
                            <td><?= money($item['line_total']) ?></td>
                            <td>
                                <form method="POST" action="<?= h(app_url('/shop/cart.php')) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item['cart_id'] ?>">
                                    <button type="submit" class="btn btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="checkout-bar">
            <strong>Total: <?= money($total) ?></strong>

            <?php
            /*
             * Still a link, and still pointing at checkout.php, even when the
             * cart cannot be paid for.
             *
             * js/equipment.js is what stops the click and shows the reason,
             * which is the quick answer the shopper wants. It is not the
             * protection: a shopper with JavaScript off follows the link and
             * checkout.php refuses them there, from the same
             * cartLineProblem() rules, and payForEquipmentOrder() checks the
             * stock a third time inside the transaction that takes it. The
             * script only saves a wasted page load.
             */
            ?>
            <div class="checkout-actions">
                <a href="<?= h(app_url('/shop/equipment.php')) ?>"
                   class="btn btn-secondary">Continue Shopping</a>

                <a href="<?= h(app_url('/shop/checkout.php')) ?>"
                   class="btn js-checkout-link<?= $cart_is_payable ? '' : ' is-blocked' ?>"
                   data-blocked="<?= $cart_is_payable ? '0' : '1' ?>"
                   aria-describedby="cartBlocker">Proceed to Payment</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<script src="<?= h(asset_url('/js/equipment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
