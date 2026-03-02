<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

// Security: Only Admins can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    die("Unauthorized access.");
}

$user_id_to_edit = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id_to_edit === 0) {
    die("No user ID specified.");
}

// Fetch the details of the user being edited
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id_to_edit);
$stmt->execute();
$user_to_edit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_to_edit) {
    die("User not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <style>
        /* Using the same style as your login page for consistency */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-image: linear-gradient(to right top, #051937, #004d7a, #008793, #00bf72, #a8eb12); margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { max-width: 400px; padding: 40px; background: rgba(255, 255, 255, 0.9); border-radius: 10px; box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37); }
        h2 { text-align: center; margin-bottom: 25px; color: #333; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 12px; margin: 10px 0 20px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        input[type="submit"] { width: 100%; background-color: #007bff; color: white; padding: 14px 20px; margin-top: 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; }
        label { font-weight: bold; color: #555; }
        .checkbox-group label { display: inline-block; margin-right: 15px; font-weight: normal;}
        .back-link { display: block; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit User: <?php echo htmlspecialchars($user_to_edit['username']); ?></h2>
    <form action="update_user.php" method="post">
        <input type="hidden" name="user_id" value="<?php echo $user_to_edit['id']; ?>">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user_to_edit['full_name']); ?>" required>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_to_edit['username']); ?>" required>
        <label for="email">Email (for staff notifications)</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_to_edit['email'] ?? ''); ?>">
        <label for="password">New Password (leave blank to keep current)</label>
        <input type="password" id="password" name="password" autocomplete="new-password">
        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="User" <?php if ($user_to_edit['role'] == 'User') echo 'selected'; ?>>User (External Client)</option>
            <option value="Staff" <?php if ($user_to_edit['role'] == 'Staff') echo 'selected'; ?>>Staff</option>
            <option value="Admin" <?php if ($user_to_edit['role'] == 'Admin') echo 'selected'; ?>>Admin</option>
        </select>

        <input type="submit" value="Update User">
    </form>
    <a href="manage_users.php" class="back-link">Back to User List</a>
</div>
</body>
</html>