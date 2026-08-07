<?php
/**
 * categories.php (admin)
 * Category management for the equipment store.
 *
 * Categories used to be free text typed into the equipment form, which meant
 * "Racquet" and "racquet" became two separate categories and the store filter
 * could not group them. This page is the single place they are maintained.
 *
 * All four database operations are here:
 *   Create - add a new category
 *   Read   - list every category with how many products use it
 *   Update - rename a category or change its description
 *   Delete - remove a category, but only when no product still uses it
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

requireAdmin();

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----- Create -----
    if ($action === 'add') {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $errors[] = "Category name is required.";
        } elseif (mb_strlen($name) > 50) {
            $errors[] = "Category name cannot be longer than 50 characters.";
        }

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                $stmt->execute();
                $stmt->close();
                $notice = "Category \"" . $name . "\" has been added.";
            } catch (mysqli_sql_exception $e) {
                // categories.name is UNIQUE, so MySQL rejects a duplicate for us.
                // The JavaScript checks for this too, but only this catch block
                // can be relied on.
                $errors[] = "A category called \"" . $name . "\" already exists.";
            }
        }
    }

    // ----- Update -----
    if ($action === 'update') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!findCategory($conn, $category_id)) {
            $errors[] = "That category could not be found.";
        }
        if ($name === '') {
            $errors[] = "Category name is required.";
        } elseif (mb_strlen($name) > 50) {
            $errors[] = "Category name cannot be longer than 50 characters.";
        }

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare(
                    "UPDATE categories SET name = ?, description = ? WHERE category_id = ?"
                );
                $stmt->bind_param("ssi", $name, $description, $category_id);
                $stmt->execute();
                $stmt->close();

                // equipment.category is the old text column that shop/cart.php
                // still reads, so it is kept in step with the rename here.
                $stmt = $conn->prepare("UPDATE equipment SET category = ? WHERE category_id = ?");
                $stmt->bind_param("si", $name, $category_id);
                $stmt->execute();
                $stmt->close();

                $notice = "Category updated.";
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Another category is already called \"" . $name . "\".";
            }
        }
    }

    // ----- Delete -----
    if ($action === 'delete') {
        $category_id = (int)($_POST['category_id'] ?? 0);
        $category    = findCategory($conn, $category_id);

        if (!$category) {
            $errors[] = "That category could not be found.";
        } else {
            // Deleting a category that products still point at would break the
            // foreign key, so the count is checked first and the admin is told
            // what to do instead of being shown a database error.
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM equipment WHERE category_id = ?");
            $stmt->bind_param("i", $category_id);
            $stmt->execute();
            $in_use = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();

            if ($in_use > 0) {
                $errors[] = "\"" . $category['name'] . "\" is still used by " . $in_use
                          . " product(s). Move those products to another category first.";
            } else {
                $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                $stmt->bind_param("i", $category_id);
                $stmt->execute();
                $stmt->close();
                $notice = "Category \"" . $category['name'] . "\" has been deleted.";
            }
        }
    }
}

$categories = getCategories($conn);

// ?edit=5 switches the form at the top from "Add" mode into "Edit" mode.
$editing = null;
if (isset($_GET['edit'])) {
    $editing = findCategory($conn, (int)$_GET['edit']);
    if (!$editing) {
        $errors[] = "That category could not be found.";
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= h(app_url('/css/shop.css')) ?>?v=1.0">

<section class="card">
    <h1>Manage Categories</h1>
    <p class="muted">Categories group the products in the equipment store and fill the filter on the store page.</p>
    <p><a class="btn btn-secondary" href="<?= h(app_url('/admin/equipment.php')) ?>">Back to Manage Equipment</a></p>
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
    <h2><?= $editing ? 'Edit Category' : 'Add Category' ?></h2>

    <!-- js-category-form switches on the checks in js/equipment.js.
         data-editing-id lets it allow a category to keep its own name. -->
    <form method="POST" action="<?= h(app_url('/admin/categories.php')) ?>"
          class="form-grid wide js-category-form"
          data-editing-id="<?= $editing ? (int)$editing['category_id'] : '' ?>">

        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="category_id" value="<?= (int)$editing['category_id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" maxlength="50" required
                   value="<?= h($editing['name'] ?? '') ?>">
        </div>

        <div class="form-group full-span">
            <label for="description">Description</label>
            <input type="text" id="description" name="description" maxlength="255"
                   value="<?= h($editing['description'] ?? '') ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn"><?= $editing ? 'Save Changes' : 'Add Category' ?></button>
        </div>

        <?php if ($editing): ?>
            <div class="form-actions">
                <a class="btn btn-secondary" href="<?= h(app_url('/admin/categories.php')) ?>">Cancel</a>
            </div>
        <?php endif; ?>
    </form>
</section>

<section class="card">
    <h2>All Categories</h2>

    <?php if (empty($categories)): ?>
        <div class="empty-state">No categories have been added yet.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table id="categoryTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <!-- data-category-* is read by js/equipment.js so a
                             duplicate name is caught before the form is sent. -->
                        <tr data-category-id="<?= (int)$category['category_id'] ?>"
                            data-category-name="<?= h($category['name']) ?>">

                            <td><strong><?= h($category['name']) ?></strong></td>
                            <td class="muted"><?= h($category['description'] ?: '-') ?></td>
                            <td><?= (int)$category['product_count'] ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-secondary"
                                       href="<?= h(app_url('/admin/categories.php?edit=' . (int)$category['category_id'])) ?>">Edit</a>

                                    <?php if ((int)$category['product_count'] === 0): ?>
                                        <form method="POST" action="<?= h(app_url('/admin/categories.php')) ?>"
                                              class="js-confirm"
                                              data-confirm="Delete the category &quot;<?= h($category['name']) ?>&quot;?">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="category_id" value="<?= (int)$category['category_id'] ?>">
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">In use</span>
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
