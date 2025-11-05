<?php
session_start();
try {
    require_once 'config/database.php';
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $error = "System error. Please try again later.";
}

// Initialize variables
$error = '';
$success = '';
$valid_token = false;
$token_data = null;

// Check if token is provided and valid
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    
    try {
        $stmt = $pdo->prepare("SELECT prt.*, u.email, u.name 
                              FROM password_reset_tokens prt 
                              JOIN user u ON prt.user_id = u.id 
                              WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()");
        $stmt->execute([$token]);
        $token_data = $stmt->fetch();
        
        if ($token_data) {
            $valid_token = true;
            $_SESSION['reset_token'] = $token;
            $_SESSION['reset_user_id'] = $token_data['user_id'];
        } else {
            $error = "Invalid or expired reset link. Please request a new password reset.";
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        $error = "System error. Please try again.";
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $token = $_POST['token'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        try {
            // Verify token again for security
            $stmt = $pdo->prepare("SELECT prt.*, u.id as user_id 
                                  FROM password_reset_tokens prt 
                                  JOIN user u ON prt.user_id = u.id 
                                  WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()");
            $stmt->execute([$token]);
            $token_data = $stmt->fetch();
            
            if (!$token_data) {
                $error = "Invalid or expired reset token. Please request a new password reset.";
            } else {
                // Update user password
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE user SET password_hash = ? WHERE id = ?");
                $update_stmt->execute([$password_hash, $token_data['user_id']]);
                
                // Mark token as used
                $mark_used_stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
                $mark_used_stmt->execute([$token]);
                
                // Log the password reset
                $log_stmt = $pdo->prepare("INSERT INTO log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->execute([$token_data['user_id'], 'password_reset', 'Password reset successfully via reset link', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                
                $success = "Password reset successfully! You can now login with your new password.";
                $valid_token = false; // Prevent further use
                
                // Clear session
                unset($_SESSION['reset_token']);
                unset($_SESSION['reset_user_id']);
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "System error occurred. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Inter Ministry Exchange</title>
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
        
        .info-box {
            border-radius: 12px;
            background: var(--light-green);
            box-shadow: inset 3px 3px 6px #c4e5d5, 
                        inset -3px -3px 6px #deffef;
        }
        
        .password-strength {
            height: 6px;
            border-radius: 3px;
            background: var(--bg-color);
            box-shadow: inset 2px 2px 4px #d9dfe2, 
                        inset -2px -2px 4px #ffffff;
        }
        
        .password-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md mx-auto">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 neumorphic rounded-2xl mb-4">
                <i data-lucide="shield" class="w-8 h-8 text-green-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-green-800">Reset Password</h1>
            <p class="text-sm text-green-600 mt-1">Inter Ministry Exchange Portal</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="neumorphic-inset p-4 mb-6 text-sm error-message rounded-lg fade-in" role="alert">
                <div class="flex items-center">
                    <i data-lucide="alert-circle" class="w-4 h-4 mr-2"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="neumorphic-inset p-4 mb-6 text-sm success-message rounded-lg fade-in" role="alert">
                <div class="flex items-center">
                    <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($valid_token && !$success): ?>
            <div class="neumorphic p-6 fade-in">
                <div class="text-center mb-6">
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="key" class="w-6 h-6 text-green-600"></i>
                    </div>
                    <h2 class="text-xl font-semibold text-green-800">Create New Password</h2>
                    <p class="text-sm text-green-600 mt-1">for <?php echo htmlspecialchars($token_data['email']); ?></p>
                </div>

                <form method="POST" action="" class="space-y-4" id="reset-form">
                    <input type="hidden" name="reset_password" value="1">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">
                    
                    <div>
                        <label class="block text-xs font-medium text-green-700 mb-2">New Password *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="lock" class="input-icon w-4 h-4 text-green-500"></i>
                            </div>
                            <input type="password" name="new_password" id="new_password" required 
                                   class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                   placeholder="••••••••" 
                                   minlength="8"
                                   oninput="checkPasswordStrength()">
                        </div>
                        <div class="password-strength mt-2">
                            <div id="password-strength-fill" class="password-fill w-0 bg-red-500"></div>
                        </div>
                        <p class="text-xs text-green-600 mt-1" id="password-strength-text">Minimum 8 characters</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-green-700 mb-2">Confirm New Password *</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="shield-check" class="input-icon w-4 h-4 text-green-500"></i>
                            </div>
                            <input type="password" name="confirm_password" id="confirm_password" required 
                                   class="w-full pl-10 pr-4 py-3 neumorphic-input text-sm" 
                                   placeholder="••••••••"
                                   oninput="checkPasswordMatch()">
                        </div>
                        <p class="text-xs mt-1" id="password-match-text"></p>
                    </div>

                    <div class="flex items-start pt-2">
                        <input id="show-password" type="checkbox" class="checkbox-neumorphic mt-1" onchange="togglePasswordVisibility()">
                        <label for="show-password" class="ml-2 block text-xs text-green-700">
                            Show passwords
                        </label>
                    </div>

                    <button type="submit" class="w-full neumorphic-btn py-3 px-4 text-sm mt-4">
                        <i data-lucide="refresh-cw" class="w-4 h-4 mr-2 inline"></i>
                        Reset Password
                    </button>
                </form>

                <div class="mt-6 p-4 info-box text-xs">
                    <div class="flex">
                        <div class="flex-shrink-0 pt-0.5">
                            <i data-lucide="info" class="w-4 h-4 text-green-600"></i>
                        </div>
                        <div class="ml-2">
                            <p class="text-green-700 font-medium mb-1">Password Requirements</p>
                            <p class="text-green-700">• Minimum 8 characters<br>• Use a combination of letters, numbers, and symbols for better security</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif (!$valid_token && !$success): ?>
            <div class="neumorphic p-6 text-center fade-in">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="x-circle" class="w-8 h-8 text-red-600"></i>
                </div>
                <h2 class="text-xl font-semibold text-green-800 mb-2">Invalid Reset Link</h2>
                <p class="text-sm text-green-600 mb-6">This password reset link is invalid or has expired.</p>
                
                <div class="space-y-3">
                    <a href="loginSignup.php" class="w-full neumorphic-btn py-3 px-4 text-sm inline-block">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2 inline"></i>
                        Back to Login
                    </a>
                    <a href="loginSignup.php" class="w-full neumorphic-btn py-3 px-4 text-sm inline-block" onclick="showForgotPassword()">
                        <i data-lucide="mail" class="w-4 h-4 mr-2 inline"></i>
                        Request New Reset Link
                    </a>
                </div>
            </div>
        <?php elseif ($success): ?>
            <div class="neumorphic p-6 text-center fade-in">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="check-circle" class="w-8 h-8 text-green-600"></i>
                </div>
                <h2 class="text-xl font-semibold text-green-800 mb-2">Password Reset Successful</h2>
                <p class="text-sm text-green-600 mb-6">Your password has been reset successfully. You can now login with your new password.</p>
                
                <a href="loginSignup.php" class="w-full neumorphic-btn py-3 px-4 text-sm inline-block">
                    <i data-lucide="log-in" class="w-4 h-4 mr-2 inline"></i>
                    Continue to Login
                </a>
            </div>
        <?php endif; ?>

        <div class="text-center mt-6">
            <p class="text-xs text-green-600">
                © <?php echo date('Y'); ?> Inter Ministry Exchange. All rights reserved.
            </p>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('password-strength-fill');
            const strengthText = document.getElementById('password-strength-text');
            
            let strength = 0;
            let color = '#ef4444'; // red
            let text = 'Weak';
            
            if (password.length >= 8) {
                strength += 25;
                color = '#f59e0b'; // orange
                text = 'Fair';
            }
            
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) {
                strength += 25;
                color = '#10b981'; // green
                text = 'Good';
            }
            
            if (password.match(/\d/)) {
                strength += 25;
            }
            
            if (password.match(/[^a-zA-Z\d]/)) {
                strength += 25;
                color = '#047857'; // dark green
                text = 'Strong';
            }
            
            strengthBar.style.width = strength + '%';
            strengthBar.style.backgroundColor = color;
            strengthText.textContent = text;
            strengthText.className = 'text-xs mt-1 ' + 
                (color === '#ef4444' ? 'text-red-600' : 
                 color === '#f59e0b' ? 'text-yellow-600' : 
                 color === '#10b981' ? 'text-green-600' : 'text-green-700');
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const matchText = document.getElementById('password-match-text');
            
            if (confirmPassword === '') {
                matchText.textContent = '';
                matchText.className = 'text-xs mt-1';
            } else if (password === confirmPassword) {
                matchText.textContent = 'Passwords match';
                matchText.className = 'text-xs mt-1 text-green-600';
            } else {
                matchText.textContent = 'Passwords do not match';
                matchText.className = 'text-xs mt-1 text-red-600';
            }
        }
        
        function togglePasswordVisibility() {
            const showPassword = document.getElementById('show-password').checked;
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            newPassword.type = showPassword ? 'text' : 'password';
            confirmPassword.type = showPassword ? 'text' : 'password';
        }
        
        function showForgotPassword() {
            // This function would be handled by the login page
            // We'll set a session variable to show the forgot password form
            sessionStorage.setItem('showForgotPassword', 'true');
        }
        
        // Auto-hide messages after 5 seconds
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
        
        // Form submission handling
        document.getElementById('reset-form')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please check your entries.');
                return;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return;
            }
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 inline animate-spin"></i>Resetting...';
                lucide.createIcons();
            }
        });
        
        // Check if we should show forgot password (from sessionStorage)
        if (sessionStorage.getItem('showForgotPassword') === 'true') {
            sessionStorage.removeItem('showForgotPassword');
            // This would be handled by the login page
        }
    </script>
</body>
</html>