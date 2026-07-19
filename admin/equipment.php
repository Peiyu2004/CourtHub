<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

requireAdmin();

$sports = getSportTypes($conn);
$errors = [];
$notice = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $sport_type_id = (int)($_POST['sport_type_id'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
        $option_rows = parseOptionLines($_POST['options_text'] ?? '');

        if ($name === '') {
            $errors[] = "Equipment name is required.";
        }
        if ($category === '') {
            $errors[] = "Category is required.";
        }
        if ($price <= 0) {
            $errors[] = "Price must be more than zero.";
        }
        if ($stock < 0) {
            $errors[] = "Stock cannot be negative.";
        }

        $sport_exists = false;
        foreach ($sports as $sport) {
            if ((int)$sport['sport_type_id'] === $sport_type_id) {
                $sport_exists = true;
                break;
            }
        }
        if (!$sport_exists) {
            $errors[] = "Please choose a valid sport type.";
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO equipment
                     (name, sport_type_id, category, brand, price, stock, description, image_url)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sissdiss", $name, $sport_type_id, $category, $brand, $price, $stock, $description, $image_url);
                $stmt->execute();
                $equipment_id = $stmt->insert_id;
                $stmt->close();

                if (!empty($option_rows)) {
                    $option_stmt = $conn->prepare(
                        "INSERT INTO equipment_options (equipment_id, option_name, option_value)
                         VALUES (?, ?, ?)"
                    );
                    foreach ($option_rows as $row) {
                        [$option_name, $option_value] = $row;
                        $option_stmt->bind_param("iss", $equipment_id, $option_name, $option_value);
                        $option_stmt->execute();
                    }
                    $option_stmt->close();
                }

                $conn->commit();
                $notice = "Equipment added successfully.";
            } catch (Throwable $e) {
                $conn->rollback();
                $errors[] = "Equipment could not be added.";
            }
        }
    }

    if ($action === 'delete') {
        $equipment_id = (int)($_POST['equipment_id'] ?? 0);
        $stmt = $conn->prepare("SELECT name FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $equipment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$equipment) {
            $errors[] = "Equipment could not be found.";
        } else {
            $stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id = ?");
            $stmt->bind_param("i", $equipment_id);
            if ($stmt->execute()) {
                $notice = $equipment['name'] . " has been deleted from the store.";
            } else {
                $errors[] = "Equipment could not be deleted because it is linked to existing records.";
            }
            $stmt->close();
        }
    }
}

$equipment = [];
$result = $conn->query(
    "SELECT e.equipment_id, e.name, e.category, e.brand, e.price, e.stock, st.name AS sport_name,
            GROUP_CONCAT(CONCAT(eo.option_name, ': ', eo.option_value) ORDER BY eo.option_name, eo.option_value SEPARATOR '; ') AS options_summary
     FROM equipment e
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id
     LEFT JOIN equipment_options eo ON e.equipment_id = eo.equipment_id
     GROUP BY e.equipment_id, e.name, e.category, e.brand, e.price, e.stock, st.name
     ORDER BY e.created_at DESC, e.equipment_id DESC"
);
while ($row = $result->fetch_assoc()) {
    $equipment[] = $row;
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Manage Equipment</h1>
    <p class="muted">Add new equipment and variant choices, or remove items from the store.</p>
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
    <h2>Add Equipment</h2>
    <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>" class="form-grid wide">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" required>
        </div>
        <div class="form-group">
            <label for="sport_type_id">Sport Type</label>
            <select id="sport_type_id" name="sport_type_id" required>
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= (int)$sport['sport_type_id'] ?>"><?= h($sport['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="category">Category</label>
            <input type="text" id="category" name="category" placeholder="Racquet, Ball, Shoes" required>
        </div>
        <div class="form-group">
            <label for="brand">Brand</label>
            <input type="text" id="brand" name="brand">
        </div>
        <div class="form-group">
            <label for="price">Price (RM)</label>
            <input type="number" id="price" name="price" min="0.01" step="0.01" required>
        </div>
        <div class="form-group">
            <label for="stock">Stock</label>
            <input type="number" id="stock" name="stock" min="0" required>
        </div>
        <div class="form-group full-span">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"></textarea>
        </div>
        <div class="form-group full-span">
            <label for="image_url">Image URL</label>
            <input type="text" id="image_url" name="image_url">
        </div>
        <div class="form-group full-span">
            <label for="options_text">Variant Options</label>
            <textarea id="options_text" name="options_text" rows="4" placeholder="Model: Astrox 100ZZ, Astrox 99 Pro&#10;Grip Color: Red, Blue, Black"></textarea>
            <p class="muted">Use one option group per line: Option Name: Value 1, Value 2</p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add Equipment</button>
        </div>
    </form>
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
                        <th>Name</th>
                        <th>Sport</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Options</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipment as $item): ?>
                        <tr>
                            <td><strong><?= h($item['name']) ?></strong><br><span class="muted"><?= h($item['brand']) ?></span></td>
                            <td><?= h($item['sport_name']) ?></td>
                            <td><?= h($item['category']) ?></td>
                            <td><?= money($item['price']) ?></td>
                            <td><?= (int)$item['stock'] ?></td>
                            <td><?= h($item['options_summary'] ?: 'No variants') ?></td>
                            <td>
                                <form method="POST" action="<?= h(app_url('/admin/equipment.php')) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="equipment_id" value="<?= (int)$item['equipment_id'] ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
