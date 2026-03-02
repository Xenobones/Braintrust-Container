<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

$username = $_POST['username'];
$password = $_POST['password'];

if (empty($username) || empty($password)) {
    header("Location: login.php?error=1");
    exit();
}

// Fetch the user from the new users table
$stmt = $conn->prepare("SELECT id, username, full_name, password_hash, ccmc_staff, role FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    // Verify the password
    if (password_verify($password, $user['password_hash'])) {
        // Password is correct, store user data in session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_staff'] = $user['ccmc_staff']; // 1 for yes, 0 for no
        $_SESSION['role'] = $user['role'];

        // --- Conditional Redirect ---
        if ($user['ccmc_staff'] == 1) {
            // User is CCMC Staff, send them to the staff dashboard
            header("Location: collabchat/braintrust_projects.php");
        } else {
            // User is an external client, send them to the upload form
            header("Location: upload_form.php");
        }
        exit();

    } else {
        // Password incorrect
        header("Location: login.php?error=1");
        exit();
    }
} else {
    // User not found
    header("Location: login.php?error=1");
    exit();
}

$stmt->close();
$conn->close();
?>