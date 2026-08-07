<?php
/**
 * equipment.php
 * Equipment store listing.
 *
 * Shows a short card for every product. Choosing an item opens
 * equipmentDetails.php, which is where the full description, the reviews and
 * the Add to Cart form live.
 *
 * Searching and filtering happen twice on purpose:
 *   - the PHP below builds the SQL, so the page works with JavaScript off and
 *     so a shared link like ?q=racquet&category=1 still returns the right list
 *   - js/equipment.js then filters the cards already on the page as the
 *     customer types, so results update without waiting for a reload
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

$sports     = getSportTypes($conn);
$categories = getCategories($conn);

// Search and filter values coming from the form in the URL.
$q               = trim($_GET['q'] ?? '');
$sport_filter    = isset($_GET['sport']) && $_GET['sport'] !== '' ? (int)$_GET['sport'] : 0;
$category_filter = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : 0;
$sort            = $_GET['sort'] ?? 'name_asc';

// Conditions are collected in an array and joined with AND at the end. Each
// value is bound as a parameter rather than pasted into the SQL text, so a
// search for O'Brien or for '; DROP TABLE is treated as plain text.
$where  = ["e.status = 'active'"];
$types  = '';
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

if ($category_filter > 0) {
    $where[] = "e.category_id = ?";
    $types .= 'i';
    $params[] = $category_filter;
}

// The sort value is never put into the SQL directly. It picks one of a fixed
// set of known-safe ORDER BY strings, because column names cannot be bound as
// parameters the way values can.
$order_by = "e.name ASC";
if ($sort === 'price_asc') {
    $order_by = "e.price ASC, e.name ASC";
} elseif ($sort === 'price_desc') {
    $order_by = "e.price DESC, e.name ASC";
} elseif ($sort === 'rating_desc') {
    $order_by = "avg_rating DESC, e.name ASC";
}

/*
 * The two small subqueries add the review count and average score for each
 * product. Doing it this way rather than with a JOIN plus GROUP BY keeps the
 * main query readable and avoids the row multiplication a JOIN would cause
 * when a product has several reviews.
 */
$sql =
    "SELECT e.*,
            st.name AS sport_name,
            c.name  AS category_name,
            (SELECT COUNT(*)   FROM equipment_reviews r WHERE r.equipment_id = e.equipment_id) AS review_count,
            (SELECT AVG(rating) FROM equipment_reviews r WHERE r.equipment_id = e.equipment_id) AS avg_rating
     FROM equipment e
     JOIN sport_types st ON e.sport_type_id = st.sport_type_id
     LEFT JOIN categories c ON e.category_id = c.category_id
     WHERE " . implode(" AND ", $where) . "
     ORDER BY " . $order_by;

$stmt = $conn->prepare($sql);
bindParams($stmt, $types, $params);
$stmt->execute();
$equipment = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= h(app_url('/css/shop.css')) ?>?v=1.0">

<section class="card">
    <h1>Equipment Store</h1>
    <p class="muted">Shop equipment for badminton, pickleball, and futsal.</p>
</section>

<section class="card">
    <form method="GET" action="<?= h(app_url('/shop/equipment.php')) ?>" class="form-grid">
        <div class="form-group">
            <label for="liveSearch">Search</label>
            <input type="search" id="liveSearch" name="q" value="<?= h($q) ?>"
                   placeholder="Racquet, ball, brand...">
        </div>

        <div class="form-group">
            <label for="liveCategory">Category</label>
            <select id="liveCategory" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int)$category['category_id'] ?>"
                        <?= (int)$category['category_id'] === $category_filter ? 'selected' : '' ?>>
                        <?= h($category['name']) ?> (<?= (int)$category['product_count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="sport">Sport Type</label>
            <select id="sport" name="sport">
                <option value="">All sports</option>
                <?php foreach ($sports as $sport): ?>
                    <option value="<?= (int)$sport['sport_type_id'] ?>"
                        <?= (int)$sport['sport_type_id'] === $sport_filter ? 'selected' : '' ?>>
                        <?= h($sport['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="liveSort">Sort</label>
            <select id="liveSort" name="sort">
                <option value="name_asc"    <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                <option value="price_asc"   <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price low to high</option>
                <option value="price_desc"  <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price high to low</option>
                <option value="rating_desc" <?= $sort === 'rating_desc' ? 'selected' : '' ?>>Highest rated</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Apply</button>
        </div>

        <?php if ($q !== '' || $sport_filter > 0 || $category_filter > 0 || $sort !== 'name_asc'): ?>
            <div class="form-actions">
                <a class="btn btn-secondary" href="<?= h(app_url('/shop/equipment.php')) ?>">Clear</a>
            </div>
        <?php endif; ?>

        <p class="result-count full-span" id="resultCount"></p>
    </form>
</section>

<?php if (empty($equipment)): ?>
    <section class="card">
        <div class="empty-state">No equipment matches your search.</div>
    </section>
<?php else: ?>

    <!-- Hidden by default; js/equipment.js shows this when live filtering
         removes every card without the page being reloaded. -->
    <section class="card" id="noResults" style="display: none;">
        <div class="empty-state">No products match what you typed.</div>
    </section>

    <section class="product-grid" id="productGrid">
        <?php foreach ($equipment as $item): ?>
            <?php
                $rating_count   = (int)$item['review_count'];
                $rating_average = $rating_count > 0 ? round((float)$item['avg_rating'], 1) : 0;
                $details_url    = app_url('/shop/equipmentDetails.php?id=' . (int)$item['equipment_id']);
            ?>
            <!-- The data-* attributes carry everything js/equipment.js needs to
                 search and sort these cards without asking the server again. -->
            <article class="card product-card"
                     data-name="<?= h($item['name']) ?>"
                     data-brand="<?= h($item['brand']) ?>"
                     data-category="<?= h($item['category_name'] ?? $item['category']) ?>"
                     data-sport="<?= h($item['sport_name']) ?>"
                     data-price="<?= h(number_format((float)$item['price'], 2, '.', '')) ?>"
                     data-rating="<?= h((string)$rating_average) ?>">

                <a href="<?= h($details_url) ?>">
                    <img class="product-image"
                         src="<?= h(equipmentImage($item['image_url'])) ?>"
                         alt="<?= h($item['name']) ?>">
                </a>

                <div>
                    <div class="tag"><?= h($item['sport_name']) ?></div>
                    <h2><a href="<?= h($details_url) ?>"><?= h($item['name']) ?></a></h2>
                    <p class="muted"><?= h($item['brand']) ?> &bull; <?= h($item['category_name'] ?? $item['category']) ?></p>

                    <div class="rating-line">
                        <?php if ($rating_count > 0): ?>
                            <span class="stars stars-small"><?= ratingStars($rating_average) ?></span>
                            <span class="muted"><?= h((string)$rating_average) ?> (<?= $rating_count ?>)</span>
                        <?php else: ?>
                            <span class="muted">No reviews yet</span>
                        <?php endif; ?>
                    </div>

                    <p class="product-desc"><?= h($item['description']) ?></p>
                </div>

                <div class="product-meta">
                    <strong><?= money($item['price']) ?></strong>
                    <?php if ((int)$item['stock'] > 0): ?>
                        <span class="stock-ok"><?= (int)$item['stock'] ?> in stock</span>
                    <?php else: ?>
                        <span class="stock-out">Out of stock</span>
                    <?php endif; ?>
                </div>

                <a class="btn" href="<?= h($details_url) ?>">View Details</a>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<script src="<?= h(app_url('/js/equipment.js')) ?>?v=1.0"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
