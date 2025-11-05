<?php
session_start();
try {
    require_once 'config/database.php';
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $error = "System error. Please try again later.";
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin') {
        header("Location: admin_dashboard.php");
        exit();
    } else {
        header("Location: user_dashboard.php");
        exit();
    }
}

// Initialize variables
$error = '';
$success = '';

// Handle PDF download
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['download_pdf'])) {
    $filename = basename($_GET['download_pdf']);
    $filepath = 'pdf_documents/' . $filename;
    
    // Security check - ensure file exists and is a PDF
    if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'pdf') {
        // Set headers for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Read and output the file
        readfile($filepath);
        exit();
    } else {
        $error = "File not found or invalid file type.";
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM user WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['ministry_id'] = $user['ministry_id'];
                $_SESSION['user_role'] = $user['role'];
                
                // Update last login timestamp
                $update_stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
                $update_stmt->execute([$user['id']]);
                
                error_log("Session set: " . print_r($_SESSION, true));
                
                $log_stmt = $pdo->prepare("INSERT INTO log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$user['id'], 'login', 'User logged in successfully', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                
                if ($user['role'] === 'super_admin') {
                    header("Location: admin_dashboard.php");
                    exit();
                } elseif ($user['role'] === 'admin') {
                    header("Location: admin_user_dashboard.php");
                    exit();
                } else {
                    header("Location: user_dashboard.php");
                    exit();
                }
            } else {
                $error = "Invalid email or password";
                error_log("Login failed for email: $email - " . ($user ? "Password mismatch" : "User not found"));
                if ($user) {
                    $log_stmt = $pdo->prepare("INSERT INTO log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    $log_stmt->execute([$user['id'], 'failed_login', 'Failed login attempt', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "Database error occurred: " . $e->getMessage();
        }
    }
}

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup'])) {
    $name = trim($_POST['name'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $full_name = trim($name . ' ' . $lastname);
    $email = trim($_POST['email'] ?? '');
    $ministry_id = trim($_POST['ministry_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    error_log("Signup attempt: name=$name, email=$email, ministry_id=$ministry_id, password_length=" . strlen($password));
    
    if (empty($name) || empty($email) || empty($ministry_id) || empty($password)) {
        $error = "Please fill in all required fields";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
        error_log("Password mismatch: password='$password', confirm_password='$confirm_password'");
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM user WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $error = "Email already registered";
            } else {
                $ministry_stmt = $pdo->prepare("SELECT id FROM ministry WHERE id = ?");
                $ministry_stmt->execute([$ministry_id]);
                
                if ($ministry_stmt->rowCount() === 0) {
                    $error = "Invalid ministry selection";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'user';
                    
                    $stmt = $pdo->prepare("INSERT INTO user (ministry_id, name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    
                    if ($stmt->execute([$ministry_id, $full_name, $email, $password_hash, $role])) {
                        $success = "Account created successfully. Please login with your credentials.";
                        $new_user_id = $pdo->lastInsertId();
                        $log_stmt = $pdo->prepare("INSERT INTO log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                        $log_stmt->execute([$new_user_id, 'registration', 'New user registered', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                    } else {
                        $error = "Error creating account. Please try again.";
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Signup error: " . $e->getMessage());
            $error = "Database error occurred: " . $e->getMessage();
        }
    }
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email FROM user WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            // Always show the same message for security
            $success = "If this email exists in our system, you will receive a password reset link shortly.";
            
            if ($user) {
                // Generate unique token
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                
                // Delete any existing tokens for this user
                $delete_stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?");
                $delete_stmt->execute([$user['id']]);
                
                // Insert new token
                $insert_stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                $insert_stmt->execute([$user['id'], $token, $expires_at]);
                
                // Send reset email
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset-password.php?token=" . $token;
                
                // Email content
                $subject = "Password Reset Request - Inter Ministry Exchange";
                $message = "
                <html>
                <head>
                    <title>Password Reset Request</title>
                    <style>
                        body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                        .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                        .header { background: #10B981; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { padding: 20px; }
                        .button { background: #10B981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; }
                        .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Inter Ministry Exchange</h2>
                            <p>Government Data Exchange Portal</p>
                        </div>
                        <div class='content'>
                            <h3>Hello " . htmlspecialchars($user['name']) . ",</h3>
                            <p>You requested a password reset for your account. Click the button below to reset your password:</p>
                            <p style='text-align: center; margin: 30px 0;'>
                                <a href='$reset_link' class='button'>Reset Your Password</a>
                            </p>
                            <p>Or copy and paste this link in your browser:</p>
                            <p style='background: #f8f9fa; padding: 10px; border-radius: 5px; word-break: break-all;'>
                                <a href='$reset_link'>$reset_link</a>
                            </p>
                            <p><strong>This link will expire in 30 minutes.</strong></p>
                            <p>If you didn't request this reset, please ignore this email.</p>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from the Inter Ministry Exchange System.</p>
                            <p>© " . date('Y') . " Government Portal. All rights reserved.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                // Email headers
                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: Inter Ministry Exchange <noreply@" . $_SERVER['HTTP_HOST'] . ">" . "\r\n";
                $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
                
                // Send email
                if (mail($user['email'], $subject, $message, $headers)) {
                    error_log("Password reset email sent to: " . $user['email']);
                    
                    $log_stmt = $pdo->prepare("INSERT INTO log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    $log_stmt->execute([$user['id'], 'password_reset_request', 'Password reset email sent', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                } else {
                    error_log("Failed to send password reset email to: " . $user['email']);
                    // Don't show error to user for security reasons
                }
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            // Don't show detailed error to user for security reasons
        }
    }
}

// Get PDF files for download section
$pdf_folder = 'pdf_documents/';
$pdf_files = [];
if (is_dir($pdf_folder)) {
    $pdf_files = glob($pdf_folder . "*.pdf");
    // Sort by modification time, newest first
    usort($pdf_files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inter Ministry Exchange - Authentication</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        :root {
            --primary-green: #10B981;
            --light-green: #D1FAE5;
            --dark-green: #059669;
            --darker-green: #047857;
            --bg-color: #ECFDF5;
            --text-color: #064E3B;
            --shadow-light: #F9FAFB;
            --shadow-dark: #D1D5DB;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
        }
        
        .neumorphic {
            border-radius: 16px;
            background: var(--bg-color);
            box-shadow:  8px 8px 16px #d9dfe2, 
                        -8px -8px 16px #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        .neumorphic-inset {
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow: inset 4px 4px 8px #d9dfe2, 
                        inset -4px -4px 8px #ffffff;
        }
        
        .neumorphic-btn {
            border-radius: 12px;
            background: linear-gradient(145deg, #d4f5e4, #b2e6cf);
            box-shadow:  4px 4px 8px #c4e5d5, 
                        -4px -4px 8px #f6fffb;
            transition: all 0.3s ease;
            color: var(--darker-green);
            font-weight: 600;
        }
        
        .neumorphic-btn:hover {
            box-shadow:  2px 2px 4px #c4e5d5, 
                        -2px -2px 4px #f6fffb;
            transform: translateY(2px);
        }
        
        .neumorphic-btn:active {
            box-shadow: inset 3px 3px 6px #c4e5d5, 
                        inset -3px -3px 6px #f6fffb;
        }
        
        .neumorphic-btn-secondary {
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow:  4px 4px 8px #c4e5d5, 
                        -4px -4px 8px #f6fffb;
            transition: all 0.3s ease;
            color: var(--darker-green);
            font-weight: 600;
        }
        
        .neumorphic-btn-secondary:hover {
            box-shadow:  2px 2px 4px #c4e5d5, 
                        -2px -2px 4px #f6fffb;
            transform: translateY(2px);
        }
        
        .neumorphic-input {
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow: inset 3px 3px 6px #d9dfe2, 
                        inset -3px -3px 6px #ffffff;
            border: none;
            transition: all 0.3s ease;
        }
        
        .neumorphic-input:focus {
            box-shadow: inset 5px 5px 10px #d9dfe2, 
                        inset -5px -5px 10px #ffffff;
            outline: none;
        }
        
        .neumorphic-toggle {
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow:  4px 4px 8px #d9dfe2, 
                        -4px -4px 8px #ffffff;
        }
        
        .toggle-bg {
            border-radius: 10px;
            background: var(--primary-green);
            box-shadow:  3px 3px 6px #c4e5d5, 
                        -3px -3px 6px #f6fffb;
        }
        
        .auth-transition {
            transition: all 0.3s ease-in-out;
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .slide-left {
            animation: slideLeft 0.4s ease-in-out;
        }
        
        .slide-right {
            animation: slideRight 0.4s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideLeft {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes slideRight {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .form-toggle {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
        }
        
        input:focus ~ .input-icon {
            color: var(--primary-green);
        }
        
        .checkbox-neumorphic {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 4px;
            background: var(--bg-color);
            box-shadow: inset 2px 2px 4px #d9dfe2, 
                        inset -2px -2px 4px #ffffff;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .checkbox-neumorphic:checked::before {
            content: "✓";
            color: var(--primary-green);
            font-weight: bold;
            font-size: 12px;
        }
        
        .checkbox-neumorphic:checked {
            box-shadow: inset 1px 1px 2px #d9dfe2, 
                        inset -1px -1px 2px #ffffff;
        }
        
        .info-box {
            border-radius: 12px;
            background: var(--light-green);
            box-shadow: inset 3px 3px 6px #c4e5d5, 
                        inset -3px -3px 6px #deffef;
        }
        
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: var(--bg-color);
            box-shadow: inset 2px 2px 4px #d9dfe2, 
                        inset -2px -2px 4px #ffffff;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--primary-green);
            transition: width 0.5s ease;
        }
        
        .form-step {
            display: none;
        }
        
        .form-step.active {
            display: block;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        
        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }
        
        .pdf-card {
            transition: all 0.3s ease;
            border: 1px solid rgba(16, 185, 129, 0.1);
        }
        
        .pdf-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: rgba(16, 185, 129, 0.2);
        }
        
        .download-btn {
            border-radius: 10px;
            background: linear-gradient(145deg, #10B981, #059669);
            box-shadow:  4px 4px 8px #c4e5d5, 
                        -4px -4px 8px #f6fffb;
            transition: all 0.3s ease;
            color: white;
            font-weight: 600;
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .download-btn:hover {
            background: linear-gradient(145deg, #059669, #047857);
            box-shadow:  2px 2px 6px #c4e5d5, 
                        -2px -2px 6px #f6fffb,
                        0 4px 12px rgba(16, 185, 129, 0.3);
            transform: translateY(-2px);
            color: white;
        }
        
        .download-btn:active {
            box-shadow: inset 3px 3px 6px #047857, 
                        inset -3px -3px 6px #10B981;
            transform: translateY(0);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md mx-auto">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 neumorphic rounded-2xl mb-4">
                <i data-lucide="building-2" class="w-8 h-8 text-green-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-green-800">Inter Ministry Exchange</h1>
            <p class="text-sm text-green-600 mt-1">Government Data Exchange Portal</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="neumorphic-inset p-4 mb-6 text-sm error-message rounded-lg" role="alert">
                <div class="flex items-center">
                    <i data-lucide="alert-circle" class="w-4 h-4 mr-2"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="neumorphic-inset p-4 mb-6 text-sm success-message rounded-lg" role="alert">
                <div class="flex items-center">
                    <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-toggle flex neumorphic-toggle p-1 mb-6">
            <div class="toggle-bg absolute top-1 left-1 h-10 w-[calc(33.333%-0.25rem)] transition-all duration-300" id="toggle-bg"></div>
            <button class="flex-1 text-sm font-medium text-center py-2 relative z-10" id="login-toggle">
                <span class="text-green-800">Login</span>
            </button>
            <button class="flex-1 text-sm font-medium text-center py-2 relative z-10" id="signup-toggle">
                <span class="text-green-600">Sign Up</span>
            </button>
            <button class="flex-1 text-sm font-medium text-center py-2 relative z-10" id="pdf-toggle">
                <span class="text-green-600"> Documentation PDF</span>
            </button>
        </div>

        <div id="login-form" class="auth-form fade-in">
            <div class="neumorphic p-6">
                <h2 class="text-xl font-semibold text-green-800 mb-2">Welcome back</h2>
                <p class="text-sm text-green-600 mb-5">Sign in to your government account</p>
                
                <form method="POST" action="" class="space-y-4">
                    <input type="hidden" name="login" value="1">
                    <div>
                        <label class="block text-xs font-medium text-green-700 mb-2">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="mail" class="input-icon w-4 h-4 text-green-500"></i>
                            </div>
                            <input type="email" name="email" required 
                                   class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                   placeholder="you@ministry.gov.zm" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-medium text-green-700">Password</label>
                            <button type="button" onclick="showView('forgot-password')" 
                                    class="text-xs text-green-600 hover:text-green-700">Forgot password?</button>
                        </div>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="lock" class="input-icon w-4 h-4 text-green-500"></i>
                            </div>
                            <input type="password" name="password" required 
                                   class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                   placeholder="••••••••">
                        </div>
                    </div>

                    <div class="flex items-center pt-2">
                        <input id="remember-me" type="checkbox" name="remember" class="checkbox-neumorphic">
                        <label for="remember-me" class="ml-2 block text-xs text-green-700">Remember me ?</label>
                    </div>

                    <button type="submit" class="w-full neumorphic-btn py-3 px-4 text-sm mt-4">
                        <i data-lucide="log-in" class="w-4 h-4 mr-2 inline"></i>
                        Sign in to Portal
                    </button>
                    
                    <button type="button" onclick="showView('demo-users')" class="w-full neumorphic-btn-secondary py-3 px-4 text-sm mt-2">
                        <i data-lucide="users" class="w-4 h-4 mr-2 inline"></i>
                        Show Demo Users
                    </button>
                </form>

                <div class="mt-6">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-green-200"></div>
                        </div>
                        <div class="relative flex justify-center text-xs">
                            <span class="px-2 bg-transparent text-green-600">Secure Government Access</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="signup-form" class="auth-form hidden">
            <div class="neumorphic p-6">
                <h2 class="text-xl font-semibold text-green-800 mb-2">Create account</h2>
                <p class="text-sm text-green-600 mb-3">Join the inter-ministry data network</p>
                
                <div class="mb-6">
                    <div class="progress-bar">
                        <div id="signup-progress" class="progress-fill w-1/2"></div>
                    </div>
                    <div class="flex justify-between text-xs text-green-600 mt-2">
                        <span>Step 1: Personal Info</span>
                        <span>Step 2: Account Setup</span>
                    </div>
                </div>
                
                <form method="POST" action="" id="multi-step-form" class="space-y-4">
                    <input type="hidden" name="signup" value="1">
                    
                    <div id="step-1" class="form-step active slide-left">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-green-700 mb-2">First Name *</label>
                                <input type="text" name="name" required 
                                       class="w-full px-4 py-3 neumorphic-input text-sm" 
                                       placeholder="Florence" 
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-green-700 mb-2">Last Name</label>
                                <input type="text" name="lastname" 
                                       class="w-full px-4 py-3 neumorphic-input text-sm" 
                                       placeholder="Zulu" 
                                       value="<?php echo isset($_POST['lastname']) ? htmlspecialchars($_POST['lastname']) : ''; ?>">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-green-700 mb-2">Ministry/Department *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i data-lucide="building" class="input-icon w-4 h-4 text-green-500"></i>
                                </div>
                                <select name="ministry_id" required 
                                        class="w-full pl-10 pr-8 py-3 neumorphic-input text-sm appearance-none">
                                    <option value="">Select your ministry...</option>
                                    <?php
                                    try {
                                        $ministries = $pdo->query("SELECT * FROM ministry ORDER BY name");
                                        while ($ministry = $ministries->fetch()) {
                                            $selected = (isset($_POST['ministry_id']) && $_POST['ministry_id'] == $ministry['id']) ? 'selected' : '';
                                            echo "<option value='{$ministry['id']}' $selected>" . htmlspecialchars($ministry['name']) . " (" . htmlspecialchars($ministry['abbreviation']) . ")</option>";
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Ministry query error: " . $e->getMessage());
                                        echo "<option value=''>Error loading ministries</option>";
                                    }
                                    ?>
                                </select>
                                <div class="absolute inset-y-0 right-0 pr-2 flex items-center pointer-events-none">
                                    <i data-lucide="chevron-down" class="w-4 h-4 text-green-500"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="pt-4 flex justify-between">
                            <div></div>
                            <button type="button" onclick="nextStep()" 
                                    class="neumorphic-btn py-2 px-5 text-sm flex items-center">
                                Next Step <i data-lucide="arrow-right" class="w-4 h-4 ml-1"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div id="step-2" class="form-step">
                        <div>
                            <label class="block text-xs font-medium text-green-700 mb-2">Work Email *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i data-lucide="mail" class="input-icon w-4 h-4 text-green-500"></i>
                                </div>
                                <input type="email" name="email" required 
                                       class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                       placeholder="Florence.Zulu@ministry.gov.zm" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>
                            <p class="text-xs text-green-600 mt-1">Use your official government email address</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-green-700 mb-2">Password *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i data-lucide="lock" class="input-icon w-4 h-4 text-green-500"></i>
                                </div>
                                <input type="password" name="password" id="password" required 
                                       class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                       placeholder="••••••••" 
                                       minlength="8">
                            </div>
                            <p class="text-xs text-green-600 mt-1">Minimum 8 characters</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-green-700 mb-2">Confirm Password *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i data-lucide="shield-check" class="input-icon w-4 h-4 text-green-500"></i>
                                </div>
                                <input type="password" name="confirm_password" id="confirm_password" required 
                                       class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                       placeholder="••••••••">
                            </div>
                        </div>

                        <div class="flex items-start pt-2">
                            <input id="terms" type="checkbox" name="terms" required class="checkbox-neumorphic mt-1">
                            <label for="terms" class="ml-2 block text-xs text-green-700">
                                I agree to the <a href="#" class="text-green-600 hover:text-green-700 underline">Terms of Service</a> 
                                and  continue<a href="#" class="text-green-600 hover:text-green-700 underline">Privacy Policy</a>
                            </label>
                        </div>
                        
                        <div class="pt-4 flex justify-between">
                            <button type="button" onclick="prevStep()" 
                                    class="neumorphic-btn-secondary py-2 px-5 text-sm flex items-center">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-1"></i> Back
                            </button>
                            <button type="submit" class="neumorphic-btn py-2 px-5 text-sm flex items-center">
                                <i data-lucide="user-plus" class="w-4 h-4 mr-1"></i> Create Account
                            </button>
                        </div>
                    </div>
                </form>

                <div class="mt-6 text-center text-xs text-green-600">
                    Already have an account? 
                    <button type="button" onclick="showLogin()" 
                            class="font-medium text-green-700 hover:text-green-800 underline">Sign in here</button>
                </div>
            </div>
        </div>

        <!-- PDF Download Section -->
        <div id="pdf-download-form" class="auth-form hidden">
            <div class="neumorphic p-6">
                <h2 class="text-xl font-semibold text-green-800 mb-2">Download Documentation</h2>
                <p class="text-sm text-green-600 mb-5">Access government PDF documentation </p>
                
                <?php if (empty($pdf_files)): ?>
                    <div class="neumorphic-inset p-6 text-center">
                        <i data-lucide="file-x" class="w-12 h-12 text-green-400 mx-auto mb-3"></i>
                        <p class="text-green-600 text-sm">No PDF documents available at the moment.</p>
                        <p class="text-green-500 text-xs mt-1">Please check back later or contact administrator.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-h-96 overflow-y-auto pr-2">
                        <?php foreach ($pdf_files as $pdf_file): 
                            $file_name = basename($pdf_file);
                            $file_size = filesize($pdf_file);
                            $file_date = date("F d, Y H:i", filemtime($pdf_file));
                            $file_size_formatted = $file_size > 1024 * 1024 ? 
                                round($file_size / (1024 * 1024), 2) . ' MB' : 
                                round($file_size / 1024, 2) . ' KB';
                            $display_name = pathinfo($file_name, PATHINFO_FILENAME);
                            $display_name = str_replace(['_', '-'], ' ', $display_name);
                            $display_name = ucwords($display_name);
                        ?>
                            <div class="neumorphic-inset p-4 pdf-card rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                                        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                            <i data-lucide="file-text" class="w-5 h-5 text-red-600"></i>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="text-sm font-medium text-green-800 truncate" title="<?php echo htmlspecialchars($display_name); ?>">
                                                <?php echo htmlspecialchars($display_name); ?>
                                            </h3>
                                            <p class="text-xs text-green-600 flex items-center space-x-2">
                                                <span class="flex items-center">
                                                    <i data-lucide="hard-drive" class="w-3 h-3 mr-1"></i>
                                                    <?php echo $file_size_formatted; ?>
                                                </span>
                                                <span>•</span>
                                                <span class="flex items-center">
                                                    <i data-lucide="calendar" class="w-3 h-3 mr-1"></i>
                                                    <?php echo $file_date; ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <a href="?download_pdf=<?php echo urlencode($file_name); ?>" 
                                       class="download-btn py-2 px-4 text-sm font-semibold flex items-center ml-3 flex-shrink-0">
                                        <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                                        Download
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4 text-center text-xs text-green-600 flex items-center justify-center">
                        <i data-lucide="info" class="w-3 h-3 mr-1"></i>
                        <p><?php echo count($pdf_files); ?> document(s) available for download</p>
                    </div>
                <?php endif; ?>
                
                <div class="mt-6 p-4 info-box text-xs">
                    <div class="flex">
                        <div class="flex-shrink-0 pt-0.5">
                            <i data-lucide="shield" class="w-4 h-4 text-green-600"></i>
                        </div>
                        <div class="ml-2">
                            <p class="text-green-700 font-medium mb-1">Secure Document Access</p>
                            <p class="text-green-700">All documents are verified and safe to download. You need a PDF reader to view these files.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="forgot-password-form" class="auth-form hidden">
            <div class="neumorphic p-6">
                <button type="button" onclick="showView('login')" 
                        class="flex items-center text-green-600 hover:text-green-800 mb-4 text-xs">
                    <i data-lucide="arrow-left" class="w-3 h-3 mr-1"></i>
                    Back to login
                </button>

                <h2 class="text-xl font-semibold text-green-800 mb-2">Reset password</h2>
                <p class="text-sm text-green-600 mb-5">Enter your work email for a reset link</p>
                
                <form method="POST" action="" class="space-y-4">
                    <input type="hidden" name="reset_password" value="1">
                    <div>
                        <label class="block text-xs font-medium text-green-700 mb-2">Work Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="mail" class="input-icon w-4 h-4 text-green-500"></i>
                            </div>
                            <input type="email" name="email" required 
                                   class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                   placeholder="you@ministry.gov.zm" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <button type="submit" class="w-full neumorphic-btn py-3 px-4 text-sm">
                        <i data-lucide="send" class="w-4 h-4 mr-2 inline"></i>
                        Send reset link
                    </button>
                </form>

                <div class="mt-6 p-4 info-box text-xs">
                    <div class="flex">
                        <div class="flex-shrink-0 pt-0.5">
                            <i data-lucide="info" class="w-4 h-4 text-green-600"></i>
                        </div>
                        <div class="ml-2">
                            <p class="text-green-700 font-medium mb-1">Security Notice</p>
                            <p class="text-green-700">Reset links expire after 30 minutes and can only be used once for security purposes.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="demo-users-view" class="auth-form hidden mt-6">
            <div class="neumorphic p-6">
                <h2 class="text-xl font-semibold text-green-800 mb-2">Demo Users</h2>
                <p class="text-sm text-green-600 mb-5">Use these credentials to quickly log in.</p>
                
                <div class="space-y-4">
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Super Admin</h3>
                            <p class="text-xs text-green-600">Full System Access</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> chiwayaelijah6@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('chiwayaelijah6@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Ministry of Health Admin </h3>
                            <p class="text-xs text-green-600">Access the MOH Admin Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> admin@moh.gov.zm</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('admin@moh.gov.zm', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                    
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Standard User</h3>
                            <p class="text-xs text-green-600">Access the MOH Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> katongoM16@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('katongoM16@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Ministry of Education Admin</h3>
                            <p class="text-xs text-green-600">Access the MOE Admin Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> admin@moe.gov.zm</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('admin@moe.gov.zm', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>  
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Standard User</h3>
                            <p class="text-xs text-green-600">Access the MOE Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> carineodia@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('carineodia@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Ministry of finance Admin</h3>
                            <p class="text-xs text-green-600">Access the MOF Admin Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> admin@mof.gov.zm</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('admin@mof.gov.zm', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div> 
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Standard User</h3>
                            <p class="text-xs text-green-600">Access the MOF Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> semmyngoma@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('semmyngoma@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                </div>
                <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Ministry of Foreign Affairs Admin</h3>
                            <p class="text-xs text-green-600">Access the MOFA Admin Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> admin@mofa.gov.zm</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('admin@mofa.gov.zm', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                     <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Standard User</h3>
                            <p class="text-xs text-green-600">Access the MOFA Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> mahongosanji@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('mahongosanji@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                     <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Ministry of Home Affairs Admin</h3>
                            <p class="text-xs text-green-600">Access the MOHA Admin Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> admin@moha.gov.zm</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('admin@moha.gov.zm', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                    <div class="neumorphic-inset p-4 rounded-lg flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-sm text-green-700">Standard User</h3>
                            <p class="text-xs text-green-600">Access the MOHA Portal</p>
                            <div class="mt-2 text-xs text-green-800 space-y-1">
                                <p><strong>Email:</strong> chiwayaelijah@gmail.com</p>
                                <p><strong>Password:</strong> 12345678</p>
                            </div>
                        </div>
                        <button type="button" 
                                onclick="fillDemoCredentials('chiwayaelijah@gmail.com', '12345678')" 
                                class="neumorphic-btn-secondary py-2 px-4 text-xs font-semibold">
                            Use
                        </button>
                    </div>
                
                <div class="mt-6 text-center text-xs text-green-600">
                    <button type="button" onclick="showLogin()" class="font-medium text-green-700 hover:text-green-800 underline">Back to login</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        let currentStep = 1;
        const totalSteps = 2;
        
        function nextStep() {
            const firstName = document.querySelector('input[name="name"]').value;
            const ministryId = document.querySelector('select[name="ministry_id"]').value;
            
            if (!firstName.trim()) {
                alert('Please enter your first name');
                return;
            }
            
            if (!ministryId) {
                alert('Please select your ministry');
                return;
            }
            
            if (currentStep < totalSteps) {
                document.getElementById(`step-${currentStep}`).classList.remove('active');
                document.getElementById(`step-${currentStep}`).classList.add('hidden');
                currentStep++;
                document.getElementById(`step-${currentStep}`).classList.remove('hidden');
                document.getElementById(`step-${currentStep}`).classList.add('active', 'slide-left');
                
                document.getElementById('signup-progress').style.width = `${(currentStep / totalSteps) * 100}%`;
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                document.getElementById(`step-${currentStep}`).classList.remove('active');
                document.getElementById(`step-${currentStep}`).classList.add('hidden');
                currentStep--;
                document.getElementById(`step-${currentStep}`).classList.remove('hidden');
                document.getElementById(`step-${currentStep}`).classList.add('active', 'slide-right');
                
                document.getElementById('signup-progress').style.width = `${(currentStep / totalSteps) * 100}%`;
            }
        }

        function showLogin() {
            document.getElementById('toggle-bg').style.transform = 'translateX(0)';
            updateToggleColors('login');
            
            document.getElementById('login-form').classList.remove('hidden');
            document.getElementById('signup-form').classList.add('hidden');
            document.getElementById('pdf-download-form').classList.add('hidden');
            document.getElementById('forgot-password-form').classList.add('hidden');
            document.getElementById('demo-users-view').classList.add('hidden');
            
            resetSignupForm();
        }

        function showSignup() {
            document.getElementById('toggle-bg').style.transform = 'translateX(100%)';
            updateToggleColors('signup');
            
            document.getElementById('signup-form').classList.remove('hidden');
            document.getElementById('login-form').classList.add('hidden');
            document.getElementById('pdf-download-form').classList.add('hidden');
            document.getElementById('forgot-password-form').classList.add('hidden');
            document.getElementById('demo-users-view').classList.add('hidden');
            
            resetSignupForm();
        }

        function showPdfDownload() {
            document.getElementById('toggle-bg').style.transform = 'translateX(200%)';
            updateToggleColors('pdf');
            
            document.getElementById('pdf-download-form').classList.remove('hidden');
            document.getElementById('login-form').classList.add('hidden');
            document.getElementById('signup-form').classList.add('hidden');
            document.getElementById('forgot-password-form').classList.add('hidden');
            document.getElementById('demo-users-view').classList.add('hidden');
            
            resetSignupForm();
        }

        function updateToggleColors(activeTab) {
            const tabs = ['login', 'signup', 'pdf'];
            tabs.forEach(tab => {
                const element = document.getElementById(`${tab}-toggle`).querySelector('span');
                if (tab === activeTab) {
                    element.classList.remove('text-green-600');
                    element.classList.add('text-green-800');
                } else {
                    element.classList.remove('text-green-800');
                    element.classList.add('text-green-600');
                }
            });
        }

        function showDemoUsers() {
            document.getElementById('demo-users-view').classList.remove('hidden');
            document.getElementById('login-form').classList.add('hidden');
            document.getElementById('signup-form').classList.add('hidden');
            document.getElementById('pdf-download-form').classList.add('hidden');
            document.getElementById('forgot-password-form').classList.add('hidden');
            resetSignupForm();
        }

        function fillDemoCredentials(email, password) {
            showLogin();
            document.querySelector('#login-form input[name="email"]').value = email;
            document.querySelector('#login-form input[name="password"]').value = password;
        }

        function resetSignupForm() {
            if (currentStep > 1) {
                document.getElementById(`step-${currentStep}`).classList.remove('active');
                document.getElementById(`step-${currentStep}`).classList.add('hidden');
                currentStep = 1;
                document.getElementById(`step-${currentStep}`).classList.remove('hidden');
                document.getElementById(`step-${currentStep}`).classList.add('active');
                document.getElementById('signup-progress').style.width = '50%';
            }
            document.getElementById('multi-step-form').reset();
        }

        function showView(view) {
            if (view === 'login') {
                showLogin();
            } else if (view === 'signup') {
                showSignup();
            } else if (view === 'pdf-download') {
                showPdfDownload();
            } else if (view === 'forgot-password') {
                document.getElementById('forgot-password-form').classList.remove('hidden');
                document.getElementById('login-form').classList.add('hidden');
                document.getElementById('signup-form').classList.add('hidden');
                document.getElementById('pdf-download-form').classList.add('hidden');
                document.getElementById('demo-users-view').classList.add('hidden');
            } else if (view === 'demo-users') {
                showDemoUsers();
            }
        }

        document.getElementById('login-toggle').addEventListener('click', showLogin);
        document.getElementById('signup-toggle').addEventListener('click', showSignup);
        document.getElementById('pdf-toggle').addEventListener('click', showPdfDownload);

        setTimeout(function() {
            const alerts = document.querySelectorAll('.error-message, .success-message');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease-out';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 inline animate-spin"></i>Processing...';
                    lucide.createIcons();
                }
            });
        });

        <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup'])): ?>
            showSignup();
        <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])): ?>
            showView('forgot-password');
        <?php endif; ?>
    </script>
</body>
</html>