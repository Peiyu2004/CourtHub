<?php
/**
 * equipment.php (admin)
 * Manage the products in the equipment store.
 *
 * All four database operations are covered here:
 *   Create - add a product together with its variant options
 *   Read   - list every product with its options and review count
 *   Update - edit a product, replacing its variant options
 *   Delete - remove a product, or discontinue it when removing would damage
 *            somebody's cart or an existing order
 *
 * Stock is not edited on the product form, because a product does not have
 * one stock number any more - each combination of its options has its own.
 * Saving the options builds those combinations, and the grid further down the
 * edit page is where their quantities are typed in. The two are separate
 * forms on purpose: the options decide which boxes the grid shows, so they
 * have to be saved before there is a grid to fill in.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();

$sports     = getSportTypes($conn);
$categories = getCategories($conn);
$errors     = [];
$notice     = '';
// Set by an action that wants the page to stay on one product afterwards,
// so the stock grid for it is on screen without the admin navigating back.
$reopen_id  = 0;

/**
 * Turns the textarea into rows for equipment_options.
 * One option group per line:  Grip Color: Red, Blue, Black
 *
 * Duplicates within a group are dropped rather than saved. equipment_options
 * has a UNIQUE key on (equipment_id, option_name, option_value), so "Red,
 * Red" would otherwise fail the whole save over what is only a typing slip.
 */
function parseOptionLines($text) {
    $options = [];
    $seen = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = trim($parts[0]);
        $values = array_filter(array_map('trim', explode(',', $parts[1])));
        foreach ($values as $value) {
            if ($name === '' || $value === '') {
                continue;
            }
            $fingerprint = $name . "\0" . $value;
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $options[] = [$name, $value];
        }
    }
    return $options;
}

/**
 * Checks the option names and values before they are saved.
 *
 * '=' and '|' are refused because those two characters are what join a
 * combination into its variant_key - 'Grip Color=Red|Grip Size=G4'. A value
 * containing either of them would produce a key that decodes back into
 * something other than what was typed, and the variant would no longer be
 * findable from the customer's choices.
 */
function validateOptionRows($option_rows) {
    $errors = [];

    foreach ($option_rows as $row) {
        [$option_name, $option_value] = $row;
        foreach ([$option_name, $option_value] as $text) {
            if (strpos($text, VARIANT_VALUE_SEPARATOR) !== false ||
                strpos($text, VARIANT_PAIR_SEPARATOR) !== false) {
                $errors[] = "Variant options cannot contain the characters "
                          . VARIANT_VALUE_SEPARATOR . " or " . VARIANT_PAIR_SEPARATOR
                          . ", but \"" . $text . "\" does.";
                // One message is enough to send the admin back to the box.
                return $errors;
            }
        }
    }

    return $errors;
}

/**
 * The opposite of parseOptionLines: turns the saved options back into the
 * textarea format so the edit form can show what is already there.
 */
function optionsToText($conn, $equipment_id) {
    $groups = getEquipmentOptionGroups($conn, $equipment_id);
    $lines = [];
    foreach ($groups as $option_name => $values) {
        $lines[] = $option_name . ': ' . implode(', ', $values);
    }
    return implode("\n", $lines);
}

/**
 * Writes the variant options for a product, replacing whatever was there,
 * and then brings its stock-holding combinations back in line with them.
 * Used by both add and edit so the two paths cannot drift apart.
 *
 * The option rows are rewritten from scratch, but syncEquipmentVariants()
 * is careful not to: a combination that survives the edit keeps its row, and
 * so keeps the stock somebody counted onto it. Only combinations that are no
 * longer offered go, and only new ones arrive, starting at zero.
 *
 * The groups handed to the sync are read back out of the database rather than
 * built from $option_rows, so the combinations are always generated from what
 * was actually stored.
 */
function saveOptions($conn, $equipment_id, $option_rows) {
    $stmt = $conn->prepare("DELETE FROM equipment_options WHERE equipment_id = ?");
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $stmt->close();

    if (!empty($option_rows)) {
        $stmt = $conn->prepare(
            "INSERT INTO equipment_options (equipment_id, option_name, option_value) VALUES (?, ?, ?)"
        );
        foreach ($option_rows as $row) {
            [$option_name, $option_value] = $row;
            $stmt->bind_param("iss", $equipment_id, $option_name, $option_value);
            $stmt->execute();
        }
        $stmt->close();
    }

    // Runs even when there are no options at all: a product with no choices
    // still needs its single '' variant, because that is where its stock goes.
    syncEquipmentVariants($conn, $equipment_id, getEquipmentOptionGroups($conn, $equipment_id));
}

/**
 * Shared checks for the add and edit forms. Returns a list of messages.
 * The same rules run in js/equipment.js before the form is sent, but that
 * check is only there for convenience - this one is the real gate.
 */
function validateEquipmentInput($fields, $sports, $categories) {
    $errors = [];

    if ($fields['name'] === '') {
        $errors[] = "Equipment name is required.";
    }
    if ($fields['price'] <= 0) {
        $errors[] = "Price must be more than zero.";
    }

    $sport_exists = false;
    foreach ($sports as $sport) {
        if ((int)$sport['sport_type_id'] === $fields['sport_type_id']) {
            $sport_exists = true;
            break;
        }
    }
    if (!$sport_exists) {
        $errors[] = "Please choose a valid sport type.";
    }

    // The category comes from a dropdown, but a posted form can be edited by
    // hand, so the id is checked against the real list rather than trusted.
    $category_exists = false;
    foreach ($categories as $category) {
        if ((int)$category['category_id'] === $fields['category_id']) {
            $category_exists = true;
            break;
        }
    }
    if (!$category_exists) {
        $errors[] = "Please choose a valid category.";
    }

    return $errors;
}

/** Pulls the product fields out of $_POST in one place. */
function equipmentFieldsFromPost() {
    return [
        'name'          => trim($_POST['name'] ?? ''),
        'sport_type_id' => (int)($_POST['sport_type_id'] ?? 0),
        'category_id'   => (int)($_POST['category_id'] ?? 0),
        'brand'         => trim($_POST['brand'] ?? ''),
        'price'         => (float)($_POST['price'] ?? 0),
        'description'   => trim($_POST['description'] ?? ''),
        'image_url'     => trim($_POST['image_url'] ?? ''),
    ];
}

/** The category name for a chosen id, used to keep the old text column in step. */
function categoryNameFor($categories, $category_id) {
    foreach ($categories as $category) {
        if ((int)$category['category_id'] === $category_id) {
            return $category['name'];
        }
    }
    return '';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----------------------------- Create -----------------------------
    if ($action === 'add') {
        $fields      = equipmentFieldsFromPost();
        $option_rows = parseOptionLines($_POST['options_text'] ?? '');
        $errors      = array_merge(
            validateEquipmentInput($fields, $sports, $categories),
            validateOptionRows($option_rows)
        );

        $uploaded = handleProductImageUpload($_FILES['product_image'] ?? null, $errors);
        if ($uploaded !== null) {
            $fields['image_url'] = $uploaded;
        }

        if (empty($errors)) {
            // The product row and its option rows must either both be written
            // or neither. Now that the tables are InnoDB this rollback is real.
            $conn->begin_transaction();
            try {
                $category_name = categoryNameFor($categories, $fields['category_id']);

                $stmt = $conn->prepare(
                    "INSERT INTO equipment
                     (name, sport_type_id, category_id, category, brand, price, description, image_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "siissdss",
                    $fields['name'], $fields['sport_type_id'], $fields['category_id'], $category_name,
                    $fields['brand'], $fields['price'],
                    $fields['description'], $fields['image_url']
                );
                $stmt->execute();
                $equipment_id = $stmt->insert_id;
                $stmt->close();

                saveOptions($conn, $equipment_id, $option_rows);

                $conn->commit();

                // A new product's combinations all start at zero, so it is not
                // finished yet. Reopening it for editing puts the stock grid on
                // screen straight away rather than leaving the admin to work
                // out that a second step exists.
                $reopen_id = $equipment_id;
                $notice = "\"" . $fields['name'] . "\" has been added to the store. "
                        . "Set how many of each variation you have below - it is not on sale until you do.";
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = "The equipment could not be added.";
            }
        }
    }

    // ----------------------------- Update -----------------------------
    if ($action === 'update') {
        $equipment_id = (int)($_POST['equipment_id'] ?? 0);
        $fields       = equipmentFieldsFromPost();
        $option_rows  = parseOptionLines($_POST['options_text'] ?? '');
        $errors       = array_merge(
            validateEquipmentInput($fields, $sports, $categories),
            validateOptionRows($option_rows)
        );

        $stmt = $conn->prepare("SELECT equipment_id FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            $errors[] = "That product could not be found.";
        }

        $uploaded = handleProductImageUpload($_FILES['product_image'] ?? null, $errors);
        if ($uploaded !== null) {
            $fields['image_url'] = $uploaded;
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $category_name = categoryNameFor($categories, $fields['category_id']);

                $stmt = $conn->prepare(
                    "UPDATE equipment
                     SET name = ?, sport_type_id = ?, category_id = ?, category = ?,
                         brand = ?, price = ?, description = ?, image_url = ?
                     WHERE equipment_id = ?"
                );
                $stmt->bind_param(
                    "siissdssi",
                    $fields['name'], $fields['sport_type_id'], $fields['category_id'], $category_name,
                    $fields['brand'], $fields['price'],
                    $fields['description'], $fields['image_url'], $equipment_id
                );
                $stmt->execute();
                $stmt->close();

                saveOptions($conn, $equipment_id, $option_rows);

                $conn->commit();

                // Changing the options changes the grid, so the page stays on
                // this product to show what the combinations now look like.
                $reopen_id = $equipment_id;
                $notice = "\"" . $fields['name'] . "\" has been updated. "
                        . "Check the stock for each variation below - any new combination starts at zero.";
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = "The equipment could not be updated.";
            }
        }
    }

    // -------------------------- Stock grid ----------------------------
    // The second half of editing a product: one quantity per combination.
    // Kept apart from the product form because the options saved there are
    // what decide which boxes appear here.
    if ($action === 'save_stock') {
        $equipment_id = (int)($_POST['equipment_id'] ?? 0);
        $reopen_id    = $equipment_id;

        $stmt = $conn->prepare("SELECT name FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) {
            $errors[] = "That product could not be found.";
        } else {
            $errors = saveVariantStock($conn, $equipment_id, $_POST['stock'] ?? []);
            if (empty($errors)) {
                $notice = "Stock for \"" . $product['name'] . "\" has been updated.";
            }
        }
    }

    // ----------------------------- Delete -----------------------------
    if ($action === 'delete') {
        $equipment_id = (int)($_POST['equipment_id'] ?? 0);

        $stmt = $conn->prepare("SELECT name FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) {
            $errors[] = "That product could not be found.";
        } else {
            /*
             * Deleting a product cascades into its variants, and cart_items
             * cascades from there, so it would quietly empty the product out
             * of every customer's cart; past orders would lose the link to
             * what was bought. So the references are counted first: if
             * anything points at this product it is marked discontinued
             * (hidden from the store, row kept) instead of deleted. This
             * mirrors how admin/courts.php retires a court.
             *
             * The cart side is counted through equipment_variants, because a
             * cart line names a variant and not a product.
             */
            $stmt = $conn->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM cart_items ci
                       JOIN equipment_variants v ON ci.variant_id = v.variant_id
                      WHERE v.equipment_id = ?)                            AS in_carts,
                    (SELECT COUNT(*) FROM equipment_order_items WHERE equipment_id = ?) AS in_orders"
            );
            $stmt->bind_param("ii", $equipment_id, $equipment_id);
            $stmt->execute();
            $usage = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $referenced = (int)$usage['in_carts'] + (int)$usage['in_orders'];

            if ($referenced > 0) {
                $stmt = $conn->prepare("UPDATE equipment SET status = 'discontinued' WHERE equipment_id = ?");
                $stmt->bind_param("i", $equipment_id);
                $stmt->execute();
                $stmt->close();
                $notice = "\"" . $product['name'] . "\" is in " . (int)$usage['in_carts']
                        . " cart(s) and " . (int)$usage['in_orders'] . " past order item(s), so it has been "
                        . "discontinued instead of deleted. It is now hidden from the store.";
            } else {
                $stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id = ?");
                $stmt->bind_param("i", $equipment_id);
                $stmt->execute();
                $stmt->close();
                $notice = "\"" . $product['name'] . "\" has been deleted from the store.";
            }
        }
    }

    // --------------------------- Reactivate ---------------------------
    if ($action === 'reactivate') {
        $equipment_id = (int)($_POST['equipment_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE equipment SET status = 'active' WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $stmt->close();
        $notice = "The product is back on sale.";
    }

    // The counts shown next to each category change after any of the above.
    $categories = getCategories($conn);
}

// ?edit=5 loads that product into the form at the top of the page. An action
// that just saved a product asks for the same thing through $reopen_id, so
// its stock grid is waiting when the page comes back.
$editing = null;
$edit_id = $reopen_id > 0 ? $reopen_id : (int)($_GET['edit'] ?? 0);

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM equipment WHERE equipment_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editing) {
        $errors[] = "That product could not be found.";
    }
}

// The combinations of the product being edited, with the stock of each. This
// is what the grid under the form is built from.
$editing_variants = $editing ? getEquipmentVariants($conn, (int)$editing['equipment_id']) : [];

// Read: every product, with its options rolled into one string and a count of
// how many reviews it has.
//
// The stock column is added up from the product's variants rather than read
// from the product, because that total is not stored anywhere - the variants
// are the only place it exists. variant_count goes with it so the list can say
// how many combinations the total is spread across, and out_of_stock_count
// flags a product that still has stock overall but has run out in one of its
// sizes or colours, which is exactly the case a single number would hide.
$equipment = [];
$result = $conn->query(
    "SELECT e.equipment_id, e.name, e.category, e.brand, e.price, e.status, e.image_url,
            st.name AS sport_name,
            (SELECT COALESCE(SUM(v.stock), 0) FROM equipment_variants v
              WHERE v.equipment_id = e.equipment_id) AS stock,
            (SELECT COUNT(*) FROM equipment_variants v
              WHERE v.equipment_id = e.equipment_id) AS variant_count,
            (SELECT COUNT(*) FROM equipment_variants v
              WHERE v.equipment_id = e.equipment_id AND v.stock = 0) AS out_of_stock_count,
            (SELECT GROUP_CONCAT(CONCAT(eo.option_name, ': ', eo.option_value)
                                 ORDER BY eo.option_name, eo.option_value SEPARATOR '; ')
             FROM equipment_options eo WHERE eo.equipment_id = e.equipment_id) AS options_summary,
            (SELECT COUNT(*) FROM equipment_reviews r WHERE r.equipment_id = e.equipment_id) AS review_count
     FROM equipment e
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id
     ORDER BY e.status, e.created_at DESC, e.equipment_id DESC"
);
while ($row = $result->fetch_assoc()) {
    $equipment[] = $row;
}
$result->close();

// Admin pages use the wider container - see includes/header.php.
$wide_layout = true;
$page_title = 'Manage Equipment';
$extra_css = ['shop', 'admin'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <h1>Equipment Management</h1>
    <p class="muted">Add, update and remove products, variants and stock levels.</p>
</section>

<div class="dashboard-layout">
    <!-- Persistent Admin Panel -->
    <?php include __DIR__ . '/../includes/adminPanel.php'; ?>

    <!-- Main Content Area -->
    <div class="dashboard-main-content">
        <section class="card">
            <h1>Manage Equipment</h1>
            <p class="muted">Add, edit and remove the products shown in the equipment store.</p>
        </section>

        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="card">
            <h2><?= $editing ? 'Edit Equipment' : 'Add Equipment' ?></h2>

            <?php if (empty($categories)): ?>
                <div class="empty-state">
                    There are no categories yet. Please
                    <a href="<?= h(app_url('/admin/categories.php')) ?>">add a category</a> before adding a product.
                </div>
            <?php else: ?>
                <!-- js-equipment-form switches on the checks in js/equipment.js -->
                <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>"
                    class="form-grid wide js-equipment-form" enctype="multipart/form-data">

                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="equipment_id" value="<?= (int)$editing['equipment_id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Name</label>
                        <input type="text" id="name" name="name" required value="<?= h($editing['name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="sport_type_id">Sport Type</label>
                        <select id="sport_type_id" name="sport_type_id" required>
                            <?php foreach ($sports as $sport): ?>
                                <option value="<?= (int)$sport['sport_type_id'] ?>"
                                    <?= isset($editing) && (int)$editing['sport_type_id'] === (int)$sport['sport_type_id'] ? 'selected' : '' ?>>
                                    <?= h($sport['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int)$category['category_id'] ?>"
                                    <?= isset($editing) && (int)$editing['category_id'] === (int)$category['category_id'] ? 'selected' : '' ?>>
                                    <?= h($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" value="<?= h($editing['brand'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="price">Price (RM)</label>
                        <input type="number" id="price" name="price" min="0.01" step="0.01" required
                            value="<?= h($editing['price'] ?? '') ?>">
                    </div>

                    <div class="form-group full-span">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?= h($editing['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group full-span">
                        <label for="product_image">Product Photo</label>
                        <input type="file" id="product_image" name="product_image"
                               accept="image/jpeg,image/png,image/webp" class="js-image-file">
                        <p class="muted">JPG, PNG or WEBP, up to 2 MB. Leave empty to keep the current photo.</p>
                        <input type="hidden" name="image_url" value="<?= h($editing['image_url'] ?? '') ?>">
                        <?php if (!empty($editing['image_url'])): ?>
                            <img class="image-preview" src="<?= h(equipmentImage($editing['image_url'])) ?>" alt="Current product photo">
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-span">
                        <label for="options_text">Variant Options</label>
                        <textarea id="options_text" name="options_text" rows="4"
                                placeholder="Grip Size: G4, G5&#10;Grip Color: Red, Blue, Black"><?= h($editing ? optionsToText($conn, (int)$editing['equipment_id']) : '') ?></textarea>
                        <p class="muted">
                            One option group per line: Option Name: Value 1, Value 2<br>
                            Saving builds one stock box per combination below &mdash;
                            two sizes and three colours make six. Combinations that
                            already exist keep the stock you gave them.
                        </p>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn"><?= $editing ? 'Save Changes' : 'Add Equipment' ?></button>
                    </div>

                    <?php if ($editing): ?>
                        <div class="form-actions">
                            <a class="btn btn-secondary" href="<?= h(app_url('/admin/equipment.php')) ?>">Cancel</a>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($editing): ?>
            <?php
                // The grid is generated from the product's combinations, so the
                // admin never types a combination out by hand and cannot invent
                // one that is not offered.
                $editing_total = totalVariantStock($editing_variants);
                $editing_empty = 0;
                foreach ($editing_variants as $variant) {
                    if ($variant['stock'] === 0) {
                        $editing_empty++;
                    }
                }
            ?>
            <section class="card">
                <h2>Stock per Variation</h2>
                <p class="muted">
                    Every combination of <strong><?= h($editing['name']) ?></strong> holds its own stock,
                    so running out of one size or colour leaves the rest on sale.
                </p>

                <?php if (empty($editing_variants)): ?>
                    <div class="empty-state">
                        This product has no variations yet. Save the form above to build them.
                    </div>
                <?php else: ?>
                    <?php if ($editing_empty > 0): ?>
                        <p class="muted">
                            <?= (int)$editing_empty ?> of <?= count($editing_variants) ?> variations
                            <?= $editing_empty === 1 ? 'is' : 'are' ?> at zero and cannot be bought.
                        </p>
                    <?php endif; ?>

                    <!-- js-variant-stock-form keeps the total at the bottom in
                         step with the boxes as they are typed in -->
                    <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>"
                          class="js-variant-stock-form">
                        <input type="hidden" name="action" value="save_stock">
                        <input type="hidden" name="equipment_id" value="<?= (int)$editing['equipment_id'] ?>">

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Variation</th>
                                        <th>Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($editing_variants as $variant): ?>
                                        <tr>
                                            <td>
                                                <?php if (empty($variant['options'])): ?>
                                                    <strong>Standard</strong>
                                                    <span class="muted">&mdash; this product has no options</span>
                                                <?php else: ?>
                                                    <?php foreach ($variant['options'] as $option_name => $option_value): ?>
                                                        <span class="mini-pill"><?= h($option_name) ?>: <?= h($option_value) ?></span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="inline-form">
                                                <input type="number" class="qty-input js-variant-stock"
                                                       name="stock[<?= (int)$variant['variant_id'] ?>]"
                                                       min="0" step="1"
                                                       value="<?= (int)$variant['stock'] ?>"
                                                       aria-label="Stock for <?= h($variant['label']) ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th>Total in stock</th>
                                        <th id="variantStockTotal"><?= (int)$editing_total ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn">Save Stock</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2>Equipment List</h2>

            <?php if (empty($equipment)): ?>
                <div class="empty-state">No equipment has been added yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Sport</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Reviews</th>
                                <th>Status</th>
                                <th>Options</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipment as $item): ?>
                                <tr>
                                    <td>
                                        <img class="admin-thumb" src="<?= h(equipmentImage($item['image_url'])) ?>"
                                            alt="<?= h($item['name']) ?>">
                                    </td>
                                    <td>
                                        <strong><?= h($item['name']) ?></strong><br>
                                        <span class="muted"><?= h($item['brand']) ?></span>
                                    </td>
                                    <td><?= h($item['sport_name']) ?></td>
                                    <td><?= h($item['category']) ?></td>
                                    <td><?= money($item['price']) ?></td>
                                    <td>
                                        <?= (int)$item['stock'] ?>
                                        <?php if ((int)$item['variant_count'] > 1): ?>
                                            <br>
                                            <span class="muted">
                                                across <?= (int)$item['variant_count'] ?> variations<?php
                                                    // Worth saying out loud: a healthy looking total can
                                                    // still be sold out in the size somebody wants.
                                                    if ((int)$item['out_of_stock_count'] > 0): ?>,
                                                    <?= (int)$item['out_of_stock_count'] ?> empty<?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$item['review_count'] ?></td>
                                    <td>
                                        <span class="status <?= h($item['status']) ?>">
                                            <?= $item['status'] === 'active' ? 'Active' : 'Discontinued' ?>
                                        </span>
                                    </td>
                                    <td class="muted"><?= h($item['options_summary'] ?: 'No variants') ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn btn-secondary"
                                            href="<?= h(app_url('/admin/equipment.php?edit=' . (int)$item['equipment_id'])) ?>">Edit</a>

                                            <?php if ($item['status'] === 'discontinued'): ?>
                                                <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="equipment_id" value="<?= (int)$item['equipment_id'] ?>">
                                                    <button type="submit" class="btn">Restore</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>"
                                                    class="js-confirm"
                                                    data-confirm="Remove &quot;<?= h($item['name']) ?>&quot; from the store?">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="equipment_id" value="<?= (int)$item['equipment_id'] ?>">
                                                    <button type="submit" class="btn btn-danger">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script src="<?= h(asset_url('/js/equipment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
