<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'super_admin')) {
    header('Location: ../loginSignup.php');
    exit();
}

// Get user data from session
$user_name = $_SESSION['user_name'] ?? 'Admin User';
$user_initials = strtoupper(substr($user_name, 0, 1) . substr(strstr($user_name, ' '), 1, 1));

// Fetch audit logs from database
try {
    $stmt = $pdo->query("SELECT l.*, u.name as user_name FROM log l JOIN user u ON l.user_id = u.id ORDER BY l.timestamp DESC LIMIT 50");
    $logs = $stmt->fetchAll();
} catch(PDOException $e) {
    $logs = [];
    error_log("Error fetching audit logs: " . $e->getMessage());
}

// Handle export requests
if (isset($_GET['export'])) {
    $format = $_GET['format'] ?? 'csv';
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=audit_logs_'.date('Y-m-d').'.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'User', 'Action', 'Details', 'Timestamp'));
        
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log['id'],
                $log['user_name'],
                $log['action'],
                $log['details'],
                $log['timestamp']
            ));
        }
        fclose($output);
        exit();
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=audit_logs_'.date('Y-m-d').'.json');
        
        echo json_encode($logs, JSON_PRETTY_PRINT);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | int-ministry exchange</title>
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
        
        /* Logs Section */
        .logs-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .logs-header {
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
        
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .logs-table th {
            text-align: left;
            padding: 15px;
            background: rgba(6, 95, 70, 0.1);
            color: var(--primary);
            font-weight: 600;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .logs-table td {
            padding: 15px;
            border-bottom: 1px solid var(--glass-border);
            color: var(--dark);
        }
        
        .logs-table tr:last-child td {
            border-bottom: none;
        }
        
        .logs-table tr:hover {
            background: rgba(6, 95, 70, 0.05);
        }
        
        .log-action {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.85em;
            font-weight: 500;
        }
        
        .action-login {
            background: rgba(52, 211, 153, 0.2);
            color: #166534;
        }
        
        .action-update {
            background: rgba(6, 95, 70, 0.2);
            color: #065f46;
        }
        
        .action-delete {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .action-create {
            background: rgba(245, 158, 11, 0.2);
            color: #92400e;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .export-options {
                display: none !important;
            }
            
            body, .main-content, .logs-container {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
            }
            
            .header {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            
            .logs-table th {
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
        }
        
        @media (max-width: 768px) {
            .export-options {
                flex-direction: column;
                width: 100%;
            }
            
            .logs-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .logs-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .logs-container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .logs-table th, 
            .logs-table td {
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
            <a href="admin_requests.php"><i class="fas fa-exchange-alt"></i> Data Requests</a>
             <a href="#" class="active"><i class="fas fa-file-alt"></i> Audit Logs</a>
            <a href="admin_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="admin_documentation.php"><i class="fas fa-book"></i> Documentation</a>
             <a href="admin_settings.php"><i class="fas fa-cog"></i> System Settings</a>
            <a href="admin_security.php"><i class="fas fa-shield-alt"></i> Security</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Audit Logs</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" placeholder="Search logs...">
                    </div>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <div class="logs-container">
                <div class="logs-header">
                    <h2>Recent Activity</h2>
                    <div class="export-options">
                        <a href="?export=true&format=csv" class="btn btn-primary">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                        <a href="?export=true&format=json" class="btn btn-primary">
                            <i class="fas fa-file-code"></i> Export JSON
                        </a>
                    </div>
                </div>
                
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): 
                                // Determine action class for styling
                                $action_class = '';
                                if (stripos($log['action'], 'login') !== false) {
                                    $action_class = 'action-login';
                                } elseif (stripos($log['action'], 'update') !== false) {
                                    $action_class = 'action-update';
                                } elseif (stripos($log['action'], 'delete') !== false) {
                                    $action_class = 'action-delete';
                                } elseif (stripos($log['action'], 'create') !== false || stripos($log['action'], 'insert') !== false) {
                                    $action_class = 'action-create';
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($log['user_name']); ?></strong></td>
                                    <td>
                                        <span class="log-action <?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($log['timestamp'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 30px; color: var(--dark);">
                                    No audit logs found.
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

        // Simple search functionality
        document.querySelector('.search-bar input').addEventListener('keyup', function() {
            const searchText = this.value.toLowerCase();
            const rows = document.querySelectorAll('.logs-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchText)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>