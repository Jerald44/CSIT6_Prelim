<?php
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    exit("Access denied.");
}

session_start(); // start the session

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // If not logged in, redirect to login page
    header("Location: ../pages/login.php"); // adjust path as needed
    exit();
}
?>