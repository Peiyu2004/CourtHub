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
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();

$sports     = getSportTypes($conn);
$categories = getCategories($conn);
$errors     = [];
$notice     = '';

/**
 * Turns the textarea into rows for equipment_options.
 * One option group per line:  Grip Color: Red, Blue, Black
 */
function parseOptionLines($text) {
    $options = [];
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
            if ($name !== '' && $value !== '') {
                $options[] = [$name, $value];
            }
        }
    }
    return $options;
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
 * Writes the variant options for a product, replacing whatever was there.
 * Used by both add and edit so the two paths cannot drift apart.
 */
function saveOptions($conn, $equipment_id, $option_rows) {
    $stmt = $conn->prepare("DELETE FROM equipment_options WHERE equipment_id = ?");
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $stmt->close();

    if (empty($option_rows)) {
        return;
    }

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
    if ($fields['stock'] < 0) {
        $errors[] = "Stock cannot be negative.";
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
        'stock'         => (int)($_POST['stock'] ?? 0),
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
        $errors      = validateEquipmentInput($fields, $sports, $categories);

        if (empty($errors)) {
            // The product row and its option rows must either both be written
            // or neither. Now that the tables are InnoDB this rollback is real.
            $conn->begin_transaction();
            try {
                $category_name = categoryNameFor($categories, $fields['category_id']);

                $stmt = $conn->prepare(
                    "INSERT INTO equipment
                     (name, sport_type_id, category_id, category, brand, price, stock, description, image_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "siissdiss",
                    $fields['name'], $fields['sport_type_id'], $fields['category_id'], $category_name,
                    $fields['brand'], $fields['price'], $fields['stock'],
                    $fields['description'], $fields['image_url']
                );
                $stmt->execute();
                $equipment_id = $stmt->insert_id;
                $stmt->close();

                saveOptions($conn, $equipment_id, $option_rows);

                $conn->commit();
                $notice = "\"" . $fields['name'] . "\" has been added to the store.";
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
        $errors       = validateEquipmentInput($fields, $sports, $categories);

        $stmt = $conn->prepare("SELECT equipment_id FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            $errors[] = "That product could not be found.";
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $category_name = categoryNameFor($categories, $fields['category_id']);

                $stmt = $conn->prepare(
                    "UPDATE equipment
                     SET name = ?, sport_type_id = ?, category_id = ?, category = ?,
                         brand = ?, price = ?, stock = ?, description = ?, image_url = ?
                     WHERE equipment_id = ?"
                );
                $stmt->bind_param(
                    "siissdissi",
                    $fields['name'], $fields['sport_type_id'], $fields['category_id'], $category_name,
                    $fields['brand'], $fields['price'], $fields['stock'],
                    $fields['description'], $fields['image_url'], $equipment_id
                );
                $stmt->execute();
                $stmt->close();

                saveOptions($conn, $equipment_id, $option_rows);

                $conn->commit();
                $notice = "\"" . $fields['name'] . "\" has been updated.";
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = "The equipment could not be updated.";
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
             * cart_items has ON DELETE CASCADE, so deleting a product would
             * quietly empty it out of every customer's cart, and past orders
             * would lose the link to what was bought. So the references are
             * counted first: if anything points at this product it is marked
             * discontinued (hidden from the store, row kept) instead of
             * deleted. This mirrors how admin/courts.php retires a court.
             */
            $stmt = $conn->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM cart_items            WHERE equipment_id = ?) AS in_carts,
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

// ?edit=5 loads that product into the form at the top of the page.
$editing = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM equipment WHERE equipment_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editing) {
        $errors[] = "That product could not be found.";
    }
}

// Read: every product, with its options rolled into one string and a count of
// how many reviews it has.
$equipment = [];
$result = $conn->query(
    "SELECT e.equipment_id, e.name, e.category, e.brand, e.price, e.stock, e.status, e.image_url,
            st.name AS sport_name,
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

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= h(app_url('/css/shop.css')) ?>?v=1.0">

<section class="card">
    <h1>Manage Equipment</h1>
    <p class="muted">Add, edit and remove the products shown in the equipment store.</p>
    <p><a class="btn btn-secondary" href="<?= h(app_url('/admin/categories.php')) ?>">Manage Categories</a></p>
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
              class="form-grid wide js-equipment-form">

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

            <div class="form-group">
                <label for="stock">Stock</label>
                <input type="number" id="stock" name="stock" min="0" required
                       value="<?= h($editing['stock'] ?? '') ?>">
            </div>

            <div class="form-group full-span">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"><?= h($editing['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group full-span">
                <label for="image_url">Image URL</label>
                <!-- js-image-url makes js/equipment.js show a live preview -->
                <input type="text" id="image_url" name="image_url" class="js-image-url"
                       placeholder="images/badminton.jpg"
                       value="<?= h($editing['image_url'] ?? '') ?>">
                <p class="muted">Relative to the project folder, for example images/badminton.jpg</p>
                <?php if (!empty($editing['image_url'])): ?>
                    <img class="image-preview" src="<?= h(equipmentImage($editing['image_url'])) ?>" alt="Image preview">
                <?php endif; ?>
            </div>

            <div class="form-group full-span">
                <label for="options_text">Variant Options</label>
                <textarea id="options_text" name="options_text" rows="4"
                          placeholder="Grip Size: G4, G5&#10;Grip Color: Red, Blue, Black"><?= h($editing ? optionsToText($conn, (int)$editing['equipment_id']) : '') ?></textarea>
                <p class="muted">One option group per line: Option Name: Value 1, Value 2</p>
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
                            <td><?= (int)$item['stock'] ?></td>
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

<script src="<?= h(app_url('/js/equipment.js')) ?>?v=1.0"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
