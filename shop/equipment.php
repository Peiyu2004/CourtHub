<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

$sports = getSportTypes($conn);
$errors = [];
$notice = '';

function optionGroupsForEquipment($conn, $equipment_id) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
    requireLogin();

    $equipment_id = (int)($_POST['equipment_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $selected_options = $_POST['options'] ?? [];

    $stmt = $conn->prepare("SELECT equipment_id, name, stock FROM equipment WHERE equipment_id = ?");
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $equipment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$equipment) {
        $errors[] = "The selected item could not be found.";
    } elseif ($equipment['stock'] < $quantity) {
        $errors[] = "Not enough stock is available for " . h($equipment['name']) . ".";
    } else {
        $option_groups = optionGroupsForEquipment($conn, $equipment_id);
        $clean_options = [];
        foreach ($option_groups as $option_name => $values) {
            $chosen = $selected_options[$option_name] ?? '';
            if (!in_array($chosen, $values, true)) {
                $errors[] = "Please choose a valid " . h($option_name) . " for " . h($equipment['name']) . ".";
            } else {
                $clean_options[$option_name] = $chosen;
            }
        }

        if (empty($errors)) {
            $user_id = (int)$_SESSION['user_id'];
            $options_json = json_encode($clean_options);
            $stmt = $conn->prepare(
                "INSERT INTO cart_items (user_id, equipment_id, quantity, selected_options)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("iiis", $user_id, $equipment_id, $quantity, $options_json);
            $stmt->execute();
            $stmt->close();
            $notice = h($equipment['name']) . " has been added to your cart.";
        }
    }
}

$q = trim($_GET['q'] ?? '');
$sport_filter = isset($_GET['sport']) && $_GET['sport'] !== '' ? (int)$_GET['sport'] : 0;
$sort = $_GET['sort'] ?? 'name_asc';

$where = [];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(e.name LIKE ? OR e.description LIKE ? OR e.brand LIKE ? OR e.category LIKE ?)";
    $like = '%' . $q . '%';
    $types .= 'ssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($sport_filter > 0) {
    $where[] = "e.sport_type_id = ?";
    $types .= 'i';
    $params[] = $sport_filter;
}

$order_by = "e.name ASC";
if ($sort === 'price_asc') {
    $order_by = "e.price ASC, e.name ASC";
} elseif ($sort === 'price_desc') {
    $order_by = "e.price DESC, e.name ASC";
}

$sql =
    "SELECT e.*, st.name AS sport_name
     FROM equipment e
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY " . $order_by;

$stmt = $conn->prepare($sql);
bindParams($stmt, $types, $params);
$stmt->execute();
$equipment = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$options_by_equipment = [];
foreach ($equipment as $item) {
    $options_by_equipment[(int)$item['equipment_id']] = optionGroupsForEquipment($conn, (int)$item['equipment_id']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Equipment Store</h1>
    <p class="muted">Shop equipment for badminton, pickleball, and futsal.</p>
</section>

<?php if ($notice): ?>
    <div class="alert alert-success"><?= $notice ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card">
    <form method="GET" action="<?= h(app_url('/shop/equipment.php')) ?>" class="form-grid">
        <div class="form-group">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Racquet, ball, brand...">
        </div>
        <div class="form-group">
            <label for="sport">Sport Type</label>
            <select id="sport" name="sport">
                <option value="">All sports</option>
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= (int)$sport['sport_type_id'] ?>" <?= (int)$sport['sport_type_id'] === $sport_filter ? 'selected' : '' ?>>
                        <?= h($sport['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="sort">Sort</label>
            <select id="sort" name="sort">
                <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price low to high</option>
                <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price high to low</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Apply</button>
        </div>
    </form>
</section>

<?php if (empty($equipment)): ?>
    <section class="card">
        <div class="empty-state">No equipment matches your search.</div>
    </section>
<?php else: ?>
    <section class="product-grid">
        <?php foreach ($equipment as $item): ?>
            <?php $item_options = $options_by_equipment[(int)$item['equipment_id']] ?? []; ?>
            <article class="card product-card">
                <div>
                    <div class="tag"><?= h($item['sport_name']) ?></div>
                    <h2><?= h($item['name']) ?></h2>
                    <p class="muted"><?= h($item['brand']) ?> <?= h($item['category']) ?></p>
                    <p><?= h($item['description']) ?></p>
                </div>

                <div class="product-meta">
                    <strong><?= money($item['price']) ?></strong>
                    <span><?= (int)$item['stock'] ?> in stock</span>
                </div>

                <form method="POST" action="<?= h(app_url('/shop/equipment.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''))) ?>">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="equipment_id" value="<?= (int)$item['equipment_id'] ?>">

                    <?php foreach ($item_options as $option_name => $values): ?>
                        <div class="form-group compact">
                            <label><?= h($option_name) ?></label>
                            <select name="options[<?= h($option_name) ?>]" required>
                                <?php foreach ($values as $value): ?>
                                    <option value="<?= h($value) ?>"><?= h($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-group compact">
                        <label>Quantity</label>
                        <input type="number" name="quantity" value="1" min="1" max="<?= max(1, (int)$item['stock']) ?>">
                    </div>

                    <button type="submit" class="btn" <?= (int)$item['stock'] <= 0 ? 'disabled' : '' ?>>Add to Cart</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
