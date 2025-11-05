<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is a ministry admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: loginSignup.php");
    exit;
}

// Verify this admin belongs to the ministry they're trying to access
if (!isset($_SESSION['ministry_id'])) {
    header("Location: loginSignup.php");
    exit;
}

// Fetch admin data
try {
    $stmt = $pdo->prepare("SELECT u.name, u.email, m.name as ministry_name, m.abbreviation 
                          FROM user u 
                          JOIN ministry m ON u.ministry_id = m.id 
                          WHERE u.id = ? AND u.role = 'admin'");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        error_log("Ministry admin not found: user_id={$_SESSION['user_id']}");
        session_destroy();
        header("Location: loginSignup.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching admin data: " . $e->getMessage());
    $error = "Failed to load admin data.";
}

// Fetch audit logs for this ministry
try {
    $stmt = $pdo->prepare("
        SELECT 
            al.id, al.action, al.table_affected, al.record_id, al.details, al.timestamp,
            u.name as user_name, u.role as user_role,
            m.name as ministry_name, m.abbreviation as ministry_abbr
        FROM audit_log al
        JOIN user u ON al.user_id = u.id
        JOIN ministry m ON u.ministry_id = m.id
        WHERE u.ministry_id = ?
        ORDER BY al.timestamp DESC
        LIMIT 100
    ");
    $stmt->execute([$_SESSION['ministry_id']]);
    $ministry_audit_logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
    $ministry_audit_logs = [];
}

// Get user initials for avatar
$user_initials = strtoupper(substr($admin['name'], 0, 1) . substr(strstr($admin['name'], ' '), 1, 1));

// Get audit log stats
try {
    $audit_stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_logs,
            SUM(CASE WHEN action = 'CREATE' THEN 1 ELSE 0 END) as create_actions,
            SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as update_actions,
            SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as delete_actions,
            SUM(CASE WHEN action = 'LOGIN' THEN 1 ELSE 0 END) as login_actions
        FROM audit_log al
        JOIN user u ON al.user_id = u.id
        WHERE u.ministry_id = ?
    ");
    $audit_stats_stmt->execute([$_SESSION['ministry_id']]);
    $audit_stats = $audit_stats_stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching audit stats: " . $e->getMessage());
    $audit_stats = ['total_logs' => 0, 'create_actions' => 0, 'update_actions' => 0, 'delete_actions' => 0, 'login_actions' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | Inter-Ministry Exchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary: #10B981;
            --secondary: #047857;
            --accent: #34D399;
            --light: #ECFDF5;
            --dark: #064E3B;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --card-bg: rgba(255, 255, 255, 0.95);
            --glass-effect: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Inter', sans-serif; 
        }
        
        body { 
            background: linear-gradient(135deg, var(--light) 0%, #D1FAE5 100%);
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
            background: rgba(16, 185, 129, 0.9);
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
            background: var(--primary);
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); 
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
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .search-bar { 
            display: flex; 
            align-items: center; 
            background-color: var(--light); 
            border-radius: 50px; 
            padding: 10px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .search-bar input { 
            border: none; 
            outline: none; 
            font-size: 1em; 
            color: var(--dark); 
            background: transparent; 
            width: 200px; 
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
        }
        
        .stat-icon {
            padding: 15px;
            border-radius: 12px;
            margin-right: 15px;
            font-size: 1.5em;
        }
        
        .stat-total .stat-icon {
            background: rgba(16, 185, 129, 0.2);
            color: var(--primary);
        }
        
        .stat-create .stat-icon {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
        }
        
        .stat-update .stat-icon {
            background: rgba(139, 92, 246, 0.2);
            color: #8B5CF6;
        }
        
        .stat-delete .stat-icon {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .stat-login .stat-icon {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }
        
        .stat-info h3 {
            font-size: 2em;
            font-weight: 700;
            color: var(--dark);
        }
        
        .stat-info p {
            color: var(--dark);
            opacity: 0.7;
            font-size: 0.9em;
        }
        
        /* Tables */
        .table-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary);
        }
        
        .export-options {
            display: flex;
            gap: 10px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            text-align: left;
            padding: 15px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--primary);
            font-weight: 600;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid var(--glass-border);
            color: var(--dark);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tr:hover {
            background: rgba(16, 185, 129, 0.05);
        }
        
        .user-role-badge {
            display: inline-flex;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .role-admin {
            background: rgba(16, 185, 129, 0.2);
            color: var(--primary);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .role-user {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .action-badge {
            display: inline-flex;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .action-CREATE {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .action-UPDATE {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .action-DELETE {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .action-LOGIN {
            background: rgba(139, 92, 246, 0.2);
            color: #8B5CF6;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 25px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: var(--dark);
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .export-options, .btn {
                display: none !important;
            }
            
            body, .main-content, .table-container {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
            }
            
            .header {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .data-table th {
                color: black !important;
                background: #f0f0f0 !important;
            }
            
            .container {
                display: block !important;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content {
                padding: 20px;
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: 250px;
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
            
            .header {
                flex-direction: column;
                gap: 20px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .export-options {
                flex-direction: column;
                width: 100%;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .table-container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .data-table th, 
            .data-table td {
                padding: 10px;
            }
        }
        
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .notification.success {
            background: var(--success);
        }
        
        .notification.error {
            background: var(--danger);
        }
        
        .notification i {
            font-size: 1.2em;
        }
        
        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid var(--glass-border);
            background: var(--card-bg);
            color: var(--dark);
            min-width: 150px;
        }
        
        .date-range {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .date-input {
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--glass-border);
            background: var(--card-bg);
            color: var(--dark);
        }
    </style>
</head>
<body>
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
                    <strong><?php echo htmlspecialchars($admin['name']); ?></strong>
                    <div class="user-role"><?php echo htmlspecialchars($admin['ministry_name']); ?> Admin</div>
                </div>
            </div>
            <a href="admin_user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="admin_user_ministry.php"><i class="fas fa-users"></i> Ministry Users</a>
            <a href="admin_user_requests.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="admin_user_audit_logs.php" class="active"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="admin_user_documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Audit Logs - <?php echo htmlspecialchars($admin['ministry_name']); ?></h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" id="searchInput" placeholder="Search logs...">
                    </div>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="notification error" id="errorNotification">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="notification success" id="successNotification">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Audit Log Stats -->
            <div class="stats-grid">
                <div class="stat-card stat-total">
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($audit_stats['total_logs']); ?></h3>
                        <p>Total Logs</p>
                    </div>
                </div>
                
                <div class="stat-card stat-create">
                    <div class="stat-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($audit_stats['create_actions']); ?></h3>
                        <p>Create Actions</p>
                    </div>
                </div>
                
                <div class="stat-card stat-update">
                    <div class="stat-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($audit_stats['update_actions']); ?></h3>
                        <p>Update Actions</p>
                    </div>
                </div>
                
                <div class="stat-card stat-delete">
                    <div class="stat-icon">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($audit_stats['delete_actions']); ?></h3>
                        <p>Delete Actions</p>
                    </div>
                </div>
                
                <div class="stat-card stat-login">
                    <div class="stat-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($audit_stats['login_actions']); ?></h3>
                        <p>Login Actions</p>
                    </div>
                </div>
            </div>
            
            <!-- Filter Controls -->
            <div class="filter-controls">
                <select class="filter-select" id="actionFilter">
                    <option value="">All Actions</option>
                    <option value="CREATE">Create</option>
                    <option value="UPDATE">Update</option>
                    <option value="DELETE">Delete</option>
                    <option value="LOGIN">Login</option>
                </select>
                
                <select class="filter-select" id="userFilter">
                    <option value="">All Users</option>
                    <?php
                    $unique_users = [];
                    foreach ($ministry_audit_logs as $log) {
                        if (!in_array($log['user_name'], $unique_users)) {
                            $unique_users[] = $log['user_name'];
                            echo '<option value="' . htmlspecialchars($log['user_name']) . '">' . htmlspecialchars($log['user_name']) . '</option>';
                        }
                    }
                    ?>
                </select>
                
                <select class="filter-select" id="tableFilter">
                    <option value="">All Tables</option>
                    <?php
                    $unique_tables = [];
                    foreach ($ministry_audit_logs as $log) {
                        if (!in_array($log['table_affected'], $unique_tables)) {
                            $unique_tables[] = $log['table_affected'];
                            echo '<option value="' . htmlspecialchars($log['table_affected']) . '">' . htmlspecialchars($log['table_affected']) . '</option>';
                        }
                    }
                    ?>
                </select>
                
                <div class="date-range">
                    <input type="date" class="date-input" id="dateFrom">
                    <span>to</span>
                    <input type="date" class="date-input" id="dateTo">
                </div>
                
                <button class="btn btn-outline" id="clearFilters">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
            </div>
            
            <!-- Audit Log Table -->
            <div class="table-container">
                <div class="table-header">
                    <h2>Audit Logs for <?php echo htmlspecialchars($admin['ministry_name']); ?></h2>
                    <div class="export-options">
                        <span class="text-sm text-gray-600">
                            Showing <?php echo count($ministry_audit_logs); ?> logs
                        </span>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="data-table" id="auditTable">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record ID</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ministry_audit_logs) > 0): ?>
                                <?php foreach ($ministry_audit_logs as $log): ?>
                                    <tr class="audit-row" 
                                        data-action="<?php echo htmlspecialchars($log['action']); ?>"
                                        data-user="<?php echo htmlspecialchars($log['user_name']); ?>"
                                        data-table="<?php echo htmlspecialchars($log['table_affected']); ?>"
                                        data-date="<?php echo date('Y-m-d', strtotime($log['timestamp'])); ?>">
                                        <td>
                                            <div class="font-medium"><?php echo date('M j, Y', strtotime($log['timestamp'])); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo date('g:i A', strtotime($log['timestamp'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="flex items-center">
                                                <div class="avatar-small mr-3" style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8em; font-weight: bold;">
                                                    <?php 
                                                        $initials = strtoupper(substr($log['user_name'], 0, 1));
                                                        if (strpos($log['user_name'], ' ') !== false) {
                                                            $initials .= strtoupper(substr(strstr($log['user_name'], ' '), 1, 1));
                                                        }
                                                        echo $initials;
                                                    ?>
                                                </div>
                                                <div>
                                                    <div><?php echo htmlspecialchars($log['user_name']); ?></div>
                                                    <div class="text-sm text-gray-500">
                                                        <span class="user-role-badge <?php echo $log['user_role'] === 'admin' ? 'role-admin' : 'role-user'; ?>">
                                                            <?php echo htmlspecialchars(ucfirst($log['user_role'])); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="action-badge action-<?php echo htmlspecialchars($log['action']); ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['table_affected']); ?></td>
                                        <td><?php echo htmlspecialchars($log['record_id']); ?></td>
                                        <td>
                                            <div class="text-sm text-gray-600">
                                                <?php echo htmlspecialchars($log['details']); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: var(--dark);">
                                        No audit logs found for this ministry.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
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

        // Adjust layout on window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });

        // Show notifications
        <?php if (isset($error) || isset($success)): ?>
            setTimeout(() => {
                const notification = document.getElementById('<?php echo isset($error) ? 'errorNotification' : 'successNotification'; ?>');
                if (notification) {
                    notification.classList.add('show');
                    
                    // Hide after 5 seconds
                    setTimeout(() => {
                        notification.classList.remove('show');
                    }, 5000);
                }
            }, 300);
        <?php endif; ?>

        // Filter functionality
        document.getElementById('actionFilter').addEventListener('change', applyFilters);
        document.getElementById('userFilter').addEventListener('change', applyFilters);
        document.getElementById('tableFilter').addEventListener('change', applyFilters);
        document.getElementById('dateFrom').addEventListener('change', applyFilters);
        document.getElementById('dateTo').addEventListener('change', applyFilters);
        document.getElementById('clearFilters').addEventListener('click', clearFilters);
        
        function applyFilters() {
            const actionFilter = document.getElementById('actionFilter').value;
            const userFilter = document.getElementById('userFilter').value;
            const tableFilter = document.getElementById('tableFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            
            const rows = document.querySelectorAll('#auditTable tbody .audit-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const action = row.getAttribute('data-action');
                const user = row.getAttribute('data-user');
                const table = row.getAttribute('data-table');
                const date = row.getAttribute('data-date');
                
                let showRow = true;
                
                // Apply action filter
                if (actionFilter && action !== actionFilter) {
                    showRow = false;
                }
                
                // Apply user filter
                if (userFilter && user !== userFilter) {
                    showRow = false;
                }
                
                // Apply table filter
                if (tableFilter && table !== tableFilter) {
                    showRow = false;
                }
                
                // Apply date filter
                if (dateFrom && date < dateFrom) {
                    showRow = false;
                }
                
                if (dateTo && date > dateTo) {
                    showRow = false;
                }
                
                if (showRow) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update the count display
            const countDisplay = document.querySelector('.export-options span');
            if (countDisplay) {
                countDisplay.textContent = `Showing ${visibleCount} logs`;
            }
        }
        
        function clearFilters() {
            document.getElementById('actionFilter').value = '';
            document.getElementById('userFilter').value = '';
            document.getElementById('tableFilter').value = '';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            
            applyFilters();
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const rows = document.querySelectorAll('#auditTable tbody .audit-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchText)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
            
            // Update the count display
            const countDisplay = document.querySelector('.export-options span');
            if (countDisplay) {
                countDisplay.textContent = `Showing ${visibleCount} logs`;
            }
        });
    </script>
</body>
</html>