<?php
/**
 * equipmentDetails.php
 * Full details for one product, plus the customer review section.
 *
 * The listing page (equipment.php) shows the short version of every product;
 * this page shows one product in full and is where the item is actually added
 * to the cart. Reviews are created, edited and deleted here too.
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/equipment_functions.php';

$errors = [];
$notice = '';

// The product is chosen by ?id= in the URL. Casting to int means a tampered
// value like ?id=5;DROP simply becomes 5, and the prepared statement below
// never sees the rest of it.
$equipment_id = (int)($_GET['id'] ?? 0);

// ---------------------------------------------------------------------
// Form handling
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $equipment_id = (int)($_POST['equipment_id'] ?? $equipment_id);

    if ($action === 'add_to_cart') {
        requireLogin();
        $quantity = (int)($_POST['quantity'] ?? 1);
        $errors = addToCart(
            $conn,
            (int)$_SESSION['user_id'],
            $equipment_id,
            $quantity,
            $_POST['options'] ?? []
        );
        if (empty($errors)) {
            $notice = "Item has been added to your shopping cart";
        }
    }

    if ($action === 'save_review') {
        requireLogin();
        $user_id = (int)$_SESSION['user_id'];
        $rating  = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        // Same rules the JavaScript checks, run again here because the
        // JavaScript can be bypassed but this cannot.
        $errors = validateReviewInput($rating, $comment);

        if (empty($errors)) {
            // The table has UNIQUE (equipment_id, user_id), so a customer can
            // only hold one review per product. Look for theirs first and
            // decide between UPDATE and INSERT from that.
            $stmt = $conn->prepare(
                "SELECT review_id FROM equipment_reviews WHERE equipment_id = ? AND user_id = ?"
            );
            $stmt->bind_param("ii", $equipment_id, $user_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            try {
                if ($existing) {
                    $stmt = $conn->prepare(
                        "UPDATE equipment_reviews SET rating = ?, comment = ?
                         WHERE review_id = ? AND user_id = ?"
                    );
                    $stmt->bind_param("isii", $rating, $comment, $existing['review_id'], $user_id);
                    $stmt->execute();
                    $stmt->close();
                    $notice = "Your review has been updated.";
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO equipment_reviews (equipment_id, user_id, rating, comment)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->bind_param("iiis", $equipment_id, $user_id, $rating, $comment);
                    $stmt->execute();
                    $stmt->close();
                    $notice = "Thank you, your review has been posted.";
                }
            } catch (mysqli_sql_exception $e) {
                // Reached if the product id does not exist (foreign key) or if
                // two submissions race each other onto the same unique key.
                $errors[] = "Your review could not be saved. Please try again.";
            }
        }
    }

    if ($action === 'delete_review') {
        requireLogin();
        $user_id   = (int)$_SESSION['user_id'];
        $review_id = (int)($_POST['review_id'] ?? 0);

        // A customer may delete their own review; an admin may delete any of
        // them. Putting user_id in the WHERE clause for customers is what stops
        // someone deleting another person's review by editing the form.
        if (isAdmin()) {
            $stmt = $conn->prepare("DELETE FROM equipment_reviews WHERE review_id = ?");
            $stmt->bind_param("i", $review_id);
        } else {
            $stmt = $conn->prepare("DELETE FROM equipment_reviews WHERE review_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $review_id, $user_id);
        }
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $notice = $deleted > 0
            ? "The review has been deleted."
            : "";
        if ($deleted === 0) {
            $errors[] = "That review could not be deleted.";
        }
    }
}

// ---------------------------------------------------------------------
// Load the product
// ---------------------------------------------------------------------
$product = null;
if ($equipment_id > 0) {
    $stmt = $conn->prepare(
        "SELECT e.*, st.name AS sport_name, c.name AS category_name
         FROM equipment e
         JOIN sport_types st ON e.sport_type_id = st.sport_type_id
         LEFT JOIN categories c ON e.category_id = c.category_id
         WHERE e.equipment_id = ?"
    );
    $stmt->bind_param("i", $equipment_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// A missing product is a normal thing to happen (an old link, a deleted item),
// so it gets a friendly page instead of a crash or a blank screen.
if (!$product) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<link rel="stylesheet" href="' . h(app_url('/css/shop.css')) . '?v=1.0">';
    ?>
    <section class="card">
        <h1>Product not found</h1>
        <p class="muted">This item may have been removed from the store, or the link is incorrect.</p>
        <p><a class="btn" href="<?= h(app_url('/shop/equipment.php')) ?>">Back to Equipment Store</a></p>
    </section>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit();
}

$option_groups = getEquipmentOptionGroups($conn, $equipment_id);
$reviews       = getEquipmentReviews($conn, $equipment_id);
$summary       = reviewSummary($reviews);
$breakdown     = ratingBreakdown($reviews);
$purchasers    = purchaserIdsFor($conn, $equipment_id);

// Find the logged-in customer's own review so the form can pre-fill and the
// heading can say "Edit your review" rather than "Write a review".
$my_review = null;
if (isLoggedIn()) {
    foreach ($reviews as $review) {
        if ((int)$review['user_id'] === (int)$_SESSION['user_id']) {
            $my_review = $review;
            break;
        }
    }
}

// Other products in the same category, so the page has somewhere to go next.
$related = [];
if (!empty($product['category_id'])) {
    $stmt = $conn->prepare(
        "SELECT equipment_id, name, price, image_url
         FROM equipment
         WHERE category_id = ? AND equipment_id <> ? AND status = 'active'
         ORDER BY name
         LIMIT 3"
    );
    $stmt->bind_param("ii", $product['category_id'], $equipment_id);
    $stmt->execute();
    $related = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$in_stock = (int)$product['stock'] > 0 && $product['status'] === 'active';

require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= h(app_url('/css/shop.css')) ?>?v=1.0">

<section class="card">
    <p class="muted">
        <a href="<?= h(app_url('/shop/equipment.php')) ?>">Equipment Store</a>
        &rsaquo; <?= h($product['category_name'] ?? $product['category']) ?>
    </p>
</section>

<?php
// Success is shown as the fading popup instead of a green bar. Errors stay as
// a bar on purpose, because the customer needs time to read and fix those.
renderToast($notice);
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="card">
    <div class="detail-layout">

        <div>
            <img class="detail-image"
                 src="<?= h(equipmentImage($product['image_url'])) ?>"
                 alt="<?= h($product['name']) ?>">
        </div>

        <div>
            <div class="tag"><?= h($product['sport_name']) ?></div>
            <h1><?= h($product['name']) ?></h1>
            <p class="muted"><?= h($product['brand']) ?> &bull; <?= h($product['category_name'] ?? $product['category']) ?></p>

            <div class="rating-line">
                <?php if ($summary['count'] > 0): ?>
                    <span class="stars"><?= ratingStars($summary['average']) ?></span>
                    <span><strong><?= h((string)$summary['average']) ?></strong> out of 5</span>
                    <span class="muted">(<?= (int)$summary['count'] ?> review<?= $summary['count'] === 1 ? '' : 's' ?>)</span>
                <?php else: ?>
                    <span class="muted">No reviews yet</span>
                <?php endif; ?>
            </div>

            <p class="detail-price"><?= money($product['price']) ?></p>

            <ul class="spec-list">
                <li><span>Brand</span><span><?= h($product['brand'] ?: 'Not specified') ?></span></li>
                <li><span>Sport</span><span><?= h($product['sport_name']) ?></span></li>
                <li><span>Category</span><span><?= h($product['category_name'] ?? $product['category']) ?></span></li>
                <li>
                    <span>Availability</span>
                    <span class="<?= $in_stock ? 'stock-ok' : 'stock-out' ?>">
                        <?= $in_stock ? (int)$product['stock'] . ' in stock' : 'Out of stock' ?>
                    </span>
                </li>
            </ul>

            <p><?= nl2br(h($product['description'])) ?></p>

            <?php if (!isLoggedIn()): ?>
                <div class="empty-state">
                    Please <a href="<?= h(app_url('/auth/login.php')) ?>">log in</a> to add this item to your cart.
                </div>
            <?php elseif (!$in_stock): ?>
                <div class="empty-state">This item is currently unavailable.</div>
            <?php else: ?>
                <!-- data-* values are read by js/equipment.js to bound the
                     quantity stepper and work out the live line total -->
                <form method="POST"
                      action="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$equipment_id)) ?>"
                      id="addToCartForm"
                      data-stock="<?= (int)$product['stock'] ?>"
                      data-price="<?= h(number_format((float)$product['price'], 2, '.', '')) ?>">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="equipment_id" value="<?= (int)$equipment_id ?>">

                    <?php foreach ($option_groups as $option_name => $values): ?>
                        <div class="form-group compact">
                            <label for="opt-<?= h(str_replace(' ', '-', $option_name)) ?>"><?= h($option_name) ?></label>
                            <select id="opt-<?= h(str_replace(' ', '-', $option_name)) ?>"
                                    name="options[<?= h($option_name) ?>]" required>
                                <?php foreach ($values as $value): ?>
                                    <option value="<?= h($value) ?>"><?= h($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-group compact">
                        <label for="quantity">Quantity</label>
                        <div class="qty-control">
                            <button type="button" id="qtyDown" aria-label="Decrease quantity">&minus;</button>
                            <input type="number" id="quantity" name="quantity"
                                   value="1" min="1" max="<?= (int)$product['stock'] ?>" required>
                            <button type="button" id="qtyUp" aria-label="Increase quantity">+</button>
                        </div>
                    </div>

                    <p class="line-total">Total: <strong id="lineTotal"><?= money($product['price']) ?></strong></p>

                    <button type="submit" class="btn">Add to Cart</button>
                </form>
            <?php endif; ?>
        </div>

    </div>
</section>

<!-- ---------------- Reviews ---------------- -->
<section class="card">
    <h2>Customer Reviews</h2>

    <?php if ($summary['count'] > 0): ?>
        <div class="rating-line">
            <span class="rating-score"><?= h((string)$summary['average']) ?></span>
            <span class="stars"><?= ratingStars($summary['average']) ?></span>
            <span class="muted">based on <?= (int)$summary['count'] ?> review<?= $summary['count'] === 1 ? '' : 's' ?></span>
        </div>

        <?php foreach ($breakdown as $stars => $count): ?>
            <div class="breakdown-row">
                <span><?= (int)$stars ?> star</span>
                <span class="breakdown-track">
                    <span class="breakdown-fill"
                          style="width: <?= $summary['count'] > 0 ? round(($count / $summary['count']) * 100) : 0 ?>%"></span>
                </span>
                <span><?= (int)$count ?></span>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">No reviews yet. Be the first to review this product.</div>
    <?php endif; ?>
</section>

<?php if (isLoggedIn()): ?>
    <section class="card">
        <h2><?= $my_review ? 'Edit your review' : 'Write a review' ?></h2>

        <!-- data-min / data-max let equipment.js apply the same length rules
             the PHP validateReviewInput() function applies on the server -->
        <form method="POST"
              action="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$equipment_id)) ?>"
              id="reviewForm"
              data-min="<?= REVIEW_COMMENT_MIN ?>"
              data-max="<?= REVIEW_COMMENT_MAX ?>">
            <input type="hidden" name="action" value="save_review">
            <input type="hidden" name="equipment_id" value="<?= (int)$equipment_id ?>">

            <div class="form-group">
                <label for="ratingInput">Your rating</label>
                <!-- The star buttons are built and handled by equipment.js.
                     The number input underneath is what actually gets posted,
                     so the form still works with JavaScript turned off. -->
                <div class="star-picker" id="starPicker"></div>
                <input type="number" id="ratingInput" name="rating" min="1" max="5" required
                       value="<?= $my_review ? (int)$my_review['rating'] : '' ?>">
            </div>

            <div class="form-group">
                <label for="comment">Your comment</label>
                <textarea id="comment" name="comment" rows="4"
                          placeholder="What did you think of this product?"><?= h($my_review['comment'] ?? '') ?></textarea>
                <div class="char-counter" id="charCounter"></div>
            </div>

            <button type="submit" class="btn"><?= $my_review ? 'Update Review' : 'Post Review' ?></button>
        </form>
    </section>
<?php else: ?>
    <section class="card">
        <div class="empty-state">
            <a href="<?= h(app_url('/auth/login.php')) ?>">Log in</a> to write a review for this product.
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($reviews)): ?>
    <section class="card">
        <h2>What customers said</h2>
        <?php foreach ($reviews as $review): ?>
            <?php
                $is_mine  = isLoggedIn() && (int)$review['user_id'] === (int)$_SESSION['user_id'];
                $verified = in_array((int)$review['user_id'], $purchasers, true);
            ?>
            <article class="review">
                <div class="review-head">
                    <div>
                        <span class="review-author"><?= h($review['full_name']) ?></span>
                        <?php if ($is_mine): ?><span class="you-badge">You</span><?php endif; ?>
                        <?php if ($verified): ?><span class="you-badge">Verified purchase</span><?php endif; ?>
                    </div>
                    <span class="review-date"><?= h(date('j M Y', strtotime($review['created_at']))) ?></span>
                </div>

                <div class="stars stars-small"><?= ratingStars($review['rating']) ?></div>
                <p class="review-body"><?= h($review['comment']) ?></p>

                <?php if ($is_mine || isAdmin()): ?>
                    <div class="review-actions">
                        <form method="POST"
                              action="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$equipment_id)) ?>"
                              class="js-confirm"
                              data-confirm="Delete this review? This cannot be undone.">
                            <input type="hidden" name="action" value="delete_review">
                            <input type="hidden" name="review_id" value="<?= (int)$review['review_id'] ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (!empty($related)): ?>
    <section class="card">
        <h2>More in <?= h($product['category_name'] ?? $product['category']) ?></h2>
        <div class="product-grid">
            <?php foreach ($related as $item): ?>
                <article class="card product-card">
                    <img class="product-image"
                         src="<?= h(equipmentImage($item['image_url'])) ?>"
                         alt="<?= h($item['name']) ?>">
                    <h2><?= h($item['name']) ?></h2>
                    <div class="product-meta">
                        <strong><?= money($item['price']) ?></strong>
                    </div>
                    <a class="btn"
                       href="<?= h(app_url('/shop/equipmentDetails.php?id=' . (int)$item['equipment_id'])) ?>">
                        View Details
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<script src="<?= h(app_url('/js/equipment.js')) ?>?v=1.0"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
