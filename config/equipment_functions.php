<?php
/**
 * equipment_functions.php
 * Helpers shared by the equipment store pages and the admin equipment pages.
 *
 * These live here instead of in functions.php so the equipment module keeps
 * its own logic together, and so the shared functions.php used by the booking
 * and account pages does not keep growing.
 */

/** Longest and shortest review comment we accept. */
const REVIEW_COMMENT_MIN = 10;
const REVIEW_COMMENT_MAX = 1000;

/**
 * Every category, alphabetically, with a count of how many products use it.
 * The count is what the admin page uses to decide whether a category is safe
 * to delete.
 */
function getCategories($conn) {
    $categories = [];
    $result = $conn->query(
        "SELECT c.category_id, c.name, c.description,
                COUNT(e.equipment_id) AS product_count
         FROM categories c
         LEFT JOIN equipment e ON e.category_id = c.category_id
         GROUP BY c.category_id, c.name, c.description
         ORDER BY c.name"
    );
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $result->close();
    return $categories;
}

/**
 * Looks up one category by its id. Returns null when it does not exist, which
 * is how the admin page rejects a tampered category_id from a form post.
 */
function findCategory($conn, $category_id) {
    $stmt = $conn->prepare("SELECT category_id, name, description FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $category ?: null;
}

/**
 * The variant choices for one product, grouped by option name so the page can
 * build one dropdown per group:
 *   ['Grip Size' => ['G4','G5'], 'Grip Color' => ['Red','Blue']]
 */
function getEquipmentOptionGroups($conn, $equipment_id) {
    $groups = [];
    $stmt = $conn->prepare(
        "SELECT option_name, option_value
         FROM equipment_options
         WHERE equipment_id = ?
         ORDER BY option_name, option_value"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groups[$row['option_name']][] = $row['option_value'];
    }
    $stmt->close();
    return $groups;
}

/**
 * The variant choices for a whole list of products in one query.
 *
 * The listing page shows up to fourteen products at once, so calling
 * getEquipmentOptionGroups() per product would mean fourteen round trips to
 * the database. This fetches them all at once and groups the rows in PHP:
 *   [equipment_id => ['Grip Size' => ['G4','G5'], ...], ...]
 *
 * One "?" is generated per id so every value stays bound.
 */
function getOptionGroupsForMany($conn, $equipment_ids) {
    $groups = [];
    if (empty($equipment_ids)) {
        return $groups;
    }

    $placeholders = implode(',', array_fill(0, count($equipment_ids), '?'));
    $types = str_repeat('i', count($equipment_ids));

    $stmt = $conn->prepare(
        "SELECT equipment_id, option_name, option_value
         FROM equipment_options
         WHERE equipment_id IN (" . $placeholders . ")
         ORDER BY equipment_id, option_name, option_value"
    );
    bindParams($stmt, $types, $equipment_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groups[(int)$row['equipment_id']][$row['option_name']][] = $row['option_value'];
    }
    $stmt->close();

    return $groups;
}

/**
 * All reviews for one product, newest first.
 * The reviews table only stores user_id, so the JOIN on users is what turns
 * the row into a name the customer can actually read.
 */
function getEquipmentReviews($conn, $equipment_id) {
    $reviews = [];
    $stmt = $conn->prepare(
        "SELECT r.review_id, r.user_id, r.rating, r.comment, r.created_at,
                u.full_name
         FROM equipment_reviews r
         JOIN users u ON r.user_id = u.user_id
         WHERE r.equipment_id = ?
         ORDER BY r.created_at DESC, r.review_id DESC"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt->close();
    return $reviews;
}

/**
 * Average rating and review count.
 * This is worked out in PHP from the rows already fetched rather than with a
 * second AVG()/GROUP BY query, because the page has the reviews in memory
 * anyway and one trip to the database is cheaper than two.
 */
function reviewSummary($reviews) {
    $count = count($reviews);
    if ($count === 0) {
        return ['count' => 0, 'average' => 0];
    }
    $total = 0;
    foreach ($reviews as $review) {
        $total += (int)$review['rating'];
    }
    return ['count' => $count, 'average' => round($total / $count, 1)];
}

/**
 * How many reviews sit at each star level, used for the 5-bar breakdown.
 * Returns [5 => n, 4 => n, 3 => n, 2 => n, 1 => n].
 */
function ratingBreakdown($reviews) {
    $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($reviews as $review) {
        $rating = (int)$review['rating'];
        if (isset($breakdown[$rating])) {
            $breakdown[$rating]++;
        }
    }
    return $breakdown;
}

/**
 * Filled/empty stars for display, e.g. 4 becomes "★★★★☆".
 */
function ratingStars($rating) {
    $rating = max(0, min(5, (int)round($rating)));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}

/**
 * Server-side validation for a review.
 *
 * The same rules are also checked in JavaScript before the form is submitted,
 * but that check only makes the page pleasant to use - a user can disable
 * JavaScript or post the form directly, so this function is the one that
 * actually protects the database.
 */
function validateReviewInput($rating, $comment) {
    $errors = [];

    if ($rating < 1 || $rating > 5) {
        $errors[] = "Please choose a rating between 1 and 5 stars.";
    }

    $length = mb_strlen(trim($comment));
    if ($length === 0) {
        $errors[] = "Please write a short comment with your review.";
    } elseif ($length < REVIEW_COMMENT_MIN) {
        $errors[] = "Your comment must be at least " . REVIEW_COMMENT_MIN . " characters long.";
    } elseif ($length > REVIEW_COMMENT_MAX) {
        $errors[] = "Your comment cannot be longer than " . REVIEW_COMMENT_MAX . " characters.";
    }

    return $errors;
}

/**
 * How many units of one product the user already has sitting in their cart.
 *
 * Without this, the stock check only compares the new quantity against stock,
 * so a customer could add 20 of a 20-stock item twice and only discover the
 * problem at checkout.
 */
function cartQuantityFor($conn, $user_id, $equipment_id) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS total
         FROM cart_items
         WHERE user_id = ? AND equipment_id = ?"
    );
    $stmt->bind_param("ii", $user_id, $equipment_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/**
 * Adds a product to the cart, or tops up the quantity when the exact same
 * product with the exact same variant choices is already in there.
 *
 * Merging matters because the cart page lists one row per cart_items record -
 * without this, adding the same racquet twice produced two separate lines.
 *
 * Returns an array of error messages; an empty array means it worked.
 */
function addToCart($conn, $user_id, $equipment_id, $quantity, $selected_options) {
    $errors = [];

    $stmt = $conn->prepare(
        "SELECT equipment_id, name, stock, status FROM equipment WHERE equipment_id = ?"
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

    $stock = (int)$product['stock'];
    $already_in_cart = cartQuantityFor($conn, $user_id, $equipment_id);

    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1.";
    } elseif ($stock <= 0) {
        // Checked before the cart maths, otherwise a product with no stock at
        // all would be reported as "you already have all of it in your cart"
        // even when the cart is empty.
        $errors[] = $product['name'] . " is out of stock.";
    } elseif (($already_in_cart + $quantity) > $stock) {
        $remaining = $stock - $already_in_cart;
        $errors[] = $remaining > 0
            ? "Only " . $remaining . " more of " . $product['name'] . " can be added; you already have " . $already_in_cart . " in your cart."
            : "You already have all " . $stock . " available of " . $product['name'] . " in your cart.";
    }

    if (!empty($errors)) {
        return $errors;
    }

    // ksort puts the choices in a fixed order so two identical selections
    // always look identical when they are compared below.
    ksort($clean_options);
    $options_json = json_encode($clean_options);

    /*
     * Look for a cart line holding this same product with these same choices.
     *
     * The comparison is done in PHP, not in the SQL WHERE clause, because
     * selected_options is a JSON column and MySQL rewrites JSON into its own
     * normalised form when it stores it - {"Speed": "77"} with a space, where
     * json_encode() produces {"Speed":"77"} without one. Matching on the raw
     * text therefore never succeeds. Decoding both sides back into arrays
     * compares the actual choices rather than how they happen to be spelled.
     */
    $existing = null;
    $stmt = $conn->prepare(
        "SELECT cart_id, quantity, selected_options
         FROM cart_items
         WHERE user_id = ? AND equipment_id = ?"
    );
    $stmt->bind_param("ii", $user_id, $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $saved = json_decode($row['selected_options'] ?? '', true);
        if (!is_array($saved)) {
            $saved = [];
        }
        ksort($saved);
        if ($saved == $clean_options) {
            $existing = $row;
            break;
        }
    }
    $stmt->close();

    if ($existing) {
        $new_quantity = (int)$existing['quantity'] + $quantity;
        $stmt = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND user_id = ?");
        $stmt->bind_param("iii", $new_quantity, $existing['cart_id'], $user_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO cart_items (user_id, equipment_id, quantity, selected_options)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("iiis", $user_id, $equipment_id, $quantity, $options_json);
        $stmt->execute();
        $stmt->close();
    }

    return [];
}

/**
 * Has this customer actually bought this product before?
 *
 * Used to put a "Verified purchase" badge on a review. It walks two tables:
 * equipment_order_items holds the products, but only equipment_orders knows
 * who placed the order, so the JOIN is what links a product back to a user.
 */
function hasPurchased($conn, $user_id, $equipment_id) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM equipment_order_items oi
         JOIN equipment_orders o ON oi.equipment_order_id = o.equipment_order_id
         WHERE o.user_id = ? AND oi.equipment_id = ? AND o.payment_status = 'paid'"
    );
    $stmt->bind_param("ii", $user_id, $equipment_id);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total > 0;
}

/**
 * The set of user ids that have bought this product, so the review list can
 * badge them without running one query per review.
 */
function purchaserIdsFor($conn, $equipment_id) {
    $ids = [];
    $stmt = $conn->prepare(
        "SELECT DISTINCT o.user_id
         FROM equipment_order_items oi
         JOIN equipment_orders o ON oi.equipment_order_id = o.equipment_order_id
         WHERE oi.equipment_id = ? AND o.payment_status = 'paid'"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['user_id'];
    }
    $stmt->close();
    return $ids;
}

/**
 * Prints the confirmation popup used after a successful action.
 *
 * Shared by the store listing and the product details page so both show the
 * same thing. The popup fades itself away with a CSS animation, so it does
 * not depend on JavaScript to disappear; js/equipment.js only adds the extra
 * touch of letting the customer click it away sooner.
 *
 * Nothing is printed when there is no message, so the caller can hand this
 * an empty string safely.
 */
function renderToast($message) {
    if (trim((string)$message) === '') {
        return;
    }
    ?>
    <div class="toast-overlay" id="cartToast" role="status">
        <div class="toast-check"></div>
        <p class="toast-message"><?= h($message) ?></p>
    </div>
    <?php
}

/**
 * Resolves a product image to a URL the browser can load.
 * Falls back to the generic equipment photo when a product has no image saved,
 * so the product grid never shows a broken image icon.
 */
function equipmentImage($image_url) {
    $path = trim((string)$image_url);
    if ($path === '') {
        return app_url('/images/equipment.jpg');
    }
    // Already an absolute URL, use it as-is.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return app_url('/' . ltrim($path, '/'));
}
