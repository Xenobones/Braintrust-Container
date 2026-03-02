<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create New User</title>
    <style>
        /* Using the same style as your login page for consistency */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-image: linear-gradient(to right top, #051937, #004d7a, #008793, #00bf72, #a8eb12); margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { max-width: 400px; padding: 40px; background: rgba(255, 255, 255, 0.9); border-radius: 10px; box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37); }
        h2 { text-align: center; margin-bottom: 25px; color: #333; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 12px; margin: 10px 0 20px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        input[type="submit"] { width: 100%; background-color: #28a745; color: white; padding: 14px 20px; margin-top: 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; }
        label { font-weight: bold; color: #555; }
        .checkbox-group label { display: inline-block; margin-right: 15px; font-weight: normal;}
        .back-link {
    display: block;
    text-align: center;
    margin-top: 20px;
    font-weight: bold;
    color: #007bff;
    text-decoration: none;
}
    </style>
</head>
<body>
<div class="container">
    <h2>Create New User</h2>
    <form action="add_user.php" method="post">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" required>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
        <label for="email">Email (for staff notifications)</label>
        <input type="email" id="email" name="email">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="User">User (External Client)</option>
            <option value="Staff">Staff</option>
            <option value="Admin">Admin</option>
        </select>

        <input type="submit" value="Create User">
    </form>
    <a href="dashboard.php" class="back-link">Cancel / Back to Dashboard</a>
</div>
</body>
</html>