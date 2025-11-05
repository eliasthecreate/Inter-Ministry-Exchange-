<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in (all users can access audit logs)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
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
$action_filter = isset($_GET['action_filter']) ? $_GET['action_filter'] : '';
$user_filter = isset($_GET['user_filter']) ? $_GET['user_filter'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($action_filter)) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action_filter;
}

if (!empty($user_filter)) {
    $where_conditions[] = "u.name LIKE ?";
    $params[] = "%$user_filter%";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(al.timestamp) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(al.timestamp) <= ?";
    $params[] = $date_to;
}

// For regular users, only show their own ministry's activities
if ($user_role !== 'super_admin' && $user_role !== 'admin') {
    $where_conditions[] = "u.ministry_id = ?";
    $params[] = $_SESSION['user_ministry_id'];
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Handle CSV download
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'download_audit_logs') {
    try {
        $stmt = $pdo->prepare("
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
        ");
        $stmt->execute($params);
        $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');

        // Create CSV output
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Timestamp', 'User', 'Ministry', 'Ministry Abbreviation', 'Action', 'Table Affected', 'Record ID', 'Details']);

        foreach ($audit_logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['timestamp'],
                $log['user_name'],
                $log['ministry_name'],
                $log['ministry_abbr'],
                $log['action'],
                $log['table_affected'] ?: '-',
                $log['record_id'] ?: '-',
                $log['details']
            ]);
        }

        fclose($output);
        exit;
    } catch (PDOException $e) {
        error_log("Error downloading audit logs: " . $e->getMessage());
        $error = "Failed to download audit logs. Please try again.";
    }
}

// Fetch audit logs
try {
    $stmt = $pdo->prepare("
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
    ");
    $stmt->execute($params);
    $audit_logs = $stmt->fetchAll();
    
    $total_records = count($audit_logs);
    
} catch (PDOException $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
    $error = "Failed to load audit logs.";
    $audit_logs = [];
    $total_records = 0;
}

// Fetch unique actions for filter dropdown
try {
    $stmt = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");
    $available_actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching actions: " . $e->getMessage());
    $available_actions = [];
}

// Fetch login activities from the log table
try {
    $login_where_conditions = [];
    $login_params = [];
    
    // For regular users, only show their own ministry's login activities
    if ($user_role !== 'super_admin' && $user_role !== 'admin') {
        $login_where_conditions[] = "u.ministry_id = ?";
        $login_params[] = $_SESSION['user_ministry_id'];
    }
    
    $login_where_clause = !empty($login_where_conditions) ? 'WHERE ' . implode(' AND ', $login_where_conditions) : '';
    
    $login_stmt = $pdo->prepare("
        SELECT 
            l.id,
            l.action,
            l.details,
            l.timestamp,
            u.name as user_name,
            m.name as ministry_name,
            m.abbreviation as ministry_abbr
        FROM log l
        LEFT JOIN user u ON l.user_id = u.id
        LEFT JOIN ministry m ON u.ministry_id = m.id
        WHERE l.action IN ('login', 'logout', 'failed_login')
        $login_where_clause
        ORDER BY l.timestamp DESC
        LIMIT 50
    ");
    $login_stmt->execute($login_params);
    $login_activities = $login_stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Error fetching login activities: " . $e->getMessage());
    $login_activities = [];
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

// Get stats for dashboard cards
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT user_id) as unique_users,
            SUM(CASE WHEN action = 'login' THEN 1 ELSE 0 END) as total_logins,
            SUM(CASE WHEN action = 'failed_login' THEN 1 ELSE 0 END) as failed_logins
        FROM audit_log
        WHERE DATE(timestamp) = CURDATE()
    ");
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats = ['total_activities' => 0, 'unique_users' => 0, 'total_logins' => 0, 'failed_logins' => 0];
}

// Get user initials for avatar
$user_initials = strtoupper(substr($user_name, 0, 1) . substr(strstr($user_name, ' '), 1, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | int-ministry exchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        .stat-approved .stat-icon {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .stat-pending .stat-icon {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .stat-rejected .stat-icon {
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
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .export-options {
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
            
            .form-grid {
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
                <span>int-ministry exchange</span>
            </div>
            <div class="user-info">
                <div class="avatar"><?php echo $user_initials; ?></div>
                <div class="user-details">
                    <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    <div class="user-role"><?php echo htmlspecialchars($user_ministry_abbr); ?></div>
                </div>
            </div>
               <a href="user_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php" class="active"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
             <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Audit Logs</h1>
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
                
                <div class="stat-card stat-approved">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['unique_users']); ?></h3>
                        <p>Active Users</p>
                    </div>
                </div>
                
                <div class="stat-card stat-pending">
                    <div class="stat-icon">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_logins']); ?></h3>
                        <p>Successful Logins</p>
                    </div>
                </div>
                
                <div class="stat-card stat-rejected">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['failed_logins']); ?></h3>
                        <p>Failed Logins</p>
                    </div>
                </div>
            </div>
            
            <div class="filter-container">
                <div class="form-header">
                    <h2>Filter Logs</h2>
                </div>
                <form method="GET" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="action_filter">Action Type</label>
                            <select id="action_filter" name="action_filter" class="filter-input">
                                <option value="">All Actions</option>
                                <?php foreach ($available_actions as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst(strtolower($action))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="user_filter">User Name</label>
                            <input type="text" id="user_filter" name="user_filter" value="<?php echo htmlspecialchars($user_filter); ?>" placeholder="Search user...">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">Clear</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-header">
                    <h2>System Activities</h2>
                    <div class="export-options">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="download_audit_logs">
                            <input type="hidden" name="action_filter" value="<?php echo htmlspecialchars($action_filter); ?>">
                            <input type="hidden" name="user_filter" value="<?php echo htmlspecialchars($user_filter); ?>">
                            <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                            <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                            <button type="submit" class="btn btn-outline">
                                <i class="fas fa-download"></i> Export CSV
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="tabs">
                    <button class="tab active" data-tab="system">System Activities</button>
                    <button class="tab" data-tab="auth">Authentication Events</button>
                </div>
                
                <div class="tab-content active" id="system-tab">
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Ministry</th>
                                    <th>Action</th>
                                    <th>Table Affected</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($audit_logs) > 0): ?>
                                    <?php foreach ($audit_logs as $log): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($log['timestamp'])); ?></td>
                                            <td><?php echo htmlspecialchars($log['user_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($log['ministry_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo getActionBadge($log['action']); ?></td>
                                            <td><?php echo getTableBadge($log['table_affected']); ?></td>
                                            <td><?php echo htmlspecialchars($log['details']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No system activities found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-content" id="auth-tab">
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
                                <?php if (count($login_activities) > 0): ?>
                                    <?php foreach ($login_activities as $activity): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($activity['timestamp'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['user_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($activity['ministry_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo getActionBadge($activity['action']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['details']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No authentication events found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

        // Auto-refresh page every 5 minutes to get latest logs
        setTimeout(function() {
            window.location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>