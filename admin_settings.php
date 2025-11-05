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

// Fetch current system settings
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings_array = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $settings = [
        'backup_frequency' => $settings_array['backup_frequency'] ?? 'Daily',
        'session_timeout' => $settings_array['session_timeout'] ?? 30,
        'notification_email' => $settings_array['notification_email'] ?? 'admin@ministrylink.com',
        'maintenance_mode' => $settings_array['maintenance_mode'] ?? 'disabled',
        'data_retention' => $settings_array['data_retention'] ?? 365,
        'max_file_size' => $settings_array['max_file_size'] ?? 10,
        'two_factor_auth' => $settings_array['two_factor_auth'] ?? 'optional',
        'system_language' => $settings_array['system_language'] ?? 'en',
        'auto_update' => $settings_array['auto_update'] ?? 'enabled'
    ];
} catch(PDOException $e) {
    $settings = [
        'backup_frequency' => 'Daily',
        'session_timeout' => 30,
        'notification_email' => 'admin@ministrylink.com',
        'maintenance_mode' => 'disabled',
        'data_retention' => 365,
        'max_file_size' => 10,
        'two_factor_auth' => 'optional',
        'system_language' => 'en',
        'auto_update' => 'enabled'
    ];
    error_log("Error fetching settings: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Prepare a single statement to update each setting by its key
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");

        // Update all settings
        $settings_to_update = [
            'backup_frequency' => $_POST['backup_frequency'],
            'session_timeout' => intval($_POST['session_timeout']),
            'notification_email' => $_POST['notification_email'],
            'maintenance_mode' => $_POST['maintenance_mode'],
            'data_retention' => intval($_POST['data_retention']),
            'max_file_size' => intval($_POST['max_file_size']),
            'two_factor_auth' => $_POST['two_factor_auth'],
            'system_language' => $_POST['system_language'],
            'auto_update' => $_POST['auto_update']
        ];

        foreach ($settings_to_update as $key => $value) {
            $stmt->execute([$key, $value, $value]);
        }

        $success_message = "Settings updated successfully!";
        
        // Re-fetch the settings to display the updated values
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings_array = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $settings = [
            'backup_frequency' => $settings_array['backup_frequency'] ?? 'Daily',
            'session_timeout' => $settings_array['session_timeout'] ?? 30,
            'notification_email' => $settings_array['notification_email'] ?? 'admin@ministrylink.com',
            'maintenance_mode' => $settings_array['maintenance_mode'] ?? 'disabled',
            'data_retention' => $settings_array['data_retention'] ?? 365,
            'max_file_size' => $settings_array['max_file_size'] ?? 10,
            'two_factor_auth' => $settings_array['two_factor_auth'] ?? 'optional',
            'system_language' => $settings_array['system_language'] ?? 'en',
            'auto_update' => $settings_array['auto_update'] ?? 'enabled'
        ];
    } catch(PDOException $e) {
        $error_message = "Failed to update settings: " . $e->getMessage();
        error_log($error_message);
    }
}

// Handle backup action
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    try {
        // In a real application, this would perform an actual database backup
        // For demonstration, we'll just log the action
        $backup_stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
        $backup_stmt->execute([$_SESSION['user_id'], 'backup', 'Manual database backup initiated']);
        
        $success_message = "Database backup initiated successfully! Backup file will be available shortly.";
    } catch(PDOException $e) {
        $error_message = "Failed to initiate backup: " . $e->getMessage();
        error_log($error_message);
    }
}

// Handle cache clear action
if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
    try {
        // In a real application, this would clear the application cache
        $cache_stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)");
        $cache_stmt->execute([$_SESSION['user_id'], 'clear_cache', 'System cache cleared']);
        
        $success_message = "System cache cleared successfully!";
    } catch(PDOException $e) {
        $error_message = "Failed to clear cache: " . $e->getMessage();
        error_log($error_message);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - int-ministry exchange Admin</title>
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
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f59e0b 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
        }
        
        /* Settings Section */
        .settings-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 992px) {
            .settings-container {
                grid-template-columns: 1fr;
            }
        }
        
        .settings-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            color: var(--primary);
            padding-bottom: 15px;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .settings-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .settings-header i {
            color: var(--accent);
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
        
        .form-group input, 
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid var(--glass-border);
            background: var(--light);
            color: var(--dark);
            font-size: 1em;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, 
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* System Actions */
        .actions-container {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            background: rgba(6, 95, 70, 0.05);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .action-icon {
            font-size: 2.5em;
            margin-bottom: 15px;
            color: var(--primary);
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .action-description {
            font-size: 0.9em;
            color: var(--dark);
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .alert-success {
            background: rgba(52, 211, 153, 0.2);
            color: #166534;
            border: 1px solid rgba(52, 211, 153, 0.3);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .alert i {
            font-size: 1.5em;
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--secondary);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .header-actions, .actions-container {
                display: none !important;
            }
            
            body, .main-content, .settings-card {
                background: white !important;
                color: black !important;
                box-shadow: none !important;
            }
            
            .header {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
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
            .settings-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .settings-card, .actions-container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.5em;
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
             <a href="admin_audit_logs.php"><i class="fas fa-file-alt"></i> Audit Logs</a>
            <a href="admin_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
              <a href="admin_documentation.php"><i class="fas fa-book"></i> Documentation</a>
             <a href="#" class="active"><i class="fas fa-cog"></i> System Settings</a>
            <a href="admin_security.php"><i class="fas fa-shield-alt"></i> Security</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>System Settings</h1>
                <div class="header-actions">
                    <div class="search-bar">
                        <i class="fas fa-search" style="color: var(--primary); margin-right: 10px;"></i>
                        <input type="text" placeholder="Search settings...">
                    </div>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <!-- System Actions -->
            <div class="actions-container">
                <div class="settings-header">
                    <h2><i class="fas fa-bolt"></i> System Actions</h2>
                </div>
                <div class="actions-grid">
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-database"></i>
                        </div>
                        <div class="action-title">Database Backup</div>
                        <div class="action-description">Create a manual backup of the system database</div>
                        <a href="?action=backup" class="btn btn-primary">
                            <i class="fas fa-download"></i> Backup Now
                        </a>
                    </div>
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-broom"></i>
                        </div>
                        <div class="action-title">Clear Cache</div>
                        <div class="action-description">Clear system cache and temporary files</div>
                        <a href="?action=clear_cache" class="btn btn-warning">
                            <i class="fas fa-trash"></i> Clear Cache
                        </a>
                    </div>
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="action-title">System Logs</div>
                        <div class="action-description">View and manage system activity logs</div>
                        <a href="admin_audit_logs.php" class="btn btn-outline">
                            <i class="fas fa-eye"></i> View Logs
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Settings Forms -->
            <div class="settings-container">
                <!-- General Settings -->
                <div class="settings-card">
                    <div class="settings-header">
                        <h2><i class="fas fa-cog"></i> General Settings</h2>
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label for="backup_frequency">Backup Frequency</label>
                            <select id="backup_frequency" name="backup_frequency">
                                <option value="Daily" <?php echo ($settings['backup_frequency'] ?? 'Daily') === 'Daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="Weekly" <?php echo ($settings['backup_frequency'] ?? '') === 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="Monthly" <?php echo ($settings['backup_frequency'] ?? '') === 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="session_timeout">Session Timeout (minutes)</label>
                            <input type="number" id="session_timeout" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>" min="1" max="1440">
                        </div>
                        <div class="form-group">
                            <label for="notification_email">Notification Email</label>
                            <input type="email" id="notification_email" name="notification_email" value="<?php echo htmlspecialchars($settings['notification_email'] ?? 'admin@ministrylink.com'); ?>">
                        </div>
                        <div class="form-group">
                            <label for="system_language">System Language</label>
                            <select id="system_language" name="system_language">
                                <option value="en" <?php echo ($settings['system_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="fr" <?php echo ($settings['system_language'] ?? '') === 'fr' ? 'selected' : ''; ?>>French</option>
                                <option value="es" <?php echo ($settings['system_language'] ?? '') === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                <option value="ar" <?php echo ($settings['system_language'] ?? '') === 'ar' ? 'selected' : ''; ?>>Arabic</option>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Security Settings -->
                <div class="settings-card">
                    <div class="settings-header">
                        <h2><i class="fas fa-shield-alt"></i> Security Settings</h2>
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label for="two_factor_auth">Two-Factor Authentication</label>
                            <select id="two_factor_auth" name="two_factor_auth">
                                <option value="disabled" <?php echo ($settings['two_factor_auth'] ?? 'optional') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                <option value="optional" <?php echo ($settings['two_factor_auth'] ?? 'optional') === 'optional' ? 'selected' : ''; ?>>Optional</option>
                                <option value="required" <?php echo ($settings['two_factor_auth'] ?? '') === 'required' ? 'selected' : ''; ?>>Required for all users</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="data_retention">Data Retention Period (days)</label>
                            <input type="number" id="data_retention" name="data_retention" value="<?php echo htmlspecialchars($settings['data_retention'] ?? '365'); ?>" min="30" max="1095">
                        </div>
                        <div class="form-group">
                            <label for="max_file_size">Maximum File Size (MB)</label>
                            <input type="number" id="max_file_size" name="max_file_size" value="<?php echo htmlspecialchars($settings['max_file_size'] ?? '10'); ?>" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Maintenance Mode</label>
                            <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="maintenance_mode" value="enabled" <?php echo ($settings['maintenance_mode'] ?? 'disabled') === 'enabled' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span><?php echo ($settings['maintenance_mode'] ?? 'disabled') === 'enabled' ? 'Enabled' : 'Disabled'; ?></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Auto Updates</label>
                            <div style="display: flex; align-items: center; gap: 10px; margin-top: 5px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="auto_update" value="enabled" <?php echo ($settings['auto_update'] ?? 'enabled') === 'enabled' ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                                <span><?php echo ($settings['auto_update'] ?? 'enabled') === 'enabled' ? 'Enabled' : 'Disabled'; ?></span>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
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

        // Toggle switch functionality
        document.querySelectorAll('.toggle-switch input').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const statusText = this.parentElement.nextElementSibling;
                statusText.textContent = this.checked ? 'Enabled' : 'Disabled';
            });
        });
    </script>
</body>
</html>