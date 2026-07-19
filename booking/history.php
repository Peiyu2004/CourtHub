<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$booking_success = isset($_GET['booking_success']) ? (int)$_GET['booking_success'] : 0;
$orders = [];
$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT bo.booking_order_id, bo.total_amount, bo.payment_method, bo.payment_status,
            bo.transaction_ref, bo.created_at,
            boi.booking_date, boi.start_time, boi.end_time, boi.price,
            c.court_number, st.name AS sport_name
     FROM booking_orders bo
     JOIN booking_order_items boi ON bo.booking_order_id = boi.booking_order_id
     JOIN courts c ON boi.court_id = c.court_id
     JOIN sport_types st ON c.sport_type_id = st.sport_type_id
     WHERE bo.user_id = ?
     ORDER BY bo.created_at DESC, bo.booking_order_id DESC, boi.booking_date DESC, boi.start_time ASC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $order_id = (int)$row['booking_order_id'];
    if (!isset($orders[$order_id])) {
        $orders[$order_id] = [
            'booking_order_id' => $order_id,
            'total_amount' => $row['total_amount'],
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'transaction_ref' => $row['transaction_ref'],
            'created_at' => $row['created_at'],
            'items' => [],
        ];
    }
    $orders[$order_id]['items'][] = $row;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>My Reservations</h1>
    <p class="muted">Paid reservations are final and cannot be modified after payment.</p>
</section>

<?php if ($booking_success > 0): ?>
    <div class="alert alert-success">
        Reservation #<?= $booking_success ?> has been paid successfully.
    </div>
<?php endif; ?>

<?php if (empty($orders)): ?>
    <section class="card">
        <div class="empty-state">You do not have any reservations yet.</div>
        <a href="<?= h(app_url('/booking/search.php')) ?>" class="btn">Book a Court</a>
    </section>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <section class="card">
            <div class="split-row">
                <div>
                    <h2>Reservation #<?= (int)$order['booking_order_id'] ?></h2>
                    <p class="muted">
                        Paid with <?= h(paymentMethodLabel($order['payment_method'])) ?>
                        on <?= h(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                    </p>
                    <p class="muted">Transaction: <?= h($order['transaction_ref']) ?></p>
                </div>
                <div class="amount-pill"><?= money($order['total_amount']) ?></div>
            </div>

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
                        <?php foreach ($order['items'] as $item): ?>
                            <tr>
                                <td><?= h($item['sport_name']) ?></td>
                                <td><?= h($item['court_number']) ?></td>
                                <td><?= h(date('d M Y', strtotime($item['booking_date']))) ?></td>
                                <td><?= h(substr($item['start_time'], 0, 5)) ?> - <?= h(substr($item['end_time'], 0, 5)) ?></td>
                                <td><?= money($item['price']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
