<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

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
        $stmt = $conn->prepare(
            "UPDATE cart_items ci
             JOIN equipment e ON e.equipment_id = ci.equipment_id
             SET ci.quantity = LEAST(?, e.stock)
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
    "SELECT ci.cart_id, ci.quantity, ci.selected_options,
            e.equipment_id, e.name, e.price, e.stock, e.brand, e.category,
            st.name AS sport_name
     FROM cart_items ci
     JOIN equipment e ON ci.equipment_id = e.equipment_id
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id
     WHERE ci.user_id = ?
     ORDER BY ci.added_at DESC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total = 0;
while ($row = $result->fetch_assoc()) {
    $row['line_total'] = (float)$row['price'] * (int)$row['quantity'];
    $total += $row['line_total'];
    $items[] = $row;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
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
                        <?php $options = json_decode($item['selected_options'] ?? '{}', true) ?: []; ?>
                        <tr>
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
                                    <input type="number" name="quantity" class="qty-input"
                                           value="<?= (int)$item['quantity'] ?>" min="1"
                                           max="<?= max(1, (int)$item['stock']) ?>"
                                           aria-label="Quantity of <?= h($item['name']) ?>">
                                    <button type="submit" class="btn btn-secondary">Update</button>
                                </form>
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
            <a href="<?= h(app_url('/shop/checkout.php')) ?>" class="btn">Proceed to Payment</a>
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
