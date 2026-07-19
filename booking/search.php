<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$sports = getSportTypes($conn);
$errors = [];
$success = '';

$sport_type_id = isset($_REQUEST['sport']) ? (int)$_REQUEST['sport'] : 1;
$booking_date = $_REQUEST['booking_date'] ?? date('Y-m-d');
$start_time = $_REQUEST['start_time'] ?? '08:00';
$end_time = $_REQUEST['end_time'] ?? '09:00';
$payment_method = $_POST['payment_method'] ?? 'tng_ewallet';

$selected_sport = null;
foreach ($sports as $sport) {
    if ((int)$sport['sport_type_id'] === $sport_type_id) {
        $selected_sport = $sport;
        break;
    }
}
if (!$selected_sport && count($sports) > 0) {
    $selected_sport = $sports[0];
    $sport_type_id = (int)$selected_sport['sport_type_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reserve') {
    $court_ids = array_map('intval', $_POST['court_ids'] ?? []);
    $errors = validateBookingWindow($booking_date, $start_time, $end_time);

    if (empty($court_ids)) {
        $errors[] = "Please select at least one court.";
    }
    if (!in_array($payment_method, ['tng_ewallet', 'credit_debit_card'], true)) {
        $errors[] = "Please choose a valid payment method.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT court_id, court_number
             FROM courts
             WHERE sport_type_id = ?
               AND court_id = ?
               AND status = 'active'"
        );
        $valid_courts = [];
        foreach ($court_ids as $court_id) {
            $stmt->bind_param("ii", $sport_type_id, $court_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $valid_courts[(int)$row['court_id']] = $row;
            }
        }
        $stmt->close();

        if (count($valid_courts) !== count($court_ids)) {
            $errors[] = "One or more selected courts are no longer available.";
        }
    }

    if (empty($errors)) {
        $start_sql = normalizeTime($start_time);
        $end_sql = normalizeTime($end_time);

        $overlap_stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM booking_order_items boi
             JOIN booking_orders bo ON bo.booking_order_id = boi.booking_order_id
             WHERE boi.court_id = ?
               AND boi.booking_date = ?
               AND bo.payment_status = 'paid'
               AND boi.start_time < ?
               AND boi.end_time > ?"
        );

        foreach ($court_ids as $court_id) {
            $overlap_stmt->bind_param("isss", $court_id, $booking_date, $end_sql, $start_sql);
            $overlap_stmt->execute();
            $total = (int)$overlap_stmt->get_result()->fetch_assoc()['total'];
            if ($total > 0) {
                $errors[] = h($valid_courts[$court_id]['court_number']) . " has just been booked by someone else.";
            }
        }
        $overlap_stmt->close();
    }

    if (empty($errors)) {
        $duration = bookingDurationHours($start_time, $end_time);
        $item_price = (float)$selected_sport['price_per_hour'] * $duration;
        $total_amount = $item_price * count($court_ids);
        $transaction_ref = makeTransactionRef($payment_method === 'tng_ewallet' ? 'TNG' : 'CARD');

        $conn->begin_transaction();
        try {
            $user_id = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare(
                "INSERT INTO booking_orders (user_id, total_amount, payment_method, payment_status, transaction_ref)
                 VALUES (?, ?, ?, 'paid', ?)"
            );
            $stmt->bind_param("idss", $user_id, $total_amount, $payment_method, $transaction_ref);
            $stmt->execute();
            $booking_order_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare(
                "INSERT INTO booking_order_items
                 (booking_order_id, court_id, booking_date, start_time, end_time, price)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($court_ids as $court_id) {
                $item_stmt->bind_param("iisssd", $booking_order_id, $court_id, $booking_date, $start_sql, $end_sql, $item_price);
                $item_stmt->execute();
            }
            $item_stmt->close();

            $conn->commit();
            header("Location: " . app_url('/booking/history.php?booking_success=' . $booking_order_id));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = "Reservation could not be completed. Please try another slot.";
        }
    }
}

$availability_errors = validateBookingWindow($booking_date, $start_time, $end_time);
$available_courts = [];
if (empty($availability_errors)) {
    $start_sql = normalizeTime($start_time);
    $end_sql = normalizeTime($end_time);
    $stmt = $conn->prepare(
        "SELECT c.court_id, c.court_number
         FROM courts c
         WHERE c.sport_type_id = ?
           AND c.status = 'active'
           AND NOT EXISTS (
               SELECT 1
               FROM booking_order_items boi
               JOIN booking_orders bo ON bo.booking_order_id = boi.booking_order_id
               WHERE boi.court_id = c.court_id
                 AND boi.booking_date = ?
                 AND bo.payment_status = 'paid'
                 AND boi.start_time < ?
                 AND boi.end_time > ?
           )
         ORDER BY c.court_id"
    );
    $stmt->bind_param("isss", $sport_type_id, $booking_date, $end_sql, $start_sql);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_courts[] = $row;
    }
    $stmt->close();
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Book a Court</h1>
    <p class="muted">Choose any time between 8:00 AM and 11:00 PM. Minimum booking length is 1 hour.</p>
</section>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($availability_errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($availability_errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card">
    <form method="GET" action="<?= h(app_url('/booking/search.php')) ?>" class="form-grid">
        <div class="form-group">
            <label for="sport">Sport</label>
            <select id="sport" name="sport">
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= (int)$sport['sport_type_id'] ?>" <?= (int)$sport['sport_type_id'] === $sport_type_id ? 'selected' : '' ?>>
                        <?= h($sport['name']) ?> - <?= money($sport['price_per_hour']) ?>/hour
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="booking_date">Date</label>
            <input type="date" id="booking_date" name="booking_date" value="<?= h($booking_date) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="start_time">Start Time</label>
            <input type="time" id="start_time" name="start_time" value="<?= h($start_time) ?>" min="08:00" max="22:00" step="1800" required>
        </div>
        <div class="form-group">
            <label for="end_time">End Time</label>
            <input type="time" id="end_time" name="end_time" value="<?= h($end_time) ?>" min="09:00" max="23:00" step="1800" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Check Availability</button>
        </div>
    </form>
</section>

<?php if (empty($availability_errors)): ?>
    <section class="card">
        <h2>Available Courts</h2>
        <p class="muted">
            <?= h($selected_sport['name']) ?> on <?= h($booking_date) ?>,
            <?= h($start_time) ?> to <?= h($end_time) ?>.
            Price per selected court: <?= money((float)$selected_sport['price_per_hour'] * bookingDurationHours($start_time, $end_time)) ?>.
        </p>

        <?php if (empty($available_courts)): ?>
            <div class="empty-state">No courts are available for this slot. Try another time.</div>
        <?php else: ?>
            <form method="POST" action="<?= h(app_url('/booking/search.php')) ?>">
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="sport" value="<?= (int)$sport_type_id ?>">
                <input type="hidden" name="booking_date" value="<?= h($booking_date) ?>">
                <input type="hidden" name="start_time" value="<?= h($start_time) ?>">
                <input type="hidden" name="end_time" value="<?= h($end_time) ?>">

                <div class="selection-grid">
                    <?php foreach ($available_courts as $court): ?>
                        <label class="select-card">
                            <input type="checkbox" name="court_ids[]" value="<?= (int)$court['court_id'] ?>">
                            <span><?= h($court['court_number']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="form-group" style="margin-top: 18px;">
                    <label for="payment_method">Simulated Payment Method</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="tng_ewallet">TNG E-Wallet</option>
                        <option value="credit_debit_card">Credit / Debit Card</option>
                    </select>
                </div>

                <button type="submit" class="btn">Pay and Reserve Selected Courts</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
