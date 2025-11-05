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
    if (isset($_POST['add_ministry'])) {
        // Add new ministry
        $name = trim($_POST['name']);
        $abbreviation = trim($_POST['abbreviation']);
        $description = trim($_POST['description']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        $head_of_ministry = trim($_POST['head_of_ministry']);
        $status = $_POST['status'];
        
        // Validate inputs
        if (empty($name) || empty($abbreviation)) {
            $error = "Name and Abbreviation are required fields.";
        } else {
            try {
                // Check if abbreviation already exists
                $check_stmt = $pdo->prepare("SELECT id FROM ministry WHERE abbreviation = ?");
                $check_stmt->execute([$abbreviation]);
                
                if ($check_stmt->fetch()) {
                    $error = "Abbreviation already exists in the system.";
                } else {
                    // Insert new ministry
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO ministry (name, abbreviation, description, contact_email, contact_phone, head_of_ministry, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $insert_stmt->execute([
                        $name, 
                        $abbreviation, 
                        $description,
                        $contact_email,
                        $contact_phone,
                        $head_of_ministry,
                        $status
                    ]);
                    
                    $success = "Ministry added successfully!";
                    
                    // Log the action
                    $log_stmt = $pdo->prepare("
                        INSERT INTO log (user_id, action, details, timestamp) 
                        VALUES (?, 'ministry_created', ?, NOW())
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'], 
                        "Created ministry: $name ($abbreviation)"
                    ]);
                }
            } catch (PDOException $e) {
                $error = "Error adding ministry: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_ministry'])) {
        // Update existing ministry
        $ministry_id = $_POST['ministry_id'];
        $name = trim($_POST['name']);
        $abbreviation = trim($_POST['abbreviation']);
        $description = trim($_POST['description']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);
        $head_of_ministry = trim($_POST['head_of_ministry']);
        $status = $_POST['status'];
        
        try {
            // Check if abbreviation already exists (excluding current ministry)
            $check_stmt = $pdo->prepare("SELECT id FROM ministry WHERE abbreviation = ? AND id != ?");
            $check_stmt->execute([$abbreviation, $ministry_id]);
            
            if ($check_stmt->fetch()) {
                $error = "Abbreviation already exists in the system.";
            } else {
                $update_stmt = $pdo->prepare("
                    UPDATE ministry 
                    SET name = ?, abbreviation = ?, description = ?, contact_email = ?, 
                        contact_phone = ?, head_of_ministry = ?, status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                
                $update_stmt->execute([
                    $name, $abbreviation, $description, $contact_email, 
                    $contact_phone, $head_of_ministry, $status, $ministry_id
                ]);
                $success = "Ministry updated successfully!";
                
                // Log the action
                $log_stmt = $pdo->prepare("
                    INSERT INTO log (user_id, action, details, timestamp) 
                    VALUES (?, 'ministry_updated', ?, NOW())
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'], 
                    "Updated ministry: $name ($abbreviation)"
                ]);
            }
            
        } catch (PDOException $e) {
            $error = "Error updating ministry: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_ministry'])) {
        // Delete ministry
        $ministry_id = $_POST['ministry_id'];
        
        try {
            // Check if ministry has users
            $user_check_stmt = $pdo->prepare("SELECT COUNT(*) as user_count FROM user WHERE ministry_id = ?");
            $user_check_stmt->execute([$ministry_id]);
            $user_count = $user_check_stmt->fetch()['user_count'];
            
            if ($user_count > 0) {
                $error = "Cannot delete ministry. There are $user_count users associated with this ministry. Please reassign or delete those users first.";
            } else {
                // Get ministry info for logging
                $ministry_stmt = $pdo->prepare("SELECT name, abbreviation FROM ministry WHERE id = ?");
                $ministry_stmt->execute([$ministry_id]);
                $ministry = $ministry_stmt->fetch();
                
                $delete_stmt = $pdo->prepare("DELETE FROM ministry WHERE id = ?");
                $delete_stmt->execute([$ministry_id]);
                
                $success = "Ministry deleted successfully!";
                
                // Log the action
                if ($ministry) {
                    $log_stmt = $pdo->prepare("
                        INSERT INTO log (user_id, action, details, timestamp) 
                        VALUES (?, 'ministry_deleted', ?, NOW())
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'], 
                        "Deleted ministry: {$ministry['name']} ({$ministry['abbreviation']})"
                    ]);
                }
            }
            
        } catch (PDOException $e) {
            $error = "Error deleting ministry: " . $e->getMessage();
        }
    }
}

// Fetch all ministries with user counts
try {
    $ministries_stmt = $pdo->prepare("
        SELECT m.*, 
               COUNT(u.id) as user_count,
               (SELECT COUNT(*) FROM data_request WHERE requesting_ministry_id = m.id OR target_ministry_id = m.id) as total_requests
        FROM ministry m 
        LEFT JOIN user u ON m.id = u.ministry_id 
        GROUP BY m.id, m.name, m.abbreviation, m.description, m.contact_email, m.contact_phone, 
                 m.head_of_ministry, m.created_at, m.updated_at, m.status
        ORDER BY m.name
    ");
    $ministries_stmt->execute();
    $ministries = $ministries_stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching ministries: " . $e->getMessage();
    $ministries = [];
}

// Fetch ministry statistics for dashboard cards
try {
    // Total ministries
    $total_ministries_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ministry");
    $total_ministries_stmt->execute();
    $total_ministries = $total_ministries_stmt->fetch()['count'];
    
    // Active ministries
    $active_ministries_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ministry WHERE status = 'active'");
    $active_ministries_stmt->execute();
    $active_ministries = $active_ministries_stmt->fetch()['count'];
    
    // Inactive ministries
    $inactive_ministries_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM ministry WHERE status = 'inactive'");
    $inactive_ministries_stmt->execute();
    $inactive_ministries = $inactive_ministries_stmt->fetch()['count'];
    
    // Ministries with most users
    $top_ministries_stmt = $pdo->prepare("
        SELECT m.name, COUNT(u.id) as user_count 
        FROM ministry m 
        LEFT JOIN user u ON m.id = u.ministry_id 
        WHERE m.status = 'active'
        GROUP BY m.id, m.name 
        ORDER BY user_count DESC 
        LIMIT 5
    ");
    $top_ministries_stmt->execute();
    $top_ministries = $top_ministries_stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error fetching ministry statistics: " . $e->getMessage();
    $total_ministries = 0;
    $active_ministries = 0;
    $inactive_ministries = 0;
    $top_ministries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ministry Management | Inter-Ministry Exchange</title>
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
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
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
        
        .btn-info {
            background: #0369a1;
            color: white;
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }
        
        .ministries-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .ministries-table th,
        .ministries-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .ministries-table th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--primary);
            position: sticky;
            top: 0;
        }
        
        .ministries-table tr:hover {
            background: #f0fdf4;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .status-active { 
            background: #d1fae5; 
            color: #065f46; 
        }
        
        .status-inactive { 
            background: #fef3c7; 
            color: #92400e; 
        }
        
        .count-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.75em;
            font-weight: 600;
        }
        
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
        
        /* Ministry Cards */
        .ministry-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .ministry-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }
        
        .ministry-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
        }
        
        .ministry-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .ministry-card-title {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--primary);
            margin: 0;
        }
        
        .ministry-abbreviation {
            background: var(--primary);
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .ministry-card-body {
            margin-bottom: 15px;
        }
        
        .ministry-description {
            color: #6b7280;
            font-size: 0.9em;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        
        .ministry-contacts {
            font-size: 0.85em;
            color: #6b7280;
        }
        
        .ministry-contacts div {
            margin-bottom: 4px;
        }
        
        .ministry-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 15px;
        }
        
        .ministry-stats {
            display: flex;
            gap: 15px;
        }
        
        .stat {
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.75em;
            color: #6b7280;
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
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
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .view-btn {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .view-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            
            .ministries-table {
                font-size: 0.9em;
            }
            
            .ministries-table th,
            .ministries-table td {
                padding: 8px 10px;
            }
            
            .ministry-cards {
                grid-template-columns: 1fr;
            }
            
            .cards {
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
            <a href="admin_management.php"><i class="fas fa-users"></i> User Management</a>
            <a href="#" class="active"><i class="fas fa-building"></i> Ministries</a>
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
                <h1>Ministry Management</h1>
                <button class="btn btn-primary" onclick="openAddMinistryModal()">
                    <i class="fas fa-plus"></i> Add New Ministry
                </button>
            </div>
            
            <!-- Statistics Cards -->
            <div class="cards">
                <div class="card">
                    <h3>Total Ministries</h3>
                    <p><?php echo $total_ministries; ?></p>
                    <small>All registered ministries</small>
                </div>
                <div class="card">
                    <h3>Active Ministries</h3>
                    <p><?php echo $active_ministries; ?></p>
                    <small>Currently active</small>
                </div>
                <div class="card">
                    <h3>Inactive Ministries</h3>
                    <p><?php echo $inactive_ministries; ?></p>
                    <small>Temporarily disabled</small>
                </div>
                <div class="card">
                    <h3>Top Ministry</h3>
                    <p><?php echo !empty($top_ministries) ? $top_ministries[0]['name'] : 'N/A'; ?></p>
                    <small>Most users</small>
                </div>
            </div>
            
            <!-- Add Ministry Form -->
            <div class="section">
                <h3><i class="fas fa-plus-circle"></i> Add New Ministry</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Ministry Name *</label>
                            <input type="text" id="name" name="name" class="form-control" required 
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="abbreviation">Abbreviation *</label>
                            <input type="text" id="abbreviation" name="abbreviation" class="form-control" required 
                                   value="<?php echo isset($_POST['abbreviation']) ? htmlspecialchars($_POST['abbreviation']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" 
                                  placeholder="Brief description of the ministry's responsibilities"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contact_email">Contact Email</label>
                            <input type="email" id="contact_email" name="contact_email" class="form-control" 
                                   value="<?php echo isset($_POST['contact_email']) ? htmlspecialchars($_POST['contact_email']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_phone">Contact Phone</label>
                            <input type="text" id="contact_phone" name="contact_phone" class="form-control" 
                                   value="<?php echo isset($_POST['contact_phone']) ? htmlspecialchars($_POST['contact_phone']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="head_of_ministry">Head of Ministry</label>
                            <input type="text" id="head_of_ministry" name="head_of_ministry" class="form-control" 
                                   value="<?php echo isset($_POST['head_of_ministry']) ? htmlspecialchars($_POST['head_of_ministry']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status *</label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : 'selected'; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_ministry" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Ministry
                    </button>
                </form>
            </div>
            
            <!-- View Toggle -->
            <div class="view-toggle">
                <button class="view-btn active" onclick="switchView('cards')">
                    <i class="fas fa-th-large"></i> Card View
                </button>
                <button class="view-btn" onclick="switchView('table')">
                    <i class="fas fa-table"></i> Table View
                </button>
            </div>
            
            <!-- Ministries List - Card View -->
            <div class="section" id="cardView">
                <h3><i class="fas fa-building"></i> All Ministries (<?php echo count($ministries); ?>)</h3>
                
                <div class="ministry-cards">
                    <?php if (!empty($ministries)): ?>
                        <?php foreach ($ministries as $ministry): ?>
                            <div class="ministry-card">
                                <div class="ministry-card-header">
                                    <h4 class="ministry-card-title"><?php echo htmlspecialchars($ministry['name']); ?></h4>
                                    <span class="ministry-abbreviation"><?php echo htmlspecialchars($ministry['abbreviation']); ?></span>
                                </div>
                                
                                <div class="ministry-card-body">
                                    <?php if ($ministry['description']): ?>
                                        <p class="ministry-description"><?php echo htmlspecialchars($ministry['description']); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="ministry-contacts">
                                        <?php if ($ministry['contact_email']): ?>
                                            <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($ministry['contact_email']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($ministry['contact_phone']): ?>
                                            <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($ministry['contact_phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($ministry['head_of_ministry']): ?>
                                            <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($ministry['head_of_ministry']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="ministry-card-footer">
                                    <div class="ministry-stats">
                                        <div class="stat">
                                            <div class="stat-number"><?php echo $ministry['user_count']; ?></div>
                                            <div class="stat-label">Users</div>
                                        </div>
                                        <div class="stat">
                                            <div class="stat-number"><?php echo $ministry['total_requests']; ?></div>
                                            <div class="stat-label">Requests</div>
                                        </div>
                                    </div>
                                    
                                    <div class="action-buttons">
                                        <span class="status-badge status-<?php echo $ministry['status']; ?>">
                                            <?php echo ucfirst($ministry['status']); ?>
                                        </span>
                                        <button class="action-btn btn-warning" 
                                                onclick="openEditModal(
                                                    <?php echo $ministry['id']; ?>,
                                                    '<?php echo addslashes($ministry['name']); ?>',
                                                    '<?php echo addslashes($ministry['abbreviation']); ?>',
                                                    `<?php echo addslashes($ministry['description']); ?>`,
                                                    '<?php echo addslashes($ministry['contact_email']); ?>',
                                                    '<?php echo addslashes($ministry['contact_phone']); ?>',
                                                    '<?php echo addslashes($ministry['head_of_ministry']); ?>',
                                                    '<?php echo $ministry['status']; ?>'
                                                )">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="action-btn btn-danger" 
                                                onclick="openDeleteModal(<?php echo $ministry['id']; ?>, '<?php echo addslashes($ministry['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #6b7280;">
                            <i class="fas fa-building" style="font-size: 3em; margin-bottom: 15px;"></i>
                            <p>No ministries found in the system.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Ministries List - Table View (Hidden by default) -->
            <div class="section" id="tableView" style="display: none;">
                <h3><i class="fas fa-table"></i> All Ministries (<?php echo count($ministries); ?>)</h3>
                
                <div class="table-responsive">
                    <table class="ministries-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Abbreviation</th>
                                <th>Description</th>
                                <th>Contact Email</th>
                                <th>Contact Phone</th>
                                <th>Head of Ministry</th>
                                <th>Users</th>
                                <th>Requests</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($ministries)): ?>
                                <?php foreach ($ministries as $ministry): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($ministry['name']); ?></strong></td>
                                        <td><span class="count-badge"><?php echo htmlspecialchars($ministry['abbreviation']); ?></span></td>
                                        <td><?php echo $ministry['description'] ? htmlspecialchars(substr($ministry['description'], 0, 50)) . (strlen($ministry['description']) > 50 ? '...' : '') : 'N/A'; ?></td>
                                        <td><?php echo $ministry['contact_email'] ? htmlspecialchars($ministry['contact_email']) : 'N/A'; ?></td>
                                        <td><?php echo $ministry['contact_phone'] ? htmlspecialchars($ministry['contact_phone']) : 'N/A'; ?></td>
                                        <td><?php echo $ministry['head_of_ministry'] ? htmlspecialchars($ministry['head_of_ministry']) : 'N/A'; ?></td>
                                        <td><span class="count-badge"><?php echo $ministry['user_count']; ?> users</span></td>
                                        <td><span class="count-badge"><?php echo $ministry['total_requests']; ?> requests</span></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $ministry['status']; ?>">
                                                <?php echo ucfirst($ministry['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($ministry['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-warning" 
                                                        onclick="openEditModal(
                                                            <?php echo $ministry['id']; ?>,
                                                            '<?php echo addslashes($ministry['name']); ?>',
                                                            '<?php echo addslashes($ministry['abbreviation']); ?>',
                                                            `<?php echo addslashes($ministry['description']); ?>`,
                                                            '<?php echo addslashes($ministry['contact_email']); ?>',
                                                            '<?php echo addslashes($ministry['contact_phone']); ?>',
                                                            '<?php echo addslashes($ministry['head_of_ministry']); ?>',
                                                            '<?php echo $ministry['status']; ?>'
                                                        )">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="action-btn btn-danger" 
                                                        onclick="openDeleteModal(<?php echo $ministry['id']; ?>, '<?php echo addslashes($ministry['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: 20px;">
                                        No ministries found in the system.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Ministry Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Ministry</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="ministry_id" id="edit_ministry_id">
                <div class="form-group">
                    <label for="edit_name">Ministry Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_abbreviation">Abbreviation</label>
                    <input type="text" id="edit_abbreviation" name="abbreviation" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_contact_email">Contact Email</label>
                    <input type="email" id="edit_contact_email" name="contact_email" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_contact_phone">Contact Phone</label>
                    <input type="text" id="edit_contact_phone" name="contact_phone" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_head_of_ministry">Head of Ministry</label>
                    <input type="text" id="edit_head_of_ministry" name="head_of_ministry" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="update_ministry" class="btn btn-primary">Update Ministry</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Ministry Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Ministry</h3>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="ministry_id" id="delete_ministry_id">
                <div class="form-group">
                    <p>Are you sure you want to delete ministry: <strong id="delete_ministry_name"></strong>?</p>
                    <p style="color: #dc2626; font-weight: 600;">This action cannot be undone!</p>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="delete_ministry" class="btn btn-danger">Delete Ministry</button>
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

        // View toggle functionality
        function switchView(viewType) {
            const cardView = document.getElementById('cardView');
            const tableView = document.getElementById('tableView');
            const viewBtns = document.querySelectorAll('.view-btn');
            
            viewBtns.forEach(btn => btn.classList.remove('active'));
            
            if (viewType === 'cards') {
                cardView.style.display = 'block';
                tableView.style.display = 'none';
                document.querySelector('.view-btn:first-child').classList.add('active');
            } else {
                cardView.style.display = 'none';
                tableView.style.display = 'block';
                document.querySelector('.view-btn:last-child').classList.add('active');
            }
        }

        // Modal functions
        function openEditModal(ministryId, name, abbreviation, description, contactEmail, contactPhone, headOfMinistry, status) {
            document.getElementById('edit_ministry_id').value = ministryId;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_abbreviation').value = abbreviation;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_contact_email').value = contactEmail;
            document.getElementById('edit_contact_phone').value = contactPhone;
            document.getElementById('edit_head_of_ministry').value = headOfMinistry;
            document.getElementById('edit_status').value = status;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(ministryId, ministryName) {
            document.getElementById('delete_ministry_id').value = ministryId;
            document.getElementById('delete_ministry_name').textContent = ministryName;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function openAddMinistryModal() {
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
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === editModal) closeEditModal();
            if (event.target === deleteModal) closeDeleteModal();
        });
    </script>
</body>
</html>