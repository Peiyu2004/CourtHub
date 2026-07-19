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

// mysqli OOP-style connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// If connection fails, stop the whole page instead of continuing with a broken $conn
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Make sure PHP and MySQL agree on character encoding (avoids garbled text)
$conn->set_charset("utf8mb4");
