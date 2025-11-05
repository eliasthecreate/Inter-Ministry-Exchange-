<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header("Location: loginSignup.php");
    exit;
}

// Fetch user data for the navigation bar and settings
try {
    $stmt = $pdo->prepare("SELECT u.*, m.name as ministry_name, m.abbreviation as ministry_abbr 
                          FROM user u 
                          LEFT JOIN ministry m ON u.ministry_id = m.id 
                          WHERE u.id = ?");
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
    $user_email = htmlspecialchars($user['email']);
    $user_ministry_abbr = htmlspecialchars($user['ministry_abbr'] ?? '');
    $user_ministry_name = htmlspecialchars($user['ministry_name'] ?? '');
    
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $error = "Failed to load user data.";
}

// Initialize variables
$success = '';
$error = '';

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    try {
        // Validate inputs
        if (empty($name) || empty($email)) {
            $error = "Name and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } else {
            // Check if password change is requested
            if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                if (empty($current_password)) {
                    $error = "Current password is required to change password.";
                } elseif (empty($new_password)) {
                    $error = "New password is required.";
                } elseif (strlen($new_password) < 8) {
                    $error = "New password must be at least 8 characters long.";
                } elseif ($new_password !== $confirm_password) {
                    $error = "New passwords do not match.";
                } else {
                    // Verify current password
                    $stmt = $pdo->prepare("SELECT password FROM user WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $db_user = $stmt->fetch();
                    
                    if (!$db_user || !password_verify($current_password, $db_user['password'])) {
                        $error = "Current password is incorrect.";
                    } else {
                        // Update password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE user SET name = ?, email = ?, password = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $hashed_password, $_SESSION['user_id']]);
                        $success = "Profile and password updated successfully.";
                    }
                }
            } else {
                // Update without password change
                $stmt = $pdo->prepare("UPDATE user SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([$name, $email, $_SESSION['user_id']]);
                $success = "Profile updated successfully.";
            }
            
            // Update session variables if successful
            if (empty($error)) {
                $_SESSION['user_name'] = $name;
                $user_name = htmlspecialchars($name);
                $user_email = htmlspecialchars($email);
            }
        }
    } catch (PDOException $e) {
        error_log("Error updating profile: " . $e->getMessage());
        $error = "Failed to update profile. Please try again.";
    }
}

// Handle notification preferences update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_notifications') {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $request_updates = isset($_POST['request_updates']) ? 1 : 0;
    $security_alerts = isset($_POST['security_alerts']) ? 1 : 0;
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE user SET 
                              email_notifications = ?, 
                              request_updates = ?, 
                              security_alerts = ?, 
                              newsletter = ? 
                              WHERE id = ?");
        $stmt->execute([$email_notifications, $request_updates, $security_alerts, $newsletter, $_SESSION['user_id']]);
        $success = "Notification preferences updated successfully.";
    } catch (PDOException $e) {
        error_log("Error updating notification preferences: " . $e->getMessage());
        $error = "Failed to update notification preferences. Please try again.";
    }
}

// Handle contact message
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    if (empty($subject) || empty($message)) {
        $error = "Subject and message are required.";
    } else {
        // Send email to support
        $to = "chiwayaelijah6@gmail.com";
        $headers = "From: " . $user_email . "\r\n";
        $headers .= "Reply-To: " . $user_email . "\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        $email_body = "
            <html>
            <head>
                <title>Support Message: $subject</title>
            </head>
            <body>
                <h2>New Support Message</h2>
                <p><strong>From:</strong> $user_name ($user_email)</p>
                <p><strong>Ministry:</strong> $user_ministry_name</p>
                <p><strong>Subject:</strong> $subject</p>
                <p><strong>Message:</strong></p>
                <div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>
                    " . nl2br(htmlspecialchars($message)) . "
                </div>
            </body>
            </html>
        ";
        
        if (mail($to, "Support Request: $subject", $email_body, $headers)) {
            $success = "Your message has been sent successfully. We'll get back to you soon.";
        } else {
            $error = "Failed to send message. Please try again.";
        }
    }
}

// Handle account deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    $confirm_text = trim($_POST['confirm_text']);
    
    if ($confirm_text !== "DELETE MY ACCOUNT") {
        $error = "Please type 'DELETE MY ACCOUNT' to confirm account deletion.";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Delete user data (you might want to soft delete instead)
            $stmt = $pdo->prepare("DELETE FROM user WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            // Send deletion notification email
            $to = "chiwayaelijah6@gmail.com";
            $subject = "Account Deletion Notification - Inter Ministry Exchange";
            $headers = "From: system@interministryexchange.com\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $email_body = "
                <html>
                <head>
                    <title>Account Deletion Notification</title>
                </head>
                <body>
                    <h2>Account Deletion Notification</h2>
                    <p><strong>User:</strong> $user_name ($user_email)</p>
                    <p><strong>Ministry:</strong> $user_ministry_name</p>
                    <p><strong>Role:</strong> $user_role</p>
                    <p><strong>Deletion Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <p><strong>Note:</strong> This account has been permanently deleted from the system.</p>
                </body>
                </html>
            ";
            
            mail($to, $subject, $email_body, $headers);
            
            $pdo->commit();
            
            // Destroy session and redirect
            session_destroy();
            header("Location: loginSignup.php?message=account_deleted");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error deleting account: " . $e->getMessage());
            $error = "Failed to delete account. Please try again.";
        }
    }
}

// Handle data export
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'export_data') {
    try {
        // Fetch user's data
        $user_data = [];
        
        // User profile data
        $stmt = $pdo->prepare("SELECT * FROM user WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data['profile'] = $stmt->fetch();
        
        // User's data requests
        $stmt = $pdo->prepare("SELECT * FROM data_request WHERE requested_by = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data['data_requests'] = $stmt->fetchAll();
        
        // User's audit logs
        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE user_id = ? ORDER BY timestamp DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $user_data['audit_logs'] = $stmt->fetchAll();
        
        // Generate JSON file
        $json_data = json_encode($user_data, JSON_PRETTY_PRINT);
        $filename = "user_data_export_" . date('Y-m-d') . ".json";
        
        // Set headers for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json_data));
        
        echo $json_data;
        exit;
        
    } catch (PDOException $e) {
        error_log("Error exporting data: " . $e->getMessage());
        $error = "Failed to export data. Please try again.";
    }
}

// Fetch FAQ data
$faqs = [
    [
        'question' => 'How do I request data from another ministry?',
        'answer' => 'Navigate to the Requests section and click on "New Request". Select the ministry and specify the data you need. Your request will be reviewed by the ministry admin.'
    ],
    [
        'question' => 'What information is tracked in the audit log?',
        'answer' => 'The audit log tracks all significant actions including logins, data requests, approvals, rejections, and system changes for security and accountability.'
    ],
    [
        'question' => 'How can I update my notification preferences?',
        'answer' => 'Go to Settings > Notification Preferences to customize which emails and alerts you receive from the system.'
    ],
    [
        'question' => 'What should I do if I forget my password?',
        'answer' => 'Use the "Forgot Password" feature on the login page. You will receive an email with instructions to reset your password.'
    ],
    [
        'question' => 'How is my data secured?',
        'answer' => 'We use industry-standard encryption, secure protocols, and regular security audits to protect your data. All data transfers are encrypted and access is strictly controlled.'
    ],
    [
        'question' => 'Can I export my personal data?',
        'answer' => 'Yes, you can export all your personal data from the Account Management section in Settings. This includes your profile information and activity history.'
    ]
];

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
    <title>Settings | Inter Ministry Exchange</title>
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
        
        .btn-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }
        
        /* Settings Cards */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        .settings-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary);
            border-bottom: 1px solid var(--glass-border);
            padding-bottom: 15px;
        }
        
        .card-header h2 {
            font-size: 1.5em;
            font-weight: 600;
        }
        
        .card-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
        
        .slider {
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
        
        .slider:before {
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
        
        input:checked + .slider {
            background-color: var(--primary);
        }
        
        input:checked + .slider:before {
            transform: translateX(30px);
        }
        
        .toggle-label {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .toggle-text {
            flex: 1;
        }
        
        .toggle-text h4 {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .toggle-text p {
            font-size: 0.9em;
            color: var(--dark);
            opacity: 0.7;
        }
        
        /* FAQ Styles */
        .faq-item {
            margin-bottom: 20px;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .faq-question {
            padding: 20px;
            background: rgba(59, 130, 246, 0.05);
            cursor: pointer;
            display: flex;
            justify-content: between;
            align-items: center;
            font-weight: 600;
            color: var(--primary);
        }
        
        .faq-question:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
            background: white;
        }
        
        .faq-answer.show {
            padding: 20px;
            max-height: 500px;
        }
        
        .faq-toggle {
            margin-left: auto;
            transition: transform 0.3s ease;
        }
        
        .faq-toggle.rotated {
            transform: rotate(180deg);
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
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .modal-header h3 {
            color: var(--primary);
            font-size: 1.5em;
            font-weight: 600;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: var(--dark);
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
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .modal-content {
                padding: 20px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .settings-card {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 1.5em;
            }
        }

        .mt-6 {
            margin-top: 1.5rem;
        }
        
        .mb-4 {
            margin-bottom: 1rem;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem;
        }
        
        .text-lg {
            font-size: 1.125rem;
        }
        
        .font-semibold {
            font-weight: 600;
        }
        
        .text-gray-700 {
            color: #374151;
        }
        
        .text-sm {
            font-size: 0.875rem;
        }
        
        .text-gray-600 {
            color: #4b5563;
        }
        
        .mr-2 {
            margin-right: 0.5rem;
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
            <a href="user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
             <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Account Settings</h1>
                <div class="header-actions">
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <?php if (isset($error) && !empty($error)): ?>
                <div class="notification error" id="errorNotification">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($success) && !empty($success)): ?>
                <div class="notification success" id="successNotification">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
            <?php endif; ?>
            
            <div class="settings-grid">
                <!-- Profile Settings Card -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-user-circle mr-2"></i> Profile Information</h2>
                        <div class="card-icon">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" id="name" name="name" value="<?php echo $user_name; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo $user_email; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="ministry">Ministry</label>
                                <input type="text" id="ministry" value="<?php echo $user_ministry_name; ?>" disabled>
                            </div>
                            
                            <div class="form-group">
                                <label for="role">Role</label>
                                <input type="text" id="role" value="<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_role))); ?>" disabled>
                            </div>
                        </div>
                        
                        <h3 class="mt-6 mb-4 text-lg font-semibold text-gray-700">Change Password</h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" placeholder="Enter current password">
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" placeholder="Enter new password">
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline">Reset</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Notification Settings Card -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-bell mr-2"></i> Notification Preferences</h2>
                        <div class="card-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_notifications">
                        
                        <div class="toggle-list">
                            <label class="toggle-label">
                                <div class="toggle-text">
                                    <h4>Email Notifications</h4>
                                    <p>Receive important account notifications via email</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="email_notifications" <?php echo isset($user['email_notifications']) && $user['email_notifications'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                            
                            <label class="toggle-label">
                                <div class="toggle-text">
                                    <h4>Request Updates</h4>
                                    <p>Get notified when your data requests are updated</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="request_updates" <?php echo isset($user['request_updates']) && $user['request_updates'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                            
                            <label class="toggle-label">
                                <div class="toggle-text">
                                    <h4>Security Alerts</h4>
                                    <p>Receive alerts about important security events</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="security_alerts" <?php echo isset($user['security_alerts']) && $user['security_alerts'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                            
                            <label class="toggle-label">
                                <div class="toggle-text">
                                    <h4>Newsletter</h4>
                                    <p>Subscribe to our monthly newsletter</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="newsletter" <?php echo isset($user['newsletter']) && $user['newsletter'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline">Reset</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </form>
                </div>

                <!-- FAQ Section -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-question-circle mr-2"></i> Frequently Asked Questions</h2>
                        <div class="card-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                    </div>
                    
                    <div class="faq-list">
                        <?php foreach ($faqs as $index => $faq): ?>
                            <div class="faq-item">
                                <div class="faq-question" onclick="toggleFAQ(<?php echo $index; ?>)">
                                    <?php echo $faq['question']; ?>
                                    <i class="fas fa-chevron-down faq-toggle" id="faqToggle<?php echo $index; ?>"></i>
                                </div>
                                <div class="faq-answer" id="faqAnswer<?php echo $index; ?>">
                                    <?php echo $faq['answer']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Contact Support Section -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-envelope mr-2"></i> Contact Support</h2>
                        <div class="card-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="send_message">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="subject">Subject</label>
                                <input type="text" id="subject" name="subject" placeholder="Enter subject" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="message">Message</label>
                            <textarea id="message" name="message" rows="5" placeholder="Describe your issue or question..." required></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline">Clear</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Account Management Card -->
                <div class="settings-card">
                    <div class="card-header">
                        <h2><i class="fas fa-shield-alt mr-2"></i> Account Management</h2>
                        <div class="card-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    
                    <div class="account-actions">
                        <div class="form-group">
                            <h4 class="mb-2 font-semibold">Export Data</h4>
                            <p class="mb-4 text-sm text-gray-600">Download a copy of your personal data stored in our system</p>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="export_data">
                                <button type="submit" class="btn btn-outline">
                                    <i class="fas fa-download"></i> Export Data
                                </button>
                            </form>
                        </div>
                        
                        <div class="form-group mt-6">
                            <h4 class="mb-2 font-semibold">Delete Account</h4>
                            <p class="mb-4 text-sm text-gray-600">Permanently delete your account and all associated data. This action cannot be undone.</p>
                            <button type="button" class="btn btn-danger" onclick="showDeleteModal()">
                                <i class="fas fa-trash-alt"></i> Delete Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Account</h3>
                <button class="close-modal" onclick="hideDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <p class="mb-4 text-red-600 font-semibold">Warning: This action is permanent and cannot be undone. All your data will be permanently deleted.</p>
                    <p class="mb-4">To confirm, please type <strong>DELETE MY ACCOUNT</strong> in the box below:</p>
                    <form method="POST" action="" id="deleteForm">
                        <input type="hidden" name="action" value="delete_account">
                        <input type="text" id="confirm_text" name="confirm_text" placeholder="DELETE MY ACCOUNT" class="w-full p-3 border border-red-300 rounded-lg" required>
                        <div class="form-actions mt-4">
                            <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash-alt"></i> Permanently Delete Account
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

        // Show notifications
        <?php if (isset($error) || isset($success)): ?>
            setTimeout(() => {
                const notification = document.getElementById('<?php echo isset($error) && !empty($error) ? 'errorNotification' : 'successNotification'; ?>');
                if (notification) {
                    notification.classList.add('show');
                    
                    // Hide after 5 seconds
                    setTimeout(() => {
                        notification.classList.remove('show');
                    }, 5000);
                }
            }, 300);
        <?php endif; ?>

        // Password validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePassword() {
            if (newPassword && confirmPassword && newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity("Passwords don't match");
            } else if (confirmPassword) {
                confirmPassword.setCustomValidity('');
            }
        }
        
        if (newPassword && confirmPassword) {
            newPassword.addEventListener('change', validatePassword);
            confirmPassword.addEventListener('keyup', validatePassword);
        }

        // FAQ toggle functionality
        function toggleFAQ(index) {
            const answer = document.getElementById('faqAnswer' + index);
            const toggle = document.getElementById('faqToggle' + index);
            
            if (answer.classList.contains('show')) {
                answer.classList.remove('show');
                toggle.classList.remove('rotated');
            } else {
                answer.classList.add('show');
                toggle.classList.add('rotated');
            }
        }

        // Delete account modal functions
        function showDeleteModal() {
            document.getElementById('deleteModal').classList.add('show');
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            document.getElementById('deleteForm').reset();
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(event) {
            if (event.target === this) {
                hideDeleteModal();
            }
        });

        // Prevent form submission if confirmation text doesn't match
        document.getElementById('deleteForm').addEventListener('submit', function(event) {
            const confirmText = document.getElementById('confirm_text').value;
            if (confirmText !== 'DELETE MY ACCOUNT') {
                event.preventDefault();
                alert('Please type "DELETE MY ACCOUNT" exactly as shown to confirm deletion.');
            }
        });
    </script>
</body>
</html>