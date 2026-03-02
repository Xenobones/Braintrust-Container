<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

// Security: Only Admins can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    die("Unauthorized access. Please log in as an Admin.");
}

// Get the user ID from the URL
if (!isset($_GET['id'])) {
    die("User ID not specified.");
}

$user_id_to_delete = (int)$_GET['id'];

// Prevent admin from deleting themselves
if ($user_id_to_delete === (int)$_SESSION['user_id']) {
    die("You cannot delete your own account.");
}

// Execute the DELETE query
$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id_to_delete);

if ($stmt->execute()) {
    // Success, redirect back to the user list
    header("Location: manage_users.php?deleted=1");
    exit();
} else {
    // Handle error
    die("Error deleting user: " . $stmt->error);
}

$stmt->close();
$conn->close();
?>
