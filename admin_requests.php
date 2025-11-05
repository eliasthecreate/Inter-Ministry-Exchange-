<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header('Location: ../loginSignup.php');
    exit();
}

// Fetch user data for the navigation bar
try {
    $stmt = $pdo->prepare("SELECT u.name, u.email, u.ministry_id, u.role FROM user u WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        error_log("User not found: user_id={$_SESSION['user_id']}");
        session_destroy();
        header("Location: ../loginSignup.php");
        exit;
    }
    
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
        // Handle case where user's ministry is not found
        $user_ministry_abbr = 'N/A';
        $user_ministry_name = 'Unknown Ministry';
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Failed to load user data.";
}

$user_initials = strtoupper(substr($user_name, 0, 1) . substr(strstr($user_name, ' '), 1, 1));

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$priority_filter = $_GET['priority'] ?? 'all';
$ministry_filter = $_GET['ministry'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query with filters
$query = "
    SELECT 
        dr.id,
        dr.requesting_ministry_id,
        dr.target_ministry_id,
        dr.requested_by,
        dr.title,
        dr.description,
        dr.request_type,
        dr.status,
        dr.priority,
        dr.requested_date,
        dr.response_date,
        dr.approved_by,
        rm.name as requesting_ministry_name,
        tm.name as target_ministry_name,
        u.name as requested_by_name
    FROM data_request dr
    LEFT JOIN ministry rm ON dr.requesting_ministry_id = rm.id
    LEFT JOIN ministry tm ON dr.target_ministry_id = tm.id
    LEFT JOIN user u ON dr.requested_by = u.id
    WHERE 1=1
";

$params = [];

// Apply status filter
if ($status_filter !== 'all') {
    $query .= " AND dr.status = ?";
    $params[] = $status_filter;
}

// Apply priority filter
if ($priority_filter !== 'all') {
    $query .= " AND dr.priority = ?";
    $params[] = $priority_filter;
}

// Apply ministry filter
if ($ministry_filter !== 'all') {
    $query .= " AND (dr.requesting_ministry_id = ? OR dr.target_ministry_id = ?)";
    $params[] = $ministry_filter;
    $params[] = $ministry_filter;
}

// Apply search filter
if (!empty($search_query)) {
    $query .= " AND (dr.title LIKE ? OR dr.description LIKE ? OR u.name LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY dr.requested_date DESC";

// Fetch filtered data requests
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
} catch(PDOException $e) {
    $requests = [];
    error_log("Error fetching data requests: " . $e->getMessage());
}

// Fetch ministries for filter dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM ministry ORDER BY name");
    $ministries = $stmt->fetchAll();
} catch(PDOException $e) {
    $ministries = [];
    error_log("Error fetching ministries: " . $e->getMessage());
}

// Get request statistics
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM data_request GROUP BY status");
    $status_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(PDOException $e) {
    $status_stats = [];
    error_log("Error fetching status statistics: " . $e->getMessage());
}

// Get priority statistics
try {
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM data_request GROUP BY priority");
    $priority_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(PDOException $e) {
    $priority_stats = [];
    error_log("Error fetching priority statistics: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Requests - int-ministry exchange Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #065f46;
            --secondary: #059669;
            --accent: #34d399;
            --light: #f0fdf4;
            --dark: #064e3b;
            --success: #4caf50;
            --warning: #ff9800;
            --danger: #f44336;
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
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Stats Section */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .stat-label {
            color: var(--dark);
            font-size: 0.9em;
            opacity: 0.8;
        }
        
        /* Filter Section */
        .filter-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            color: var(--primary);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .form-group select, 
        .form-group input {
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid var(--glass-border);
            background: var(--light);
            color: var(--dark);
            font-size: 1em;
        }
        
        .form-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        
        /* Requests Section */
        .requests-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .requests-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary);
        }
        
        .requests-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .requests-table th {
            text-align: left;
            padding: 15px;
            background: rgba(6, 95, 70, 0.1);
            color: var(--primary);
            font-weight: 600;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .requests-table td {
            padding: 15px;
            border-bottom: 1px solid var(--glass-border);
            color: var(--dark);
        }
        
        .requests-table tr:last-child td {
            border-bottom: none;
        }
        
        .requests-table tr:hover {
            background: rgba(6, 95, 70, 0.05);
        }
        
        .priority {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.85em;
            font-weight: 500;
        }
        
        .priority-low { 
            background: rgba(156, 163, 175, 0.2);
            color: #374151;
        }
        
        .priority-medium { 
            background: rgba(59, 130, 246, 0.2);
            color: #1d4ed8;
        }
        
        .priority-high { 
            background: rgba(217, 119, 6, 0.2);
            color: #92400e;
        }
        
        .priority-urgent { 
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.85em;
            font-weight: 500;
        }
        
        .status-pending { 
            background: rgba(245, 158, 11, 0.2);
            color: #92400e;
        }
        
        .status-approved { 
            background: rgba(52, 211, 153, 0.2);
            color: #166534;
        }
        
        .status-rejected { 
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .status-completed { 
            background: rgba(59, 130, 246, 0.2);
            color: #1d4ed8;
        }
        
        .actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-view {
            padding: 8px 16px;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            background: rgba(59, 130, 246, 0.2);
            color: #1d4ed8;
        }
        
        .btn-view:hover {
            background: rgba(59, 130, 246, 0.3);
            transform: translateY(-2px);
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .filter-container, .actions {
                display: none !important;
            }
            
            body, .main-content, .requests-container, .stats-container {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
            }
            
            .header, .stat-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .requests-table th {
                color: black !important;
                background: #f0f0f0 !important;
            }
            
            .container {
                display: block !important;
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
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .requests-header, .filter-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .requests-table {
                display: block;
                overflow-x: auto;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .requests-container, .filter-container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .requests-table th, 
            .requests-table td {
                padding: 10px;
            }
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
                    <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    <div class="user-role">Super Administrator</div>
                </div>
            </div>
             <a href="admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
             <a href="admin_management.php"><i class="fas fa-users"></i> User Management</a>
            <a href="admin_ministries.php"><i class="fas fa-building"></i> Ministries</a>
            <a href="#" class="active"><i class="fas fa-exchange-alt"></i> Data Requests</a>
             <a href="admin_audit_logs.php"><i class="fas fa-file-alt"></i> Audit Logs</a>
            <a href="admin_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
             <a href="admin_documentation.php"><i class="fas fa-book"></i> Documentation</a>
             <a href="admin_settings.php"><i class="fas fa-cog"></i> System Settings</a>
            <a href="admin_security.php"><i class="fas fa-shield-alt"></i> Security</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Data Requests</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" placeholder="Search requests..." id="searchInput">
                    </div>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Statistics Section -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-label">Total Requests</div>
                    <div class="stat-value"><?php echo count($requests); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value"><?php echo $status_stats['pending'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Approved Requests</div>
                    <div class="stat-value"><?php echo $status_stats['approved'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">High Priority</div>
                    <div class="stat-value"><?php echo ($priority_stats['high'] ?? 0) + ($priority_stats['urgent'] ?? 0); ?></div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-container">
                <div class="filter-header">
                    <h2>Filter Requests</h2>
                </div>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            <option value="all" <?php echo $priority_filter === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ministry">Ministry</label>
                        <select id="ministry" name="ministry">
                            <option value="all" <?php echo $ministry_filter === 'all' ? 'selected' : ''; ?>>All Ministries</option>
                            <?php foreach ($ministries as $ministry): ?>
                                <option value="<?php echo $ministry['id']; ?>" <?php echo $ministry_filter == $ministry['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ministry['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" name="search" placeholder="Search requests..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="Admin_requests.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Requests Table -->
            <div class="requests-container">
                <div class="requests-header">
                    <h2>Data Requests</h2>
                    <span class="badge"><?php echo count($requests); ?> results</span>
                </div>
                
                <table class="requests-table">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Requesting Ministry</th>
                            <th>Target Ministry</th>
                            <th>Requested By</th>
                            <th>Request Details</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($requests)): ?>
                            <?php foreach ($requests as $request): ?>
                            <tr>
                                <td><strong>REQ<?php echo str_pad($request['id'], 3, '0', STR_PAD_LEFT); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['requesting_ministry_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($request['target_ministry_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($request['requested_by_name'] ?? 'Unknown'); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['title']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($request['description']); ?></small>
                                </td>
                                <td>
                                    <span class="priority priority-<?php echo $request['priority']; ?>">
                                        <?php echo ucfirst($request['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y H:i', strtotime($request['requested_date'])); ?></td>
                                <td class="actions">
                                    <button class="btn-view" onclick="viewRequest(<?php echo $request['id']; ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 30px; color: var(--dark);">
                                    <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 15px; opacity: 0.5;"></i>
                                    <p>No data requests found matching your filters.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const rows = document.querySelectorAll('.requests-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // View request details
        function viewRequest(requestId) {
            alert(`View details for request REQ${String(requestId).padStart(3, '0')}`);
            // In a real application, this would open a modal or redirect to a details page
        }
    </script>
</body>
</html>