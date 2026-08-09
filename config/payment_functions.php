<?php
/**
 * payment_functions.php
 * The simulated payment provider, shared by both things a customer can pay
 * for: a court booking and an equipment order.
 *
 * Court bookings and equipment orders are stored in different tables and are
 * put together on different pages, but from the moment a payment method is
 * chosen the two are identical - the same TNG screen, the same card screen,
 * the same receipt. So the screens under /payment are written once and this
 * file is what lets them serve both.
 *
 * A payment in progress is described by one array in the session:
 *
 *   [ 'type'           => 'booking' | 'equipment',
 *     'payment_method' => 'tng_ewallet' | 'credit_debit_card',
 *     'confirmed'      => true,
 *     ...whatever that type needs to price itself ]
 *
 * Only the type-specific parts differ, and the two functions at the bottom -
 * reviewPendingPayment() and takePendingPayment() - are the only places that
 * care which type it is.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/booking_functions.php';
require_once __DIR__ . '/equipment_functions.php';

const PAYMENT_TYPE_BOOKING = 'booking';
const PAYMENT_TYPE_EQUIPMENT = 'equipment';

/**
 * Where each payment type's order lives, and what the customer calls it.
 *
 * The table and column names here are fixed strings written in this file. They
 * are interpolated into SQL below, which is only safe because of that - every
 * lookup goes through paymentType() first, so a value from a request can never
 * reach the query. Anything unrecognised returns null and the caller stops.
 *
 * number_style is which number the customer is shown for the order:
 *
 *   'sequence'  their own 1, 2, 3, counted across their bookings
 *   'id'        the real database id
 *
 * Bookings are private to the customer, so they get the tidier sequence.
 * Equipment orders are collected in person and read out at the counter, where
 * they have to mean the same thing to the admin, so they get the real id.
 */
function paymentType($type) {
    $types = [
        PAYMENT_TYPE_BOOKING => [
            'table' => 'booking_orders',
            'id_column' => 'booking_order_id',
            'noun' => 'Booking',
            'number_style' => 'sequence',
            'return_url' => '/booking/history.php',
            'return_param' => 'booking_success',
        ],
        PAYMENT_TYPE_EQUIPMENT => [
            'table' => 'equipment_orders',
            'id_column' => 'equipment_order_id',
            'noun' => 'Order',
            'number_style' => 'id',
            'return_url' => '/shop/purchaseHistory.php',
            'return_param' => 'order_success',
        ],
    ];

    return $types[$type] ?? null;
}

/* ---------------------------------------------------------------------
   The payment in progress
   --------------------------------------------------------------------- */

/**
 * The purchase a customer has committed to but not yet paid for.
 *
 * Held in the session rather than written to the database with a "pending"
 * status, because payment_status only has 'paid' and 'failed' on both order
 * tables - the schema deliberately has no half-finished gateway state. Keeping
 * it out of the database also means an abandoned payment screen leaves nothing
 * to clean up, and never holds a court or stock that nobody paid for.
 */
function storePendingPayment($payment) {
    $_SESSION['pending_payment'] = $payment;
}

function getPendingPayment() {
    return $_SESSION['pending_payment'] ?? null;
}

function clearPendingPayment() {
    unset($_SESSION['pending_payment']);
}

/**
 * Where each payment method collects its details.
 *
 * One list, so the two review pages know where to send the customer and each
 * payment screen knows whether it is the one that was actually chosen.
 */
function paymentPageFor($payment_method) {
    $pages = [
        'tng_ewallet' => '/payment/tng.php',
        'credit_debit_card' => '/payment/card.php',
    ];

    return $pages[$payment_method] ?? null;
}

/**
 * The guard every payment screen runs before drawing anything.
 *
 * Sends the customer back to wherever they came from unless there is a payment
 * waiting, this screen is the method they picked for it, and the review page
 * marked it confirmed. That last part is what stops a payment screen being
 * reached by typing its address, skipping the summary the customer is meant to
 * agree to first.
 *
 * Returns the pending payment, or redirects and stops the request.
 */
function requirePendingPayment($payment_method) {
    $pending = getPendingPayment();

    if (!$pending
        || ($pending['payment_method'] ?? '') !== $payment_method
        || empty($pending['confirmed'])) {
        // With a pending payment we know which review page the customer
        // belongs on. With none at all we do not - sending a shopper to the
        // booking page to be told there is no booking waiting would be worse
        // than saying nothing - so the home page is the honest answer.
        header("Location: " . app_url($pending
            ? paymentReviewUrl($pending['type'] ?? '')
            : '/index.php'));
        exit();
    }

    return $pending;
}

/**
 * The page that puts a purchase together and asks how to pay for it. Also
 * where a customer is sent back to when a payment screen will not serve them.
 */
function paymentReviewUrl($type) {
    return $type === PAYMENT_TYPE_EQUIPMENT
        ? '/shop/checkout.php'
        : '/booking/payment.php';
}

/** The provider's receipt for a payment that has just gone through. */
function paymentReceiptUrl($type, $order_id) {
    return '/payment/success.php?type=' . urlencode($type) . '&order=' . (int)$order_id;
}

/** Back to the merchant: the customer's own history of what they just bought. */
function merchantReturnUrl($type, $order_id) {
    $config = paymentType($type);
    if (!$config) {
        return app_url('/index.php');
    }

    return app_url($config['return_url'] . '?' . $config['return_param'] . '=' . (int)$order_id);
}

/* ---------------------------------------------------------------------
   Checking the simulated payment form
   --------------------------------------------------------------------- */

/**
 * Checks the fields on a payment screen.
 *
 * Nothing here is a real payment check - there is no gateway, no bank and no
 * card network behind this site. The only rule is that the customer filled the
 * fields in, so a PIN of "000000" and a card number of "1" both pass. What it
 * must not do is let an empty form through.
 *
 * None of these values are stored. The order row keeps the method and a
 * generated reference and nothing else, so a card number typed on the screen
 * exists for the length of one request and is then gone - which is the only
 * responsible way to treat card details, simulation or not.
 */
function validatePaymentDetails($payment_method, $input) {
    $required_fields = [
        'tng_ewallet' => [
            'tng_phone' => 'TNG e-wallet phone number',
            'tng_pin' => '6-digit PIN',
        ],
        'credit_debit_card' => [
            'card_name' => 'name on the card',
            'card_number' => 'card number',
            'card_expiry' => 'expiry date',
            'card_cvv' => 'CVV',
        ],
    ];

    if (!isset($required_fields[$payment_method])) {
        return ["Please choose a payment method."];
    }

    $errors = [];
    foreach ($required_fields[$payment_method] as $field => $label) {
        if (trim((string)($input[$field] ?? '')) === '') {
            $errors[] = "Please enter your " . $label . ".";
        }
    }

    return $errors;
}

/* ---------------------------------------------------------------------
   The two places that care which type of payment this is
   --------------------------------------------------------------------- */

/**
 * Re-reads a pending payment against the live database and works out what it
 * costs, in a shape the payment screens can show whatever it is for.
 *
 * Nothing is taken on trust from the page the customer came from: prices are
 * read again from sport_types or equipment, so an edited form cannot change
 * what is charged, and anything that has since sold out or been taken is
 * caught here. It runs when a payment screen is drawn AND again when Pay is
 * pressed, because a court or the last of a product can go in between.
 *
 * Returns:
 *   ['errors' => [...], 'amount' => float, 'headline' => string, 'detail' => string]
 */
function reviewPendingPayment($conn, $user_id, $pending) {
    if (($pending['type'] ?? '') === PAYMENT_TYPE_EQUIPMENT) {
        $review = reviewPendingEquipmentOrder($conn, $user_id);

        $names = [];
        foreach ($review['items'] as $item) {
            $names[] = $item['name'];
        }

        return [
            'errors' => $review['errors'],
            'amount' => $review['total_amount'],
            'headline' => $review['item_count'] . ' item' . ($review['item_count'] === 1 ? '' : 's'),
            // Two names is enough to recognise the order without the summary
            // line growing to several lines on a narrow screen.
            'detail' => count($names) > 2
                ? implode(', ', array_slice($names, 0, 2)) . ' and ' . (count($names) - 2) . ' more'
                : implode(', ', $names),
        ];
    }

    $review = reviewPendingBooking($conn, $pending);

    return [
        'errors' => $review['errors'],
        'amount' => $review['total_amount'],
        'headline' => $review['sport']
            ? $review['sport']['name'] . ' - ' . count($review['courts'])
                . ' court' . (count($review['courts']) === 1 ? '' : 's')
            : '',
        'detail' => !empty($pending['booking_date'])
            ? date('d M Y', strtotime($pending['booking_date'])) . ', '
                . date('g:i A', strtotime($pending['start_time'])) . ' - '
                . date('g:i A', strtotime($pending['end_time']))
            : '',
    ];
}

/**
 * Takes the payment and writes the order.
 *
 * Both types re-check themselves and then write inside one transaction, so a
 * failure leaves nothing behind either way - no order without its items, no
 * stock taken from a sale that did not happen, no court held by a booking that
 * was rolled back.
 *
 * Returns ['errors' => [...], 'order_id' => int|null].
 */
function takePendingPayment($conn, $user_id, $pending, $input) {
    $payment_method = $pending['payment_method'] ?? '';

    $errors = validatePaymentDetails($payment_method, $input);
    if (!empty($errors)) {
        return ['errors' => $errors, 'order_id' => null];
    }

    if (($pending['type'] ?? '') === PAYMENT_TYPE_EQUIPMENT) {
        $result = payForEquipmentOrder($conn, $user_id, $payment_method);
        $order_key = 'equipment_order_id';
    } else {
        $result = payForPendingBooking($conn, $user_id, $pending, $input);
        $order_key = 'booking_order_id';
    }

    if (empty($result['errors'])) {
        clearPendingPayment();
    }

    return ['errors' => $result['errors'], 'order_id' => $result[$order_key] ?? null];
}

/* ---------------------------------------------------------------------
   Reading back a payment that has already gone through
   --------------------------------------------------------------------- */

/**
 * One paid order of either type belonging to this customer, or null.
 *
 * The user_id is part of the WHERE clause rather than being checked after the
 * row comes back. The receipt takes its order from the address bar, so without
 * this a customer could read somebody else's receipt - what they paid and when
 * - just by changing the number.
 */
function findPaidOrder($conn, $user_id, $type, $order_id) {
    $config = paymentType($type);
    if (!$config) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT {$config['id_column']} AS order_id, total_amount, payment_method,
                payment_status, transaction_ref, created_at
         FROM {$config['table']}
         WHERE {$config['id_column']} = ? AND user_id = ?"
    );
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $order ?: null;
}

/**
 * The number this customer sees for one of their own orders, e.g. 3.
 *
 * The receipt has to name the same number the history page will show when the
 * customer lands back on it, so this follows whichever number_style that type
 * uses. For an equipment order that is the database id, which the history page
 * prints straight out. For a booking the whole list is numbered with the same
 * orderDisplayNumbers() that page uses and this picks one out - ordering the
 * query the way it does is what keeps the two in step.
 */
function paidOrderDisplayNumber($conn, $user_id, $type, $order_id) {
    $config = paymentType($type);
    if (!$config) {
        return 0;
    }

    if ($config['number_style'] === 'id') {
        return $order_id;
    }

    $orders = [];
    $stmt = $conn->prepare(
        "SELECT {$config['id_column']} AS order_id
         FROM {$config['table']}
         WHERE user_id = ?
         ORDER BY created_at DESC, {$config['id_column']} DESC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $orders[(int)$row['order_id']] = true;
    }
    $stmt->close();

    $numbers = orderDisplayNumbers($orders);
    return $numbers[$order_id] ?? 0;
}
