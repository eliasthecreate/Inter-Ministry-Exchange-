<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: loginSignup.php");
    exit;
}

// Fetch user data for the navigation bar and set user_ministry_id in session
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
        // Handle case where user's ministry is not found
        $user_ministry_abbr = 'N/A';
        $user_ministry_name = 'Unknown Ministry';
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Failed to load user data.";
}

// Check if ministry_id is passed from ministries page
$selected_ministry = '';
if (isset($_GET['ministry_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM ministry WHERE id = ?");
        $stmt->execute([$_GET['ministry_id']]);
        $ministry_result = $stmt->fetch();
        if ($ministry_result) {
            $selected_ministry = $ministry_result['name'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching ministry: " . $e->getMessage());
    }
}

// Handle form submission for a new request or download
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_request') {
            $target_ministry = $_POST['target_ministry'];
            $request_type = $_POST['request_type'];
            $title = $_POST['title'];
            $description = $_POST['description'];
            $priority = $_POST['priority'];
            $requesting_ministry_id = $_SESSION['user_ministry_id'];
            $requested_by = $_SESSION['user_id'];

            if (!empty($target_ministry) && !empty($request_type) && !empty($title) && !empty($description)) {
                try {
                    // Get the ID of the target ministry
                    $stmt = $pdo->prepare("SELECT id FROM ministry WHERE name = ?");
                    $stmt->execute([$target_ministry]);
                    $target_ministry_id = $stmt->fetchColumn();

                    if ($target_ministry_id) {
                        // Insert the new request
                        $stmt = $pdo->prepare("INSERT INTO data_request (requesting_ministry_id, target_ministry_id, requested_by, title, description, request_type, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                        $stmt->execute([
                            $requesting_ministry_id, 
                            $target_ministry_id, 
                            $requested_by,
                            $title, 
                            $description, 
                            $request_type, 
                            $priority
                        ]);
                        
                        // Log the audit trail
                        $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details, timestamp) VALUES (?, 'CREATE', 'data_request', LAST_INSERT_ID(), ?, NOW())");
                        $stmt->execute([$_SESSION['user_id'], "Created new request: $title"]);
                        
                        $success = "Request submitted successfully!";
                    } else {
                        $error = "Invalid target ministry selected.";
                    }
                } catch (PDOException $e) {
                    error_log("Error submitting request: " . $e->getMessage());
                    $error = "Failed to submit request. Please try again.";
                }
            } else {
                $error = "Please fill out all required fields.";
            }
        } elseif ($_POST['action'] === 'approve_request') {
            // Approve request
            $request_id = (int)$_POST['request_id'];
            try {
                $stmt = $pdo->prepare("UPDATE data_request SET status = 'approved', response_date = NOW(), approved_by = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $request_id]);
                
                // Log the audit trail
                $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details, timestamp) VALUES (?, 'UPDATE', 'data_request', ?, 'Request approved', NOW())");
                $stmt->execute([$_SESSION['user_id'], $request_id]);
                
                $success = "Request approved successfully!";
            } catch (PDOException $e) {
                error_log("Error approving request: " . $e->getMessage());
                $error = "Failed to approve request. Please try again.";
            }
        } elseif ($_POST['action'] === 'reject_request') {
            // Reject request
            $request_id = (int)$_POST['request_id'];
            try {
                $stmt = $pdo->prepare("UPDATE data_request SET status = 'rejected', response_date = NOW(), approved_by = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $request_id]);
                
                // Log the audit trail
                $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_affected, record_id, details, timestamp) VALUES (?, 'UPDATE', 'data_request', ?, 'Request rejected', NOW())");
                $stmt->execute([$_SESSION['user_id'], $request_id]);
                
                $success = "Request rejected successfully!";
            } catch (PDOException $e) {
                error_log("Error rejecting request: " . $e->getMessage());
                $error = "Failed to reject request. Please try again.";
            }
        } elseif ($_POST['action'] === 'download_requests') {
            // Download requests as CSV
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        dr.id, 
                        tm.name as target_ministry, 
                        dr.request_type as type, 
                        dr.status, 
                        dr.requested_date,
                        dr.title,
                        dr.description as purpose,
                        dr.priority
                    FROM data_request dr
                    LEFT JOIN ministry tm ON dr.target_ministry_id = tm.id
                    WHERE dr.requesting_ministry_id = ?
                    ORDER BY dr.requested_date DESC
                ");
                $stmt->execute([$_SESSION['user_ministry_id']]);
                $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Set headers for CSV download
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="requests_' . date('Ymd_His') . '.csv"');

                // Create CSV output
                $output = fopen('php://output', 'w');
                fputcsv($output, ['ID', 'Target Ministry', 'Type', 'Status', 'Requested Date', 'Title', 'Purpose', 'Priority']);

                foreach ($requests as $request) {
                    fputcsv($output, [
                        $request['id'],
                        $request['target_ministry'],
                        $request['type'],
                        $request['status'],
                        $request['requested_date'],
                        $request['title'],
                        $request['purpose'],
                        $request['priority']
                    ]);
                }

                fclose($output);
                exit;
            } catch (PDOException $e) {
                error_log("Error downloading requests: " . $e->getMessage());
                $error = "Failed to download requests. Please try again.";
            }
        }
    }
}

// Fetch list of ministries for the dropdown
try {
    $ministries = $pdo->query("SELECT name FROM ministry WHERE status = 'active' AND id != {$_SESSION['user_ministry_id']} ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching ministries: " . $e->getMessage());
    $error = "Could not load ministries list.";
}

// Fetch the user's outgoing requests
try {
    $stmt = $pdo->prepare("
        SELECT 
            dr.id, 
            tm.name as target_ministry, 
            dr.request_type as type, 
            dr.status, 
            dr.requested_date,
            dr.title,
            dr.description as purpose,
            dr.priority
        FROM data_request dr
        LEFT JOIN ministry tm ON dr.target_ministry_id = tm.id
        WHERE dr.requesting_ministry_id = ?
        ORDER BY dr.requested_date DESC
    ");
    $stmt->execute([$_SESSION['user_ministry_id']]);
    $outgoing_requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching user requests: " . $e->getMessage());
    $error = "Failed to load your requests.";
}

// Fetch incoming requests for the user's ministry
try {
    $stmt = $pdo->prepare("
        SELECT 
            dr.id, 
            rm.name as requesting_ministry, 
            dr.request_type as type, 
            dr.status, 
            dr.requested_date,
            dr.title,
            dr.description as purpose,
            dr.priority,
            u.name as requested_by
        FROM data_request dr
        LEFT JOIN ministry rm ON dr.requesting_ministry_id = rm.id
        LEFT JOIN user u ON dr.requested_by = u.id
        WHERE dr.target_ministry_id = ?
        ORDER BY dr.requested_date DESC
    ");
    $stmt->execute([$_SESSION['user_ministry_id']]);
    $incoming_requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching incoming requests: " . $e->getMessage());
    $error = "Failed to load incoming requests.";
}

function getStatusBadge($status) {
    $classes = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'approved' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100 text-red-800'
    ];
    return '<span class="inline-flex px-2 py-1 text-xs font-medium rounded-full ' . $classes[$status] . '">' . ucfirst($status) . '</span>';
}

function getPriorityBadge($priority) {
    $classes = [
        'low' => 'bg-gray-100 text-gray-800',
        'medium' => 'bg-blue-100 text-blue-800',
        'high' => 'bg-orange-100 text-orange-800',
        'urgent' => 'bg-red-100 text-red-800'
    ];
    return '<span class="inline-flex px-2 py-1 text-xs font-medium rounded-full ' . $classes[$priority] . '">' . ucfirst($priority) . '</span>';
}

// Get user initials for avatar
$user_initials = strtoupper(substr($user_name, 0, 1) . substr(strstr($user_name, ' '), 1, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Management -int-ministry exchange</title>
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
        
        /* Request Form */
        .request-form-container {
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
        
        /* Request Tables */
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
        
        .export-options {
            display: flex;
            gap: 10px;
        }
        
        .requests-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .requests-table th {
            text-align: left;
            padding: 15px;
            background: rgba(59, 130, 246, 0.1);
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
            background: rgba(59, 130, 246, 0.05);
        }
        
        .status-badge {
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
            background: rgba(16, 185, 129, 0.2);
            color: #065f46;
        }
        
        .status-rejected {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.85em;
            font-weight: 500;
        }
        
        .priority-low {
            background: rgba(107, 114, 128, 0.2);
            color: #374151;
        }
        
        .priority-medium {
            background: rgba(59, 130, 246, 0.2);
            color: #1e40af;
        }
        
        .priority-high {
            background: rgba(245, 158, 11, 0.2);
            color: #92400e;
        }
        
        .priority-urgent {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-approve {
            background: rgba(16, 185, 129, 0.2);
            color: #065f46;
        }
        
        .btn-approve:hover {
            background: rgba(16, 185, 129, 0.3);
        }
        
        .btn-reject {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
        }
        
        .btn-reject:hover {
            background: rgba(239, 68, 68, 0.3);
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
            .sidebar, .header-actions, .export-options, .action-buttons {
                display: none !important;
            }
            
            body, .main-content, .requests-container {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
            }
            
            .header {
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
            
            .requests-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .requests-table {
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
            
            .requests-container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
            
            .requests-table th, 
            .requests-table td {
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
            <a href="user_request.php" class="active"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
             <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="?logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Request Management</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" id="searchInput" placeholder="Search requests...">
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
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo count($outgoing_requests); ?></h3>
                        <p>Total Requests</p>
                    </div>
                </div>
                
                <div class="stat-card stat-approved">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo count(array_filter($outgoing_requests, function($r) { return $r['status'] === 'approved'; })); ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                
                <div class="stat-card stat-pending">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo count(array_filter($outgoing_requests, function($r) { return $r['status'] === 'pending'; })); ?></h3>
                        <p>Pending</p>
                    </div>
                </div>
                
                <div class="stat-card stat-rejected">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo count(array_filter($outgoing_requests, function($r) { return $r['status'] === 'rejected'; })); ?></h3>
                        <p>Rejected</p>
                    </div>
                </div>
            </div>
            
            <div class="request-form-container" id="requestFormContainer">
                <div class="form-header">
                    <h2>Create New Request</h2>
                    <button class="btn btn-outline" id="toggleFormBtn">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <form action="user_request.php" method="POST" id="requestForm">
                    <input type="hidden" name="action" value="create_request">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="target_ministry">Target Ministry</label>
                            <select id="target_ministry" name="target_ministry" required>
                                <option value="">Select a Ministry</option>
                                <?php foreach ($ministries as $ministry_name): ?>
                                    <option value="<?php echo htmlspecialchars($ministry_name); ?>" <?php echo ($selected_ministry === $ministry_name) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ministry_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="request_type">Type of Request</label>
                            <select id="request_type" name="request_type" required>
                                <option value="data">Data</option>
                                <option value="document">Document</option>
                                <option value="information">Information</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="priority">Priority</label>
                            <select id="priority" name="priority" required>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="title">Request Title</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description / Purpose</label>
                        <textarea id="description" name="description" rows="4" required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">Clear</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="requests-container">
                <div class="requests-header">
                    <h2>My Requests</h2>
                    <div class="export-options">
                        <button class="btn btn-primary" id="newRequestBtn">
                            <i class="fas fa-plus"></i> New Request
                        </button>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="download_requests">
                            <button type="submit" class="btn btn-outline">
                                <i class="fas fa-download"></i> Export CSV
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="tabs">
                    <button class="tab active" data-tab="outgoing">Outgoing Requests</button>
                    <button class="tab" data-tab="incoming">Incoming Requests</button>
                </div>
                
                <div class="tab-content active" id="outgoing-tab">
                    <div class="overflow-x-auto">
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>To Ministry</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Requested Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($outgoing_requests) > 0): ?>
                                    <?php foreach ($outgoing_requests as $request): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($request['title']); ?></td>
                                            <td><?php echo htmlspecialchars($request['target_ministry']); ?></td>
                                            <td><?php echo htmlspecialchars($request['type']); ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                    <?php echo ucfirst($request['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo ucfirst($request['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($request['requested_date'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No outgoing requests found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-content" id="incoming-tab">
                    <div class="overflow-x-auto">
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>From Ministry</th>
                                    <th>Requested By</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($incoming_requests) > 0): ?>
                                    <?php foreach ($incoming_requests as $request): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($request['title']); ?></td>
                                            <td><?php echo htmlspecialchars($request['requesting_ministry']); ?></td>
                                            <td><?php echo htmlspecialchars($request['requested_by']); ?></td>
                                            <td><?php echo htmlspecialchars($request['type']); ?></td>
                                            <td>
                                                <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                                                    <?php echo ucfirst($request['priority']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo ucfirst($request['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <div class="action-buttons">
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="approve_request">
                                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                            <button type="submit" class="action-btn btn-approve">Approve</button>
                                                        </form>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="reject_request">
                                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                            <button type="submit" class="action-btn btn-reject">Reject</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 30px; color: var(--dark);">
                                            No incoming requests found.
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

        // Show/hide new request form
        const newRequestBtn = document.getElementById('newRequestBtn');
        const requestFormContainer = document.getElementById('requestFormContainer');
        const toggleFormBtn = document.getElementById('toggleFormBtn');

        newRequestBtn.addEventListener('click', () => {
            requestFormContainer.style.display = 'block';
            setTimeout(() => {
                requestFormContainer.scrollIntoView({ behavior: 'smooth' });
            }, 100);
        });

        toggleFormBtn.addEventListener('click', () => {
            requestFormContainer.style.display = 'none';
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
            const tables = document.querySelectorAll('.requests-table tbody');
            
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

        // Auto-hide form after submission if success
        <?php if (isset($success)): ?>
            document.getElementById('requestFormContainer').style.display = 'none';
        <?php endif; ?>
    </script>
</body>
</html>