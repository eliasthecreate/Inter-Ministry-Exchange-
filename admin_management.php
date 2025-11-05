<?php
session_start();

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header("Location: loginSignup.php");
    exit();
}

// Database connection
try {
    require_once 'config/database.php';
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get user data from session
$user_name = $_SESSION['user_name'] ?? 'Admin User';
$user_role = $_SESSION['user_role'] ?? 'admin';
$user_initials = strtoupper(substr($user_name, 0, 1) . (strstr($user_name, ' ') ? substr(strstr($user_name, ' '), 1, 1) : ''));

// Initialize variables
$error = null;
$success = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        // Add new user
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $role = $_POST['role'];
        $ministry_id = $_POST['ministry_id'];
        
        // Validate inputs
        if (empty($name) || empty($email) || empty($password) || empty($role)) {
            $error = "All required fields must be filled.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            try {
                // Check if email already exists
                $check_stmt = $pdo->prepare("SELECT id FROM user WHERE email = ?");
                $check_stmt->execute([$email]);
                
                if ($check_stmt->fetch()) {
                    $error = "Email already exists in the system.";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO user (name, email, password_hash, role, ministry_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $insert_stmt->execute([
                        $name, 
                        $email, 
                        $hashed_password, 
                        $role, 
                        $ministry_id ?: null
                    ]);
                    
                    $success = "User added successfully!";
                    
                    // Log the action
                    $log_stmt = $pdo->prepare("
                        INSERT INTO log (user_id, action, details, timestamp) 
                        VALUES (?, 'user_created', ?, NOW())
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'], 
                        "Created user: $name ($email) with role: $role"
                    ]);
                }
            } catch (PDOException $e) {
                $error = "Error adding user: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_user'])) {
        // Update existing user
        $user_id = $_POST['user_id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $ministry_id = $_POST['ministry_id'];
        
        try {
            $update_stmt = $pdo->prepare("
                UPDATE user 
                SET name = ?, email = ?, role = ?, ministry_id = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $update_stmt->execute([$name, $email, $role, $ministry_id ?: null, $user_id]);
            $success = "User updated successfully!";
            
            // Log the action
            $log_stmt = $pdo->prepare("
                INSERT INTO log (user_id, action, details, timestamp) 
                VALUES (?, 'user_updated', ?, NOW())
            ");
            $log_stmt->execute([
                $_SESSION['user_id'], 
                "Updated user: $name ($email)"
            ]);
            
        } catch (PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_user'])) {
        // Delete user
        $user_id = $_POST['user_id'];
        
        try {
            // Get user info for logging
            $user_stmt = $pdo->prepare("SELECT name, email FROM user WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            $delete_stmt = $pdo->prepare("DELETE FROM user WHERE id = ?");
            $delete_stmt->execute([$user_id]);
            
            $success = "User deleted successfully!";
            
            // Log the action
            if ($user) {
                $log_stmt = $pdo->prepare("
                    INSERT INTO log (user_id, action, details, timestamp) 
                    VALUES (?, 'user_deleted', ?, NOW())
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Deleted user: {$user['name']} ({$user['email']})"
                ]);
            }
            
        } catch (PDOException $e) {
            $error = "Error deleting user: " . $e->getMessage();
        }
    } elseif (isset($_POST['reset_password'])) {
        // Reset user password
        $user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        
        if (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $reset_stmt = $pdo->prepare("
                    UPDATE user 
                    SET password_hash = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                
                $reset_stmt->execute([$hashed_password, $user_id]);
                $success = "Password reset successfully!";
                
                // Log the action
                $log_stmt = $pdo->prepare("
                    INSERT INTO log (user_id, action, details, timestamp) 
                    VALUES (?, 'password_reset', ?, NOW())
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Reset password for user ID: $user_id"
                ]);
                
            } catch (PDOException $e) {
                $error = "Error resetting password: " . $e->getMessage();
            }
        }
    }
}

// Fetch all users
try {
    $users_stmt = $pdo->prepare("
        SELECT u.*, m.name as ministry_name 
        FROM user u 
        LEFT JOIN ministry m ON u.ministry_id = m.id 
        ORDER BY u.created_at DESC
    ");
    $users_stmt->execute();
    $users = $users_stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
    $users = [];
}

// Fetch ministries for dropdown
try {
    $ministries_stmt = $pdo->prepare("SELECT id, name FROM ministry WHERE status = 'active' ORDER BY name");
    $ministries_stmt->execute();
    $ministries = $ministries_stmt->fetchAll();
} catch (PDOException $e) {
    $ministries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Inter-Ministry Exchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #065f46;
            --secondary: #059669;
            --accent: #34d399;
            --light: #f0fdf4;
            --dark: #064e3b;
            --card-bg: rgba(255, 255, 255, 0.95);
            --glass-effect: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        
        body { 
            background: linear-gradient(135deg, var(--light) 0%, #d1fae5 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container { 
            display: flex; 
            width: 100%; 
            min-height: 100vh; 
        }
        
        /* Sidebar Styles */
        .sidebar { 
            width: 280px; 
            background: rgba(6, 95, 70, 0.9);
            backdrop-filter: blur(10px);
            padding: 25px 20px; 
            display: flex; 
            flex-direction: column; 
            gap: 15px; 
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            z-index: 10;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            z-index: 1000;
            cursor: pointer;
        }
        
        .logo { 
            color: white; 
            font-size: 1.8em; 
            font-weight: bold; 
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo i {
            font-size: 1.2em;
            color: var(--accent);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--glass-effect);
            border-radius: 12px;
            border: 1px solid var(--glass-border);
        }
        
        .avatar { 
            background: linear-gradient(135deg, var(--secondary) 0%, var(--accent) 100%); 
            color: white; 
            border-radius: 50%; 
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1em; 
            margin-right: 15px; 
            font-weight: bold;
        }
        
        .user-details {
            line-height: 1.4;
            color: white;
        }
        
        .user-role {
            font-size: 0.85em;
            opacity: 0.8;
            text-transform: capitalize;
        }
        
        .sidebar a { 
            color: white; 
            text-decoration: none; 
            padding: 15px; 
            display: flex; 
            align-items: center; 
            background: var(--glass-effect);
            border-radius: 10px; 
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .sidebar a:hover { 
            background: rgba(52, 211, 153, 0.3);
            transform: translateX(5px);
            border-color: var(--glass-border);
        }
        
        .sidebar a.active { 
            background: rgba(52, 211, 153, 0.4);
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--accent);
        }
        
        .sidebar a i { 
            margin-right: 15px; 
            font-size: 1.2em;
            width: 25px;
            text-align: center;
        }
        
        .logout { 
            margin-top: auto; 
            background: rgba(239, 68, 68, 0.2) !important;
        }
        
        .logout:hover {
            background: rgba(239, 68, 68, 0.3) !important;
        }
        
        /* Main Content Styles */
        .main-content { 
            flex: 1; 
            padding: 30px;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }
        
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 30px; 
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .header h1 { 
            color: var(--primary);
            font-size: 2em; 
            font-weight: 600;
        }
        
        /* Form Styles */
        .section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .section h3 {
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 1em;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-success {
            background: #059669;
            color: white;
        }
        
        .btn-warning {
            background: #d97706;
            color: white;
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .users-table th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
        }
        
        .users-table tr:hover {
            background: #f0fdf4;
        }
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .role-admin { background: #dbeafe; color: #1e40af; }
        .role-super_admin { background: #ede9fe; color: #5b21b6; }
        .role-user { background: #dcfce7; color: #166534; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: var(--primary);
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #6b7280;
        }
        
        /* Message Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        
        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        
        .close-alert {
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            color: inherit;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 250px;
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                transform: translateX(0);
                box-shadow: 5px 0 15px rgba(0, 0, 0, 0.2);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .menu-toggle {
                display: block;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .users-table {
                font-size: 0.9em;
            }
            
            .users-table th,
            .users-table td {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($error)): ?>
        <div class="alert alert-error" id="errorMessage">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            <button class="close-alert" onclick="document.getElementById('errorMessage').style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($success)): ?>
        <div class="alert alert-success" id="successMessage">
            <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
            <button class="close-alert" onclick="document.getElementById('successMessage').style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>
    
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="container">
        <div class="sidebar" id="sidebar">
            <div class="logo">
                <i class="fas fa-hands-helping"></i>
                <span>Inter Ministry Exchange</span>
            </div>
            <div class="user-info">
                <div class="avatar"><?php echo $user_initials; ?></div>
                <div class="user-details">
                    <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                </div>
            </div>
            <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="#" class="active"><i class="fas fa-users"></i> User Management</a>
            <a href="admin_ministries.php"><i class="fas fa-building"></i> Ministries</a>
            <a href="admin_requests.php"><i class="fas fa-exchange-alt"></i> Data Requests</a>
            <a href="admin_audit_logs.php"><i class="fas fa-file-alt"></i> Audit Logs</a>
            <a href="admin_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="admin_documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="admin_settings.php"><i class="fas fa-cog"></i> System Settings</a>
            <a href="admin_security.php"><i class="fas fa-shield-alt"></i> Security</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>User Management</h1>
                <button class="btn btn-primary" onclick="openAddUserModal()">
                    <i class="fas fa-user-plus"></i> Add New User
                </button>
            </div>
            
            <!-- Add User Form -->
            <div class="section">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Full Name *</label>
                            <input type="text" id="name" name="name" class="form-control" required 
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" class="form-control" required 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Password *</label>
                            <input type="password" id="password" name="password" class="form-control" required 
                                   minlength="6" placeholder="At least 6 characters">
                        </div>
                        
                        <div class="form-group">
                            <label for="role">User Role *</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">Select Role</option>
                                <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] == 'user') ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <?php if ($user_role === 'super_admin'): ?>
                                <option value="super_admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'super_admin') ? 'selected' : ''; ?>>Super Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="ministry_id">Ministry *</label>
                        <select id="ministry_id" name="ministry_id" class="form-control" required>
                            <option value="">Select Ministry</option>
                            <?php foreach ($ministries as $ministry): ?>
                                <option value="<?php echo $ministry['id']; ?>" 
                                    <?php echo (isset($_POST['ministry_id']) && $_POST['ministry_id'] == $ministry['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ministry['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" name="add_user" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add User
                    </button>
                </form>
            </div>
            
            <!-- Users List -->
            <div class="section">
                <h3><i class="fas fa-list"></i> All Users (<?php echo count($users); ?>)</h3>
                
                <div class="table-responsive">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Ministry</th>
                                <th>Created</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                <span style="color: var(--accent);">(You)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="role-badge role-<?php echo htmlspecialchars($user['role'] ?? 'user'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $user['role'] ?? 'user')); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $user['ministry_name'] ? htmlspecialchars($user['ministry_name']) : 'N/A'; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                                            <?php else: ?>
                                                Never
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-warning" 
                                                        onclick="openEditModal(
                                                            <?php echo $user['id']; ?>, 
                                                            '<?php echo addslashes($user['name']); ?>', 
                                                            '<?php echo addslashes($user['email']); ?>', 
                                                            '<?php echo $user['role'] ?? 'user'; ?>', 
                                                            '<?php echo $user['ministry_id'] ?? ''; ?>'
                                                        )">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                
                                                <button class="action-btn btn-success" 
                                                        onclick="openResetPasswordModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')">
                                                    <i class="fas fa-key"></i> Reset Password
                                                </button>
                                                
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <button class="action-btn btn-danger" 
                                                            onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 20px;">
                                        No users found in the system.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit User</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label for="edit_name">Full Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_email">Email Address</label>
                    <input type="email" id="edit_email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_role">User Role</label>
                    <select id="edit_role" name="role" class="form-control" required>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <?php if ($user_role === 'super_admin'): ?>
                        <option value="super_admin">Super Admin</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit_ministry_id">Ministry</label>
                    <select id="edit_ministry_id" name="ministry_id" class="form-control" required>
                        <option value="">Select Ministry</option>
                        <?php foreach ($ministries as $ministry): ?>
                            <option value="<?php echo $ministry['id']; ?>">
                                <?php echo htmlspecialchars($ministry['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal" id="resetPasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reset Password</h3>
                <button class="close-modal" onclick="closeResetPasswordModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="form-group">
                    <label>User: <strong id="reset_user_name"></strong></label>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required 
                           minlength="6" placeholder="At least 6 characters">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                    <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete User</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="form-group">
                    <p>Are you sure you want to delete user: <strong id="delete_user_name"></strong>?</p>
                    <p style="color: #dc2626; font-weight: 600;">This action cannot be undone!</p>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle mobile menu
        document.getElementById('menuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });

        // Close menu when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 992 && 
                !sidebar.contains(event.target) && 
                !menuToggle.contains(event.target) &&
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // Modal functions
        function openEditModal(userId, name, email, role, ministryId) {
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_role').value = role || 'user';
            document.getElementById('edit_ministry_id').value = ministryId || '';
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openResetPasswordModal(userId, userName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name').textContent = userName;
            document.getElementById('resetPasswordModal').style.display = 'flex';
        }

        function closeResetPasswordModal() {
            document.getElementById('resetPasswordModal').style.display = 'none';
        }

        function openDeleteModal(userId, userName) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_user_name').textContent = userName;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function openAddUserModal() {
            document.getElementById('name').scrollIntoView({ behavior: 'smooth' });
            document.getElementById('name').focus();
        }

        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            const errorMessage = document.getElementById('errorMessage');
            const successMessage = document.getElementById('successMessage');
            
            if (errorMessage) errorMessage.style.display = 'none';
            if (successMessage) successMessage.style.display = 'none';
        }, 5000);

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const editModal = document.getElementById('editModal');
            const resetModal = document.getElementById('resetPasswordModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === editModal) closeEditModal();
            if (event.target === resetModal) closeResetPasswordModal();
            if (event.target === deleteModal) closeDeleteModal();
        });
    </script>
</body>
</html>