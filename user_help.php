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
    $user_email = htmlspecialchars($user['email']);
    
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

// Initialize variables
$success = '';
$error = '';

// Handle contact form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_help_request') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $subject = trim($_POST['subject']);
        $message = trim($_POST['message']);
        $priority = $_POST['priority'];
        
        // Validate inputs
        if (empty($name) || empty($email) || empty($subject) || empty($message)) {
            $error = "All fields are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } else {
            try {
                // Insert help request into database
                $stmt = $pdo->prepare("INSERT INTO help_requests (user_id, name, email, subject, message, priority, status) 
                                      VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$_SESSION['user_id'], $name, $email, $subject, $message, $priority]);
                
                // Send email notification
                $to = "chiwayaelijah6@gmail.com";
                $email_subject = "Help Request: " . $subject;
                $headers = "From: " . $email . "\r\n";
                $headers .= "Reply-To: " . $email . "\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                
                $email_body = "
                    <html>
                    <head>
                        <title>Help Request: $subject</title>
                    </head>
                    <body>
                        <h2>New Help Request</h2>
                        <p><strong>From:</strong> $name ($email)</p>
                        <p><strong>Ministry:</strong> $user_ministry_name</p>
                        <p><strong>Priority:</strong> " . ucfirst($priority) . "</p>
                        <p><strong>Subject:</strong> $subject</p>
                        <p><strong>Message:</strong></p>
                        <div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>
                            " . nl2br(htmlspecialchars($message)) . "
                        </div>
                        <p><strong>Submitted:</strong> " . date('Y-m-d H:i:s') . "</p>
                    </body>
                    </html>
                ";
                
                if (mail($to, $email_subject, $email_body, $headers)) {
                    $success = "Your help request has been submitted successfully. We'll get back to you soon.";
                } else {
                    $success = "Your help request has been submitted successfully (email notification failed). We'll get back to you soon.";
                }
                
                // Clear form fields
                $name = $email = $subject = $message = '';
                $priority = 'medium';
                
            } catch (PDOException $e) {
                error_log("Error submitting help request: " . $e->getMessage());
                $error = "Failed to submit your request. Please try again.";
            }
        }
    }
    
    // Handle quick contact form
    if ($_POST['action'] === 'quick_contact') {
        $quick_subject = trim($_POST['quick_subject']);
        $quick_message = trim($_POST['quick_message']);
        
        if (empty($quick_subject) || empty($quick_message)) {
            $error = "Subject and message are required.";
        } else {
            // Send quick email
            $to = "chiwayaelijah6@gmail.com";
            $email_subject = "Quick Contact: " . $quick_subject;
            $headers = "From: " . $user_email . "\r\n";
            $headers .= "Reply-To: " . $user_email . "\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $email_body = "
                <html>
                <head>
                    <title>Quick Contact: $quick_subject</title>
                </head>
                <body>
                    <h2>Quick Contact Message</h2>
                    <p><strong>From:</strong> $user_name ($user_email)</p>
                    <p><strong>Ministry:</strong> $user_ministry_name</p>
                    <p><strong>Subject:</strong> $quick_subject</p>
                    <p><strong>Message:</strong></p>
                    <div style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>
                        " . nl2br(htmlspecialchars($quick_message)) . "
                    </div>
                </body>
                </html>
            ";
            
            if (mail($to, $email_subject, $email_body, $headers)) {
                $success = "Your message has been sent successfully. We'll respond within 24 hours.";
            } else {
                $error = "Failed to send message. Please try again.";
            }
        }
    }
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

// Fetch FAQs from database or use defaults
try {
    $stmt = $pdo->query("SELECT question, answer FROM faqs WHERE active = 1 ORDER BY display_order ASC");
    $faqs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching FAQs: " . $e->getMessage());
    // Default FAQs if table doesn't exist
    $faqs = [
        [
            'question' => 'How do I request data from another ministry?',
            'answer' => 'Navigate to the Requests section and click on "New Request". Select the target ministry, specify the data you need, and provide a justification. Your request will be reviewed by the ministry administrator.'
        ],
        [
            'question' => 'What should I do if I forget my password?',
            'answer' => 'Use the "Forgot Password" feature on the login page. You will receive an email with a secure link to reset your password. If you continue to have issues, contact support.'
        ],
        [
            'question' => 'How long does it take to get a data request approved?',
            'answer' => 'Approval times vary by ministry and request complexity. Typically, requests are processed within 2-5 business days. Urgent requests can be marked as high priority.'
        ],
        [
            'question' => 'Can I track the status of my data requests?',
            'answer' => 'Yes, you can view all your requests and their current status in the Requests section. You will also receive email notifications when your request status changes.'
        ],
        [
            'question' => 'What security measures protect my data?',
            'answer' => 'We use industry-standard encryption, secure authentication protocols, and regular security audits. All data transfers are encrypted, and access is strictly controlled based on ministry roles.'
        ],
        [
            'question' => 'How do I update my notification preferences?',
            'answer' => 'Go to Settings > Notification Preferences to customize which emails and alerts you receive. You can enable/disable notifications for request updates, security alerts, and newsletters.'
        ]
    ];
}

// Fetch resources from database or use defaults
try {
    $stmt = $pdo->query("SELECT title, description, link, icon FROM resources WHERE active = 1 ORDER BY display_order ASC");
    $resources = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching resources: " . $e->getMessage());
    // Default resources if table doesn't exist
    $resources = [
        [
            'title' => 'User Guide',
            'description' => 'Complete guide to using the Inter-Ministry Exchange platform',
            'link' => '#',
            'icon' => 'fas fa-book'
        ],
        [
            'title' => 'Video Tutorials',
            'description' => 'Step-by-step video tutorials for common tasks',
            'link' => '#',
            'icon' => 'fas fa-video'
        ],
        [
            'title' => 'API Documentation',
            'description' => 'Technical documentation for developers',
            'link' => '#',
            'icon' => 'fas fa-code'
        ],
        [
            'title' => 'Security Guidelines',
            'description' => 'Best practices for data security and privacy',
            'link' => '#',
            'icon' => 'fas fa-shield-alt'
        ],
        [
            'title' => 'Data Request Templates',
            'description' => 'Pre-built templates for common data requests',
            'link' => '#',
            'icon' => 'fas fa-file-alt'
        ],
        [
            'title' => 'Ministry Contacts',
            'description' => 'Directory of ministry administrators and contacts',
            'link' => '#',
            'icon' => 'fas fa-address-book'
        ]
    ];
}

// Fetch user's recent help requests
try {
    $stmt = $pdo->prepare("SELECT subject, message, priority, status, created_at 
                          FROM help_requests 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC 
                          LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent requests: " . $e->getMessage());
    $recent_requests = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support | Inter Ministry Exchange</title>
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
        
        /* Help Sections */
        .help-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        .help-card {
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
        
        /* FAQ Styles */
        .faq-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .faq-item {
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .faq-item.active {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .faq-question {
            padding: 20px;
            background: rgba(59, 130, 246, 0.05);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }
        
        .faq-question:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        
        .faq-item.active .faq-answer {
            padding: 20px;
            max-height: 500px;
        }
        
        .faq-item.active .faq-toggle i {
            transform: rotate(180deg);
        }
        
        .faq-toggle {
            transition: transform 0.3s ease;
        }
        
        /* Resource Styles */
        .resource-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .resource-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .resource-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }
        
        .resource-icon {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            margin-bottom: 15px;
        }
        
        .resource-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .resource-description {
            color: var(--dark);
            opacity: 0.7;
            margin-bottom: 15px;
            font-size: 0.95em;
            flex-grow: 1;
        }
        
        .resource-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .resource-link:hover {
            text-decoration: underline;
        }
        
        /* Form Styles */
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
        
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
        }
        
        /* Contact Info */
        .contact-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .contact-item:hover {
            background: rgba(59, 130, 246, 0.1);
            transform: translateY(-2px);
        }
        
        .contact-icon {
            background: var(--primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
        }
        
        .contact-details h4 {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .contact-details p {
            color: var(--dark);
            opacity: 0.7;
        }
        
        /* Recent Requests */
        .requests-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .request-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(59, 130, 246, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--primary);
        }
        
        .request-info h4 {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .request-meta {
            display: flex;
            gap: 15px;
            font-size: 0.9em;
            color: var(--dark);
            opacity: 0.7;
        }
        
        .request-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }
        
        .status-in-progress {
            background: rgba(59, 130, 246, 0.2);
            color: var(--primary);
        }
        
        .status-resolved {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        
        /* Quick Contact */
        .quick-contact {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .quick-contact h3 {
            margin-bottom: 15px;
            font-size: 1.3em;
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
            .resource-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .contact-info {
                grid-template-columns: 1fr;
            }
            
            .request-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .help-card {
                padding: 20px;
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
                    <strong><?php echo $user_name; ?></strong>
                    <div class="user-role"><?php echo $user_ministry_abbr; ?></div>
                </div>
            </div>
            <a href="user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
            <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
            <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php" class="active"><i class="fas fa-question-circle"></i> Help & Support</a>
             <a href="documentation.php"><i class="fas fa-book"></i> Documentation</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>Help & Support</h1>
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
            
            <div class="help-grid">
                <!-- Quick Contact Section -->
                <div class="help-card">
                    <div class="quick-contact">
                        <h3>Need Quick Help?</h3>
                        <form method="POST" action="" class="quick-contact-form">
                            <input type="hidden" name="action" value="quick_contact">
                            <div class="form-grid">
                                <div class="form-group">
                                    <input type="text" name="quick_subject" placeholder="Subject" required style="background: rgba(255,255,255,0.9);">
                                </div>
                            </div>
                            <div class="form-group">
                                <textarea name="quick_message" placeholder="Your message..." required style="background: rgba(255,255,255,0.9);"></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn" style="background: white; color: var(--primary);">
                                    <i class="fas fa-paper-plane"></i> Send Quick Message
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent Requests Section -->
                <?php if (count($recent_requests) > 0): ?>
                <div class="help-card">
                    <div class="card-header">
                        <h2><i class="fas fa-history mr-2"></i> Recent Help Requests</h2>
                        <div class="card-icon">
                            <i class="fas fa-history"></i>
                        </div>
                    </div>
                    
                    <div class="requests-list">
                        <?php foreach ($recent_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-info">
                                    <h4><?php echo htmlspecialchars($request['subject']); ?></h4>
                                    <div class="request-meta">
                                        <span><?php echo date('M j, Y', strtotime($request['created_at'])); ?></span>
                                        <span>Priority: <?php echo ucfirst($request['priority']); ?></span>
                                    </div>
                                </div>
                                <div class="request-status status-<?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- FAQs Section -->
                <div class="help-card">
                    <div class="card-header">
                        <h2><i class="fas fa-question-circle mr-2"></i> Frequently Asked Questions</h2>
                        <div class="card-icon">
                            <i class="fas fa-question"></i>
                        </div>
                    </div>
                    
                    <div class="faq-list">
                        <?php if (count($faqs) > 0): ?>
                            <?php foreach ($faqs as $index => $faq): ?>
                                <div class="faq-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <div class="faq-question">
                                        <span><?php echo $faq['question']; ?></span>
                                        <div class="faq-toggle">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                    <div class="faq-answer">
                                        <p><?php echo $faq['answer']; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle text-3xl text-blue-500 mb-3"></i>
                                <p class="text-gray-600">No FAQs available at the moment.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
               
                <!-- Contact Form Section -->
                <div class="help-card">
                    <div class="card-header">
                        <h2><i class="fas fa-envelope mr-2"></i> Contact Support</h2>
                        <div class="card-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="submit_help_request">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" id="name" name="name" value="<?php echo isset($name) ? htmlspecialchars($name) : $user_name; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo isset($email) ? htmlspecialchars($email) : $user_email; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="subject">Subject</label>
                                <input type="text" id="subject" name="subject" value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select id="priority" name="priority">
                                    <option value="low" <?php echo (isset($priority) && $priority === 'low') ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo (!isset($priority) || (isset($priority) && $priority === 'medium')) ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo (isset($priority) && $priority === 'high') ? 'selected' : ''; ?>>High</option>
                                    <option value="urgent" <?php echo (isset($priority) && $priority === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="message">Message</label>
                            <textarea id="message" name="message" required><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="reset" class="btn btn-outline">Clear</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </div>
                    </form>
                    
                    <div class="contact-info">
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="contact-details">
                                <h4>Email Support</h4>
                                <p>chiwayaelijah6@gmail.com</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="contact-details">
                                <h4>Phone Support</h4>
                                <p>+260 (763) 766-200</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="contact-details">
                                <h4>Support Hours</h4>
                                <p>Mon-Fri: 8AM-6PM</p>
                            </div>
                        </div>
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

        // FAQ toggle functionality
        const faqItems = document.querySelectorAll('.faq-item');
        faqItems.forEach(item => {
            const question = item.querySelector('.faq-question');
            question.addEventListener('click', () => {
                // Close all other items
                faqItems.forEach(otherItem => {
                    if (otherItem !== item) {
                        otherItem.classList.remove('active');
                    }
                });
                
                // Toggle current item
                item.classList.toggle('active');
            });
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

        // Auto-expand first FAQ by default
        document.addEventListener('DOMContentLoaded', function() {
            const firstFaq = document.querySelector('.faq-item');
            if (firstFaq) {
                firstFaq.classList.add('active');
            }
        });
    </script>
</body>
</html>