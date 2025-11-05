<?php
session_start();
require_once 'config/database.php';

// Enhanced error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Fetch user data for the navigation bar with improved error handling
try {
    // Test database connection first
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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
    
    // Debug: Check if ministry exists
    if (empty($user['ministry_id'])) {
        error_log("User has no ministry assigned: user_id={$_SESSION['user_id']}");
        $user_ministry_abbr = 'N/A';
        $user_ministry_name = 'No Ministry Assigned';
    } else {
        $stmt = $pdo->prepare("SELECT abbreviation, name FROM ministry WHERE id = ?");
        $stmt->execute([$user['ministry_id']]);
        $ministry = $stmt->fetch();
        
        if ($ministry) {
            $user_ministry_abbr = htmlspecialchars($ministry['abbreviation']);
            $user_ministry_name = htmlspecialchars($ministry['name']);
        } else {
            error_log("Ministry not found: ministry_id={$user['ministry_id']}");
            $user_ministry_abbr = 'N/A';
            $user_ministry_name = 'Unknown Ministry';
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Failed to load user data. Please check database connection.";
    $user_name = 'User';
    $user_role = 'user';
    $user_ministry_abbr = 'N/A';
    $user_ministry_name = 'Unknown Ministry';
}

// Get filter parameters with defaults
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'month';
$ministry_filter = isset($_GET['ministry_filter']) ? $_GET['ministry_filter'] : '';
$user_status = isset($_GET['user_status']) ? $_GET['user_status'] : '';

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
    case 'year':
        $date_from = date('Y-m-d', strtotime('-365 days'));
        break;
    case 'custom':
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
        break;
    default:
        $date_from = date('Y-m-d', strtotime('-30 days'));
}

// Build WHERE clause for filters with improved logic
$where_conditions = [];
$params = [];

if (!empty($date_from)) {
    $where_conditions[] = "DATE(u.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(u.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($ministry_filter)) {
    $where_conditions[] = "u.ministry_id = ?";
    $params[] = $ministry_filter;
}

if (!empty($user_status)) {
    if ($user_status === 'active') {
        $where_conditions[] = "u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($user_status === 'inactive') {
        $where_conditions[] = "(u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY))";
    }
}

// For regular users, only show their own ministry's data
if ($user_role !== 'super_admin' && $user_role !== 'admin') {
    if (!empty($_SESSION['user_ministry_id'])) {
        $where_conditions[] = "u.ministry_id = ?";
        $params[] = $_SESSION['user_ministry_id'];
    } else {
        // If user has no ministry, show no data
        $where_conditions[] = "u.ministry_id IS NULL";
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get user analytics data with improved error handling
try {
    // Total user stats - SIMPLIFIED VERSION
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_users,
            COUNT(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_users,
            COUNT(CASE WHEN u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as inactive_users,
            COUNT(CASE WHEN u.role IN ('admin', 'super_admin') THEN 1 END) as admin_users,
            COUNT(DISTINCT u.ministry_id) as ministries_represented
        FROM user u
        $where_clause
    ");
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch();
    
    if (!$stats) {
        $stats = ['total_users' => 0, 'active_users' => 0, 'inactive_users' => 0, 'admin_users' => 0, 'ministries_represented' => 0];
    }
    
    // User growth over time
    $growth_stmt = $pdo->prepare("
        SELECT 
            DATE(u.created_at) as date,
            COUNT(*) as new_users,
            COUNT(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_on_date
        FROM user u
        $where_clause
        GROUP BY DATE(u.created_at)
        ORDER BY date
    ");
    $growth_stmt->execute($params);
    $growth_data = $growth_stmt->fetchAll();
    
    // Users by ministry
    $ministry_stmt = $pdo->prepare("
        SELECT 
            m.name as ministry_name,
            m.abbreviation as ministry_abbr,
            COUNT(*) as user_count,
            COUNT(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_users
        FROM user u
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        GROUP BY m.id, m.name, m.abbreviation
        ORDER BY user_count DESC
        LIMIT 10
    ");
    $ministry_stmt->execute($params);
    $ministry_data = $ministry_stmt->fetchAll();
    
    // Users by role
    $role_stmt = $pdo->prepare("
        SELECT 
            u.role,
            COUNT(*) as user_count
        FROM user u
        $where_clause
        GROUP BY u.role
        ORDER BY user_count DESC
    ");
    $role_stmt->execute($params);
    $role_data = $role_stmt->fetchAll();
    
    // Recent user registrations
    $recent_stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at,
            u.last_login,
            m.name as ministry_name,
            m.abbreviation as ministry_abbr
        FROM user u
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $recent_stmt->execute($params);
    $recent_users = $recent_stmt->fetchAll();
    
    // Top active users (simplified without audit_log join)
    $active_stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.role,
            u.last_login,
            m.name as ministry_name,
            m.abbreviation as ministry_abbr
        FROM user u
        LEFT JOIN ministry m ON u.ministry_id = m.id
        $where_clause
        ORDER BY u.last_login DESC
        LIMIT 10
    ");
    $active_stmt->execute($params);
    $active_users = $active_stmt->fetchAll();
    
    // Login frequency analysis (simplified)
    $login_stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN last_login IS NULL THEN 'Never'
                WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'This Week'
                WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'This Month'
                WHEN last_login >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 'Last 3 Months'
                ELSE 'Over 3 Months'
            END as login_frequency,
            COUNT(*) as user_count
        FROM user u
        $where_clause
        GROUP BY login_frequency
        ORDER BY 
            CASE login_frequency
                WHEN 'Never' THEN 0
                WHEN 'This Week' THEN 1
                WHEN 'This Month' THEN 2
                WHEN 'Last 3 Months' THEN 3
                ELSE 4
            END
    ");
    $login_stmt->execute($params);
    $login_frequency_data = $login_stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Error fetching user analytics data: " . $e->getMessage());
    $error = "Failed to load user analytics data. Error: " . $e->getMessage();
    $stats = ['total_users' => 0, 'active_users' => 0, 'inactive_users' => 0, 'admin_users' => 0, 'ministries_represented' => 0];
    $growth_data = [];
    $ministry_data = [];
    $role_data = [];
    $recent_users = [];
    $active_users = [];
    $login_frequency_data = [];
}

// Fetch ministries for filter dropdown
try {
    $stmt = $pdo->query("SELECT id, name, abbreviation FROM ministry ORDER BY name ASC");
    $available_ministries = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching ministries: " . $e->getMessage());
    $available_ministries = [];
}

// Get user initials for avatar
$user_initials = strtoupper(substr($user_name, 0, 1) . substr(strstr($user_name, ' '), 1, 1));

function getUserStatusBadge($last_login) {
    if (!$last_login) {
        return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 border border-gray-200">Never Logged In</span>';
    }
    
    $last_login_time = strtotime($last_login);
    $thirty_days_ago = strtotime('-30 days');
    
    if ($last_login_time >= $thirty_days_ago) {
        return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 border border-green-200">Active</span>';
    } else {
        return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 border border-red-200">Inactive</span>';
    }
}

function getRoleBadge($role) {
    $classes = [
        'super_admin' => 'bg-purple-100 text-purple-800 border border-purple-200',
        'admin' => 'bg-blue-100 text-blue-800 border border-blue-200',
        'user' => 'bg-green-100 text-green-800 border border-green-200',
        'viewer' => 'bg-gray-100 text-gray-800 border border-gray-200'
    ];
    $class = isset($classes[$role]) ? $classes[$role] : 'bg-gray-100 text-gray-800 border border-gray-200';
    $role_display = ucfirst(str_replace('_', ' ', $role));
    return '<span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full ' . $class . '">' . $role_display . '</span>';
}

function formatLastLogin($last_login) {
    if (!$last_login) return 'Never';
    
    $now = new DateTime();
    $login_time = new DateTime($last_login);
    $interval = $now->diff($login_time);
    
    if ($interval->y > 0) return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    if ($interval->m > 0) return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    if ($interval->d > 0) return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    if ($interval->h > 0) return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    if ($interval->i > 0) return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    
    return 'Just now';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Analytics | int-ministry exchange</title>
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
        
        .stat-active .stat-icon {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        .stat-inactive .stat-icon {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .stat-admin .stat-icon {
            background: rgba(139, 92, 246, 0.2);
            color: var(--secondary);
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
        
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
        }
        
        /* Debug Info */
        .debug-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid var(--primary);
        }
        
        .debug-info h3 {
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .debug-info p {
            margin-bottom: 5px;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .filter-container, .export-options, .debug-info {
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
                <span>int-ministry exchange</span>
            </div>
            <div class="user-info">
                <div class="avatar"><?php echo $user_initials; ?></div>
                <div class="user-details">
                    <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    <div class="user-role"><?php echo htmlspecialchars($user_ministry_abbr); ?></div>
                </div>
            </div>
            <a href="user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php" class="active"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
             <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="?action=logout" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>User Analytics</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" id="searchInput" placeholder="Search users...">
                    </div>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Debug Information -->
            <div class="debug-info">
                <h3>Debug Information:</h3>
                <p><strong>User Role:</strong> <?php echo $user_role; ?></p>
                <p><strong>User Ministry ID:</strong> <?php echo $_SESSION['user_ministry_id'] ?? 'None'; ?></p>
                <p><strong>Total Users Found:</strong> <?php echo $stats['total_users']; ?></p>
                <p><strong>Date Range:</strong> <?php echo $date_range; ?> (<?php echo $date_from; ?> to <?php echo $date_to; ?>)</p>
                <p><strong>Ministry Filter:</strong> <?php echo $ministry_filter ?: 'None'; ?></p>
                <p><strong>User Status Filter:</strong> <?php echo $user_status ?: 'All'; ?></p>
                <p><strong>Available Ministries:</strong> <?php echo count($available_ministries); ?></p>
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
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total_users']); ?></h3>
                        <p>Total Users</p>
                    </div>
                </div>
                
                <div class="stat-card stat-active">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['active_users']); ?></h3>
                        <p>Active Users</p>
                    </div>
                </div>
                
                <div class="stat-card stat-inactive">
                    <div class="stat-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['inactive_users']); ?></h3>
                        <p>Inactive Users</p>
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
            </div>
            
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
                                <option value="year" <?php echo $date_range === 'year' ? 'selected' : ''; ?>>Last Year</option>
                                <option value="custom" <?php echo $date_range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="custom_dates" style="<?php echo $date_range === 'custom' ? '' : 'display: none;'; ?>">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label for="date_from">Date From</label>
                                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>
                                <div>
                                    <label for="date_to">Date To</label>
                                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
                        <div class="form-group">
                            <label for="ministry_filter">Ministry</label>
                            <select id="ministry_filter" name="ministry_filter" class="filter-input">
                                <option value="">All Ministries</option>
                                <?php foreach ($available_ministries as $ministry): ?>
                                    <option value="<?php echo htmlspecialchars($ministry['id']); ?>" <?php echo $ministry_filter == $ministry['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ministry['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="user_status">User Status</label>
                            <select id="user_status" name="user_status" class="filter-input">
                                <option value="">All Users</option>
                                <option value="active" <?php echo $user_status === 'active' ? 'selected' : ''; ?>>Active Only</option>
                                <option value="inactive" <?php echo $user_status === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                            </select>
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
            
            <div class="dashboard-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h2>User Growth Over Time</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="growthChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h2>Users by Role</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="roleChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="table-container">
                    <div class="table-header">
                        <h2>Recent User Registrations</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Ministry</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recent_users) > 0): ?>
                                    <?php foreach ($recent_users as $user): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['ministry_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo getRoleBadge($user['role']); ?></td>
                                            <td><?php echo getUserStatusBadge($user['last_login']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No users found matching your criteria.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="table-header">
                        <h2>Most Active Users</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Ministry</th>
                                    <th>Last Login</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($active_users) > 0): ?>
                                    <?php foreach ($active_users as $user): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['ministry_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo formatLastLogin($user['last_login']); ?></td>
                                            <td><?php echo getUserStatusBadge($user['last_login']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No active users found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-grid">
                <div class="table-container">
                    <div class="table-header">
                        <h2>Users by Ministry</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ministry</th>
                                    <th>Total Users</th>
                                    <th>Active Users</th>
                                    <th>Activity Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ministry_data) > 0): ?>
                                    <?php foreach ($ministry_data as $ministry): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($ministry['ministry_name']); ?></td>
                                            <td><?php echo number_format($ministry['user_count']); ?></td>
                                            <td><?php echo number_format($ministry['active_users']); ?></td>
                                            <td>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $ministry['user_count'] > 0 ? ($ministry['active_users'] / $ministry['user_count'] * 100) : 0; ?>%"></div>
                                                </div>
                                                <div class="text-sm text-gray-500 mt-1">
                                                    <?php echo $ministry['user_count'] > 0 ? number_format(($ministry['active_users'] / $ministry['user_count'] * 100), 1) : 0; ?>%
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No ministry data available.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="chart-container">
                    <div class="chart-header">
                        <h2>Login Frequency</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="loginFrequencyChart"></canvas>
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
            // User growth chart
            const growthCtx = document.getElementById('growthChart').getContext('2d');
            
            <?php
            // Prepare growth data for chart
            $growthLabels = [];
            $newUsersData = [];
            $activeUsersData = [];
            
            foreach ($growth_data as $data) {
                $growthLabels[] = date('M j', strtotime($data['date']));
                $newUsersData[] = intval($data['new_users']);
                $activeUsersData[] = intval($data['active_on_date']);
            }
            
            // If no data, create some sample data for the chart
            if (empty($growthLabels)) {
                $growthLabels = ['No Data'];
                $newUsersData = [0];
                $activeUsersData = [0];
            }
            ?>
            
            const growthChart = new Chart(growthCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($growthLabels); ?>,
                    datasets: [
                        {
                            label: 'New Users',
                            data: <?php echo json_encode($newUsersData); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Active Users',
                            data: <?php echo json_encode($activeUsersData); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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
            
            // Role distribution chart
            const roleCtx = document.getElementById('roleChart').getContext('2d');
            
            <?php
            // Prepare role data for chart
            $roleLabels = [];
            $roleCounts = [];
            $roleColors = [];
            
            $roleColorMap = [
                'super_admin' => 'rgba(139, 92, 246, 0.7)',
                'admin' => 'rgba(59, 130, 246, 0.7)',
                'user' => 'rgba(16, 185, 129, 0.7)',
                'viewer' => 'rgba(107, 114, 128, 0.7)'
            ];
            
            foreach ($role_data as $data) {
                $roleLabels[] = ucfirst(str_replace('_', ' ', $data['role']));
                $roleCounts[] = intval($data['user_count']);
                $roleColors[] = isset($roleColorMap[$data['role']]) ? $roleColorMap[$data['role']] : 'rgba(107, 114, 128, 0.7)';
            }
            
            // If no data, create some sample data
            if (empty($roleLabels)) {
                $roleLabels = ['No Data'];
                $roleCounts = [1];
                $roleColors = ['rgba(107, 114, 128, 0.7)'];
            }
            ?>
            
            const roleChart = new Chart(roleCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($roleLabels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($roleCounts); ?>,
                        backgroundColor: <?php echo json_encode($roleColors); ?>,
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
            
            // Login frequency chart
            const loginFreqCtx = document.getElementById('loginFrequencyChart').getContext('2d');
            
            <?php
            // Prepare login frequency data for chart
            $loginFreqLabels = [];
            $loginFreqCounts = [];
            $loginFreqColors = ['#ef4444', '#f59e0b', '#3b82f6', '#10b981', '#8b5cf6'];
            
            foreach ($login_frequency_data as $data) {
                $loginFreqLabels[] = $data['login_frequency'];
                $loginFreqCounts[] = intval($data['user_count']);
            }
            
            // If no data, create some sample data
            if (empty($loginFreqLabels)) {
                $loginFreqLabels = ['No Data'];
                $loginFreqCounts = [0];
            }
            ?>
            
            const loginFrequencyChart = new Chart(loginFreqCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($loginFreqLabels); ?>,
                    datasets: [{
                        label: 'Users',
                        data: <?php echo json_encode($loginFreqCounts); ?>,
                        backgroundColor: <?php echo json_encode(array_slice($loginFreqColors, 0, count($loginFreqLabels))); ?>,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
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
                    },
                    plugins: {
                        legend: {
                            display: false
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