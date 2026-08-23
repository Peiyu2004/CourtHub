<?php


/**
 * How many units of one variant the user already has sitting in their cart.
 *
 * Without this, the stock check only compares the new quantity against stock,
 * so a customer could add 4 of a 4-stock variant twice and only discover the
 * problem at checkout.
 *
 * It counts one variant rather than the whole product on purpose. Having 4
 * red racquets in the cart says nothing about how many blue ones are left,
 * so counting the product would refuse a sale the shop can actually make.
 */
function cartQuantityForVariant($conn, $user_id, $variant_id) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS total
         FROM cart_items
         WHERE user_id = ? AND variant_id = ?"
    );
    $stmt->bind_param("ii", $user_id, $variant_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}


/**
 * Adds one variant of a product to the cart, or tops up the quantity when
 * that exact combination is already in there.
 *
 * The choices posted from the page are turned into a variant, and it is the
 * variant's own stock that decides whether the sale can be made. Asking the
 * product would be wrong twice over: it would sell a colour that ran out
 * while another one still had stock, and it would refuse a colour that is on
 * the shelf because a different one is empty.
 *
 * Returns an array of error messages; an empty array means it worked.
 */
function addToCart($conn, $user_id, $equipment_id, $quantity, $selected_options) {
    $errors = [];

    $stmt = $conn->prepare(
        "SELECT equipment_id, name, status FROM equipment WHERE equipment_id = ?"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        return ["The selected item could not be found."];
    }
    if ($product['status'] !== 'active') {
        return [$product['name'] . " is no longer available."];
    }

    // Every option group the product defines must get a valid choice, and the
    // choice has to be one of the values stored for that product. Trusting the
    // posted value would let someone invent a variant that is not for sale.
    $option_groups = getEquipmentOptionGroups($conn, $equipment_id);
    $clean_options = [];
    foreach ($option_groups as $option_name => $values) {
        $chosen = $selected_options[$option_name] ?? '';
        if (!in_array($chosen, $values, true)) {
            $errors[] = "Please choose a valid " . $option_name . " for " . $product['name'] . ".";
        } else {
            $clean_options[$option_name] = $chosen;
        }
    }

    if (!empty($errors)) {
        return $errors;
    }

    // Every choice is valid on its own, so the combination they add up to has
    // to exist as a variant. It normally will; it can be missing if the admin
    // stopped offering that combination between the page loading and the form
    // being sent.
    $variant = findVariantByOptions($conn, $equipment_id, $clean_options);
    if (!$variant) {
        return ["That combination of " . $product['name'] . " is no longer available."];
    }

    $variant_id = (int)$variant['variant_id'];
    $stock      = (int)$variant['stock'];
    // What the messages below call the item. Products with no options keep
    // their plain name rather than gaining a pointless "(Standard)".
    $item_name = $variant['variant_key'] === ''
        ? $product['name']
        : $product['name'] . " (" . variantLabel($variant['variant_key']) . ")";

    $already_in_cart = cartQuantityForVariant($conn, $user_id, $variant_id);

    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1.";
    } elseif ($stock <= 0) {
        // Checked before the cart maths, otherwise a variant with no stock at
        // all would be reported as "you already have all of it in your cart"
        // even when the cart is empty.
        $errors[] = $item_name . " is out of stock.";
    } elseif (($already_in_cart + $quantity) > $stock) {
        $remaining = $stock - $already_in_cart;
        $errors[] = $remaining > 0
            ? "Only " . $remaining . " more of " . $item_name . " can be added; you already have " . $already_in_cart . " in your cart."
            : "You already have all " . $stock . " available of " . $item_name . " in your cart.";
    }

    if (!empty($errors)) {
        return $errors;
    }

    /*
     * Insert the line, or add to it when it is already there.
     *
     * UNIQUE (user_id, variant_id) on cart_items is what makes this one
     * statement: the same combination cannot occupy two rows, so MySQL knows
     * a clash means "top this line up". Before cart lines pointed at variants
     * this needed a SELECT, a JSON decode of every row in PHP and then a
     * choice between UPDATE and INSERT, because the stored JSON text never
     * matched what json_encode() produced.
     */
    $stmt = $conn->prepare(
        "INSERT INTO cart_items (user_id, variant_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + ?"
    );
    $stmt->bind_param("iiii", $user_id, $variant_id, $quantity, $quantity);
    $stmt->execute();
    $stmt->close();

    return [];
}


/**
 * Everything sitting in this customer's cart, with the product details each
 * line needs to be priced and displayed.
 *
 * The price comes from the equipment table rather than being remembered when
 * the item was added, so a cart left open overnight is charged at today's
 * price and never at a stale one.
 */
function getCartItems($conn, $user_id) {
    $items = [];
    $stmt = $conn->prepare(
        "SELECT ci.cart_id, ci.quantity,
                v.variant_id, v.variant_key, v.stock,
                e.equipment_id, e.name, e.price, e.status
         FROM cart_items ci
         JOIN equipment_variants v ON ci.variant_id = v.variant_id
         JOIN equipment e          ON v.equipment_id = e.equipment_id
         WHERE ci.user_id = ?
         ORDER BY ci.added_at"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['line_total'] = (float)$row['price'] * (int)$row['quantity'];
        $row['options']    = decodeVariantKey($row['variant_key']);
        $row['label']      = variantLabel($row['variant_key']);
        // The choices in the shape the order line will snapshot them in. The
        // cart itself has no need of this - the variant already says what was
        // chosen - but equipment_order_items keeps its own copy so a receipt
        // still reads correctly after the variant is retired.
        $row['selected_options'] = json_encode($row['options']);
        $items[] = $row;
    }
    $stmt->close();

    return $items;
}


/**
 * Why one cart line cannot be paid for, or '' when there is nothing wrong.
 *
 * The cart page and the checkout page both have to answer this, and they used
 * to answer it separately - which is how a cart could look fine and then be
 * refused a screen later, in different words. One function, called by both,
 * means the sentence the shopper reads on the cart is the same sentence that
 * would stop the payment.
 *
 * $item is a row from getCartItems(), so it carries the variant's own stock
 * rather than the product's. That is the whole point: a racquet with twenty
 * in the building is still unbuyable if the colour in this cart is at zero.
 */
function cartLineProblem($item) {
    $item_name = ($item['variant_key'] ?? '') === ''
        ? $item['name']
        : $item['name'] . " (" . variantLabel($item['variant_key']) . ")";

    if (($item['status'] ?? 'active') !== 'active') {
        return $item_name . " is no longer available.";
    }

    $stock    = (int)$item['stock'];
    $quantity = (int)$item['quantity'];

    // Nothing left at all reads better as its own sentence. "only has 0 left
    // in stock, but your cart has 2" is technically true and horrible.
    if ($stock <= 0) {
        return $item_name . " is out of stock.";
    }
    if ($quantity > $stock) {
        return $item_name . " only has " . $stock . " left in stock, but your cart has "
             . $quantity . ".";
    }

    return '';
}


/**
 * Prices the cart and collects every reason it could not be paid for.
 *
 * The equivalent of reviewPendingBooking() for the shop, and it runs at the
 * same two moments: when a payment screen is drawn, and again when Pay is
 * pressed. Stock is the thing that moves in between - somebody else can buy
 * the last racquet while this customer is typing their card number - so the
 * check cannot only happen once.
 *
 * Returns:
 *   ['errors' => [...], 'items' => [...], 'item_count' => int, 'total_amount' => float]
 */
function reviewPendingEquipmentOrder($conn, $user_id) {
    $review = ['errors' => [], 'items' => [], 'item_count' => 0, 'total_amount' => 0];

    $review['items'] = getCartItems($conn, $user_id);

    if (empty($review['items'])) {
        $review['errors'][] = "Your cart is empty.";
        return $review;
    }

    foreach ($review['items'] as $item) {
        // Each line is named with its combination, because "only 2 left" is
        // confusing on a product the customer can see plenty of in other
        // colours. cartLineProblem() is what the cart page shows too, so the
        // two screens cannot word the same refusal differently.
        $problem = cartLineProblem($item);
        if ($problem !== '') {
            $review['errors'][] = $problem;
        }
        $review['item_count'] += (int)$item['quantity'];
        $review['total_amount'] += $item['line_total'];
    }

    return $review;
}


/**
 * Takes payment for the cart: writes the order and its lines, removes the
 * stock, and empties the cart, all in one transaction.
 *
 * The stock comes off the variant, not the product, so buying the last red
 * racquet leaves the blue ones untouched.
 *
 * The stock UPDATE carries its own "AND stock >= ?" and the affected-rows
 * check is what enforces it. Reading the stock and then subtracting it would
 * leave a gap in which two customers could both pass the check and buy the
 * same last item; letting the database do both in one statement closes it.
 * A row that fails throws, and the whole order rolls back.
 *
 * Returns ['errors' => [...], 'equipment_order_id' => int|null].
 */
function payForEquipmentOrder($conn, $user_id, $payment_method) {
    $review = reviewPendingEquipmentOrder($conn, $user_id);
    if (!empty($review['errors'])) {
        return ['errors' => $review['errors'], 'equipment_order_id' => null];
    }

    $total_amount = $review['total_amount'];
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
             (equipment_order_id, equipment_id, variant_id, quantity, price_at_purchase, selected_options)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stock_stmt = $conn->prepare(
            "UPDATE equipment_variants
             SET stock = stock - ?
             WHERE variant_id = ? AND stock >= ?"
        );

        foreach ($review['items'] as $item) {
            $equipment_id = (int)$item['equipment_id'];
            $variant_id = (int)$item['variant_id'];
            $quantity = (int)$item['quantity'];
            $price = (float)$item['price'];
            $selected_options = $item['selected_options'];

            $stock_stmt->bind_param("iii", $quantity, $variant_id, $quantity);
            $stock_stmt->execute();
            if ($stock_stmt->affected_rows !== 1) {
                throw new RuntimeException("Stock changed during checkout.");
            }

            $item_stmt->bind_param(
                "iiiids",
                $equipment_order_id, $equipment_id, $variant_id, $quantity, $price, $selected_options
            );
            $item_stmt->execute();
        }

        $item_stmt->close();
        $stock_stmt->close();

        $stmt = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return ['errors' => [], 'equipment_order_id' => $equipment_order_id];
    } catch (Throwable $e) {
        $conn->rollback();
        return [
            'errors' => ["Payment could not be completed because the stock changed. Please review your cart."],
            'equipment_order_id' => null,
        ];
    }
}


/**
 * Every equipment order this customer has paid for, newest first, with the
 * lines that belong to each order already nested underneath it:
 *   [order_id => ['total_amount' => ..., 'items' => [line, line, ...]], ...]
 *
 * One JOINed query is used instead of "fetch the orders, then fetch the items
 * for each order", because that second shape runs an extra query for every
 * order on the page. The rows come back flat - the same order header repeated
 * once per line - so they are grouped by equipment_order_id here in PHP.
 *
 * The join onto equipment is a LEFT JOIN on purpose. equipment_order_items
 * stores equipment_id with ON DELETE SET NULL, so a product removed after the
 * sale leaves its order line behind with nothing attached. An inner join would
 * quietly drop those lines and the customer's own receipt would stop adding up
 * to the total they were charged.
 */
function getEquipmentPurchaseHistory($conn, $user_id) {
    $orders = [];

    $stmt = $conn->prepare(
        "SELECT eo.equipment_order_id, eo.total_amount, eo.payment_method,
                eo.payment_status, eo.order_status, eo.collected_at,
                eo.transaction_ref, eo.created_at,
                oi.order_item_id, oi.equipment_id, oi.quantity,
                oi.price_at_purchase, oi.selected_options,
                e.name AS equipment_name, e.brand, e.category, e.status AS equipment_status,
                st.name AS sport_name
         FROM equipment_orders eo
         JOIN equipment_order_items oi ON eo.equipment_order_id = oi.equipment_order_id
         LEFT JOIN equipment e ON oi.equipment_id = e.equipment_id
         LEFT JOIN sport_types st ON e.sport_type_id = st.sport_type_id
         WHERE eo.user_id = ?
         ORDER BY eo.created_at DESC, eo.equipment_order_id DESC, oi.order_item_id ASC"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $order_id = (int)$row['equipment_order_id'];
        if (!isset($orders[$order_id])) {
            $orders[$order_id] = [
                'equipment_order_id' => $order_id,
                'total_amount' => $row['total_amount'],
                'payment_method' => $row['payment_method'],
                'payment_status' => $row['payment_status'],
                'order_status' => $row['order_status'],
                'collected_at' => $row['collected_at'],
                'transaction_ref' => $row['transaction_ref'],
                'created_at' => $row['created_at'],
                'items' => [],
            ];
        }
        $row['line_total'] = (float)$row['price_at_purchase'] * (int)$row['quantity'];
        $orders[$order_id]['items'][] = $row;
    }
    $stmt->close();

    return $orders;
}

/* ---------------------------------------------------------------------
   Collection at the counter
   ---------------------------------------------------------------------
   Equipment is never delivered. Paying only reserves the goods; the sale
   is not finished until the customer walks in and takes them, which is
   what equipment_orders.order_status records and what admin/orders.php
   is there to change.
   --------------------------------------------------------------------- */


/** What each order_status is called on screen, for customers and admin alike. */
function equipmentOrderStatusLabel($status) {
    $labels = [
        'pending'   => 'Waiting for Collection',
        'completed' => 'Collected',
    ];

    return $labels[$status] ?? $status;
}


/**
 * Every equipment order in the shop, newest first, with its items nested
 * underneath and the customer it belongs to - the admin's view of the same
 * data getEquipmentPurchaseHistory() gives one customer.
 *
 * $status filters the list: 'pending', 'completed', or 'all'. Anything else is
 * read as 'all', so a tampered querystring widens the list rather than
 * breaking the page. The value never reaches the SQL as text - it only decides
 * whether the WHERE clause is added, and it is bound when it is.
 *
 * Only paid orders are listed. A failed payment took no money and reserved no
 * stock, so there is nothing at the counter to hand over.
 */
function getEquipmentOrdersForAdmin($conn, $status = 'all') {
    $where = "eo.payment_status = 'paid'";
    $types = '';
    $params = [];

    if ($status === 'pending' || $status === 'completed') {
        $where .= " AND eo.order_status = ?";
        $types = 's';
        $params[] = $status;
    }

    return fetchAdminEquipmentOrders($conn, $where, $types, $params);
}


/**
 * One order by its number, in the same shape as the list above, or an empty
 * array when there is no paid order with that number.
 *
 * Deliberately not filtered by order_status: the admin searching for #12 wants
 * that order whichever tab they happen to be looking at, and "not found" for
 * an order that exists but has already been collected would send them looking
 * for a mistake that is not there.
 */
function findEquipmentOrderForAdmin($conn, $order_id) {
    return fetchAdminEquipmentOrders(
        $conn,
        "eo.payment_status = 'paid' AND eo.equipment_order_id = ?",
        'i',
        [$order_id]
    );
}


/**
 * The query behind both of the two functions above.
 *
 * $where is written by this file and never built from a request - the callers
 * decide which fixed clause to use and pass every value from the outside in
 * $params, so a search term reaches the database as a bound parameter only.
 */
function fetchAdminEquipmentOrders($conn, $where, $types, $params) {
    $orders = [];

    $stmt = $conn->prepare(
        "SELECT eo.equipment_order_id, eo.total_amount, eo.payment_method,
                eo.payment_status, eo.order_status, eo.collected_at,
                eo.transaction_ref, eo.created_at,
                u.full_name, u.email, u.phone,
                oi.order_item_id, oi.equipment_id, oi.quantity,
                oi.price_at_purchase, oi.selected_options,
                e.name AS equipment_name, e.brand, e.category,
                st.name AS sport_name
         FROM equipment_orders eo
         JOIN users u ON eo.user_id = u.user_id
         JOIN equipment_order_items oi ON eo.equipment_order_id = oi.equipment_order_id
         LEFT JOIN equipment e ON oi.equipment_id = e.equipment_id
         LEFT JOIN sport_types st ON e.sport_type_id = st.sport_type_id
         WHERE {$where}
         ORDER BY eo.created_at DESC, eo.equipment_order_id DESC, oi.order_item_id ASC"
    );

    bindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    // Flat rows in, one entry per order out - the same grouping the customer's
    // own history does, because one JOINed query beats a query per order.
    while ($row = $result->fetch_assoc()) {
        $order_id = (int)$row['equipment_order_id'];
        if (!isset($orders[$order_id])) {
            $orders[$order_id] = [
                'equipment_order_id' => $order_id,
                'total_amount' => $row['total_amount'],
                'payment_method' => $row['payment_method'],
                'order_status' => $row['order_status'],
                'collected_at' => $row['collected_at'],
                'transaction_ref' => $row['transaction_ref'],
                'created_at' => $row['created_at'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'item_count' => 0,
                'items' => [],
            ];
        }
        $row['line_total'] = (float)$row['price_at_purchase'] * (int)$row['quantity'];
        $orders[$order_id]['item_count'] += (int)$row['quantity'];
        $orders[$order_id]['items'][] = $row;
    }
    $stmt->close();

    return $orders;
}


/**
 * How many paid orders are waiting for collection and how many have been
 * collected, for the counts on the filter tabs and the dashboard tile.
 */
function equipmentOrderStatusCounts($conn) {
    $counts = ['pending' => 0, 'completed' => 0, 'all' => 0];

    $result = $conn->query(
        "SELECT order_status, COUNT(*) AS total
         FROM equipment_orders
         WHERE payment_status = 'paid'
         GROUP BY order_status"
    );
    while ($row = $result->fetch_assoc()) {
        $counts[$row['order_status']] = (int)$row['total'];
        $counts['all'] += (int)$row['total'];
    }
    $result->close();

    return $counts;
}


/**
 * Marks one paid order as collected, and stamps the time it happened.
 *
 * "AND order_status = 'pending'" is what makes this safe to send twice. Two
 * admins on the counter, or one double-click, would otherwise push
 * collected_at forward and make the record say the goods were handed over
 * later than they were. The second update matches no row instead, and
 * affected_rows tells the caller which of the two happened.
 *
 * Returns an error string, or '' when the order was marked collected.
 */
function markEquipmentOrderCollected($conn, $order_id) {
    $stmt = $conn->prepare(
        "UPDATE equipment_orders
         SET order_status = 'completed', collected_at = NOW()
         WHERE equipment_order_id = ? AND order_status = 'pending' AND payment_status = 'paid'"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed === 1) {
        return '';
    }

    // Nothing changed, so say which of the two reasons it was rather than
    // reporting a failure for an order that is simply already done.
    $stmt = $conn->prepare(
        "SELECT order_status FROM equipment_orders WHERE equipment_order_id = ?"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$found) {
        return "That order could not be found.";
    }

    return "Order #" . (int)$order_id . " has already been collected.";
}


/**
 * Order count, item count and lifetime spend across a purchase history.
 *
 * Worked out from the rows the page already holds rather than with a second
 * SUM()/COUNT() query. Only 'paid' orders count towards the spend, so a failed
 * payment still appears in the list as a record but never inflates the total.
 */
function purchaseHistorySummary($orders) {
    $summary = ['orders' => count($orders), 'items' => 0, 'total_spent' => 0];

    foreach ($orders as $order) {
        foreach ($order['items'] as $item) {
            $summary['items'] += (int)$item['quantity'];
        }
        if ($order['payment_status'] === 'paid') {
            $summary['total_spent'] += (float)$order['total_amount'];
        }
    }

    return $summary;
}


/**
 * Turns the JSON in selected_options back into the choices the customer made
 * at the time of purchase, e.g. ['Grip Size' => 'G4'].
 *
 * Returns an empty array for a product that has no variants, and also for a
 * value that will not decode, so a page can always foreach over the result
 * without checking it first.
 */
function decodeSelectedOptions($selected_options) {
    $decoded = json_decode($selected_options ?? '', true);
    return is_array($decoded) ? $decoded : [];
}
