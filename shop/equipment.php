<?php
/**
 * equipment.php
 * Equipment store listing with a sidebar filter panel.
 *
 * Choosing an item opens equipmentDetails.php, which is where the full
 * description, the reviews and the Add to Cart form live.
 *
 * Filtering happens on two layers on purpose:
 *   - the PHP below builds the SQL from the URL, so the page still works with
 *     JavaScript switched off and a shared link reproduces the same list
 *   - js/equipment.js then filters the cards already on the page, so results
 *     update without waiting for a reload
 *
 * Category, sport and rating apply as soon as they are clicked. The price
 * range waits for the Filter button, because filtering while a number is
 * still half typed makes the list jump around ("5" then "50" then "500").
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

if (isset($_GET['clear_recent'])) {
    setcookie(RECENT_COOKIE, '', time() - 3600, '/', '', false, true);
    unset($_COOKIE[RECENT_COOKIE]);
    header("Location: " . app_url('/shop/equipment.php'));
    exit();
}

$sports     = getSportTypes($conn);
$categories = getCategories($conn);

$cart_errors = [];
$cart_notice = '';

// ---------------------------------------------------------------------
// Add to Cart straight from a product card
// The same addToCart() helper the details page uses, so the stock checks,
// the variant checks and the merging behave identically on both pages.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
    requireLogin();

    $cart_equipment_id = (int)($_POST['equipment_id'] ?? 0);
    $cart_quantity     = (int)($_POST['quantity'] ?? 1);

    $cart_errors = addToCart(
        $conn,
        (int)$_SESSION['user_id'],
        $cart_equipment_id,
        $cart_quantity,
        $_POST['options'] ?? []
    );

    if (empty($cart_errors)) {
        $cart_notice = "Item has been added to your shopping cart";
    }
}

// ---------------------------------------------------------------------
// Read the filter values out of the URL
// ---------------------------------------------------------------------
$q               = trim($_GET['q'] ?? '');
$category_filter = isset($_GET['category']) && $_GET['category'] !== '' ? (int)$_GET['category'] : 0;
$min_rating      = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$sort            = $_GET['sort'] ?? 'name_asc';

// Sport is a set of checkboxes, so it arrives as an array (sport[]).
// Everything is cast to int before it goes anywhere near the query.
$sport_filter = [];
if (isset($_GET['sport']) && is_array($_GET['sport'])) {
    foreach ($_GET['sport'] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $sport_filter[] = $id;
        }
    }
}

$filter_errors = [];

/**
 * Reads one end of the price range.
 *
 * A blank box means "no limit" and returns null. Anything that is not a
 * number is reported and then ignored, because casting straight to float
 * would turn "abc" into 0.0 - and a min and max of 0 would silently hide
 * every product instead of showing the shopper what went wrong.
 * is_finite() catches values like 1e999, which become INF once cast.
 */
function readPriceInput($key, $label, &$errors) {
    if (!isset($_GET[$key]) || trim($_GET[$key]) === '') {
        return null;
    }

    $raw = trim($_GET[$key]);
    if (!is_numeric($raw)) {
        $errors[] = $label . " price must be a number.";
        return null;
    }

    $value = (float)$raw;
    if (!is_finite($value)) {
        $errors[] = $label . " price is too large.";
        return null;
    }
    if ($value < 0) {
        $errors[] = $label . " price cannot be negative.";
        return null;
    }

    return $value;
}

$min_price = readPriceInput('min_price', 'Minimum', $filter_errors);
$max_price = readPriceInput('max_price', 'Maximum', $filter_errors);
// A reversed range is a slip rather than an error, so it is simply swapped
// round instead of refusing to show anything.
if ($min_price !== null && $max_price !== null && $min_price > $max_price) {
    $swap = $min_price;
    $min_price = $max_price;
    $max_price = $swap;
    $filter_errors[] = "The price range was the wrong way round, so it has been swapped.";
}

// ---------------------------------------------------------------------
// Build the query
// Conditions go into an array and are joined with AND. Every value is bound
// as a parameter rather than pasted into the SQL text.
// ---------------------------------------------------------------------
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

if ($category_filter > 0) {
    $where[] = "e.category_id = ?";
    $types .= 'i';
    $params[] = $category_filter;
}

/*
 * Several sports can be ticked at once, so the IN list has to grow to match.
 * One "?" is generated per chosen sport and the same number of 'i' letters is
 * added to the type string, which keeps every value bound instead of pasted
 * into the SQL.
 */
if (!empty($sport_filter)) {
    $placeholders = implode(',', array_fill(0, count($sport_filter), '?'));
    $where[] = "e.sport_type_id IN (" . $placeholders . ")";
    $types .= str_repeat('i', count($sport_filter));
    foreach ($sport_filter as $sport_id) {
        $params[] = $sport_id;
    }
}

if ($min_price !== null) {
    $where[] = "e.price >= ?";
    $types .= 'd';
    $params[] = $min_price;
}
if ($max_price !== null) {
    $where[] = "e.price <= ?";
    $types .= 'd';
    $params[] = $max_price;
}

/*
 * "4 stars & up" compares against the product's average review score.
 * COALESCE turns a product with no reviews at all into 0 rather than NULL,
 * so unreviewed products drop out of a rating filter instead of vanishing
 * from the comparison entirely.
 */
if ($min_rating > 0) {
    $where[] = "(SELECT COALESCE(AVG(r.rating), 0) FROM equipment_reviews r
                 WHERE r.equipment_id = e.equipment_id) >= ?";
    $types .= 'i';
    $params[] = $min_rating;
}

// The sort value picks one of a fixed set of known-safe ORDER BY strings.
// Column names cannot be bound as parameters the way values can.
$order_by = "e.name ASC";
if ($sort === 'price_asc') {
    $order_by = "e.price ASC, e.name ASC";
} elseif ($sort === 'price_desc') {
    $order_by = "e.price DESC, e.name ASC";
} elseif ($sort === 'rating_desc') {
    $order_by = "avg_rating DESC, e.name ASC";
}

// The stock a card shows is the product's variants added up, because that
// total is not stored on the product - the variants are the only place it
// lives. It answers "is this product worth opening", and nothing more: which
// size or colour is actually available is decided per combination, on the
// details page and again in addToCart().
$sql =
    "SELECT e.*,
            st.name AS sport_name,
            c.name  AS category_name,
            (SELECT COALESCE(SUM(v.stock), 0) FROM equipment_variants v
              WHERE v.equipment_id = e.equipment_id) AS stock,
            (SELECT COUNT(*)    FROM equipment_reviews r WHERE r.equipment_id = e.equipment_id) AS review_count,
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

// Variant choices for every product on the page, fetched in one query rather
// than one per card.
$listing_ids = [];
foreach ($equipment as $item) {
    $listing_ids[] = (int)$item['equipment_id'];
}
$options_by_equipment = getOptionGroupsForMany($conn, $listing_ids);

// The stock of every combination on the page, so a card can tell the shopper
// that the colour they just picked is empty without waiting for the POST to
// come back and say so.
$variant_stock_by_equipment = getVariantStockForMany($conn, $listing_ids);

// POSTing back to the same URL keeps the current filters after adding to the
// cart, instead of dumping the shopper back at the unfiltered list.
$post_target = app_url('/shop/equipment.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));

$has_filters =$q !== '' || $category_filter > 0 || !empty($sport_filter)
            || $min_price !== null || $max_price !== null || $min_rating > 0 || $sort !== 'name_asc';

$page_title = 'Equipment Store';
$extra_css = ['shop'];
require_once __DIR__ . '/../includes/header.php';
?>

<section class="card">
    <h1>Equipment Store</h1>
    <p class="muted">Shop equipment for badminton, pickleball, and futsal.</p>
</section>

<?php
// Success is shown as the fading popup instead of a green bar. Errors stay as
// a bar on purpose, because the customer needs time to read and fix those.
renderToast($cart_notice);
?>

<?php if (!empty($cart_errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($cart_errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($filter_errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($filter_errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="shop-layout">

    <!-- ==================== SIDEBAR ====================
         The filter form wraps only the sidebar. It cannot wrap the products
         as well, because each product card carries its own POST form for Add
         to Cart and a form inside another form is invalid HTML that browsers
         silently drop. The search and sort controls sit in the toolbar over
         on the right and join this form through their form="filterForm"
         attribute instead. -->
    <aside class="filter-sidebar">
    <form method="GET" action="<?= h(app_url('/shop/equipment.php')) ?>" id="filterForm">

        <!-- Each section can be folded away by clicking its heading. They all
             start open, and the folding is done by js/equipment.js, so with
             JavaScript switched off every section simply stays open and
             nothing is lost.
             type="button" matters: a plain <button> inside a form counts as a
             submit button, so without it every click would send the form. -->
        <div class="filter-block">
            <h3 class="filter-heading">
                <button type="button" class="filter-toggle" aria-expanded="true">
                    <span class="filter-icon">&#9776;</span>
                    <span class="filter-toggle-label">All Categories</span>
                    <span class="filter-chevron" aria-hidden="true"></span>
                </button>
            </h3>
            <div class="filter-body">
            <ul class="category-list">
                <li>
                    <label class="category-row">
                        <input type="radio" name="category" value="" <?= $category_filter === 0 ? 'checked' : '' ?>>
                        <span class="category-name">All Products</span>
                        <span class="category-count"><?= count($categories) ? array_sum(array_column($categories, 'product_count')) : 0 ?></span>
                    </label>
                </li>
                <?php foreach ($categories as $category): ?>
                    <li>
                        <label class="category-row">
                            <input type="radio" name="category" value="<?= (int)$category['category_id'] ?>"
                                <?= (int)$category['category_id'] === $category_filter ? 'checked' : '' ?>>
                            <span class="category-name"><?= h($category['name']) ?></span>
                            <span class="category-count"><?= (int)$category['product_count'] ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            </div>
        </div>

        <h3 class="filter-heading filter-heading-plain"><span class="filter-icon">&#9662;</span> SEARCH FILTER</h3>

        <div class="filter-block">
            <h4 class="filter-subheading">
                <button type="button" class="filter-toggle" aria-expanded="true">
                    <span class="filter-toggle-label">Sport Type</span>
                    <span class="filter-chevron" aria-hidden="true"></span>
                </button>
            </h4>
            <div class="filter-body">
                <?php foreach ($sports as $sport): ?>
                    <label class="check-row">
                        <input type="checkbox" name="sport[]" value="<?= (int)$sport['sport_type_id'] ?>"
                            <?= in_array((int)$sport['sport_type_id'], $sport_filter, true) ? 'checked' : '' ?>>
                        <span><?= h($sport['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-block">
            <h4 class="filter-subheading">
                <button type="button" class="filter-toggle" aria-expanded="true">
                    <span class="filter-toggle-label">Price Range</span>
                    <span class="filter-chevron" aria-hidden="true"></span>
                </button>
            </h4>
            <div class="filter-body">
            <div class="price-row">
                <input type="number" name="min_price" id="minPrice" min="0" step="0.01"
                       placeholder="Min" value="<?= $min_price !== null ? h((string)$min_price) : '' ?>">
                <span class="price-dash">&ndash;</span>
                <input type="number" name="max_price" id="maxPrice" min="0" step="0.01"
                       placeholder="Max" value="<?= $max_price !== null ? h((string)$max_price) : '' ?>">
            </div>
            <!-- A real submit button, so the whole form still works without
                 JavaScript. With JavaScript it applies the price range in place. -->
            <button type="submit" class="btn btn-block" id="applyPrice">Filter</button>
            </div>
        </div>

        <div class="filter-block">
            <h4 class="filter-subheading">
                <button type="button" class="filter-toggle" aria-expanded="true">
                    <span class="filter-toggle-label">Rating</span>
                    <span class="filter-chevron" aria-hidden="true"></span>
                </button>
            </h4>
            <div class="filter-body">
                <?php /* 5 down to 1, so "1 star & Up" is offered as well. */ ?>
                <?php for ($stars = 5; $stars >= 1; $stars--): ?>
                    <label class="rating-row">
                        <input type="radio" name="rating" value="<?= $stars ?>" <?= $min_rating === $stars ? 'checked' : '' ?>>
                        <span class="stars"><?= ratingStars($stars) ?></span>
                        <?php if ($stars < 5): ?><span class="rating-up">&amp; Up</span><?php endif; ?>
                    </label>
                <?php endfor; ?>
                <label class="rating-row">
                    <input type="radio" name="rating" value="0" <?= $min_rating === 0 ? 'checked' : '' ?>>
                    <span class="rating-any">Any rating</span>
                </label>
            </div>
        </div>

        <!-- A plain link, so it clears the filters even without JavaScript.
             With JavaScript it resets the controls without reloading. -->
        <a href="<?= h(app_url('/shop/equipment.php')) ?>"
           class="btn btn-secondary btn-block" id="clearFilters">Clear All</a>
    </form>
    </aside>

    <!-- ==================== PRODUCTS ==================== -->
    <div class="shop-main">

        <div class="card shop-toolbar">
            <!-- form="filterForm" attaches these to the sidebar form even
                 though they sit outside it in the page. -->
            <div class="form-group compact toolbar-search">
                <label for="liveSearch">Search</label>
                <input type="search" id="liveSearch" name="q" form="filterForm" value="<?= h($q) ?>"
                       placeholder="Racquet, ball, brand...">
            </div>
            <div class="form-group compact toolbar-sort">
                <label for="liveSort">Sort</label>
                <select id="liveSort" name="sort" form="filterForm">
                    <option value="name_asc"    <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="price_asc"   <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price low to high</option>
                    <option value="price_desc"  <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price high to low</option>
                    <option value="rating_desc" <?= $sort === 'rating_desc' ? 'selected' : '' ?>>Highest rated</option>
                </select>
            </div>
            <p class="result-count" id="resultCount"></p>
        </div>

        <?php if (empty($equipment)): ?>
            <section class="card">
                <div class="empty-state">
                    No equipment matches your filters.
                    <?php if ($has_filters): ?>
                        <a href="<?= h(app_url('/shop/equipment.php')) ?>">Clear all filters</a>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>

            <!-- Hidden by default; js/equipment.js shows this when live
                 filtering removes every card without a page reload. -->
            <section class="card is-hidden-initial" id="noResults">
                <div class="empty-state">No products match your filters.</div>
            </section>

            <section class="product-grid" id="productGrid">
                <?php foreach ($equipment as $item): ?>
                    <?php
                        $rating_count   = (int)$item['review_count'];
                        $rating_average = $rating_count > 0 ? round((float)$item['avg_rating'], 1) : 0;
                        $details_url    = app_url('/shop/equipmentDetails.php?id=' . (int)$item['equipment_id']);
                    ?>
                    <!-- The data-* attributes carry everything js/equipment.js
                         needs to filter and sort without asking the server. The
                         id attributes are what the dropdowns compare against;
                         the name attributes are what the search box reads. -->
                    <article class="card product-card"
                             data-name="<?= h($item['name']) ?>"
                             data-brand="<?= h($item['brand']) ?>"
                             data-category="<?= h($item['category_name'] ?? $item['category']) ?>"
                             data-category-id="<?= (int)$item['category_id'] ?>"
                             data-sport="<?= h($item['sport_name']) ?>"
                             data-sport-id="<?= (int)$item['sport_type_id'] ?>"
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

                        <?php
                            $item_options  = $options_by_equipment[(int)$item['equipment_id']] ?? [];
                            $item_variants = $variant_stock_by_equipment[(int)$item['equipment_id']] ?? [];
                            $in_stock = (int)$item['stock'] > 0;

                            // The combination the card's dropdowns start on, and the
                            // stock its note reports. Like the details page, they open
                            // on the first combination that has stock rather than on
                            // whichever one sorts first, so a card does not greet the
                            // shopper with a sold-out size on a product that has three
                            // other sizes on the shelf.
                            $card_initial = [];
                            foreach ($item_options as $option_name => $values) {
                                $card_initial[$option_name] = $values[0];
                            }
                            foreach ($item_variants as $variant_key => $variant_stock_left) {
                                if ($variant_stock_left > 0 && $variant_key !== '') {
                                    $card_initial = decodeVariantKey($variant_key);
                                    break;
                                }
                            }
                            $card_stock = $item_variants[variantKeyFor($card_initial)] ?? 0;
                        ?>

                        <div class="product-meta">
                            <strong><?= money($item['price']) ?></strong>
                            <?php if ($in_stock): ?>
                                <span class="stock-ok"><?= (int)$item['stock'] ?> in stock</span>
                            <?php else: ?>
                                <span class="stock-out">Out of stock</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$in_stock): ?>
                            <button type="button" class="btn btn-block" disabled>Out of Stock</button>

                        <?php elseif (!isLoggedIn()): ?>
                            <!-- Visitors are sent to log in first, because the cart
                                 belongs to an account. -->
                            <a class="btn btn-block" href="<?= h(app_url('/auth/login.php')) ?>">Add to Cart</a>

                        <?php else: ?>
                            <!-- Each card has its own POST form. Products with variant
                                 choices show their dropdowns here; js/equipment.js
                                 folds them away and the first click on Add to Cart
                                 opens them, so the grid stays tidy. With JavaScript
                                 off the dropdowns are simply already visible and the
                                 form works in one click. -->
                            <form method="POST" action="<?= h($post_target) ?>" class="card-cart-form"
                                  data-variants="<?= h(json_encode($item_variants)) ?>">
                                <input type="hidden" name="action" value="add_to_cart">
                                <input type="hidden" name="equipment_id" value="<?= (int)$item['equipment_id'] ?>">
                                <input type="hidden" name="quantity" value="1">

                                <?php if (!empty($item_options)): ?>
                                    <div class="card-options">
                                        <?php foreach ($item_options as $option_name => $values): ?>
                                            <div class="form-group compact">
                                                <label><?= h($option_name) ?></label>
                                                <select name="options[<?= h($option_name) ?>]"
                                                        class="js-variant-option"
                                                        data-option-name="<?= h($option_name) ?>" required>
                                                    <?php foreach ($values as $value): ?>
                                                        <option value="<?= h($value) ?>"
                                                            <?= ($card_initial[$option_name] ?? null) === $value ? 'selected' : '' ?>>
                                                            <?= h($value) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                        <p class="<?= $card_stock > 0 ? 'stock-ok' : 'stock-out' ?> js-variant-stock-note">
                                            <?= $card_stock > 0
                                                    ? (int)$card_stock . ' left in this combination'
                                                    : 'This combination is out of stock' ?>
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <!-- Never disabled here even when the starting
                                     combination is empty. On a card the dropdowns
                                     are folded away and it is this button that
                                     opens them, so disabling it would leave the
                                     shopper unable to reach the colour that is in
                                     stock. js/equipment.js takes over once they
                                     are open; with JavaScript off the choices are
                                     visible from the start and addToCart() is what
                                     turns an empty combination away. -->
                                <button type="submit" class="btn btn-block">Add to Cart</button>
                            </form>
                        <?php endif; ?>

                        <a class="btn btn-secondary btn-block" href="<?= h($details_url) ?>">View Details</a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php $recent = getRecentlyViewedProducts($conn); ?>
        <?php if (!empty($recent)): ?>
            <section class="card recent-strip">
                <div class="recent-head">
                    <h2>Recently Viewed</h2>
                    <a class="muted" href="<?= h(app_url('/shop/equipment.php?clear_recent=1')) ?>">Clear</a>
                </div>
                <div class="recent-list">
                    <?php foreach ($recent as $item): ?>
                        <a class="recent-item" href="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$item['equipment_id'])) ?>">
                            <img src="<?= h(equipmentImage($item['image_url'])) ?>" alt="<?= h($item['name']) ?>">
                            <span class="recent-name"><?= h($item['name']) ?></span>
                            <span class="recent-price"><?= money($item['price']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

</div>

<script src="<?= h(asset_url('/js/equipment.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
