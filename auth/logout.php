<?php
require_once __DIR__ . '/../config/functions.php';

// Clear all session data and destroy the session completely
$_SESSION = [];
session_destroy();

header("Location: " . app_url('/auth/login.php'));
exit();
