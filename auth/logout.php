<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/functions.php';

clearRememberToken($conn);

$_SESSION = [];
session_destroy();

header("Location: " . app_url('/auth/login.php'));
exit();
