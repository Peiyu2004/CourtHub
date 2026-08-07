<?php
/**
 * functions.php
 * Small reusable helper functions. Included on every page (via header.php)
 * so we don't repeat the same session-checking code everywhere.
 */

// Start the session once, here, so every page has access to $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kuala_Lumpur');

/**
 * Returns true if someone is currently logged in (customer or admin).
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Returns true if the logged-in user's role is 'admin'.
 */
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

/**
 * Call this at the top of any page that REQUIRES a logged-in user.
 * If nobody is logged in, bounce them to the login page.
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . app_url('/auth/login.php'));
        exit();
    }
}

/**
 * Call this at the top of any page that is admin-only.
 * Non-admins (including logged-out visitors) get redirected away.
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: " . app_url('/index.php'));
        exit();
    }
}

/**
 * Escapes a string for safe HTML output (prevents basic XSS when we
 * echo user-submitted data like name, search terms, etc. back onto a page).
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function appBasePath() {
    static $base_path = null;
    if ($base_path !== null) {
        return $base_path;
    }

    $document_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $app_root = realpath(__DIR__ . '/..');
    if ($document_root && $app_root && substr(strtolower($app_root), 0, strlen(strtolower($document_root))) === strtolower($document_root)) {
        $relative = substr($app_root, strlen($document_root));
        $base_path = str_replace('\\', '/', $relative);
        $base_path = $base_path === '/' ? '' : rtrim($base_path, '/');
    } else {
        $base_path = '';
    }

    return $base_path;
}

function app_url($path = '') {
    $path = '/' . ltrim($path, '/');
    return appBasePath() . ($path === '/' ? '' : $path);
}

function money($amount) {
    return 'RM' . number_format((float)$amount, 2);
}

function getSportTypes($conn) {
    $sports = [];
    $result = $conn->query("SELECT sport_type_id, name, price_per_hour FROM sport_types ORDER BY sport_type_id");
    while ($row = $result->fetch_assoc()) {
        $sports[] = $row;
    }
    return $sports;
}

function timeToMinutes($time) {
    $parts = explode(':', $time);
    if (count($parts) < 2) {
        return null;
    }
    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function normalizeTime($time) {
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    return $time . ':00';
}

/**
 * The operating window, as minutes past midnight: 8:00 AM to 11:00 PM.
 * Named constants rather than the bare numbers so the booking page, the
 * payment page and this check all read from one place and cannot drift apart.
 */
const BOOKING_OPENS_AT = 480;    // 08:00
const BOOKING_CLOSES_AT = 1380;  // 23:00

/** Courts are booked in whole hours, which is also the shortest booking. */
const BOOKING_STEP_MINUTES = 60;

/**
 * The one place that decides whether a requested slot is legal.
 *
 * Both the booking page and the payment page call this - the payment page
 * calls it again at the moment Pay is pressed, because a slot that was fine
 * when the page loaded can have drifted into the past by the time the customer
 * finishes typing their card details.
 *
 * Returns an array of messages; an empty array means the slot is acceptable.
 */
function validateBookingWindow($booking_date, $start_time, $end_time) {
    $errors = [];
    $today = date('Y-m-d');
    $start_minutes = timeToMinutes($start_time);
    $end_minutes = timeToMinutes($end_time);

    if (!$booking_date || $booking_date < $today) {
        $errors[] = "Please choose today or a future date.";
    }
    if ($start_minutes === null || $end_minutes === null) {
        $errors[] = "Please choose a valid start and end time.";
        return $errors;
    }
    // Bookings run in whole hours, so 14:00 is allowed and 14:30 is not. The
    // dropdowns on the booking page only ever offer whole hours, but a posted
    // form can carry anything, so the rule is enforced here as well.
    if ($start_minutes % BOOKING_STEP_MINUTES !== 0 || $end_minutes % BOOKING_STEP_MINUTES !== 0) {
        $errors[] = "Bookings run in 1 hour steps, so please choose times on the hour.";
    }
    if ($start_minutes < BOOKING_OPENS_AT || $end_minutes > BOOKING_CLOSES_AT) {
        $errors[] = "Bookings must be within operation hours, 8:00 AM to 11:00 PM.";
    }
    // "else if" so equal times report only that the end is not after the
    // start, instead of also complaining about the 1 hour minimum.
    if ($end_minutes <= $start_minutes) {
        $errors[] = "End time must be later than start time.";
    } elseif (($end_minutes - $start_minutes) < BOOKING_STEP_MINUTES) {
        $errors[] = "Each booking must be at least 1 hour.";
    }
    if ($booking_date === $today && $start_minutes <= timeToMinutes(date('H:i'))) {
        $errors[] = "Please choose a start time later than the current time.";
    }

    return $errors;
}

function bookingDurationHours($start_time, $end_time) {
    return (timeToMinutes($end_time) - timeToMinutes($start_time)) / 60;
}

function makeTransactionRef($prefix) {
    return $prefix . '-' . date('Ymd-His') . '-' . random_int(1000, 9999);
}

function paymentMethodLabel($method) {
    if ($method === 'tng_ewallet') {
        return 'TNG E-Wallet';
    }
    if ($method === 'credit_debit_card') {
        return 'Credit / Debit Card';
    }
    return $method;
}

/**
 * The numbers a customer sees next to their own orders, keyed by database id.
 *
 * The database id is deliberately not shown to customers. Ids are handed out
 * across every customer of the site, so one person's first two bookings can
 * easily be #7 and #12, which reads as though ten of their bookings went
 * missing. Counting their own list instead gives them 1, 2, 3 with no gaps.
 *
 * The list arrives newest first, which is how both history pages want to show
 * it, so the count is walked from the other end: the customer's oldest order
 * is #1 and stays #1 forever, and each new order takes the next number up.
 * Numbering the other way round would mean today's "Booking #1" became
 * "Booking #2" as soon as they booked again - the same confusion in a new
 * shape.
 *
 * $orders must be keyed by database id, which is how both pages build their
 * lists, so any order's number can be looked up by its id - that is what lets
 * the "payment successful" message name the same number as the card below it.
 */
function orderDisplayNumbers($orders) {
    $numbers = [];
    $number = 0;

    // array_reverse with preserve_keys walks oldest first without disturbing
    // the caller's newest-first array.
    foreach (array_reverse($orders, true) as $order_id => $order) {
        $number++;
        $numbers[$order_id] = $number;
    }

    return $numbers;
}

/**
 * Prints the tab strip that links the two halves of a customer's history.
 *
 * Court bookings and equipment purchases are stored in separate tables and
 * belong to separate modules, so they stay on separate pages. This strip is
 * what makes them read as one "my history" area instead of two unrelated
 * screens the user has to find on their own.
 *
 * It lives in functions.php rather than in either module's own helper file
 * because both pages include this one, and neither module should have to
 * depend on the other just to draw a link.
 *
 * $active is 'bookings' or 'purchases' and only decides which tab is
 * highlighted as the current page.
 */
function renderHistoryTabs($active) {
    $tabs = [
        'bookings'  => ['label' => 'Court Bookings',      'url' => app_url('/booking/history.php')],
        'purchases' => ['label' => 'Equipment Purchases', 'url' => app_url('/shop/purchaseHistory.php')],
    ];
    ?>
    <nav class="history-tabs">
        <?php foreach ($tabs as $key => $tab): ?>
            <a href="<?= h($tab['url']) ?>"
               class="history-tab<?= $key === $active ? ' is-active' : '' ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>><?= h($tab['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function bindParams($stmt, $types, $params) {
    if ($types === '') {
        return;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function finalizePendingCourtDeletions($conn) {
    $conn->query(
        "UPDATE courts
         SET status = 'deleted'
         WHERE status = 'pending_deletion'
           AND court_id NOT IN (
               SELECT court_id FROM booking_order_items
               WHERE booking_date > CURDATE()
                  OR (booking_date = CURDATE() AND end_time > CURTIME())
           )"
    );
}

function courtHasCurrentOrFutureBookings($conn, $court_id) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM booking_order_items
         WHERE court_id = ?
           AND (booking_date > CURDATE()
                OR (booking_date = CURDATE() AND end_time > CURTIME()))"
    );
    $stmt->bind_param("i", $court_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total > 0;
}
