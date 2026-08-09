-- =====================================================================
-- CourtHub Sport Center Database Schema
-- Badminton / Pickleball / Futsal Court Reservation + Equipment Store
-- UECS2094/UECS2194/EECS2194 Web Application Development
-- =====================================================================
--
-- Every table below is declared ENGINE=InnoDB on purpose.
-- WAMP ships with default_storage_engine = MyISAM, and MyISAM accepts
-- FOREIGN KEY and ROLLBACK without error but then ignores both. Tested on
-- this server: with MyISAM a review could be inserted for a user_id that
-- does not exist, and a ROLLBACK left the inserted row in place. InnoDB is
-- what actually enforces the relationships and makes the transactions in
-- checkout.php, search.php and admin/equipment.php real.
--
-- WARNING: the DROP below wipes the database so the file imports cleanly
-- every time. Remove that line if you need to keep existing data.
-- =====================================================================

DROP DATABASE IF EXISTS courthub_db;
CREATE DATABASE courthub_db;
USE courthub_db;

-- ---------------------------------------------------------------------
-- 1. USERS
-- Single login page for both customer and admin, redirected by role
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(100) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    phone           VARCHAR(20),
    role            ENUM('customer', 'admin') NOT NULL DEFAULT 'customer',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. SPORT_TYPES
-- Lookup table for the 3 sports and their hourly rate (req 5)
-- Keeping price here (not hardcoded in PHP) means admin could adjust
-- pricing later without touching code.
-- ---------------------------------------------------------------------
CREATE TABLE sport_types (
    sport_type_id   INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(30) NOT NULL UNIQUE,   -- 'Badminton', 'Pickleball', 'Futsal'
    price_per_hour  DECIMAL(6,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO sport_types (name, price_per_hour) VALUES
('Badminton', 30.00),
('Pickleball', 50.00),
('Futsal', 120.00);

-- ---------------------------------------------------------------------
-- 3. COURTS
-- 6 badminton + 6 pickleball + 2 futsal (req 1)
-- status handles the soft-delete workflow (req 8):
--   'active'           -> normal, bookable
--   'pending_deletion' -> admin deleted it, but it has future bookings.
--                         No NEW reservations accepted, but existing
--                         bookings on it are still honoured.
--   'deleted'          -> fully removed from display (all its bookings
--                         have been completed/passed). Row is kept
--                         (not hard-deleted) to preserve booking history
--                         via foreign key.
-- ---------------------------------------------------------------------
CREATE TABLE courts (
    court_id        INT AUTO_INCREMENT PRIMARY KEY,
    court_number    VARCHAR(30) NOT NULL,
    sport_type_id   INT NOT NULL,
    status          ENUM('active', 'pending_deletion', 'deleted') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (sport_type_id) REFERENCES sport_types(sport_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. BOOKING_ORDERS
-- One payment transaction that can cover multiple courts (req 6).
-- Reservations cannot be modified after payment (req 3) - the app
-- simply never exposes an "edit" action once payment_status = 'paid'.
-- ---------------------------------------------------------------------
CREATE TABLE booking_orders (
    booking_order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    total_amount     DECIMAL(8,2) NOT NULL,
    payment_method   ENUM('tng_ewallet', 'credit_debit_card') NOT NULL,
    payment_status   ENUM('paid', 'failed') NOT NULL DEFAULT 'paid', -- simulated, so no real 'pending' gateway state
    transaction_ref  VARCHAR(50),          -- simulated reference number, e.g. TNG-20260709-0001
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. BOOKING_ORDER_ITEMS
-- Each individual court reservation within an order.
-- Storing date/start_time/end_time per item (not just once per order)
-- keeps the design flexible even though the current UI flow has the
-- user pick one date/time slot and multiple courts at once.
-- ---------------------------------------------------------------------
CREATE TABLE booking_order_items (
    booking_item_id  INT AUTO_INCREMENT PRIMARY KEY,
    booking_order_id INT NOT NULL,
    court_id         INT NOT NULL,
    booking_date     DATE NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    price            DECIMAL(8,2) NOT NULL,   -- duration_hours * sport_types.price_per_hour, snapshotted

    FOREIGN KEY (booking_order_id) REFERENCES booking_orders(booking_order_id) ON DELETE CASCADE,
    FOREIGN KEY (court_id)         REFERENCES courts(court_id),

    -- The PHP booking flow checks overlapping time ranges before insert.
    -- These indexes keep the overlap checks fast for flexible start/end times.
    INDEX idx_booking_overlap (court_id, booking_date, start_time, end_time),
    UNIQUE KEY unique_exact_start (court_id, booking_date, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. CATEGORIES
-- Lookup table for equipment categories (Racquet, Ball, Shoes, ...).
-- Before this table the category was free text typed by the admin, so
-- "Racquet" and "racquet" became two different categories and the filter
-- could not group them. Storing them here means the admin picks from a
-- list instead of typing, and categories can be renamed in one place.
-- ---------------------------------------------------------------------
CREATE TABLE categories (
    category_id     INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50) NOT NULL UNIQUE,
    description     VARCHAR(255),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. EQUIPMENT
-- Base product (req 10). Stock/price shared across all variant choices.
--
-- Note on the two category columns:
--   category_id -> the proper foreign key, used by the store and admin pages
--   category    -> the old text column, kept and written to at the same time
-- The cart page still reads e.category, so keeping both columns in sync lets
-- the new category system work without changing any other module's queries.
--
-- status handles removal the same way courts do:
--   'active'       -> shown in the store, can be bought
--   'discontinued' -> hidden from the store, but the row stays so existing
--                     carts and past orders still resolve their product name
-- ---------------------------------------------------------------------
CREATE TABLE equipment (
    equipment_id    INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    sport_type_id   INT NOT NULL,
    category_id     INT,
    category        VARCHAR(50) NOT NULL,     -- kept in sync with categories.name
    brand           VARCHAR(50),
    price           DECIMAL(8,2) NOT NULL,
    stock           INT NOT NULL DEFAULT 0,
    description     TEXT,
    image_url       VARCHAR(255),
    status          ENUM('active', 'discontinued') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (sport_type_id) REFERENCES sport_types(sport_type_id),
    FOREIGN KEY (category_id)   REFERENCES categories(category_id),

    -- The store searches with LIKE on name/brand and filters on sport +
    -- category, so these are the columns worth indexing.
    INDEX idx_equipment_category (category_id),
    INDEX idx_equipment_sport (sport_type_id),
    INDEX idx_equipment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 8. EQUIPMENT_OPTIONS
-- Independent variant selectors per product (req 14).
-- e.g. (equipment_id=1, option_name='Model', option_value='Astrox 100ZZ')
--      (equipment_id=1, option_name='Model', option_value='Nanoflare 800')
--      (equipment_id=1, option_name='Grip Color', option_value='Red')
-- The front-end groups rows by option_name to build each dropdown.
-- ---------------------------------------------------------------------
CREATE TABLE equipment_options (
    option_id       INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id    INT NOT NULL,
    option_name     VARCHAR(50) NOT NULL,     -- 'Model', 'Grip Color', 'Size'
    option_value    VARCHAR(100) NOT NULL,

    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 9. CART_ITEMS
-- Shopping cart before checkout (req 12). Selected variant choices are
-- stored as JSON, e.g. {"Model":"Astrox 100ZZ","Grip Color":"Red"}
-- ---------------------------------------------------------------------
CREATE TABLE cart_items (
    cart_id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    equipment_id      INT NOT NULL,
    quantity          INT NOT NULL DEFAULT 1,
    selected_options  JSON,
    added_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)      REFERENCES users(user_id)         ON DELETE CASCADE,
    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 10. EQUIPMENT_ORDERS / EQUIPMENT_ORDER_ITEMS
-- Confirmed equipment purchases, separate from court booking orders
-- since they're conceptually different transactions.
--
-- Two different statuses live on an order and they answer two different
-- questions, so neither can stand in for the other:
--
--   payment_status  did the money go through?
--   order_status    has the customer walked in and taken the goods?
--
-- Equipment is collected in person - the shop delivers nothing - so an
-- order stays 'pending' from the moment it is paid for until an admin
-- hands the items over at the counter and marks it 'completed'.
-- collected_at records when that happened, and is NULL for as long as the
-- order is still waiting to be picked up.
-- ---------------------------------------------------------------------
CREATE TABLE equipment_orders (
    equipment_order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    total_amount        DECIMAL(8,2) NOT NULL,
    payment_method      ENUM('tng_ewallet', 'credit_debit_card') NOT NULL,
    payment_status      ENUM('paid', 'failed') NOT NULL DEFAULT 'paid',
    order_status        ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    transaction_ref     VARCHAR(50),
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    collected_at        DATETIME NULL,           -- set when order_status becomes 'completed'

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,

    -- The admin order list opens on "waiting for collection", so the
    -- status is what it filters and sorts on most.
    INDEX idx_equipment_order_status (order_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE equipment_order_items (
    order_item_id       INT AUTO_INCREMENT PRIMARY KEY,
    equipment_order_id   INT NOT NULL,
    equipment_id         INT NULL,
    quantity             INT NOT NULL,
    price_at_purchase    DECIMAL(8,2) NOT NULL,
    selected_options     JSON,

    FOREIGN KEY (equipment_order_id) REFERENCES equipment_orders(equipment_order_id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id)       REFERENCES equipment(equipment_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 11. EQUIPMENT_REVIEWS
-- Customer reviews shown on the product details page.
-- rating is 1 to 5. MySQL will happily store 9 here, so the 1-5 range is
-- checked in JavaScript before submitting and again in PHP before the
-- INSERT runs - the JavaScript check is only for convenience, because a
-- user can bypass it, so PHP is the one that actually protects the data.
--
-- UNIQUE (equipment_id, user_id) enforces one review per customer per
-- product, which is why the details page offers "edit your review"
-- instead of letting the same person post twice.
-- ---------------------------------------------------------------------
CREATE TABLE equipment_reviews (
    review_id       INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id    INT NOT NULL,
    user_id         INT NOT NULL,
    rating          TINYINT NOT NULL,          -- 1 to 5
    comment         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(user_id)          ON DELETE CASCADE,

    UNIQUE KEY unique_user_review (equipment_id, user_id),
    INDEX idx_review_equipment (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 10. CONTACT MESSAGES
-- Stores customer enquiries from Contact Us page
-- ---------------------------------------------------------------------

CREATE TABLE contact_messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =====================================================================
-- SAMPLE DATA
-- =====================================================================

-- Users (use PHP password_hash() to generate real hashes at registration time)
-- Sample login password for every seed account: password123
-- The extra customers exist so the product reviews below have different
-- authors, which is what makes the reviewer-name JOIN visible on screen.
INSERT INTO users (full_name, email, password_hash, phone, role) VALUES
('Admin User', 'admin@courthub.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0123456789', 'admin'),
('Tan Rui Han', 'tanrh@example.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0198765432', 'customer'),
('Lim Pei Yu', 'limpy@example.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0161234567', 'customer'),
('Wong Wei Xin', 'wongwx@example.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0172345678', 'customer'),
('Kau Yong Jun', 'kauyj@example.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0183456789', 'customer');

-- Courts: 6 badminton, 6 pickleball, 2 futsal
INSERT INTO courts (court_number, sport_type_id, status) VALUES
('Badminton Court 1', 1, 'active'),
('Badminton Court 2', 1, 'active'),
('Badminton Court 3', 1, 'active'),
('Badminton Court 4', 1, 'active'),
('Badminton Court 5', 1, 'active'),
('Badminton Court 6', 1, 'active'),
('Pickleball Court 1', 2, 'active'),
('Pickleball Court 2', 2, 'active'),
('Pickleball Court 3', 2, 'active'),
('Pickleball Court 4', 2, 'active'),
('Pickleball Court 5', 2, 'active'),
('Pickleball Court 6', 2, 'active'),
('Futsal Court A', 3, 'active'),
('Futsal Court B', 3, 'active');

-- Categories (category_id 1 to 10, in this exact order)
INSERT INTO categories (name, description) VALUES
('Racquet',     'Badminton racquets and frames'),
('Paddle',      'Pickleball paddles'),
('Ball',        'Futsal balls and outdoor pickleballs'),
('Shuttlecock', 'Feather and nylon shuttlecocks'),
('Grip',        'Overgrips and replacement grips'),
('String',      'Racquet strings and reels'),
('Bag',         'Racquet bags and backpacks'),
('Apparel',     'Jerseys, shirts and sportswear'),
('Footwear',    'Indoor court shoes and boots'),
('Drinks',      'Isotonic drinks and mineral water');

-- Equipment (equipment_id 1 to 14, in this exact order)
-- category_id and category are written together so the store can filter on
-- the foreign key while the cart page keeps reading the text column.
-- Two deliberate cases in this data:
--   'Court Grip Tape' has stock 0    -> shows the disabled "Add to Cart" state
--   the 'Drinks' category has no rows -> shows the "no results" empty state
INSERT INTO equipment (name, sport_type_id, category_id, category, brand, price, stock, description, image_url) VALUES
('Astrox 100ZZ Racquet',          1,  1, 'Racquet',     'Yonex',   899.00,  20, 'Head-heavy badminton racquet built for steep, powerful smashes. Suits attacking singles players.', 'images/badminton.jpg'),
('Nanoflare 800 Pro Racquet',     1,  1, 'Racquet',     'Yonex',   949.00,  12, 'Head-light frame with a fast swing for quick doubles exchanges and rapid defence.', 'images/badminton.jpg'),
('Aerosensa 50 Shuttlecock Tube', 1,  4, 'Shuttlecock', 'Yonex',   145.00,  60, 'Tournament grade goose feather shuttlecocks. One tube contains 12 shuttles.', 'images/badminton.jpg'),
('Super Grap Overgrip 3-Pack',    1,  5, 'Grip',        'Yonex',    25.00, 100, 'Tacky overgrip that absorbs sweat well. Three rolls per pack.', 'images/equipment.jpg'),
('BG66 Ultimax String',           1,  6, 'String',      'Yonex',    38.00,  80, 'Thin 0.65mm gauge string for a crisp, high-pitched repulsion feel.', 'images/equipment.jpg'),
('Power Cushion 65Z3 Shoes',      1,  9, 'Footwear',    'Yonex',   459.00,  18, 'Indoor court shoes with cushioned heel support for repeated lunging.', 'images/equipment.jpg'),
('Pro Tournament Racquet Bag',    1,  7, 'Bag',         'Yonex',   289.00,  22, 'Six-racquet thermal bag with a separate ventilated shoe compartment.', 'images/equipment.jpg'),
('Vanguard Power Air Paddle',     2,  2, 'Paddle',      'Selkirk', 450.00,  15, 'Carbon fibre pickleball paddle with a large sweet spot and quiet core.', 'images/pickleball.jpg'),
('Amped Epic Paddle',             2,  2, 'Paddle',      'Selkirk', 389.00,  10, 'Balanced control paddle with an elongated handle for two-handed backhands.', 'images/pickleball.jpg'),
('Dura Fast 40 Balls 6-Pack',     2,  3, 'Ball',        'Onix',     69.00,  40, 'Outdoor pickleballs with 40 precision drilled holes for consistent flight.', 'images/pickleball.jpg'),
('Court Grip Tape',               2,  5, 'Grip',        'Selkirk',  19.00,   0, 'Replacement grip tape for pickleball paddle handles. Currently out of stock.', 'images/equipment.jpg'),
('Premier Futsal Match Ball',     3,  3, 'Ball',        'Nike',     89.00,  30, 'Size 4 low-bounce futsal ball that stays controllable on hard indoor courts.', 'images/futsal.jpg'),
('Lunargato II Indoor Boots',     3,  9, 'Footwear',    'Nike',    379.00,  14, 'Low-profile indoor boots with a gum rubber sole for grip on futsal courts.', 'images/futsal.jpg'),
('Club Training Jersey',          3,  8, 'Apparel',     'Nike',    119.00,  25, 'Breathable training jersey with moisture-wicking fabric for indoor matches.', 'images/futsal.jpg');

-- Equipment variant options (independent selectors, req 14)
-- Only products that genuinely have choices get rows here. Products with no
-- rows simply render no dropdowns on the details page.
INSERT INTO equipment_options (equipment_id, option_name, option_value) VALUES
(1,  'Grip Size',  'G4'),
(1,  'Grip Size',  'G5'),
(1,  'Grip Color', 'Red'),
(1,  'Grip Color', 'Blue'),
(1,  'Grip Color', 'Black'),
(2,  'Grip Size',  'G4'),
(2,  'Grip Size',  'G5'),
(2,  'Grip Color', 'Black'),
(2,  'Grip Color', 'White'),
(5,  'Color',      'Yellow'),
(5,  'Color',      'Red'),
(6,  'Size',       'UK 7'),
(6,  'Size',       'UK 8'),
(6,  'Size',       'UK 9'),
(6,  'Size',       'UK 10'),
(8,  'Grip Color', 'Yellow'),
(8,  'Grip Color', 'Black'),
(9,  'Grip Color', 'Blue'),
(9,  'Grip Color', 'Black'),
(13, 'Size',       'UK 7'),
(13, 'Size',       'UK 8'),
(13, 'Size',       'UK 9'),
(13, 'Size',       'UK 10'),
(14, 'Size',       'S'),
(14, 'Size',       'M'),
(14, 'Size',       'L'),
(14, 'Size',       'XL');

-- Product reviews (written by the seed customers, user_id 2 to 5)
-- The UNIQUE (equipment_id, user_id) key means no customer appears twice
-- on the same product.
INSERT INTO equipment_reviews (equipment_id, user_id, rating, comment) VALUES
(1,  2, 5, 'Smashes feel much heavier than my old racquet. Took a week to get used to the weight but worth it.'),
(1,  3, 4, 'Great power but quite stiff. Beginners should probably start with something more flexible.'),
(1,  5, 5, 'Strung it at 26lbs and the control is excellent. No complaints at all.'),
(3,  2, 4, 'Flight is consistent and they last around three games each. Fair price for feather shuttles.'),
(6,  4, 5, 'Cushioning is superb, my knees stopped aching after long sessions. Runs slightly small, size up.'),
(8,  3, 5, 'Very quiet on contact and the sweet spot is huge. Best paddle I have owned.'),
(8,  5, 3, 'Plays well but the edge guard started peeling after two months of outdoor use.'),
(10, 4, 4, 'Consistent bounce outdoors and they survive rough courts. Slightly loud though.'),
(12, 2, 5, 'Proper low bounce, behaves exactly like a match ball should on a hard court.'),
(14, 3, 4, 'Light and breathable. Sizing runs a little large so consider one size down.');


-- =====================================================================
-- KEY QUERIES (for reference / report explanation)
-- =====================================================================

-- (a) Find available courts for a chosen sport + date + time range (req 1/2)
-- Excludes courts that are 'deleted', and 'pending_deletion' courts
-- also cannot receive NEW bookings, so both non-active statuses are excluded.
-- SELECT c.court_id, c.court_number
-- FROM courts c
-- WHERE c.sport_type_id = ?         -- e.g. 1 = Badminton
--   AND c.status = 'active'
--   AND c.court_id NOT IN (
--       SELECT boi.court_id
--       FROM booking_order_items boi
--       WHERE boi.booking_date = ?             -- chosen date
--         AND boi.start_time < ?               -- chosen end_time
--         AND boi.end_time   > ?               -- chosen start_time
--   );

-- (b) Soft-delete a court (req 8) - run when admin clicks "Delete"
-- Step 1: check if it has any future/current bookings
-- SELECT COUNT(*) FROM booking_order_items
-- WHERE court_id = ? AND (booking_date > CURDATE()
--       OR (booking_date = CURDATE() AND end_time > CURTIME()));
--
-- Step 2a: if COUNT = 0 -> hard hide immediately
-- UPDATE courts SET status = 'deleted' WHERE court_id = ?;
--
-- Step 2b: if COUNT > 0 -> soft-disable, show admin the message
-- UPDATE courts SET status = 'pending_deletion' WHERE court_id = ?;

-- (c) Housekeeping check (run on admin page load / cron) - finalizes
-- any 'pending_deletion' courts whose bookings have all completed
-- UPDATE courts
-- SET status = 'deleted'
-- WHERE status = 'pending_deletion'
--   AND court_id NOT IN (
--       SELECT court_id FROM booking_order_items
--       WHERE booking_date > CURDATE()
--          OR (booking_date = CURDATE() AND end_time > CURTIME())
--   );

-- (d) Monthly revenue for admin dashboard (req 9)
-- SELECT DATE_FORMAT(bo.created_at, '%Y-%m') AS month,
--        SUM(bo.total_amount) AS revenue
-- FROM booking_orders bo
-- WHERE bo.payment_status = 'paid'
-- GROUP BY month
-- ORDER BY month DESC;

-- (e) Equipment search + filter by sport/category + sort by price (req 11/13)
-- LIKE with wildcards is used rather than a FULLTEXT index because it matches
-- partial words ("racq" finds "Racquet"), which is what a shop search box is
-- expected to do. The search term is still passed as a bound parameter, so
-- the wildcards are added to the value in PHP and never to the SQL string.
-- SELECT e.*, st.name AS sport_name, c.name AS category_name
-- FROM equipment e
-- JOIN sport_types st ON e.sport_type_id = st.sport_type_id
-- LEFT JOIN categories c ON e.category_id = c.category_id
-- WHERE e.status = 'active'
--   AND (e.name LIKE ? OR e.description LIKE ? OR e.brand LIKE ?)
--   AND e.sport_type_id = ?
--   AND e.category_id = ?
-- ORDER BY e.price ASC;   -- or price DESC, or name ASC

-- (g) All reviews for one product, newest first, with the reviewer's name.
-- This is the relational query behind the product details page - the review
-- table only stores user_id, so the JOIN is what turns it into a real name.
-- SELECT r.review_id, r.rating, r.comment, r.created_at, u.full_name
-- FROM equipment_reviews r
-- JOIN users u ON r.user_id = u.user_id
-- WHERE r.equipment_id = ?
-- ORDER BY r.created_at DESC;

-- (h) Check whether the logged-in customer has already reviewed a product.
-- Used to decide between showing "write a review" and "edit your review".
-- SELECT review_id, rating, comment
-- FROM equipment_reviews
-- WHERE equipment_id = ? AND user_id = ?;

-- (i) Is a category safe to delete? Blocked while products still use it.
-- SELECT COUNT(*) AS total FROM equipment WHERE category_id = ?;

-- (j) Is a product safe to hard delete, or should it be discontinued?
-- Counts references from live carts and from past orders. If either exists
-- the product is marked 'discontinued' instead, so order history survives.
-- SELECT
--   (SELECT COUNT(*) FROM cart_items            WHERE equipment_id = ?) AS in_carts,
--   (SELECT COUNT(*) FROM equipment_order_items WHERE equipment_id = ?) AS in_orders;

-- (f) A user's reservation history (req 7)
-- SELECT bo.booking_order_id, bo.created_at, bo.total_amount, bo.payment_status,
--        boi.booking_date, boi.start_time, boi.end_time, co.court_number
-- FROM booking_orders bo
-- JOIN booking_order_items boi ON bo.booking_order_id = boi.booking_order_id
-- JOIN courts co ON boi.court_id = co.court_id
-- WHERE bo.user_id = ?
-- ORDER BY bo.created_at DESC;