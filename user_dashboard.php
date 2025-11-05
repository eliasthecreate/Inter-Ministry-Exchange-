<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: loginSignup.php");
    exit;
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: loginSignup.php");
    exit;
}

// Fetch user data for the navigation bar
try {
    $stmt = $pdo->prepare("SELECT u.name, u.email, u.ministry_id, u.role FROM user u WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        error_log("User not found: user_id={$_SESSION['user_id']}");
        session_destroy();
        header("Location: loginSignup.php");
        exit;
    }
    
    $_SESSION['user_ministry_id'] = $user['ministry_id'];
    $user_name = htmlspecialchars($user['name']);
    $user_role = $user['role'];
    
    // Fetch ministry data separately
    $stmt = $pdo->prepare("SELECT abbreviation, name FROM ministry WHERE id = ?");
    $stmt->execute([$user['ministry_id']]);
    $ministry = $stmt->fetch();
    
    if ($ministry) {
        $user_ministry_abbr = htmlspecialchars($ministry['abbreviation']);
        $user_ministry_name = htmlspecialchars($ministry['name']);
    } else {
        $user_ministry_abbr = 'N/A';
        $user_ministry_name = 'Unknown Ministry';
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Failed to load user data.";
}

// Get filter parameters
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'today';
$ministry_filter = isset($_GET['ministry_filter']) ? $_GET['ministry_filter'] : '';

// Set date range based on selection
$date_from = '';
$date_to = date('Y-m-d');

switch($date_range) {
    case 'today':
        $date_from = date('Y-m-d');
        break;
    case 'yesterday':
        $date_from = date('Y-m-d', strtotime('-1 day'));
        $date_to = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        break;
    case 'month':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        break;
    case 'quarter':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'custom':
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        break;
    default:
        $date_from = date('Y-m-d');
}

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($date_from)) {
    $where_conditions[] = "DATE(al.timestamp) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(al.timestamp) <= ?";
    $params[] = $date_to;
}

if (!empty($ministry_filter)) {
    $where_conditions[] = "u.ministry_id = ?";
    $params[] = $ministry_filter;
}

// For regular users, only show their own ministry's activities
if ($user_role !== 'super_admin' && $user_role !== 'admin') {
    $where_conditions[] = "u.ministry_id = ?";
    $params[] = $_SESSION['user_ministry_id'];
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get stats for dashboard cards
try {
    // Total activities
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT al.user_id) as unique_users,
            SUM(CASE WHEN al.action = 'login' THEN 1 ELSE 0 END) as total_logins,
            SUM(CASE WHEN al.action = 'failed_login' THEN 1 ELSE 0 END) as failed_logins,
            SUM(CASE WHEN al.action = 'CREATE' THEN 1 ELSE 0 END) as created_records,
            SUM(CASE WHEN al.action = 'UPDATE' THEN 1 ELSE 0 END) as updated_records,
            SUM(CASE WHEN al.action = 'DELETE' THEN 1 ELSE 0 END) as deleted_records
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        $where_clause
    ");
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch();
    
    // Activities by hour for chart
    $hourly_stmt = $pdo->prepare("
        SELECT 
            HOUR(al.timestamp) as hour,
            COUNT(*) as count
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        $where_clause
        GROUP BY HOUR(al.timestamp)
        ORDER BY hour
    ");
    $hourly_stmt->execute($params);
    $hourly_data = $hourly_stmt->fetchAll();
    
    // Activities by action type
    $action_stmt = $pdo->prepare("
        SELECT 
            al.action,
            COUNT(*) as count
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        $where_clause
        GROUP BY al.action
        ORDER BY count DESC
    ");
    $action_stmt->execute($params);
    $action_data = $action_stmt->fetchAll();
    
    // Activities by ministry
    $ministry_stmt = $pdo->prepare("
        SELECT 
            m.name as ministry_name,
            m.abbreviation as ministry_abbr,
            COUNT(*) as count
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        GROUP BY m.id, m.name, m.abbreviation
        ORDER BY count DESC
        LIMIT 10
    ");
    $ministry_stmt->execute($params);
    $ministry_data = $ministry_stmt->fetchAll();
    
    // Recent activities
    $recent_stmt = $pdo->prepare("
        SELECT 
            al.id,
            al.action,
            al.table_affected,
            al.record_id,
            al.details,
            al.timestamp,
            u.name as user_name,
            m.name as ministry_name,
            m.abbreviation as ministry_abbr
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        ORDER BY al.timestamp DESC
        LIMIT 10
    ");
    $recent_stmt->execute($params);
    $recent_activities = $recent_stmt->fetchAll();
    
    // Top users
    $users_stmt = $pdo->prepare("
        SELECT 
            u.name as user_name,
            m.name as ministry_name,
            COUNT(*) as activity_count
        FROM audit_log al
        LEFT JOIN user u ON al.user_id = u.id
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        GROUP BY u.id, u.name, m.name
        ORDER BY activity_count DESC
        LIMIT 10
    ");
    $users_stmt->execute($params);
    $top_users = $users_stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Error fetching dashboard data: " . $e->getMessage());
    $error = "Failed to load dashboard data.";
    $stats = ['total_activities' => 0, 'unique_users' => 0, 'total_logins' => 0, 'failed_logins' => 0];
    $hourly_data = [];
    $action_data = [];
    $ministry_data = [];
    $recent_activities = [];
    $top_users = [];
}

// Fetch ministries for filter dropdown
try {
    $stmt = $pdo->query("SELECT id, name, abbreviation FROM ministry ORDER BY name ASC");
    $available_ministries = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching ministries: " . $e->getMessage());
    $available_ministries = [];
}

function getActionBadge($action) {
    $classes = [
        'CREATE' => 'bg-green-100 text-green-800 border border-green-200',
        'UPDATE' => 'bg-blue-100 text-blue-800 border border-blue-200',
        'DELETE' => 'bg-red-100 text-red-800 border border-red-200',
        'LOGIN' => 'bg-purple-100 text-purple-800 border border-purple-200',
        'LOGOUT' => 'bg-gray-100 text-gray-800 border border-gray-200',
        'login' => 'bg-green-100 text-green-800 border border-green-200',
        'logout' => 'bg-gray-100 text-gray-800 border border-gray-200',
        'failed_login' => 'bg-red-100 text-red-800 border border-red-200'
    ];
    $class = isset($classes[$action]) ? $classes[$action] : 'bg-gray-100 text-gray-800 border border-gray-200';
    return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full ' . $class . '">' . strtoupper($action) . '</span>';
}

function getTableBadge($table) {
    $classes = [
        'data_request' => 'bg-blue-100 text-blue-800 border border-blue-200',
        'user' => 'bg-purple-100 text-purple-800 border border-purple-200',
        'ministry' => 'bg-yellow-100 text-yellow-800 border border-yellow-200'
    ];
    $class = isset($classes[$table]) ? $classes[$table] : 'bg-gray-100 text-gray-800 border border-gray-200';
    return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full ' . $class . '">' . ucfirst(str_replace('_', ' ', $table)) . '</span>';
}

// Get user initials for avatar
$user_initials = '';
if (!empty($user_name)) {
    $name_parts = explode(' ', $user_name);
    $user_initials = strtoupper(substr($name_parts[0], 0, 1));
    if (count($name_parts) > 1) {
        $user_initials .= strtoupper(substr($name_parts[1], 0, 1));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Inter Ministry Exchange</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #1e3a8a;
            --accent: #60a5fa;
            --light: #f0f7ff;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            background: linear-gradient(135deg, var(--light) 0%, #dbeafe 100%);
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
            background: rgba(59, 130, 246, 0.9);
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
            background: rgba(96, 165, 250, 0.3);
            transform: translateX(5px);
            border-color: var(--glass-border);
        }
        
        .sidebar a.active { 
            background: rgba(96, 165, 250, 0.4);
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
            background: rgba(59, 130, 246, 0.2);
            color: var(--primary);
        }
        
        .stat-users .stat-icon {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .stat-logins .stat-icon {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .stat-failed .stat-icon {
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
        
        /* Filter Form */
        .filter-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
        }
        
        /* Charts and Tables */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-container, .table-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .chart-header, .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary);
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            text-align: left;
            padding: 15px;
            background: rgba(59, 130, 246, 0.1);
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
            background: rgba(59, 130, 246, 0.05);
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.85em;
            font-weight: 500;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .filter-container, .export-options {
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .table-container, .chart-container {
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
                    <strong><?php echo $user_name; ?></strong>
                    <div class="user-role"><?php echo $user_ministry_abbr; ?></div>
                </div>
            </div>
            <a href="user_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
            <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="?action=logout" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Dashboard Overview</h1>
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
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="notification success" id="successNotification">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
            <?php endif; ?>
            
            <div class="filter-container">
                <div class="form-header">
                    <h2>Filter Data</h2>
                </div>
                <form method="GET" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="date_range">Date Range</label>
                            <select id="date_range" name="date_range" class="filter-input" onchange="toggleCustomDates()">
                                <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="yesterday" <?php echo $date_range === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                <option value="week" <?php echo $date_range === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $date_range === 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="quarter" <?php echo $date_range === 'quarter' ? 'selected' : ''; ?>>Last 90 Days</option>
                                <option value="custom" <?php echo $date_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="custom_dates" style="<?php echo $date_range === 'custom' ? '' : 'display: none;'; ?>">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label for="date_from">Date From</label>
                                    <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                <div>
                                    <label for="date_to">Date To</label>
                                    <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
                        <div class="form-group">
                            <label for="ministry_filter">Ministry</label>
                            <select id="ministry_filter" name="ministry_filter" class="filter-input">
                                <option value="">All Ministries</option>
                                <?php foreach ($available_ministries as $ministry): ?>
                                    <option value="<?php echo $ministry['id']; ?>" <?php echo $ministry_filter == $ministry['id'] ? 'selected' : ''; ?>>
                                        <?php echo $ministry['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">Clear</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card stat-total">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_activities']); ?></h3>
                        <p>Total Activities</p>
                    </div>
                </div>
                
                <div class="stat-card stat-users">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['unique_users']); ?></h3>
                        <p>Active Users</p>
                    </div>
                </div>
                
                <div class="stat-card stat-logins">
                    <div class="stat-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_logins']); ?></h3>
                        <p>Successful Logins</p>
                    </div>
                </div>
                
                <div class="stat-card stat-failed">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['failed_logins']); ?></h3>
                        <p>Failed Logins</p>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h2>Activities by Hour</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h2>Activities by Type</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="actionChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="table-container">
                    <div class="table-header">
                        <h2>Recent Activities</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Ministry</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recent_activities) > 0): ?>
                                    <?php foreach ($recent_activities as $log): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($log['timestamp'])); ?></td>
                                            <td><?php echo $log['user_name'] ?? 'N/A'; ?></td>
                                            <td><?php echo $log['ministry_name'] ?? 'N/A'; ?></td>
                                            <td><?php echo getActionBadge($log['action']); ?></td>
                                            <td><?php echo $log['details']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No recent activities found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="table-header">
                        <h2>Top Users</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Ministry</th>
                                    <th>Activity Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($top_users) > 0): ?>
                                    <?php foreach ($top_users as $user_data): ?>
                                        <tr>
                                            <td><?php echo $user_data['user_name']; ?></td>
                                            <td><?php echo $user_data['ministry_name'] ?? 'N/A'; ?></td>
                                            <td><?php echo number_format($user_data['activity_count']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No user data available.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2>Activities by Ministry</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ministry</th>
                                <th>Activity Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($ministry_data) > 0): ?>
                                <?php 
                                $total_ministry_activities = 0;
                                foreach ($ministry_data as $ministry) {
                                    $total_ministry_activities += $ministry['count'];
                                }
                                ?>
                                <?php foreach ($ministry_data as $ministry): ?>
                                    <tr>
                                        <td><?php echo $ministry['ministry_name']; ?></td>
                                        <td><?php echo number_format($ministry['count']); ?></td>
                                        <td><?php echo $total_ministry_activities > 0 ? number_format(($ministry['count'] / $total_ministry_activities) * 100, 1) : '0'; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 30px; color: var(--dark);">
                                        No ministry data available.
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

        // Show/hide custom date inputs
        function toggleCustomDates() {
            const dateRange = document.getElementById('date_range');
            const customDates = document.getElementById('custom_dates');
            
            if (dateRange.value === 'custom') {
                customDates.style.display = 'block';
            } else {
                customDates.style.display = 'none';
            }
        }

        // Simple search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const tables = document.querySelectorAll('.data-table tbody');
            
            tables.forEach(table => {
                const rows = table.querySelectorAll('tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchText)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
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

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Hourly activities chart
            const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
            
            <?php
            // Prepare hourly data for chart
            $hourLabels = [];
            $hourCounts = [];
            
            // Initialize all hours with 0
            for ($i = 0; $i < 24; $i++) {
                $hourLabels[] = sprintf('%02d:00', $i);
                $hourCounts[] = 0;
            }
            
            // Fill in actual data
            foreach ($hourly_data as $data) {
                $hour = intval($data['hour']);
                $hourCounts[$hour] = intval($data['count']);
            }
            ?>
            
            const hourlyChart = new Chart(hourlyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($hourLabels); ?>,
                    datasets: [{
                        label: 'Activities',
                        data: <?php echo json_encode($hourCounts); ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
            
            // Action type chart
            const actionCtx = document.getElementById('actionChart').getContext('2d');
            
            <?php
            // Prepare action data for chart
            $actionLabels = [];
            $actionCounts = [];
            $actionColors = [];
            
            $colorMap = [
                'CREATE' => 'rgba(16, 185, 129, 0.7)',
                'UPDATE' => 'rgba(59, 130, 246, 0.7)',
                'DELETE' => 'rgba(239, 68, 68, 0.7)',
                'LOGIN' => 'rgba(139, 92, 246, 0.7)',
                'LOGOUT' => 'rgba(107, 114, 128, 0.7)',
                'login' => 'rgba(16, 185, 129, 0.7)',
                'logout' => 'rgba(107, 114, 128, 0.7)',
                'failed_login' => 'rgba(239, 68, 68, 0.7)'
            ];
            
            foreach ($action_data as $data) {
                $actionLabels[] = ucfirst(strtolower($data['action']));
                $actionCounts[] = intval($data['count']);
                $actionColors[] = isset($colorMap[$data['action']]) ? $colorMap[$data['action']] : 'rgba(107, 114, 128, 0.7)';
            }
            ?>
            
            const actionChart = new Chart(actionCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($actionLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($actionCounts); ?>,
                        backgroundColor: <?php echo json_encode($actionColors); ?>,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right'
                        }
                    }
                }
            });
        });

        // Auto-refresh page every 5 minutes to get latest data
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>