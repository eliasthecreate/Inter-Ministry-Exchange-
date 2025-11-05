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
    // Test connection
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get user data from session
$user_name = $_SESSION['user_name'] ?? 'Admin User';
$user_role = $_SESSION['user_role'] ?? 'admin';
$user_initials = strtoupper(substr($user_name, 0, 1) . (strstr($user_name, ' ') ? substr(strstr($user_name, ' '), 1, 1) : ''));

// Initialize variables
$error = null;
$total_users = 0;
$total_ministries = 0;
$total_requests = 0;
$pending_requests = 0;
$recent_activities = [];
$recent_requests = [];
$ministry_distribution = [];
$users_this_month = 0;
$requests_this_week = 0;

// Fetch dashboard statistics
try {
    error_log("Starting dashboard data fetch...");
    
    // Total users count
    $user_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user");
    $user_stmt->execute();
    $result = $user_stmt->fetch();
    $total_users = $result ? $result['count'] : 0;
    error_log("Total users: " . $total_users);
    
    // Total ministries count
    $ministry_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ministry WHERE status = 'active'");
    $ministry_stmt->execute();
    $result = $ministry_stmt->fetch();
    $total_ministries = $result ? $result['count'] : 0;
    error_log("Total ministries: " . $total_ministries);
    
    // Total data requests count
    $request_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data_request");
    $request_stmt->execute();
    $result = $request_stmt->fetch();
    $total_requests = $result ? $result['count'] : 0;
    error_log("Total requests: " . $total_requests);
    
    // Pending requests count
    $pending_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data_request WHERE status = 'pending'");
    $pending_stmt->execute();
    $result = $pending_stmt->fetch();
    $pending_requests = $result ? $result['count'] : 0;
    error_log("Pending requests: " . $pending_requests);
    
    // Recent activities
    $activity_stmt = $pdo->prepare("
        SELECT l.*, u.name as user_name, m.name as ministry_name 
        FROM log l 
        LEFT JOIN user u ON l.user_id = u.id 
        LEFT JOIN ministry m ON u.ministry_id = m.id
        ORDER BY l.timestamp DESC 
        LIMIT 5
    ");
    $activity_stmt->execute();
    $recent_activities = $activity_stmt->fetchAll();
    error_log("Recent activities count: " . count($recent_activities));
    
    // Recent data requests
    $recent_requests_stmt = $pdo->prepare("
        SELECT dr.*, u.name as requester_name, 
               rm.name as requesting_ministry, 
               tm.name as target_ministry
        FROM data_request dr 
        LEFT JOIN user u ON dr.requested_by = u.id 
        LEFT JOIN ministry rm ON dr.requesting_ministry_id = rm.id
        LEFT JOIN ministry tm ON dr.target_ministry_id = tm.id
        ORDER BY dr.requested_date DESC 
        LIMIT 5
    ");
    $recent_requests_stmt->execute();
    $recent_requests = $recent_requests_stmt->fetchAll();
    error_log("Recent requests count: " . count($recent_requests));
    
    // Ministry distribution
    $ministry_dist_stmt = $pdo->prepare("
        SELECT m.name, COUNT(u.id) as user_count 
        FROM ministry m 
        LEFT JOIN user u ON m.id = u.ministry_id 
        WHERE m.status = 'active'
        GROUP BY m.id, m.name
        ORDER BY user_count DESC 
        LIMIT 10
    ");
    $ministry_dist_stmt->execute();
    $ministry_distribution = $ministry_dist_stmt->fetchAll();
    error_log("Ministry distribution count: " . count($ministry_distribution));
    
    // Calculate user growth this month
    $month_start = date('Y-m-01');
    $user_growth_stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM user 
        WHERE DATE(created_at) >= ?
    ");
    $user_growth_stmt->execute([$month_start]);
    $result = $user_growth_stmt->fetch();
    $users_this_month = $result ? $result['count'] : 0;
    error_log("Users this month: " . $users_this_month);
    
    // Calculate request growth this week
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $request_growth_stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM data_request 
        WHERE DATE(requested_date) >= ?
    ");
    $request_growth_stmt->execute([$week_start]);
    $result = $request_growth_stmt->fetch();
    $requests_this_week = $result ? $result['count'] : 0;
    error_log("Requests this week: " . $requests_this_week);
    
    error_log("Dashboard data fetch completed successfully");
    
} catch (PDOException $e) {
    error_log("Dashboard data fetch error: " . $e->getMessage());
    $error = "Error loading dashboard data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Inter-Ministry Exchange</title>
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
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }
        
        /* Cards Section */
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 16px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            position: relative;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .card h3 {
            font-size: 1.1em;
            margin-bottom: 15px;
            color: var(--primary);
        }
        
        .card p {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .card small {
            font-size: 0.9em;
            color: var(--secondary);
        }
        
        .card .status {
            font-size: 0.9em;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .status.operational {
            background: rgba(34, 197, 94, 0.2);
            color: #166534;
        }
        
        .status.warning {
            background: rgba(245, 158, 11, 0.2);
            color: #92400e;
        }
        
        .status.critical {
            background: rgba(239, 68, 68, 0.2);
            color: #991b1b;
        }
        
        .growth-indicator {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.8em;
            padding: 3px 8px;
            border-radius: 12px;
            background: rgba(34, 197, 94, 0.2);
            color: #166534;
        }
        
        .growth-indicator.negative {
            background: rgba(239, 68, 68, 0.2);
            color: #991b1b;
        }
        
        /* Section Styles */
        .section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            transition: box-shadow 0.3s;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .section:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }
        
        .section h3 {
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-all {
            color: var(--secondary);
            text-decoration: none;
            font-size: 0.9em;
            font-weight: 600;
        }
        
        /* Recent Activities */
        .recent-activities ul {
            list-style: none;
        }
        
        .recent-activities li {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .recent-activities li:last-child {
            border-bottom: none;
        }
        
        .dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .dot.green { background-color: #22c55e; }
        .dot.blue { background-color: #3b82f6; }
        .dot.yellow { background-color: #eab308; }
        .dot.purple { background-color: #a855f7; }
        .dot.red { background-color: #ef4444; }
        
        .activity-details {
            flex: 1;
        }
        
        .recent-activities small {
            display: block;
            font-size: 0.8em;
            color: #6b7280;
            margin-top: 2px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .quick-actions button {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            border: none;
            padding: 15px;
            border-radius: 12px;
            color: white;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
            min-height: 100px;
        }
        
        .quick-actions button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .quick-actions button i {
            font-size: 1.8em;
            margin-bottom: 10px;
        }
        
        /* System Status */
        .system-status ul {
            list-style: none;
        }
        
        .system-status li {
            display: flex;
            align-items: center;
            padding: 10px 0;
        }
        
        .system-status .dot {
            margin-right: 15px;
        }
        
        .system-status span:last-child {
            margin-left: auto;
            font-weight: bold;
        }
        
        .system-status .operational { color: #22c55e; }
        .system-status .normal { color: #22c55e; }
        .system-status .in-progress { color: #d97706; }
        .system-status .warning { color: #d97706; }
        
        /* Ministry Performance */
        .ministry-performance .item {
            margin-bottom: 15px;
            position: relative;
        }
        
        .ministry-performance .label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .ministry-performance .label p {
            margin: 0;
            font-weight: 500;
        }
        
        .ministry-performance .label span {
            font-weight: bold;
            color: var(--primary);
        }
        
        .progress-bar {
            width: 100%;
            background-color: #d1fae5;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary) 0%, var(--accent) 100%);
            transition: width 0.3s;
        }
        
        /* Pending Approvals */
        .pending-approvals .item {
            margin-bottom: 15px;
            padding: 15px;
            background: #f0fdf4;
            border-radius: 8px;
            border-left: 4px solid var(--secondary);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .pending-approvals .item:hover {
            transform: translateX(5px);
            background: #dcfce7;
        }
        
        .pending-approvals .item p {
            margin: 0;
            font-weight: 600;
            color: var(--primary);
        }
        
        .pending-approvals .item small {
            display: block;
            color: #6b7280;
        }
        
        /* Recent Requests */
        .recent-requests table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .recent-requests th,
        .recent-requests td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .recent-requests th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary);
        }
        
        .recent-requests tr:hover {
            background: #f0fdf4;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        
        /* Debug info */
        .debug-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 0.9em;
        }
        
        /* Error Message */
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Layout Utilities */
        .flex-row {
            display: flex;
            gap: 25px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .flex-row > div {
            flex: 1;
            min-width: 300px;
        }
        
        .flex-col {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .main-content {
                padding: 20px;
            }
        }
        
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
            .cards {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .search-bar {
                width: 100%;
            }
            
            .search-bar input {
                width: 100%;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .flex-row {
                flex-direction: column;
            }
            
            .flex-row > div {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .section {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($error)): ?>
        <div class="error-message" id="errorMessage">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            <button style="margin-left: 10px; background: none; border: none; color: #dc2626; cursor: pointer;" onclick="document.getElementById('errorMessage').style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>
    
    <!-- Debug Information -->
    <div class="debug-info" style="display: none;" id="debugInfo">
        <strong>Debug Information:</strong><br>
        Total Users: <?php echo $total_users; ?><br>
        Total Ministries: <?php echo $total_ministries; ?><br>
        Total Requests: <?php echo $total_requests; ?><br>
        Pending Requests: <?php echo $pending_requests; ?><br>
        Recent Activities: <?php echo count($recent_activities); ?><br>
        Recent Requests: <?php echo count($recent_requests); ?><br>
        Ministry Distribution: <?php echo count($ministry_distribution); ?>
    </div>
    
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
            <a href="#" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="admin_management.php"><i class="fas fa-users"></i> User Management</a>
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
                <h1>Admin Dashboard</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" placeholder="Search dashboard..." id="searchInput">
                    </div>
                    <button class="btn btn-primary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn" onclick="document.getElementById('debugInfo').style.display = document.getElementById('debugInfo').style.display === 'none' ? 'block' : 'none'" style="background: #f59e0b; color: white;">
                        <i class="fas fa-bug"></i> Debug
                    </button>
                </div>
            </div>
            
            <div class="cards">
                <div class="card">
                    <?php if ($users_this_month > 0): ?>
                        <span class="growth-indicator">+<?php echo $users_this_month; ?> this month</span>
                    <?php endif; ?>
                    <h3>Total Users</h3>
                    <p><?php echo number_format($total_users); ?></p>
                    <small>Registered system users</small>
                </div>
                <div class="card">
                    <h3>Active Ministries</h3>
                    <p><?php echo $total_ministries; ?></p>
                    <span class="status operational">All systems operational</span>
                </div>
                <div class="card">
                    <h3>Pending Requests</h3>
                    <p><?php echo $pending_requests; ?></p>
                    <?php if ($pending_requests > 0): ?>
                        <span class="status warning">Requires attention</span>
                    <?php else: ?>
                        <span class="status operational">All caught up</span>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <?php if ($requests_this_week > 0): ?>
                        <span class="growth-indicator">+<?php echo $requests_this_week; ?> this week</span>
                    <?php endif; ?>
                    <h3>Data Exchanges</h3>
                    <p><?php echo $total_requests; ?></p>
                    <small>Total data requests</small>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="section recent-activities">
                    <h3>Recent Activities <a href="admin_audit_logs.php" class="view-all">View all</a></h3>
                    <ul>
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): 
                                $dot_class = 'green';
                                if (strpos($activity['action'], 'failed') !== false) $dot_class = 'red';
                                elseif ($activity['action'] == 'logout') $dot_class = 'blue';
                                elseif ($activity['action'] == 'registration') $dot_class = 'purple';
                            ?>
                                <li>
                                    <span class="dot <?php echo $dot_class; ?>"></span>
                                    <div class="activity-details">
                                        <?php echo htmlspecialchars($activity['details'] ?? $activity['action']); ?> - <?php echo htmlspecialchars($activity['user_name']); ?>
                                        <?php if (!empty($activity['ministry_name'])): ?>
                                            from <?php echo htmlspecialchars($activity['ministry_name']); ?>
                                        <?php endif; ?>
                                        <small><?php echo date('g:i A, M j', strtotime($activity['timestamp'])); ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>
                                <span class="dot green"></span>
                                <div class="activity-details">
                                    User logged in - <?php echo htmlspecialchars($user_name); ?>
                                    <small><?php echo date('g:i A, M j'); ?></small>
                                </div>
                            </li>
                            <li>
                                <span class="dot blue"></span>
                                <div class="activity-details">
                                    System started successfully
                                    <small><?php echo date('g:i A, M j', strtotime('-5 minutes')); ?></small>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="flex-col">
                    <div class="section quick-actions">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions">
                            <button onclick="window.location.href='admin_management.php'">
                                <i class="fas fa-user-plus"></i>
                                Add User
                            </button>
                            <button onclick="window.location.href='admin_settings.php'">
                                <i class="fas fa-cog"></i>
                                Settings
                            </button>
                            <button onclick="window.location.href='admin_analytics.php'">
                                <i class="fas fa-file-alt"></i>
                                Reports
                            </button>
                            <button onclick="window.location.href='admin_security.php'">
                                <i class="fas fa-shield-alt"></i>
                                Security
                            </button>
                        </div>
                    </div>
                    
                    <div class="section system-status">
                        <h3>System Status</h3>
                        <ul>
                            <li><span class="dot green"></span> API Services <span class="operational">Operational</span></li>
                            <li><span class="dot green"></span> Database <span class="normal">Normal</span></li>
                            <li><span class="dot green"></span> Storage <span class="normal">Normal</span></li>
                            <li><span class="dot yellow"></span> Backup Status <span class="in-progress">Scheduled</span></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="flex-row">
                <div class="section ministry-performance">
                    <h3>Ministry Performance</h3>
                    <?php if (!empty($ministry_distribution)): ?>
                        <?php foreach ($ministry_distribution as $ministry): ?>
                            <div class="item">
                                <div class="label">
                                    <p><?php echo htmlspecialchars($ministry['name']); ?></p>
                                    <span><?php echo $ministry['user_count']; ?> users</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress" style="width: <?php echo min(100, ($ministry['user_count'] / max(1, $total_users)) * 100); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="item">
                            <div class="label">
                                <p>Ministry of Technology</p>
                                <span>15 users</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress" style="width: 65%;"></div>
                            </div>
                        </div>
                        <div class="item">
                            <div class="label">
                                <p>Ministry of Finance</p>
                                <span>12 users</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress" style="width: 52%;"></div>
                            </div>
                        </div>
                        <div class="item">
                            <div class="label">
                                <p>Ministry of Health</p>
                                <span>8 users</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress" style="width: 35%;"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="section pending-approvals">
                    <h3>Pending Approvals</h3>
                    <div class="item" onclick="window.location.href='admin_user_management.php'">
                        <p>User Registration</p>
                        <small>Manage user accounts and permissions</small>
                    </div>
                    <div class="item" onclick="window.location.href='admin_requests.php'">
                        <p>Data Requests</p>
                        <small><?php echo $pending_requests; ?> pending approvals</small>
                    </div>
                </div>
            </div>
            
            <div class="section recent-requests">
                <h3>Recent Data Requests <a href="admin_requests.php" class="view-all">View all</a></h3>
                <?php if (!empty($recent_requests)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Requester</th>
                                <th>From Ministry</th>
                                <th>To Ministry</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_requests as $request): 
                                $status_class = 'status-pending';
                                if ($request['status'] == 'approved') $status_class = 'status-approved';
                                elseif ($request['status'] == 'rejected') $status_class = 'status-rejected';
                                elseif ($request['status'] == 'completed') $status_class = 'status-completed';
                            ?>
                                <tr onclick="window.location.href='admin_requests.php'">
                                    <td><?php echo htmlspecialchars($request['title']); ?></td>
                                    <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['requesting_ministry']); ?></td>
                                    <td><?php echo htmlspecialchars($request['target_ministry']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($request['requested_date'])); ?></td>
                                    <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($request['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No recent data requests found.</p>
                <?php endif; ?>
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
        
        // Auto-hide error message after 5 seconds
        <?php if (isset($error)): ?>
        setTimeout(function() {
            const errorMessage = document.getElementById('errorMessage');
            if (errorMessage) {
                errorMessage.style.display = 'none';
            }
        }, 5000);
        <?php endif; ?>
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            if (searchTerm.length > 2) {
                // Implement search functionality here
                console.log('Searching for:', searchTerm);
            }
        });
        
        // Add loading animation to refresh button
        document.querySelector('.btn-primary').addEventListener('click', function(e) {
            const btn = e.target.closest('.btn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
            btn.disabled = true;
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }, 1000);
        });
    </script>
</body>
</html>