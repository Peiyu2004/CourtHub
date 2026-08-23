<?php


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


const RECENT_COOKIE = 'courthub_recent';

const RECENT_MAX = 6;


function recentlyViewedIds() {
    if (empty($_COOKIE[RECENT_COOKIE])) {
        return [];
    }

    $ids = [];
    foreach (explode(',', (string)$_COOKIE[RECENT_COOKIE]) as $raw) {
        $id = (int)$raw;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return array_slice($ids, 0, RECENT_MAX);
}


function rememberViewedProduct($equipment_id) {
    $equipment_id = (int)$equipment_id;
    if ($equipment_id <= 0) {
        return;
    }

    $ids = [$equipment_id];
    foreach (recentlyViewedIds() as $id) {
        if ($id !== $equipment_id) {
            $ids[] = $id;
        }
    }
    $ids = array_slice($ids, 0, RECENT_MAX);

    $value = implode(',', $ids);
    setcookie(RECENT_COOKIE, $value, time() + (30 * 86400), '/', '', false, true);
    $_COOKIE[RECENT_COOKIE] = $value;
}


function getRecentlyViewedProducts($conn, $exclude_id = 0) {
    $ids = [];
    foreach (recentlyViewedIds() as $id) {
        if ($id !== (int)$exclude_id) {
            $ids[] = $id;
        }
    }
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT equipment_id, name, price, image_url
         FROM equipment
         WHERE equipment_id IN (" . $placeholders . ") AND status = 'active'"
    );
    bindParams($stmt, str_repeat('i', count($ids)), $ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['equipment_id']] = $row;
    }

    $ordered = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }

    return $ordered;
}


const UPLOAD_DIR = 'images/products';

const UPLOAD_MAX_BYTES = 2097152;


function uploadedImageErrorMessage($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "The image is larger than the 2 MB limit.";
        case UPLOAD_ERR_PARTIAL:
            return "The image was only partly uploaded. Please try again.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return "The image could not be saved on the server.";
        default:
            return "The image could not be uploaded.";
    }
}


function handleProductImageUpload($file, &$errors) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = uploadedImageErrorMessage($file['error']);
        return null;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = "The image could not be uploaded.";
        return null;
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        $errors[] = "The image is larger than the 2 MB limit.";
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        $errors[] = "Only JPG, PNG and WEBP images are accepted.";
        return null;
    }

    if (getimagesize($file['tmp_name']) === false) {
        $errors[] = "That file is not a readable image.";
        return null;
    }

    $target_dir = __DIR__ . '/../' . UPLOAD_DIR;
    if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true)) {
        $errors[] = "The upload folder could not be created.";
        return null;
    }

    $name = 'product_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $target_dir . '/' . $name)) {
        $errors[] = "The image could not be saved on the server.";
        return null;
    }

    return UPLOAD_DIR . '/' . $name;
}
