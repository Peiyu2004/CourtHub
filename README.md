# CourtHub Sport Center Management System

An end-to-end dynamic web application developed for indoor sports court reservations and equipment e-commerce storefront management. Built strictly using PHP, MySQL, HTML5, CSS3, and JavaScript without external frameworks or third-party libraries.

---

## 1. Project Description

The **CourtHub Sport Center Management System** provides a seamless interface for customers to browse, search, and reserve sports facilities (Badminton, Pickleball, and Futsal) and purchase sports gear online.

### Key Features

* **Dynamic Court Reservations:** Real-time timeslot generation and booking status management.

* **E-Commerce Equipment Store:** Product catalog with categorization, detailed specification views, and session-backed cart management.

* **Payment Processing Simulators:** Mock checkout flows supporting Credit/Debit Card and Touch 'n Go eWallet payment gateways.

* **User Account Dashboard:** Personal history tracking for court bookings, purchase orders, and submitted inquiry messages.

* **Administrative Control Panel:** Role-based access control for court asset management, inventory updates, order processing, and user administration.

* **Responsive Design:** Native CSS media queries optimized for mobile, tablet, and desktop viewports.

---

## 2. Tech Stack & Environment Requirements

* **Web Server:** Apache HTTP Server (via XAMPP / WAMP)
* **PHP Engine:** PHP 8.0 or higher
* **Database Management System:** MySQL 8.0
* **Frontend Languages:** Standard HTML5, CSS3, and JavaScript

---

## 3. Database Configuration

The application connects to a MySQL database using the PHP MySQLi extension.

### Database Connection Parameters

The main connection settings are configured in `config/db_connect.php`:

```php
<?php
// Database Credentials
$host = 'localhost';
$user = 'root';         // Replace with your MySQL username
$pass = '';             // Replace with your MySQL password
$db   = 'courthub_db';  // Database name

// Initialize MySQLi connection
$conn = new mysqli($host, $user, $pass, $db);

// Check Connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
?>

```

---

## 4. Installation & Setup Instructions

Follow these steps to set up and deploy the project locally:

### Step 1: Copy Project Files

1. Extract or clone the project folder `CourtHub`.
2. Move the entire `CourtHub` directory into your web server's root document folder:
* **XAMPP:** `C:/xampp/htdocs/CourtHub/`
* **WAMP:** `C:/wamp64/www/CourtHub/`

### Step 2: Import the Database

1. Launch your web server control panel and start (e.g., XAMPP Control Panel: start both **Apache** and **MySQL**; WampServer: ensure the taskbar icon turns green).
2. Open your browser and go to **phpMyAdmin**: `http://localhost/phpmyadmin/`.
3. Log in to phpMyAdmin (Default Username: root, Password: leave blank).
4. Click on **New** in the left sidebar and create a database named `courthub_db`.
5. Select the newly created `courthub_db` database.
6. Click on the **Import** tab in the top menu.
7. Click **Choose File** and select the `database.sql` file located in the project root directory (`CourtHub/database.sql`).
8. Click **Import** (or **Go**) at the bottom of the page to execute the schema setup and load seed data.

---

## 5. Steps to Run the Project

1. Verify that **Apache** and **MySQL** services are running. / Ensure WampServer is actively running (the tray icon must be green).
2. Launch any web browser (Chrome, Edge, Firefox, or Safari).
3. Access the landing page by navigating to the following URL:
```text
http://localhost/CourtHub/index.php

```

---

## 6. Pre-seeded Test Credentials

For evaluation and testing purposes, use the following pre-configured user accounts:

| Account Role | Email Address | Password | Access Privileges |
| --- | --- | --- | --- |
| **Administrator** | `admin@courthub.com` | `password123` | Full administrative access to `/admin` dashboard, inventory, reservation management, and users.|
| **Customer** | `alice.tan@example.com` | `password123` | Customer court reservations, equipment purchasing, cart management, and history views.|