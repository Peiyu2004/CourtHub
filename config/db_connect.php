<?php
/**
 * db_connect.php
 * Single shared database connection used across the whole site.
 * Every page that needs the database does: require_once '../config/db_connect.php';
 */

$db_host = "localhost";
$db_user = "root";        // change to your MySQL username
$db_pass = "";             // change to your MySQL password
$db_name = "courthub_db";

/*
 * From PHP 8.1 onwards mysqli reports problems by throwing an exception
 * instead of returning false. Setting the mode here on purpose means every
 * page behaves the same way, and it is why the INSERT calls in the equipment
 * pages are wrapped in try/catch - a duplicate review or a duplicate category
 * name arrives as an exception, not as a false return value.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // mysqli OOP-style connection
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
} catch (mysqli_sql_exception $e) {
    // Stop the whole page instead of continuing with a broken $conn.
    // The real driver message is not printed to the browser because it can
    // reveal the database name and user account to a visitor.
    die("Database connection failed. Check that MySQL is running in WAMP and "
        . "that the database '" . htmlspecialchars($db_name) . "' has been imported.");
}

// Make sure PHP and MySQL agree on character encoding (avoids garbled text)
$conn->set_charset("utf8mb4");

/*
 * Schema check.
 * The equipment store needs the categories, equipment_reviews and
 * equipment_variants tables. If a group member pulls the latest code but
 * forgets to re-import database.sql, every store page would otherwise die
 * with a raw "Table 'courthub_db.equipment_variants' doesn't exist" error,
 * which is not obvious. One cheap SHOW TABLES query turns that into a
 * readable instruction.
 *
 * equipment_variants is the table asked about because it is the newest of
 * the three: a database old enough to be missing it is old enough to still
 * have stock on the equipment row, and every page that reads stock would be
 * broken. Checking for an older table would let that database through.
 *
 * This only reports the problem. It never creates or changes any table -
 * importing database.sql stays a manual step, as documented in the README.
 */
$schema_check = $conn->query("SHOW TABLES LIKE 'equipment_variants'");
if ($schema_check->num_rows === 0) {
    die("Database schema is out of date. Please import 'database.sql' again "
        . "through phpMyAdmin (it recreates the database from scratch).");
}
$schema_check->close();
