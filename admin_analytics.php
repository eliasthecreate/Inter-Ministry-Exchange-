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

// Set date ranges
$current_month = date('Y-m');
$last_month = date('Y-m', strtotime('-1 month'));
$current_year = date('Y');
$last_year = date('Y', strtotime('-1 year'));

// Fetch comprehensive analytics data
try {
    // Overall Statistics
    $total_users_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user");
    $total_users_stmt->execute();
    $total_users = $total_users_stmt->fetch()['count'];

    $total_ministries_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ministry");
    $total_ministries_stmt->execute();
    $total_ministries = $total_ministries_stmt->fetch()['count'];

    $total_requests_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data_request");
    $total_requests_stmt->execute();
    $total_requests = $total_requests_stmt->fetch()['count'];

    $pending_requests_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM data_request WHERE status = 'pending'");
    $pending_requests_stmt->execute();
    $pending_requests = $pending_requests_stmt->fetch()['count'];

    // User Growth (last 30 days)
    $user_growth_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM user 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $user_growth_stmt->execute();
    $user_growth_30days = $user_growth_stmt->fetch()['count'];

    // Request Growth (last 30 days)
    $request_growth_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM data_request 
        WHERE requested_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $request_growth_stmt->execute();
    $request_growth_30days = $request_growth_stmt->execute() ? $request_growth_stmt->fetch()['count'] : 0;

    // Monthly User Registration
    $monthly_users_stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM user 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $monthly_users_stmt->execute();
    $monthly_users = $monthly_users_stmt->fetchAll();

    // Request Status Distribution
    $request_status_stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM data_request 
        GROUP BY status
    ");
    $request_status_stmt->execute();
    $request_status = $request_status_stmt->fetchAll();

    // User Role Distribution
    $user_roles_stmt = $pdo->prepare("
        SELECT role, COUNT(*) as count 
        FROM user 
        GROUP BY role
    ");
    $user_roles_stmt->execute();
    $user_roles = $user_roles_stmt->fetchAll();

    // Ministry User Distribution
    $ministry_users_stmt = $pdo->prepare("
        SELECT m.name, COUNT(u.id) as user_count 
        FROM ministry m 
        LEFT JOIN user u ON m.id = u.ministry_id 
        WHERE m.status = 'active'
        GROUP BY m.id, m.name 
        ORDER BY user_count DESC
    ");
    $ministry_users_stmt->execute();
    $ministry_users = $ministry_users_stmt->fetchAll();

    // Monthly Request Trends
    $monthly_requests_stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(requested_date, '%Y-%m') as month,
            COUNT(*) as count
        FROM data_request 
        WHERE requested_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(requested_date, '%Y-%m')
        ORDER BY month
    ");
    $monthly_requests_stmt->execute();
    $monthly_requests = $monthly_requests_stmt->fetchAll();

    // Request Type Distribution
    $request_types_stmt = $pdo->prepare("
        SELECT request_type, COUNT(*) as count 
        FROM data_request 
        WHERE request_type IS NOT NULL AND request_type != ''
        GROUP BY request_type
    ");
    $request_types_stmt->execute();
    $request_types = $request_types_stmt->fetchAll();

    // Priority Distribution
    $priority_dist_stmt = $pdo->prepare("
        SELECT priority, COUNT(*) as count 
        FROM data_request 
        WHERE priority IS NOT NULL AND priority != ''
        GROUP BY priority
    ");
    $priority_dist_stmt->execute();
    $priority_dist = $priority_dist_stmt->fetchAll();

    // Top Requesting Ministries
    $top_requesting_ministries_stmt = $pdo->prepare("
        SELECT m.name, COUNT(dr.id) as request_count 
        FROM data_request dr 
        JOIN ministry m ON dr.requesting_ministry_id = m.id 
        GROUP BY m.id, m.name 
        ORDER BY request_count DESC 
        LIMIT 10
    ");
    $top_requesting_ministries_stmt->execute();
    $top_requesting_ministries = $top_requesting_ministries_stmt->fetchAll();

    // Top Target Ministries
    $top_target_ministries_stmt = $pdo->prepare("
        SELECT m.name, COUNT(dr.id) as request_count 
        FROM data_request dr 
        JOIN ministry m ON dr.target_ministry_id = m.id 
        GROUP BY m.id, m.name 
        ORDER BY request_count DESC 
        LIMIT 10
    ");
    $top_target_ministries_stmt->execute();
    $top_target_ministries = $top_target_ministries_stmt->fetchAll();

    // Recent Activities
    $recent_activities_stmt = $pdo->prepare("
        SELECT l.*, u.name as user_name 
        FROM log l 
        LEFT JOIN user u ON l.user_id = u.id 
        ORDER BY l.timestamp DESC 
        LIMIT 10
    ");
    $recent_activities_stmt->execute();
    $recent_activities = $recent_activities_stmt->fetchAll();

    // System Performance (Login attempts, etc.)
    $login_stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_logins,
            SUM(CASE WHEN action = 'failed_login' THEN 1 ELSE 0 END) as failed_logins,
            SUM(CASE WHEN action = 'login' THEN 1 ELSE 0 END) as successful_logins
        FROM log 
        WHERE action IN ('login', 'failed_login') 
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $login_stats_stmt->execute();
    $login_stats = $login_stats_stmt->fetch();

    // Average Response Time (for completed requests)
    $avg_response_time_stmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(HOUR, requested_date, response_date)) as avg_hours 
        FROM data_request 
        WHERE response_date IS NOT NULL 
        AND status IN ('approved', 'rejected', 'completed')
    ");
    $avg_response_time_stmt->execute();
    $avg_response_time = $avg_response_time_stmt->fetch()['avg_hours'];

} catch (PDOException $e) {
    $error = "Error loading analytics data: " . $e->getMessage();
    error_log("Analytics Error: " . $e->getMessage());
}

// Calculate percentages and growth rates
$approval_rate = $total_requests > 0 ? round((($request_status[array_search('approved', array_column($request_status, 'status'))]['count'] ?? 0) / $total_requests) * 100, 1) : 0;
$completion_rate = $total_requests > 0 ? round((($request_status[array_search('completed', array_column($request_status, 'status'))]['count'] ?? 0) / $total_requests) * 100, 1) : 0;
$user_growth_rate = ($total_users - $user_growth_30days) > 0 ? round(($user_growth_30days / ($total_users - $user_growth_30days)) * 100, 1) : 0;

// Prepare data for charts
$monthly_users_data = [];
foreach ($monthly_users as $data) {
    $monthly_users_data[] = [
        'month' => date('M Y', strtotime($data['month'] . '-01')),
        'count' => $data['count']
    ];
}

$monthly_requests_data = [];
foreach ($monthly_requests as $data) {
    $monthly_requests_data[] = [
        'month' => date('M Y', strtotime($data['month'] . '-01')),
        'count' => $data['count']
    ];
}

$request_status_data = [];
foreach ($request_status as $data) {
    $request_status_data[] = [
        'status' => ucfirst($data['status']),
        'count' => $data['count']
    ];
}

$user_roles_data = [];
foreach ($user_roles as $data) {
    $user_roles_data[] = [
        'role' => ucfirst(str_replace('_', ' ', $data['role'])),
        'count' => $data['count']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard | Inter-Ministry Exchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        
        .chart-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.85em;
            color: #6b7280;
            margin-top: 5px;
        }
        
        /* Progress Bars */
        .progress-container {
            margin-bottom: 15px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary) 0%, var(--accent) 100%);
            transition: width 0.3s ease;
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .data-table th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary);
        }
        
        .data-table tr:hover {
            background: #f0fdf4;
        }
        
        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #d1f5f5; color: #0f766e; }
        
        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
        }
        
        /* Export Button */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
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
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .chart-row {
                grid-template-columns: 1fr;
            }
            
            .cards {
                grid-template-columns: repeat(2, 1fr);
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
            
            .chart-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-controls {
                flex-direction: column;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
            <a href="admin_management.php"><i class="fas fa-users"></i> User Management</a>
            <a href="admin_ministries.php"><i class="fas fa-building"></i> Ministries</a>
            <a href="admin_requests.php"><i class="fas fa-exchange-alt"></i> Data Requests</a>
            <a href="admin_audit_logs.php"><i class="fas fa-file-alt"></i> Audit Logs</a>
            <a href="#" class="active"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="admin_documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="admin_settings.php"><i class="fas fa-cog"></i> System Settings</a>
            <a href="admin_security.php"><i class="fas fa-shield-alt"></i> Security</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Analytics Dashboard</h1>
                <div class="filter-controls">
                    <select class="filter-select" id="timeRange">
                        <option value="30">Last 30 Days</option>
                        <option value="90">Last 90 Days</option>
                        <option value="365">Last Year</option>
                        <option value="all">All Time</option>
                    </select>
                    <button class="btn btn-primary" onclick="exportAnalytics()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                </div>
            </div>
            
            <!-- Key Metrics Cards -->
            <div class="cards">
                <div class="card">
                    <?php if ($user_growth_30days > 0): ?>
                        <span class="growth-indicator">+<?php echo $user_growth_30days; ?> new</span>
                    <?php endif; ?>
                    <h3>Total Users</h3>
                    <p><?php echo number_format($total_users); ?></p>
                    <small>Registered system users</small>
                </div>
                <div class="card">
                    <h3>Active Ministries</h3>
                    <p><?php echo $total_ministries; ?></p>
                    <small>Registered ministries</small>
                </div>
                <div class="card">
                    <?php if ($request_growth_30days > 0): ?>
                        <span class="growth-indicator">+<?php echo $request_growth_30days; ?> new</span>
                    <?php endif; ?>
                    <h3>Data Requests</h3>
                    <p><?php echo number_format($total_requests); ?></p>
                    <small>Total data exchanges</small>
                </div>
                <div class="card">
                    <h3>Approval Rate</h3>
                    <p><?php echo $approval_rate; ?>%</p>
                    <small>Request approval rate</small>
                </div>
            </div>
            
            <!-- Charts Row 1 -->
            <div class="chart-row">
                <div class="section">
                    <h3><i class="fas fa-chart-line"></i> User Registration Trend</h3>
                    <div class="chart-container">
                        <canvas id="userGrowthChart"></canvas>
                    </div>
                </div>
                
                <div class="section">
                    <h3><i class="fas fa-exchange-alt"></i> Request Status Distribution</h3>
                    <div class="chart-container">
                        <canvas id="requestStatusChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row 2 -->
            <div class="chart-row">
                <div class="section">
                    <h3><i class="fas fa-users"></i> User Role Distribution</h3>
                    <div class="chart-container">
                        <canvas id="userRolesChart"></canvas>
                    </div>
                </div>
                
                <div class="section">
                    <h3><i class="fas fa-chart-bar"></i> Monthly Request Trends</h3>
                    <div class="chart-container">
                        <canvas id="requestTrendsChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Ministry Statistics -->
            <div class="section">
                <h3><i class="fas fa-building"></i> Ministry Performance</h3>
                <div class="stats-grid">
                    <?php foreach ($ministry_users as $ministry): ?>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $ministry['user_count']; ?></div>
                            <div class="stat-label"><?php echo htmlspecialchars($ministry['name']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Top Ministries -->
            <div class="chart-row">
                <div class="section">
                    <h3><i class="fas fa-paper-plane"></i> Top Requesting Ministries</h3>
                    <div class="progress-container">
                        <?php foreach ($top_requesting_ministries as $ministry): ?>
                            <div class="progress-label">
                                <span><?php echo htmlspecialchars($ministry['name']); ?></span>
                                <span><?php echo $ministry['request_count']; ?> requests</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress" style="width: <?php echo min(100, ($ministry['request_count'] / max(1, $top_requesting_ministries[0]['request_count'])) * 100); ?>%"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="section">
                    <h3><i class="fas fa-bullseye"></i> Top Target Ministries</h3>
                    <div class="progress-container">
                        <?php foreach ($top_target_ministries as $ministry): ?>
                            <div class="progress-label">
                                <span><?php echo htmlspecialchars($ministry['name']); ?></span>
                                <span><?php echo $ministry['request_count']; ?> requests</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress" style="width: <?php echo min(100, ($ministry['request_count'] / max(1, $top_target_ministries[0]['request_count'])) * 100); ?>%"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- System Performance -->
            <div class="section">
                <h3><i class="fas fa-tachometer-alt"></i> System Performance</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $login_stats['successful_logins'] ?? 0; ?></div>
                        <div class="stat-label">Successful Logins (30d)</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $login_stats['failed_logins'] ?? 0; ?></div>
                        <div class="stat-label">Failed Logins (30d)</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo round($avg_response_time, 1); ?>h</div>
                        <div class="stat-label">Avg Response Time</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $completion_rate; ?>%</div>
                        <div class="stat-label">Request Completion</div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activities -->
            <div class="section">
                <h3><i class="fas fa-history"></i> Recent System Activities</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?php echo htmlspecialchars($activity['action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['details'] ?? 'No details'); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($activity['timestamp'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No recent activities found.</td>
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

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Growth Chart
            const userGrowthCtx = document.getElementById('userGrowthChart').getContext('2d');
            new Chart(userGrowthCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_users_data, 'month')); ?>,
                    datasets: [{
                        label: 'User Registrations',
                        data: <?php echo json_encode(array_column($monthly_users_data, 'count')); ?>,
                        borderColor: '#065f46',
                        backgroundColor: 'rgba(6, 95, 70, 0.1)',
                        tension: 0.4,
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
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // Request Status Chart
            const requestStatusCtx = document.getElementById('requestStatusChart').getContext('2d');
            new Chart(requestStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($request_status_data, 'status')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($request_status_data, 'count')); ?>,
                        backgroundColor: [
                            '#f59e0b', // pending - yellow
                            '#10b981', // approved - green
                            '#ef4444', // rejected - red
                            '#3b82f6'  // completed - blue
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // User Roles Chart
            const userRolesCtx = document.getElementById('userRolesChart').getContext('2d');
            new Chart(userRolesCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($user_roles_data, 'role')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($user_roles_data, 'count')); ?>,
                        backgroundColor: [
                            '#059669', // user - green
                            '#3b82f6', // admin - blue
                            '#8b5cf6'  // super_admin - purple
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Request Trends Chart
            const requestTrendsCtx = document.getElementById('requestTrendsChart').getContext('2d');
            new Chart(requestTrendsCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_requests_data, 'month')); ?>,
                    datasets: [{
                        label: 'Data Requests',
                        data: <?php echo json_encode(array_column($monthly_requests_data, 'count')); ?>,
                        backgroundColor: 'rgba(52, 211, 153, 0.8)',
                        borderColor: '#059669',
                        borderWidth: 1
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
                            beginAtZero: true
                        }
                    }
                }
            });
        });

        // Export functionality
        function exportAnalytics() {
            // Create a simple CSV export
            const csvContent = [
                ['Metric', 'Value'],
                ['Total Users', <?php echo $total_users; ?>],
                ['Total Ministries', <?php echo $total_ministries; ?>],
                ['Total Requests', <?php echo $total_requests; ?>],
                ['Pending Requests', <?php echo $pending_requests; ?>],
                ['Approval Rate', '<?php echo $approval_rate; ?>%'],
                ['User Growth (30d)', <?php echo $user_growth_30days; ?>],
                ['Request Growth (30d)', <?php echo $request_growth_30days; ?>],
                ['Average Response Time', '<?php echo round($avg_response_time, 1); ?> hours'],
                ['Report Generated', new Date().toLocaleDateString()]
            ].map(row => row.join(',')).join('\n');

            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'analytics_report_<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        // Time range filter
        document.getElementById('timeRange').addEventListener('change', function() {
            // In a real implementation, this would reload the page with new filters
            // or make an AJAX call to update the charts
            alert('Time range filter would update the analytics data. This would require backend implementation.');
        });

        // Auto-refresh analytics every 5 minutes
        setInterval(() => {
            // Optional: Add auto-refresh functionality
            // window.location.reload();
        }, 300000);
    </script>
</body>
</html>