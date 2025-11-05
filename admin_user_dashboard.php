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

// Fetch users for this ministry
try {
    $stmt = $pdo->prepare("
        SELECT 
            id, name, email, role, created_at, last_login
        FROM user 
        WHERE ministry_id = ? 
        ORDER BY name ASC
    ");
    $stmt->execute([$_SESSION['ministry_id']]);
    $ministry_users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching ministry users: " . $e->getMessage());
    $error = "Failed to load ministry users.";
    $ministry_users = [];
}

// Fetch data requests for this ministry
try {
    $stmt = $pdo->prepare("
        SELECT 
            dr.id, dr.title, dr.description, dr.status, dr.created_at,
            r.name as requester_name, r.ministry_id as requester_ministry_id,
            rm.name as requester_ministry_name
        FROM data_request dr
        JOIN user r ON dr.requester_id = r.id
        JOIN ministry rm ON r.ministry_id = rm.id
        WHERE dr.ministry_id = ?
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$_SESSION['ministry_id']]);
    $ministry_requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching ministry requests: " . $e->getMessage());
    $ministry_requests = [];
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
        LIMIT 50
    ");
    $stmt->execute([$_SESSION['ministry_id']]);
    $ministry_audit_logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
    $ministry_audit_logs = [];
}

// Handle request deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $request_id = $_POST['request_id'];
    
    try {
        // Verify the request belongs to this ministry
        $stmt = $pdo->prepare("SELECT id FROM data_request WHERE id = ? AND ministry_id = ?");
        $stmt->execute([$request_id, $_SESSION['ministry_id']]);
        $request = $stmt->fetch();
        
        if ($request) {
            // Delete the request
            $stmt = $pdo->prepare("DELETE FROM data_request WHERE id = ?");
            $stmt->execute([$request_id]);
            
            // Log the deletion in audit log
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                'DELETE',
                'data_request',
                $request_id,
                'Deleted data request from ministry portal'
            ]);
            
            $success = "Request deleted successfully.";
            
            // Refresh the requests list
            $stmt = $pdo->prepare("
                SELECT 
                    dr.id, dr.title, dr.description, dr.status, dr.created_at,
                    r.name as requester_name, r.ministry_id as requester_ministry_id,
                    rm.name as requester_ministry_name
                FROM data_request dr
                JOIN user r ON dr.requester_id = r.id
                JOIN ministry rm ON r.ministry_id = rm.id
                WHERE dr.ministry_id = ?
                ORDER BY dr.created_at DESC
            ");
            $stmt->execute([$_SESSION['ministry_id']]);
            $ministry_requests = $stmt->fetchAll();
        } else {
            $error = "Request not found or you don't have permission to delete it.";
        }
    } catch (PDOException $e) {
        error_log("Error deleting request: " . $e->getMessage());
        $error = "Failed to delete request.";
    }
}

// Get user stats for dashboard cards
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_users,
            SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as regular_users,
            SUM(CASE WHEN last_login IS NULL THEN 1 ELSE 0 END) as never_logged_in
        FROM user 
        WHERE ministry_id = ?
    ");
    $stats_stmt->execute([$_SESSION['ministry_id']]);
    $stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = ['total_users' => 0, 'admin_users' => 0, 'regular_users' => 0, 'never_logged_in' => 0];
}

// Get request stats
try {
    $request_stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests
        FROM data_request 
        WHERE ministry_id = ?
    ");
    $request_stats_stmt->execute([$_SESSION['ministry_id']]);
    $request_stats = $request_stats_stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching request stats: " . $e->getMessage());
    $request_stats = ['total_requests' => 0, 'pending_requests' => 0, 'approved_requests' => 0, 'rejected_requests' => 0];
}

// Get user initials for avatar
$user_initials = strtoupper(substr($admin['name'], 0, 1) . substr(strstr($admin['name'], ' '), 1, 1));

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ministry Admin Portal |int-ministry exchange</title>
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
        
        .stat-admin .stat-icon {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
        }
        
        .stat-regular .stat-icon {
            background: rgba(139, 92, 246, 0.2);
            color: #8B5CF6;
        }
        
        .stat-never-logged .stat-icon {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
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
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: var(--dark);
            opacity: 0.7;
            transition: all 0.3s ease;
        }
        
        .tab.active {
            opacity: 1;
            border-bottom-color: var(--primary);
            color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
        
        .status-badge {
            display: inline-flex;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        
        .status-approved {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .action-badge {
            display: inline-flex;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .action-create {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .action-update {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        
        .action-delete {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .action-login {
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
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                width: 100%;
                text-align: left;
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
                <span>Inter 
                    Ministry Exchange</span>
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
            <a href="admin_user_audit_logs.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="admin_user_documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Ministry Admin Portal - <?php echo htmlspecialchars($admin['ministry_name']); ?></h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" id="searchInput" placeholder="Search...">
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
            <!-- Users Tab -->
            <div class="tab-content <?php echo $active_tab === 'users' ? 'active' : ''; ?>" id="users-tab">
                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['total_users']); ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-admin">
                        <div class="stat-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['admin_users']); ?></h3>
                            <p>Admin Users</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-regular">
                        <div class="stat-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['regular_users']); ?></h3>
                            <p>Regular Users</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-never-logged">
                        <div class="stat-icon">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($stats['never_logged_in']); ?></h3>
                            <p>Never Logged In</p>
                        </div>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="table-header">
                        <h2>Users in <?php echo htmlspecialchars($admin['ministry_name']); ?></h2>
                        <div class="export-options">
                            <span class="text-sm text-gray-600">
                                Total: <?php echo count($ministry_users); ?> users
                            </span>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Account Created</th>
                                    <th>Last Login</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ministry_users) > 0): ?>
                                    <?php foreach ($ministry_users as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="flex items-center">
                                                    <div class="avatar-small mr-3" style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8em; font-weight: bold;">
                                                        <?php 
                                                            $initials = strtoupper(substr($user['name'], 0, 1));
                                                            if (strpos($user['name'], ' ') !== false) {
                                                                $initials .= strtoupper(substr(strstr($user['name'], ' '), 1, 1));
                                                            }
                                                            echo $initials;
                                                        ?>
                                                    </div>
                                                    <?php echo htmlspecialchars($user['name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <span class="user-role-badge <?php echo $user['role'] === 'admin' ? 'role-admin' : 'role-user'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <?php if ($user['last_login']): ?>
                                                    <?php echo date('M j, Y g:i A', strtotime($user['last_login'])); ?>
                                                <?php else: ?>
                                                    <span class="text-gray-400">Never</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No users found in this ministry.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Requests Tab -->
            <div class="tab-content <?php echo $active_tab === 'requests' ? 'active' : ''; ?>" id="requests-tab">
                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($request_stats['total_requests']); ?></h3>
                            <p>Total Requests</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-admin">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($request_stats['pending_requests']); ?></h3>
                            <p>Pending Requests</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-regular">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($request_stats['approved_requests']); ?></h3>
                            <p>Approved Requests</p>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-never-logged">
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo number_format($request_stats['rejected_requests']); ?></h3>
                            <p>Rejected Requests</p>
                        </div>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="table-header">
                        <h2>Data Requests for <?php echo htmlspecialchars($admin['ministry_name']); ?></h2>
                        <div class="export-options">
                            <span class="text-sm text-gray-600">
                                Total: <?php echo count($ministry_requests); ?> requests
                            </span>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Requester</th>
                                    <th>Ministry</th>
                                    <th>Status</th>
                                    <th>Date Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ministry_requests) > 0): ?>
                                    <?php foreach ($ministry_requests as $request): ?>
                                        <tr>
                                            <td>
                                                <div class="font-medium"><?php echo htmlspecialchars($request['title']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars(substr($request['description'], 0, 50)); ?>...</div>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                                            <td><?php echo htmlspecialchars($request['requester_ministry_name']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo htmlspecialchars($request['status']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <button class="btn btn-outline view-request" data-id="<?php echo $request['id']; ?>" data-title="<?php echo htmlspecialchars($request['title']); ?>" data-description="<?php echo htmlspecialchars($request['description']); ?>" data-requester="<?php echo htmlspecialchars($request['requester_name']); ?>" data-ministry="<?php echo htmlspecialchars($request['requester_ministry_name']); ?>" data-status="<?php echo htmlspecialchars($request['status']); ?>" data-date="<?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this request?');">
                                                        <input type="hidden" name="delete_request" value="1">
                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                        <button type="submit" class="btn btn-danger">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No data requests found for this ministry.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Audit Log Tab -->
      
    <!-- Request Details Modal -->
    <div class="modal" id="requestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Request Details</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                    <p id="modalRequestTitle" class="text-lg font-semibold"></p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <p id="modalRequestDescription" class="text-gray-600"></p>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Requester</label>
                        <p id="modalRequester"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ministry</label>
                        <p id="modalMinistry"></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <p id="modalStatus"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Requested</label>
                        <p id="modalDate"></p>
                    </div>
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

        // Tab functionality
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.getAttribute('data-tab');
                
                // Update URL parameter
                const url = new URL(window.location);
                url.searchParams.set('tab', tabId);
                window.history.replaceState({}, '', url);
                
                // Update active tab
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Show corresponding content
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === `${tabId}-tab`) {
                        content.classList.add('active');
                    }
                });
            });
        });

        // Simple search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const activeTable = document.querySelector('.tab-content.active .data-table tbody');
            
            if (activeTable) {
                const rows = activeTable.querySelectorAll('tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchText)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
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

        // Request modal functionality
        const viewButtons = document.querySelectorAll('.view-request');
        const modal = document.getElementById('requestModal');
        const modalClose = document.getElementById('modalClose');
        
        viewButtons.forEach(button => {
            button.addEventListener('click', () => {
                document.getElementById('modalTitle').textContent = button.getAttribute('data-title');
                document.getElementById('modalRequestTitle').textContent = button.getAttribute('data-title');
                document.getElementById('modalRequestDescription').textContent = button.getAttribute('data-description');
                document.getElementById('modalRequester').textContent = button.getAttribute('data-requester');
                document.getElementById('modalMinistry').textContent = button.getAttribute('data-ministry');
                
                const status = button.getAttribute('data-status');
                document.getElementById('modalStatus').innerHTML = `<span class="status-badge status-${status}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
                
                document.getElementById('modalDate').textContent = button.getAttribute('data-date');
                
                modal.style.display = 'flex';
            });
        });
        
        modalClose.addEventListener('click', () => {
            modal.style.display = 'none';
        });
        
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>
</body>
</html>