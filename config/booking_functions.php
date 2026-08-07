<?php
/**
 * booking_functions.php
 * Helpers shared by the court booking pages (booking/search.php and
 * booking/payment.php).
 *
 * These live here instead of in functions.php for the same reason
 * equipment_functions.php exists - the booking module keeps its own logic
 * together, and the shared functions.php included by every page does not keep
 * growing. The slot rules themselves (validateBookingWindow, the operating
 * hours, bookingDurationHours) stay in functions.php because they were already
 * there and are what both modules agree on.
 */

/**
 * The hours a customer can choose from, as 'HH:MM' => '8:00 AM'.
 *
 * Generated from the same BOOKING_OPENS_AT / BOOKING_CLOSES_AT window that
 * validateBookingWindow enforces, so the dropdowns on the page and the server
 * check can never disagree about when the centre is open.
 *
 * The bounds are inclusive and given in minutes past midnight. The start
 * dropdown stops one hour before closing (nothing can start at 11:00 PM and
 * still last an hour) and the end dropdown starts one hour after opening.
 */
function bookingHourOptions($from_minutes, $to_minutes) {
    $options = [];
    for ($minutes = $from_minutes; $minutes <= $to_minutes; $minutes += BOOKING_STEP_MINUTES) {
        $value = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        $options[$value] = date('g:i A', strtotime($value));
    }
    return $options;
}

/**
 * The slot the booking page opens on: the next full hour that can still be
 * booked today, as [date, start, end].
 *
 * Without this the page defaulted to 8:00 AM today, which is in the past for
 * anyone visiting after breakfast, so the first thing a customer saw was an
 * error about choosing a later start time. Once the last bookable hour has
 * gone, it rolls over to tomorrow morning instead.
 */
function defaultBookingSlot() {
    $now_minutes = timeToMinutes(date('H:i'));
    $next_hour = (intdiv($now_minutes, 60) + 1) * BOOKING_STEP_MINUTES;

    if ($next_hour < BOOKING_OPENS_AT) {
        $next_hour = BOOKING_OPENS_AT;
    }
    if (($next_hour + BOOKING_STEP_MINUTES) > BOOKING_CLOSES_AT) {
        return [date('Y-m-d', strtotime('+1 day')), '08:00', '09:00'];
    }

    return [
        date('Y-m-d'),
        sprintf('%02d:00', intdiv($next_hour, 60)),
        sprintf('%02d:00', intdiv($next_hour + BOOKING_STEP_MINUTES, 60)),
    ];
}

/**
 * A booking length written out, e.g. "2 hours" or "1 hour".
 * Whole hours print without a pointless trailing ".0".
 */
function bookingDurationLabel($hours) {
    $hours = (float)$hours;
    $number = rtrim(rtrim(number_format($hours, 1), '0'), '.');
    return $number . ' hour' . ($hours == 1 ? '' : 's');
}

/**
 * Every court of one sport that is free for the whole of the requested slot.
 *
 * The NOT EXISTS subquery is the overlap test: an existing booking gets in the
 * way when it starts before our end AND ends after our start. Written that way
 * round, two bookings that merely touch - 10:00-11:00 and 11:00-12:00 - are
 * correctly allowed, while any genuine overlap is not.
 *
 * Only 'paid' bookings block a court. Courts in 'pending_deletion' are left
 * out because the admin has retired them: they still honour the bookings they
 * already have, but must not take new ones.
 */
function getAvailableCourts($conn, $sport_type_id, $booking_date, $start_sql, $end_sql) {
    $courts = [];
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
        $courts[] = $row;
    }
    $stmt->close();
    return $courts;
}

/**
 * Loads the courts the customer ticked, keyed by court_id.
 *
 * Only active courts belonging to the requested sport come back, so a tampered
 * court_id - one from another sport, or a retired court - simply does not
 * appear in the result. The caller compares the count it gets against the
 * count it asked for to notice that something was dropped.
 *
 * One IN (...) query is used rather than a lookup per court, because the
 * customer can tick every court of a sport at once.
 */
function loadBookableCourts($conn, $sport_type_id, $court_ids) {
    $courts = [];
    if (empty($court_ids)) {
        return $courts;
    }

    $placeholders = implode(',', array_fill(0, count($court_ids), '?'));
    $params = array_merge([$sport_type_id], $court_ids);
    $types = str_repeat('i', count($params));

    $stmt = $conn->prepare(
        "SELECT court_id, court_number
         FROM courts
         WHERE sport_type_id = ?
           AND status = 'active'
           AND court_id IN (" . $placeholders . ")
         ORDER BY court_id"
    );
    bindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courts[(int)$row['court_id']] = $row;
    }
    $stmt->close();

    return $courts;
}

/**
 * Of the courts passed in, the ids that someone else has already paid for on
 * this slot.
 *
 * This is checked when the courts are listed AND again at the moment Pay is
 * pressed. Between choosing a court and finishing the payment form, another
 * customer can pay for the same slot, and this booking must not be written on
 * top of theirs.
 */
function findClashingCourtIds($conn, $court_ids, $booking_date, $start_sql, $end_sql) {
    $clashing = [];
    if (empty($court_ids)) {
        return $clashing;
    }

    $placeholders = implode(',', array_fill(0, count($court_ids), '?'));
    $params = array_merge($court_ids, [$booking_date, $end_sql, $start_sql]);
    $types = str_repeat('i', count($court_ids)) . 'sss';

    $stmt = $conn->prepare(
        "SELECT DISTINCT boi.court_id
         FROM booking_order_items boi
         JOIN booking_orders bo ON bo.booking_order_id = boi.booking_order_id
         WHERE boi.court_id IN (" . $placeholders . ")
           AND boi.booking_date = ?
           AND bo.payment_status = 'paid'
           AND boi.start_time < ?
           AND boi.end_time > ?"
    );
    bindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $clashing[] = (int)$row['court_id'];
    }
    $stmt->close();

    return $clashing;
}

/**
 * Re-reads a chosen-but-unpaid booking against the live database and works out
 * what it costs, collecting every reason it could not go ahead.
 *
 * Nothing is taken on trust from the page the customer came from. The sport
 * price is read again from sport_types and the courts again from courts, so an
 * edited hidden field cannot change what is charged, and a court retired or
 * sold since the customer picked it is caught here.
 *
 * The same function runs when the payment page is drawn and again when Pay is
 * pressed, so the summary the customer agrees to and the booking that gets
 * written are always priced by identical code.
 *
 * Returns:
 *   ['errors' => [...], 'sport' => row|null, 'courts' => [court_id => row],
 *    'duration_hours' => float, 'price_per_court' => float, 'total_amount' => float]
 */
function reviewPendingBooking($conn, $pending) {
    $review = [
        'errors' => [],
        'sport' => null,
        'courts' => [],
        'duration_hours' => 0,
        'price_per_court' => 0,
        'total_amount' => 0,
    ];

    $court_ids = $pending['court_ids'] ?? [];
    $booking_date = $pending['booking_date'] ?? '';
    $start_time = $pending['start_time'] ?? '';
    $end_time = $pending['end_time'] ?? '';
    // Pulled into its own variable because bind_param() takes its arguments by
    // reference, and an expression like ($a['b'] ?? 0) has nothing to refer to.
    $sport_type_id = (int)($pending['sport_type_id'] ?? 0);

    $review['errors'] = validateBookingWindow($booking_date, $start_time, $end_time);

    $stmt = $conn->prepare("SELECT sport_type_id, name, price_per_hour FROM sport_types WHERE sport_type_id = ?");
    $stmt->bind_param("i", $sport_type_id);
    $stmt->execute();
    $review['sport'] = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$review['sport']) {
        $review['errors'][] = "Please choose a sport.";
    }
    if (empty($court_ids)) {
        $review['errors'][] = "Please select at least one court.";
    }

    // No point asking the database about courts for a slot that is already
    // invalid, and reviewPendingBooking must not price a booking it rejected.
    if (!empty($review['errors'])) {
        return $review;
    }

    $review['courts'] = loadBookableCourts($conn, (int)$review['sport']['sport_type_id'], $court_ids);
    if (count($review['courts']) !== count($court_ids)) {
        $review['errors'][] = "One or more of the courts you chose is no longer available.";
        return $review;
    }

    $start_sql = normalizeTime($start_time);
    $end_sql = normalizeTime($end_time);
    foreach (findClashingCourtIds($conn, $court_ids, $booking_date, $start_sql, $end_sql) as $court_id) {
        $review['errors'][] = $review['courts'][$court_id]['court_number']
            . " has just been booked by someone else. Please choose another court or time.";
    }
    if (!empty($review['errors'])) {
        return $review;
    }

    $review['duration_hours'] = bookingDurationHours($start_time, $end_time);
    $review['price_per_court'] = (float)$review['sport']['price_per_hour'] * $review['duration_hours'];
    $review['total_amount'] = $review['price_per_court'] * count($review['courts']);

    return $review;
}

/**
 * Writes the booking order and one item per court in a single transaction.
 *
 * All-or-nothing matters here: an order row without its items would show in
 * the customer's history as a paid booking with no courts on it, and items
 * without their order would be unreachable. The tables are InnoDB (see the
 * note at the top of database.sql) which is what makes the ROLLBACK real.
 *
 * booking_order_items also carries UNIQUE (court_id, booking_date, start_time).
 * If two customers somehow pay for the same court and slot at the same instant,
 * the second INSERT is rejected by the database itself and the whole order
 * rolls back - the last line of defence behind the overlap check.
 *
 * Returns the new booking_order_id, or throws for the caller to handle.
 */
function createBookingOrder($conn, $user_id, $court_ids, $booking_date, $start_sql, $end_sql, $price_per_court, $payment_method, $transaction_ref) {
    $total_amount = $price_per_court * count($court_ids);

    $conn->begin_transaction();
    try {
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
            $item_stmt->bind_param("iisssd", $booking_order_id, $court_id, $booking_date, $start_sql, $end_sql, $price_per_court);
            $item_stmt->execute();
        }
        $item_stmt->close();

        $conn->commit();
        return $booking_order_id;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Takes payment for a pending booking and writes it to the database.
 *
 * The booking is priced again here rather than trusting the figure the summary
 * page showed, and the courts are checked again for clashes, because the
 * customer has been away typing their payment details and the slot may have
 * gone in the meantime. takePendingPayment() in payment_functions.php is what
 * calls this, once the payment fields themselves have been checked.
 *
 * Returns ['errors' => [...], 'booking_order_id' => int|null].
 */
function payForPendingBooking($conn, $user_id, $pending, $input) {
    $payment_method = $pending['payment_method'] ?? '';

    $review = reviewPendingBooking($conn, $pending);
    if (!empty($review['errors'])) {
        return ['errors' => $review['errors'], 'booking_order_id' => null];
    }

    try {
        $booking_order_id = createBookingOrder(
            $conn,
            $user_id,
            array_keys($review['courts']),
            $pending['booking_date'],
            normalizeTime($pending['start_time']),
            normalizeTime($pending['end_time']),
            $review['price_per_court'],
            $payment_method,
            makeTransactionRef($payment_method === 'tng_ewallet' ? 'TNG' : 'CARD')
        );

        return ['errors' => [], 'booking_order_id' => $booking_order_id];
    } catch (Throwable $e) {
        // The unique key on (court_id, booking_date, start_time) rejected the
        // insert, so somebody paid for this exact slot a moment ago.
        return [
            'errors' => ["Payment could not be completed because that slot was taken. Please choose another court or time."],
            'booking_order_id' => null,
        ];
    }
}

/**
 * Link back to the booking page with the same slot already filled in, so
 * "change my selection" does not make the customer retype everything.
 */
function bookingSearchUrl($pending) {
    return app_url('/booking/search.php?' . http_build_query([
        'sport' => $pending['sport_type_id'] ?? '',
        'booking_date' => $pending['booking_date'] ?? '',
        'start_time' => $pending['start_time'] ?? '',
        'end_time' => $pending['end_time'] ?? '',
    ]));
}
