<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    die("Invalid request method.");
}

$username = $_POST['username'];
$full_name = $_POST['full_name'];
$email = $_POST['email'];
$password = $_POST['password'];
$ccmc_staff = ($_POST['role'] === 'Admin' || $_POST['role'] === 'Staff') ? 1 : 0;
$role = $_POST['role'];

if (empty($username) || empty($password) || empty($role)) {
    die("Please fill out all required fields.");
}

// Hash the password for secure storage
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Prepare and execute the SQL statement to insert the new user
$stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, ccmc_staff, role) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssis", $username, $full_name, $email, $password_hash, $ccmc_staff, $role);

if ($stmt->execute()) {
    echo "<h1>Success!</h1><p>New user '" . htmlspecialchars($username) . "' has been created.</p>";
    echo '<a href="login.php">Go to Login Page</a>';
} else {
    echo "<h1>Error</h1><p>There was a problem creating the user. It's possible that username already exists.</p>";
    echo "Error: Could not create user. The username may already exist.";
}

$stmt->close();
$conn->close();
?>