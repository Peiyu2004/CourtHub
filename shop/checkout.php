<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$errors = [];
$order_success = isset($_GET['order_success']) ? (int)$_GET['order_success'] : 0;

function loadCartItems($conn, $user_id) {
    $items = [];
    $stmt = $conn->prepare(
        "SELECT ci.cart_id, ci.quantity, ci.selected_options,
                e.equipment_id, e.name, e.price, e.stock
         FROM cart_items ci
         JOIN equipment e ON ci.equipment_id = e.equipment_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    if (!in_array($payment_method, ['tng_ewallet', 'credit_debit_card'], true)) {
        $errors[] = "Please choose a valid payment method.";
    }

    $items = loadCartItems($conn, $user_id);
    if (empty($items)) {
        $errors[] = "Your cart is empty.";
    }

    $total_amount = 0;
    foreach ($items as $item) {
        if ((int)$item['quantity'] > (int)$item['stock']) {
            $errors[] = h($item['name']) . " does not have enough stock.";
        }
        $total_amount += (float)$item['price'] * (int)$item['quantity'];
    }

    if (empty($errors)) {
        $transaction_ref = makeTransactionRef($payment_method === 'tng_ewallet' ? 'TNGSHOP' : 'CARDSHOP');

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO equipment_orders (user_id, total_amount, payment_method, payment_status, transaction_ref)
                 VALUES (?, ?, ?, 'paid', ?)"
            );
            $stmt->bind_param("idss", $user_id, $total_amount, $payment_method, $transaction_ref);
            $stmt->execute();
            $equipment_order_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare(
                "INSERT INTO equipment_order_items
                 (equipment_order_id, equipment_id, quantity, price_at_purchase, selected_options)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stock_stmt = $conn->prepare(
                "UPDATE equipment
                 SET stock = stock - ?
                 WHERE equipment_id = ? AND stock >= ?"
            );

            foreach ($items as $item) {
                $equipment_id = (int)$item['equipment_id'];
                $quantity = (int)$item['quantity'];
                $price = (float)$item['price'];
                $selected_options = $item['selected_options'];

                $stock_stmt->bind_param("iii", $quantity, $equipment_id, $quantity);
                $stock_stmt->execute();
                if ($stock_stmt->affected_rows !== 1) {
                    throw new RuntimeException("Stock changed during checkout.");
                }

                $item_stmt->bind_param("iiids", $equipment_order_id, $equipment_id, $quantity, $price, $selected_options);
                $item_stmt->execute();
            }

            $item_stmt->close();
            $stock_stmt->close();

            $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header("Location: " . app_url('/shop/checkout.php?order_success=' . $equipment_order_id));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = "Payment could not be completed because stock changed. Please review your cart.";
        }
    }
}

$items = loadCartItems($conn, $user_id);
$total = 0;
foreach ($items as $item) {
    $total += (float)$item['price'] * (int)$item['quantity'];
}

$success_order = null;
if ($order_success > 0) {
    $stmt = $conn->prepare(
        "SELECT equipment_order_id, total_amount, payment_method, transaction_ref, created_at
         FROM equipment_orders
         WHERE equipment_order_id = ? AND user_id = ?"
    );
    $stmt->bind_param("ii", $order_success, $user_id);
    $stmt->execute();
    $success_order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success_order): ?>
    <section class="card">
        <h1>Payment Successful</h1>
        <p>Your equipment order #<?= (int)$success_order['equipment_order_id'] ?> has been paid.</p>
        <p class="muted">
            <?= h(paymentMethodLabel($success_order['payment_method'])) ?> /
            <?= h($success_order['transaction_ref']) ?> /
            <?= money($success_order['total_amount']) ?>
        </p>
        <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn">Continue Shopping</a>
    </section>
<?php else: ?>
    <section class="card">
        <h1>Equipment Payment</h1>
        <p class="muted">This is a simulation only. No real bank or e-wallet connection is made.</p>
    </section>

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
            <a href="<?= h(app_url('/shop/equipment.php')) ?>" class="btn">Browse Equipment</a>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['name']) ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td><?= money((float)$item['price'] * (int)$item['quantity']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" action="<?= h(app_url('/shop/checkout.php')) ?>" class="checkout-form">
                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="tng_ewallet">TNG E-Wallet</option>
                        <option value="credit_debit_card">Credit / Debit Card</option>
                    </select>
                </div>
                <div class="checkout-bar">
                    <strong>Total: <?= money($total) ?></strong>
                    <button type="submit" class="btn">Pay Now</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
