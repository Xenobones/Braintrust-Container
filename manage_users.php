<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

// Security: Only Admins can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    die("Unauthorized access. Please log in as an Admin.");
}

// Fetch all users from the database to display in a list
$all_users = [];
$result = $conn->query("SELECT id, username, ccmc_staff, role, created_at FROM users ORDER BY username ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 30px; }
        .container { max-width: 800px; margin: auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #e9ecef; }
        .actions { text-align: center; }
        .actions a, .actions button { display: inline-block; padding: 6px 12px; border-radius: 5px; text-decoration: none; color: white; font-weight: bold; border: none; cursor: pointer; font-size: 14px; margin: 2px; }
        .btn-blue { background-color: #007bff; }
        .btn-green { background-color: #28a745; }
        .btn-red { background-color: #dc3545; }
        .top-actions { margin-bottom: 20px; text-align: right; }
    </style>
</head>
<body>
<div class="container">
    <?php if (isset($_GET['deleted'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; border: 1px solid #c3e6cb;">
            User deleted successfully.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; border: 1px solid #c3e6cb;">
            User updated successfully.
        </div>
    <?php endif; ?>
    <div class="top-actions">
    <a href="dashboard.php" class="actions btn-blue">⬅️ Back to Dashboard</a>
    <a href="register.php" class="actions btn-green">＋ Create New User</a>
</div>
    <h1>User Management</h1>

    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Is Staff?</th>
                <th>Role</th>
                <th>Created On</th>
                <th class="actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($all_users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo $user['ccmc_staff'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td><?php echo date('m/d/Y', strtotime($user['created_at'])); ?></td>
                    <td class="actions">
                        <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="actions btn-blue">Edit</a>
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <a href="delete_user.php?id=<?php echo $user['id']; ?>" class="actions btn-red" onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>