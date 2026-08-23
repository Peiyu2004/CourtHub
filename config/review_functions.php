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
