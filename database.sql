-- =====================================================================
-- CourtHub Sport Center Database Schema
-- Badminton / Pickleball / Futsal Court Reservation + Equipment Store
-- UECS2094/UECS2194/EECS2194 Web Application Development
-- =====================================================================

CREATE DATABASE IF NOT EXISTS courthub_db;
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
);

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
);

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
);

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
);

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
);

-- ---------------------------------------------------------------------
-- 6. EQUIPMENT
-- Base product (req 10). Stock/price shared across all variant choices.
-- ---------------------------------------------------------------------
CREATE TABLE equipment (
    equipment_id    INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    sport_type_id   INT NOT NULL,
    category        VARCHAR(50) NOT NULL,     -- e.g. Racquet, Paddle, Shoes, Ball, Apparel, Accessories
    brand           VARCHAR(50),
    price           DECIMAL(8,2) NOT NULL,
    stock           INT NOT NULL DEFAULT 0,
    description     TEXT,
    image_url       VARCHAR(255),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (sport_type_id) REFERENCES sport_types(sport_type_id)
);

-- Full-text index to support the search requirement (req 11)
ALTER TABLE equipment ADD FULLTEXT(name, description);

-- ---------------------------------------------------------------------
-- 7. EQUIPMENT_OPTIONS
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
);

-- ---------------------------------------------------------------------
-- 8. CART_ITEMS
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
);

-- ---------------------------------------------------------------------
-- 9. EQUIPMENT_ORDERS / EQUIPMENT_ORDER_ITEMS
-- Confirmed equipment purchases, separate from court booking orders
-- since they're conceptually different transactions.
-- ---------------------------------------------------------------------
CREATE TABLE equipment_orders (
    equipment_order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    total_amount        DECIMAL(8,2) NOT NULL,
    payment_method      ENUM('tng_ewallet', 'credit_debit_card') NOT NULL,
    payment_status      ENUM('paid', 'failed') NOT NULL DEFAULT 'paid',
    transaction_ref     VARCHAR(50),
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE equipment_order_items (
    order_item_id       INT AUTO_INCREMENT PRIMARY KEY,
    equipment_order_id   INT NOT NULL,
    equipment_id         INT NULL,
    quantity             INT NOT NULL,
    price_at_purchase    DECIMAL(8,2) NOT NULL,
    selected_options     JSON,

    FOREIGN KEY (equipment_order_id) REFERENCES equipment_orders(equipment_order_id) ON DELETE CASCADE,
    FOREIGN KEY (equipment_id)       REFERENCES equipment(equipment_id) ON DELETE SET NULL
);


-- =====================================================================
-- SAMPLE DATA
-- =====================================================================

-- Users (use PHP password_hash() to generate real hashes at registration time)
-- Sample login password for both seed accounts: password123
INSERT INTO users (full_name, email, password_hash, phone, role) VALUES
('Admin User', 'admin@courthub.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0123456789', 'admin'),
('Tan Rui Han', 'tanrh@example.com', '$2y$12$ZLQTVAKoMU2GkLmCy8E1sew3XvfoDaPg922Ri.3uj/.XqElY/V.p6', '0198765432', 'customer');

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

-- Equipment
INSERT INTO equipment (name, sport_type_id, category, brand, price, stock, description) VALUES
('Astrox Racquet', 1, 'Racquet', 'Yonex', 899.00, 20, 'High-performance badminton racquet, available in multiple models.'),
('Pro Paddle', 2, 'Paddle', 'Selkirk', 450.00, 15, 'Carbon fiber pickleball paddle.'),
('Match Futsal Ball', 3, 'Ball', 'Nike', 89.00, 30, 'Low-bounce futsal-specific ball.');

-- Equipment variant options (independent selectors, req 14)
INSERT INTO equipment_options (equipment_id, option_name, option_value) VALUES
(1, 'Model', 'Astrox 100ZZ'),
(1, 'Model', 'Astrox 99 Pro'),
(1, 'Grip Color', 'Red'),
(1, 'Grip Color', 'Blue'),
(1, 'Grip Color', 'Black'),
(2, 'Model', 'Selkirk Vanguard'),
(2, 'Model', 'Selkirk Amped'),
(2, 'Grip Color', 'Yellow'),
(2, 'Grip Color', 'Black');


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

-- (e) Equipment search + filter by sport + sort by price (req 11/13)
-- SELECT * FROM equipment
-- WHERE MATCH(name, description) AGAINST(? IN NATURAL LANGUAGE MODE)
--   AND (? IS NULL OR sport_type_id = ?)
-- ORDER BY price ASC;   -- or price DESC

-- (f) A user's reservation history (req 7)
-- SELECT bo.booking_order_id, bo.created_at, bo.total_amount, bo.payment_status,
--        boi.booking_date, boi.start_time, boi.end_time, co.court_number
-- FROM booking_orders bo
-- JOIN booking_order_items boi ON bo.booking_order_id = boi.booking_order_id
-- JOIN courts co ON boi.court_id = co.court_id
-- WHERE bo.user_id = ?
-- ORDER BY bo.created_at DESC;