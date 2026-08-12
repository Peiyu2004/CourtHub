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

/* ---------------------------------------------------------------------
   Variants
   ---------------------------------------------------------------------
   equipment_options says what a product offers; equipment_variants says
   what can actually be bought, and holds the stock. A racquet offering
   Grip Size (G4, G5) and Grip Color (Red, Blue, Black) has six variants,
   each with its own stock, so the last "G4 / Red" can sell out without
   taking every other colour down with it.

   A combination is identified by its variant_key, one canonical string:
       'Grip Color=Red|Grip Size=G4'
   The names are sorted before the string is built, so the same choices
   always produce the same text whichever order the dropdowns were filled
   in, and looking a variant up stays one indexed equality test.

   A product with no options is not a special case: it gets a single
   variant whose key is the empty string, so every page counts, checks and
   decrements stock through exactly one code path.
   --------------------------------------------------------------------- */

/** Separates one name=value pair from the next inside a variant_key. */
const VARIANT_PAIR_SEPARATOR = '|';
/** Separates an option name from its value inside a variant_key. */
const VARIANT_VALUE_SEPARATOR = '=';

/**
 * Builds the canonical key for a set of choices:
 *   ['Grip Size' => 'G4', 'Grip Color' => 'Red']
 *     -> 'Grip Color=Red|Grip Size=G4'
 *
 * ksort() is the important line. Without it the key would depend on the
 * order the choices happened to arrive in - the details page posts them in
 * dropdown order, the admin grid builds them in sorted order - and the same
 * combination would produce two different keys that never matched.
 *
 * An empty set of choices gives '', which is the key of the single variant
 * belonging to a product with no options.
 */
function variantKeyFor(array $options) {
    ksort($options);

    $parts = [];
    foreach ($options as $name => $value) {
        $parts[] = $name . VARIANT_VALUE_SEPARATOR . $value;
    }

    return implode(VARIANT_PAIR_SEPARATOR, $parts);
}

/**
 * The opposite of variantKeyFor(): turns a key back into the choices it
 * stands for, e.g. ['Grip Color' => 'Red', 'Grip Size' => 'G4'].
 *
 * Returns an empty array for the '' key and for anything malformed, so a
 * caller can always foreach over the result without checking it first.
 */
function decodeVariantKey($variant_key) {
    $options = [];

    $key = trim((string)$variant_key);
    if ($key === '') {
        return $options;
    }

    foreach (explode(VARIANT_PAIR_SEPARATOR, $key) as $pair) {
        $bits = explode(VARIANT_VALUE_SEPARATOR, $pair, 2);
        if (count($bits) === 2 && $bits[0] !== '') {
            $options[$bits[0]] = $bits[1];
        }
    }

    return $options;
}

/**
 * A short human label for a variant, e.g. 'G4 / Red'.
 * Only the values are shown, because the names are already the labels on the
 * dropdowns the customer just used. A product with no options reads
 * 'Standard' rather than an empty string, so the admin stock grid always has
 * something in its first column.
 */
function variantLabel($variant_key) {
    $options = decodeVariantKey($variant_key);
    if (empty($options)) {
        return 'Standard';
    }
    return implode(' / ', array_values($options));
}

/**
 * Every combination of the option groups, as an array of choice arrays.
 *
 * Each group multiplies what came before it, so two sizes and three colours
 * give six results. A product with no groups gives one empty combination
 * rather than none, which is what creates that product's single variant.
 */
function variantCombinations(array $option_groups) {
    ksort($option_groups);

    $combinations = [[]];
    foreach ($option_groups as $option_name => $values) {
        $expanded = [];
        foreach ($combinations as $combination) {
            foreach ($values as $value) {
                $combination[$option_name] = $value;
                $expanded[] = $combination;
            }
        }
        $combinations = $expanded;
    }

    return $combinations;
}

/**
 * Brings a product's variants back in line with the options it now offers.
 *
 * Called after the admin saves a product, and it deliberately does not wipe
 * and rebuild the table. A variant that still exists keeps its row, which
 * means it keeps its stock and stays pointed at by anybody who has it in
 * their cart. Only combinations that are no longer offered are removed, and
 * only genuinely new ones are added - those start at 0, because nobody has
 * said yet how many of them the shop has.
 *
 * Deleting a variant does empty it out of the carts holding it, which is the
 * right outcome: the shop has stopped selling that colour, so it cannot be
 * checked out. Past order lines survive - equipment_order_items.variant_id is
 * ON DELETE SET NULL and the receipt reads from its own selected_options
 * snapshot, not from the variant.
 *
 * The caller runs this inside its transaction so a half-updated product and
 * its variants can never both be committed.
 */
function syncEquipmentVariants($conn, $equipment_id, array $option_groups) {
    $wanted = [];
    foreach (variantCombinations($option_groups) as $combination) {
        $wanted[variantKeyFor($combination)] = true;
    }

    $existing = [];
    $stmt = $conn->prepare(
        "SELECT variant_id, variant_key FROM equipment_variants WHERE equipment_id = ?"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existing[$row['variant_key']] = (int)$row['variant_id'];
    }
    $stmt->close();

    $stale = [];
    foreach ($existing as $variant_key => $variant_id) {
        if (!isset($wanted[$variant_key])) {
            $stale[] = $variant_id;
        }
    }

    if (!empty($stale)) {
        $placeholders = implode(',', array_fill(0, count($stale), '?'));
        $stmt = $conn->prepare(
            "DELETE FROM equipment_variants WHERE variant_id IN (" . $placeholders . ")"
        );
        bindParams($stmt, str_repeat('i', count($stale)), $stale);
        $stmt->execute();
        $stmt->close();
    }

    $missing = array_diff_key($wanted, $existing);
    if (!empty($missing)) {
        $stmt = $conn->prepare(
            "INSERT INTO equipment_variants (equipment_id, variant_key, stock) VALUES (?, ?, 0)"
        );
        foreach (array_keys($missing) as $variant_key) {
            $stmt->bind_param("is", $equipment_id, $variant_key);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * Every variant of one product with its stock, ready to display.
 * Each row carries the decoded choices and a label as well as the raw key,
 * so the admin grid and the details page do not have to decode it again.
 */
function getEquipmentVariants($conn, $equipment_id) {
    $variants = [];

    $stmt = $conn->prepare(
        "SELECT variant_id, variant_key, stock
         FROM equipment_variants
         WHERE equipment_id = ?
         ORDER BY variant_key"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['stock']   = (int)$row['stock'];
        $row['options'] = decodeVariantKey($row['variant_key']);
        $row['label']   = variantLabel($row['variant_key']);
        $variants[]     = $row;
    }
    $stmt->close();

    return $variants;
}

/**
 * The one variant matching a set of choices, or null when that combination
 * is not offered. Casting a posted set of options into a key and looking it
 * up here is what stops someone inventing a variant that is not for sale.
 */
function findVariantByOptions($conn, $equipment_id, array $clean_options) {
    $variant_key = variantKeyFor($clean_options);

    $stmt = $conn->prepare(
        "SELECT variant_id, variant_key, stock
         FROM equipment_variants
         WHERE equipment_id = ? AND variant_key = ?"
    );
    $stmt->bind_param("is", $equipment_id, $variant_key);
    $stmt->execute();
    $variant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $variant ?: null;
}

/**
 * The stock of every variant of one product as [variant_key => stock].
 *
 * This is the shape the details page hands to JavaScript, so the availability
 * line and the quantity limit can follow the dropdowns without a round trip.
 */
function variantStockMap(array $variants) {
    $map = [];
    foreach ($variants as $variant) {
        $map[$variant['variant_key']] = (int)$variant['stock'];
    }
    return $map;
}

/**
 * The same map for a whole page of products at once:
 *   [equipment_id => ['Grip Color=Red|Grip Size=G4' => 4, ...], ...]
 *
 * The listing page shows up to fourteen cards, each with its own dropdowns,
 * so one query here beats fourteen. Written to match getOptionGroupsForMany()
 * which does the same thing for the choices themselves.
 */
function getVariantStockForMany($conn, $equipment_ids) {
    $stock = [];
    if (empty($equipment_ids)) {
        return $stock;
    }

    $placeholders = implode(',', array_fill(0, count($equipment_ids), '?'));
    $stmt = $conn->prepare(
        "SELECT equipment_id, variant_key, stock
         FROM equipment_variants
         WHERE equipment_id IN (" . $placeholders . ")
         ORDER BY equipment_id, variant_key"
    );
    bindParams($stmt, str_repeat('i', count($equipment_ids)), $equipment_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $stock[(int)$row['equipment_id']][$row['variant_key']] = (int)$row['stock'];
    }
    $stmt->close();

    return $stock;
}

/**
 * How many units of one option value are left across every combination that
 * contains it, as [option_name => [option_value => stock]].
 *
 * A value whose total comes to 0 cannot be bought in any combination, so the
 * dropdowns mark it "sold out" instead of letting the customer pick it and
 * only then be told no.
 */
function optionValueStock(array $option_groups, array $variants) {
    $totals = [];

    foreach ($option_groups as $option_name => $values) {
        foreach ($values as $value) {
            $totals[$option_name][$value] = 0;
        }
    }

    foreach ($variants as $variant) {
        foreach ($variant['options'] as $option_name => $value) {
            if (isset($totals[$option_name][$value])) {
                $totals[$option_name][$value] += (int)$variant['stock'];
            }
        }
    }

    return $totals;
}

/** A product's total stock, added up from its variants. */
function totalVariantStock(array $variants) {
    $total = 0;
    foreach ($variants as $variant) {
        $total += (int)$variant['stock'];
    }
    return $total;
}

/**
 * Writes the stock numbers from the admin grid.
 *
 * $posted arrives as [variant_id => quantity] straight from the form, so
 * every id is checked against the variants this product actually owns before
 * anything is written. Without that check, editing the hidden field on one
 * product's page would let an admin set the stock of another product's
 * variant. Returns a list of error messages; empty means it was saved.
 */
function saveVariantStock($conn, $equipment_id, array $posted) {
    $errors = [];

    $owned = [];
    foreach (getEquipmentVariants($conn, $equipment_id) as $variant) {
        $owned[(int)$variant['variant_id']] = $variant['label'];
    }

    $updates = [];
    foreach ($posted as $variant_id => $quantity) {
        $variant_id = (int)$variant_id;
        if (!isset($owned[$variant_id])) {
            continue;
        }
        // A blank box is a slip rather than an instruction, so it is left
        // alone instead of being read as zero and wiping the stock.
        if (trim((string)$quantity) === '') {
            continue;
        }
        if (!ctype_digit(ltrim((string)$quantity, '-')) || (int)$quantity < 0) {
            $errors[] = "Stock for " . $owned[$variant_id] . " must be zero or a positive whole number.";
            continue;
        }
        $updates[$variant_id] = (int)$quantity;
    }

    if (!empty($errors)) {
        return $errors;
    }

    $stmt = $conn->prepare(
        "UPDATE equipment_variants SET stock = ? WHERE variant_id = ? AND equipment_id = ?"
    );
    foreach ($updates as $variant_id => $quantity) {
        $stmt->bind_param("iii", $quantity, $variant_id, $equipment_id);
        $stmt->execute();
    }
    $stmt->close();

    return [];
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
