<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

// Security: Only Admins can process this, and it must be a POST request
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Unauthorized access.");
}

// Get all the data from the form
$user_id_to_edit = (int)$_POST['user_id'];
$username = $_POST['username'];
$full_name = $_POST['full_name'];
$email = $_POST['email'];
$new_password = $_POST['password'];
$ccmc_staff = ($_POST['role'] === 'Admin' || $_POST['role'] === 'Staff') ? 1 : 0;
$role = $_POST['role'];

// --- Build the UPDATE query dynamically ---
$sql = "UPDATE users SET username = ?, full_name = ?, email = ?, ccmc_staff = ?, role = ?";
$types = "sssis";
$params = [$username, $full_name, $email, $ccmc_staff, $role];

// Only add the password to the query if a new one was entered
if (!empty($new_password)) {
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $sql .= ", password_hash = ?";
    $types .= "s";
    $params[] = $password_hash;
}

// Add the final WHERE clause
$sql .= " WHERE id = ?";
$types .= "i";
$params[] = $user_id_to_edit;

// Execute the query
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    // Success, redirect back to the user list with a success message
    // We can add a success message later if you want
    header("Location: manage_users.php?updated=1");
    exit();
} else {
    // Handle error
    die("Error updating user: " . $stmt->error);
}

$stmt->close();
$conn->close();
?>