<?php
session_start();
require_once '/var/www/secure_config/braintrust_config.php';

// Security: Redirect if user is not logged in OR if they are NOT a staff member
if (!isset($_SESSION['user_id']) || $_SESSION['is_staff'] != 1) {
    header("Location: login.php");
    exit();
}

$current_staff_id = $_SESSION['user_id'];
$current_staff_plain_username = $_SESSION['username']; 
$current_staff_display_name = $_SESSION['full_name'] ?? $_SESSION['username']; 

$files = [];
$sql = "SELECT f.id, f.original_filename, f.stored_filename, f.upload_time, u.username as uploader_username
        FROM file_uploads f
        JOIN users u ON f.uploader_id = u.id
        WHERE f.recipient_id = ?
        ORDER BY f.upload_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $current_staff_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $files[] = $row;
    }
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 30px; }
        .container { max-width: 900px; margin: auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        h1 { margin: 0; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #343a40; color: white; }
        tr:hover { background-color: #e9ecef; }
        a { color: #007bff; text-decoration: none; font-weight: bold; }
        .btn { display: inline-block; padding: 8px 15px; border-radius: 5px; text-decoration: none; color: white; background-color: #007bff; margin-left: 10px; }
        .btn-logout { background-color: #dc3545; }
        .btn-red { background-color: #dc3545; }
        .btn-play-game { background-color: #6f42c1; } 
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome, <?php echo htmlspecialchars($current_staff_display_name); ?>!</h1>
        <div>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                <a href="manage_users.php" class="btn">Manage Users</a>
            <?php endif; ?>

            <?php
            // NEW CODE: Create a list of players who can see the button
            $allowed_players = ['xenobones', 'hatguy'];

            // Check if the logged-in user is in the allowed list
            if (isset($current_staff_plain_username) && in_array($current_staff_plain_username, $allowed_players)) {
                echo '<a href="collabchat/braintrust_projects.php" class="btn btn-play-game">Enter AI Chat</a>';
            }
            ?>
            
            <a href="logout.php" class="btn btn-logout">Logout</a>
        </div>
    </div>
    <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
    <div style="padding: 15px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px; text-align: center; margin-bottom: 20px;">
        File has been successfully deleted.
    </div>
    <?php endif; ?>
    <h2>Files Uploaded For You</h2>

    <table>
        <thead>
            <tr>
                <th>File Name</th>
                <th>Uploaded By</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($files)): ?>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($file['original_filename']); ?></td>
                        <td><?php echo htmlspecialchars($file['uploader_username']); ?></td>
                        <td><?php echo date('m/d/Y h:i A', strtotime($file['upload_time'])); ?></td>
                        <td style="white-space: nowrap;">
                            <a href="uploads/user_<?php echo $current_staff_id; ?>/<?php echo htmlspecialchars($file['stored_filename']); ?>" download="<?php echo htmlspecialchars($file['original_filename']); ?>" class="btn">
                                Download
                            </a>
                            <form action="delete_file.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this file?');">
                                <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                <button type="submit" class="btn btn-red" style="font-weight:bold; font-size:14px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 20px;">No files have been uploaded for you yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>