<?php
session_start();

// Simple login for testing - in production, use proper authentication
if ($_POST['email'] ?? '' === 'admin@system.com' && ($_POST['password'] ?? '') === 'admin123') {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'super_admin';
    $_SESSION['name'] = 'Super Administrator';
    header('Location: admin_management.php');
    exit();
}

// Check if already logged in
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'super_admin') {
    header('Location: admin_management.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ministry Exchange</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-container { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1); width: 100%; max-width: 400px; }
        .login-container h1 { color: #065f46; margin-bottom: 30px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #064e3b; font-weight: 500; }
        .form-group input { width: 100%; padding: 12px 15px; border: 1px solid #d1fae5; border-radius: 8px; font-size: 1em; }
        .btn { width: 100%; padding: 12px; background: #065f46; color: white; border: none; border-radius: 8px; font-size: 1em; cursor: pointer; }
        .btn:hover { background: #054c38; }
        .test-credentials { background: #f0fdf4; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 0.9em; color: #065f46; }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Ministry Exchange Login</h1>
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required value="admin@system.com">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required value="admin123">
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
        <div class="test-credentials">
            <strong>Test Credentials:</strong><br>
            Email: admin@system.com<br>
            Password: admin123
        </div>
    </div>
</body>
</html>