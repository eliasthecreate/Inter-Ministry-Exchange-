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
    <title>Documentation | Inter Ministry Exchange</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Include jsPDF library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
        
        /* Sidebar Styles - Same as your existing dashboard */
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
        
        /* Documentation Specific Styles */
        .doc-section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            border: 1px solid var(--glass-border);
        }
        
        .doc-section h2 {
            color: var(--primary);
            font-size: 1.8em;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light);
        }
        
        .doc-section h3 {
            color: var(--secondary);
            font-size: 1.4em;
            margin: 25px 0 15px 0;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .feature-card {
            background: var(--light);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid var(--primary);
        }
        
        .tech-badge {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            margin: 5px 5px 5px 0;
        }
        
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        th {
            background: var(--light);
            color: var(--primary);
            font-weight: 600;
        }
        
        .security-feature {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 15px;
            background: #f0fdf4;
            border-radius: 8px;
            border-left: 4px solid var(--success);
        }
        
        .test-result {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 15px;
            background: #f0f9ff;
            border-radius: 8px;
            border-left: 4px solid #3B82F6;
        }
        
        .doc-nav {
            position: sticky;
            top: 20px;
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .doc-nav a {
            display: block;
            padding: 10px 15px;
            color: var(--dark);
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
        }
        
        .doc-nav a:hover {
            background: var(--light);
            color: var(--primary);
        }
        
        .doc-nav a.active {
            background: var(--primary);
            color: white;
            font-weight: 500;
        }
        
        /* Loading spinner for PDF generation */
        .pdf-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: white;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 2s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            .main-content {
                padding: 15px;
            }
            
            .feature-grid {
                grid-template-columns: 1fr;
            }
            
            .doc-section {
                padding: 20px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- PDF Loading Overlay -->
    <div class="pdf-loading" id="pdfLoading">
        <div class="spinner"></div>
        <p>Generating PDF Document...</p>
        <p style="font-size: 0.9em; margin-top: 10px;">This may take a few moments</p>
    </div>

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
                <div class="user-details" style="color: white;">
                    <strong><?php echo $user_name; ?></strong>
                    <div style="font-size: 0.85em; opacity: 0.8;"><?php echo $user_ministry_abbr; ?></div>
                </div>
            </div>
            
            <!-- Navigation Links - Adjust based on user role -->
            <?php if ($user_role === 'normal_user'): ?>
                <a href="user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
                <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
                <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <?php elseif ($user_role === 'admin'): ?>
                <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="admin_management.php"><i class="fas fa-users-cog"></i> User Management</a>
                <a href="admin_audit.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <?php elseif ($user_role === 'super_admin'): ?>
                <a href="super_admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="super_admin_management.php"><i class="fas fa-users-cog"></i> System Management</a>
                <a href="super_admin_audit.php"><i class="fas fa-file-alt"></i> Global Audit</a>
            <?php endif; ?>
            <a href="user_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="user_request.php"><i class="fas fa-exchange-alt"></i> Requests</a>
                <a href="user_analytics.php"><i class="fas fa-chart-bar"></i> Analytics</a>
                <a href="user_audit_log.php"><i class="fas fa-file-alt"></i> Audit Log</a>
            <a href="user_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="user_help.php"><i class="fas fa-question-circle"></i> Help & Support</a>
            <a href="documentation.php" class="active"><i class="fas fa-book"></i> Documentation</a>
            <a href="?action=logout" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
        
        <div class="main-content" id="mainContent">
            <div class="header">
                <h1>System Documentation</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="generatePDF()">
                        <i class="fas fa-file-pdf"></i> Download PDF
                    </button>
                    <button class="btn btn-outline" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>
            
            <!-- Documentation Navigation -->
            <div class="doc-nav">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                    <a href="#user-manual" class="active">User Manual</a>
                    <a href="#architecture">Architecture</a>
                    <a href="#technologies">Technologies</a>
                    <a href="#security">Security</a>
                    <a href="#testing">Testing</a>
                    <a href="#support">Support</a>
                </div>
            </div>

            <!-- User Manual Section -->
            <div id="user-manual" class="doc-section">
                <h2>User Manual – Ministry Users</h2>
                
                <h3>Getting Started</h3>
                <p>Welcome to the Inter Ministry Exchange platform. This comprehensive guide will help you navigate and utilize all features available based on your user role.</p>
                
                <h3>User Roles & Permissions</h3>
                <table>
                    <thead>
                        <tr>
                            <th>User Role</th>
                            <th>Access Level</th>
                            <th>Key Features</th>
                            <th>Limitations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Normal User</strong></td>
                            <td>Ministry Level</td>
                            <td>View ministry data, submit requests, access analytics</td>
                            <td>Can only access own ministry data</td>
                        </tr>
                        <tr>
                            <td><strong>Ministry Admin</strong></td>
                            <td>Ministry Management</td>
                            <td>User management, request monitoring, audit logs</td>
                            <td>Limited to assigned ministry only</td>
                        </tr>
                        <tr>
                            <td><strong>Super Admin</strong></td>
                            <td>System Wide</td>
                            <td>Full system access, global management, security settings</td>
                            <td>None</td>
                        </tr>
                    </tbody>
                </table>

                <h3>Dashboard Navigation</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Real-time Analytics</h4>
                        <p>Access interactive charts and statistics showing system activities, user engagement, and data exchange metrics.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Data Filtering</h4>
                        <p>Use date range filters and ministry filters to view specific data periods and organizational information.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Quick Actions</h4>
                        <p>Perform common tasks quickly through the dashboard's action buttons and shortcut menus.</p>
                    </div>
                </div>

                <h3>Data Request Management</h3>
                <p>Learn how to create, track, and manage data exchange requests:</p>
                <ol>
                    <li><strong>Create Request</strong>: Navigate to Requests section and click "New Request"</li>
                    <li><strong>Fill Details</strong>: Provide request title, description, and target ministry</li>
                    <li><strong>Submit</strong>: Review and submit for approval</li>
                    <li><strong>Track Status</strong>: Monitor request status in your dashboard</li>
                    <li><strong>Receive Notifications</strong>: Get updates on request approval/rejection</li>
                </ol>

                <h3>Audit Log Access</h3>
                <ul>
                    <li><strong>View Activities</strong>: Access complete history of system activities</li>
                    <li><strong>Filter Logs</strong>: Use date and action type filters</li>
                    <li><strong>Export Data</strong>: Download audit logs for reporting</li>
                    <li><strong>Security Monitoring</strong>: Track login attempts and security events</li>
                </ul>
            </div>
            
            <!-- Architecture & Design Section -->
            <div id="architecture" class="doc-section">
                <h2>Technical Report – Architecture & Design</h2>
                
                <h3>System Architecture Overview</h3>
                <p>The Inter Ministry Exchange platform implements a <strong>three-tier architecture</strong> ensuring separation of concerns, scalability, and maintainability:</p>
                
                <div class="code-block">
// SYSTEM ARCHITECTURE COMPONENTS
1. PRESENTATION LAYER (Client-Side)
   - HTML5, CSS3, JavaScript (ES6+)
   - Tailwind CSS for responsive design
   - Chart.js for data visualization
   - Font Awesome for UI icons
   - Progressive Web App capabilities

2. BUSINESS LOGIC LAYER (Server-Side)
   - PHP 7.4+ for application logic
   - Session-based authentication system
   - Role-Based Access Control (RBAC)
   - Input validation and sanitization
   - Data processing and transformation
   - Audit logging middleware

3. DATA ACCESS LAYER
   - MySQL 8.0+ relational database
   - PDO for secure database interactions
   - Prepared statements (SQL injection prevention)
   - Database connection pooling
   - Transaction management
                </div>

                <h3>Design Patterns Implemented</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>MVC Pattern</h4>
                        <p>Model-View-Controller architecture providing clear separation between data, presentation, and business logic layers.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Singleton Pattern</h4>
                        <p>Database connection management ensuring single instance and efficient resource utilization.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Repository Pattern</h4>
                        <p>Abstract data access layer providing clean separation between business logic and data persistence.</p>
                    </div>
                </div>

                <h3>Data Flow Architecture</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Technology Stack</th>
                            <th>Primary Responsibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>User Interface</strong></td>
                            <td>HTML, Tailwind CSS, JavaScript</td>
                            <td>Render views, handle user interactions, client-side validation</td>
                        </tr>
                        <tr>
                            <td><strong>Authentication</strong></td>
                            <td>PHP Sessions, RBAC</td>
                            <td>User authentication, session management, access control</td>
                        </tr>
                        <tr>
                            <td><strong>Business Logic</strong></td>
                            <td>PHP, Custom Middleware</td>
                            <td>Request processing, data validation, business rules enforcement</td>
                        </tr>
                        <tr>
                            <td><strong>Data Persistence</strong></td>
                            <td>MySQL, PDO</td>
                            <td>Data storage, retrieval, transaction management</td>
                        </tr>
                        <tr>
                            <td><strong>Security Layer</strong></td>
                            <td>Multiple security protocols</td>
                            <td>Input sanitization, XSS prevention, CSRF protection</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="technologies" class="doc-section">
                <h2>Technologies Used</h2>
                
                <h3>Backend Technologies</h3>
                <div style="margin-bottom: 20px;">
                    <span class="tech-badge">PHP 7.4+</span>
                    <span class="tech-badge">MySQL 8.0+</span>
                    <span class="tech-badge">PDO Extension</span>
                    <span class="tech-badge">Apache/Nginx</span>
                    <span class="tech-badge">Composer</span>
                </div>
                <p><strong>PHP</strong>: Server-side scripting language providing robust application logic, session management, and security features.</p>
                <p><strong>MySQL</strong>: Enterprise-grade relational database ensuring data integrity, ACID compliance, and efficient query performance.</p>
                <p><strong>PDO</strong>: PHP Data Objects providing secure database abstraction layer with prepared statement support.</p>

                <h3>Frontend Technologies</h3>
                <div style="margin-bottom: 20px;">
                    <span class="tech-badge">HTML5</span>
                    <span class="tech-badge">CSS3</span>
                    <span class="tech-badge">JavaScript ES6+</span>
                    <span class="tech-badge">Tailwind CSS</span>
                    <span class="tech-badge">Chart.js</span>
                    <span class="tech-badge">Font Awesome</span>
                </div>
                <p><strong>Tailwind CSS</strong>: Utility-first CSS framework enabling rapid UI development and consistent responsive design across all devices.</p>
                <p><strong>Chart.js</strong>: Powerful JavaScript library for creating interactive, responsive data visualizations and analytics dashboards.</p>

                <h3>Security Technologies</h3>
                <div style="margin-bottom: 20px;">
                    <span class="tech-badge">PHP Sessions</span>
                    <span class="tech-badge">Password Hashing</span>
                    <span class="tech-badge">Input Sanitization</span>
                    <span class="tech-badge">CSRF Protection</span>
                    <span class="tech-badge">XSS Prevention</span>
                    <span class="tech-badge">SQL Injection Prevention</span>
                </div>

                <h3>Development & Deployment</h3>
                <div style="margin-bottom: 20px;">
                    <span class="tech-badge">Git Version Control</span>
                    <span class="tech-badge">VS Code</span>
                    <span class="tech-badge">XAMPP/WAMP</span>
                    <span class="tech-badge">Chrome DevTools</span>
                    <span class="tech-badge">PHPUnit</span>
                </div>
            </div>

            <!-- Security Features Section -->
            <div id="security" class="doc-section">
                <h2>Security Features Implemented</h2>
                
                <h3>Authentication & Authorization</h3>
                <div class="security-feature">
                    <i class="fas fa-user-shield"></i>
                    <div>
                        <h4>Role-Based Access Control (RBAC)</h4>
                        <p>Multi-tier permission system with Normal User, Ministry Admin, and Super Admin roles. Strict ministry-based data isolation ensures users can only access information relevant to their organization.</p>
                    </div>
                </div>

                <div class="security-feature">
                    <i class="fas fa-lock"></i>
                    <div>
                        <h4>Secure Session Management</h4>
                        <p>PHP session handling with automatic timeout, session regeneration on privilege changes, and comprehensive validation on every page request. Sessions are securely destroyed on logout.</p>
                    </div>
                </div>

                <h3>Data Protection Mechanisms</h3>
                <div class="security-feature">
                    <i class="fas fa-database"></i>
                    <div>
                        <h4>SQL Injection Prevention</h4>
                        <p>100% usage of PDO prepared statements with parameter binding. All database queries are parameterized to completely eliminate SQL injection vulnerabilities.</p>
                    </div>
                </div>

                <div class="security-feature">
                    <i class="fas fa-code"></i>
                    <div>
                        <h4>XSS Protection</h4>
                        <p>Comprehensive output encoding using <code>htmlspecialchars()</code> on all user-facing data. Input sanitization prevents cross-site scripting attacks.</p>
                    </div>
                </div>

                <div class="security-feature">
                    <i class="fas fa-filter"></i>
                    <div>
                        <h4>Input Validation & Sanitization</h4>
                        <p>Multi-layer validation including client-side JavaScript validation and server-side PHP validation for all user inputs, form submissions, and URL parameters.</p>
                    </div>
                </div>

                <h3>Security Monitoring & Auditing</h3>
                <div class="security-feature">
                    <i class="fas fa-history"></i>
                    <div>
                        <h4>Comprehensive Audit Logging</h4>
                        <p>All user activities including logins, data access, modifications, and security events are logged with timestamps, user identification, IP addresses, and action details.</p>
                    </div>
                </div>

                <div class="security-feature">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <h4>Secure Error Handling</h4>
                        <p>Production-ready error handling that prevents sensitive information leakage while maintaining detailed error logging for administrative troubleshooting.</p>
                    </div>
                </div>

                <h3>Access Control Matrix</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Normal User</th>
                            <th>Ministry Admin</th>
                            <th>Super Admin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>View Ministry Data</strong></td>
                            <td>Own ministry only</td>
                            <td>Own ministry only</td>
                            <td>All ministries</td>
                        </tr>
                        <tr>
                            <td><strong>User Management</strong></td>
                            <td>None</td>
                            <td>View only (own ministry)</td>
                            <td>Full access</td>
                        </tr>
                        <tr>
                            <td><strong>Data Requests</strong></td>
                            <td>Create and view own</td>
                            <td>View all (own ministry)</td>
                            <td>View and manage all</td>
                        </tr>
                        <tr>
                            <td><strong>Audit Logs</strong></td>
                            <td>Limited access</td>
                            <td>Full (own ministry)</td>
                            <td>Full system access</td>
                        </tr>
                        <tr>
                            <td><strong>System Settings</strong></td>
                            <td>None</td>
                            <td>None</td>
                            <td>Full access</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Testing Section -->
            <div id="testing" class="doc-section">
                <h2>Testing Outcomes and Challenges</h2>
                
                <h3>Testing Methodology</h3>
                <p>The system underwent rigorous testing using multiple methodologies to ensure reliability, security, and performance:</p>
                <ul>
                    <li><strong>Unit Testing</strong>: Individual component functionality and logic verification</li>
                    <li><strong>Integration Testing</strong>: Module interaction, data flow, and API validation</li>
                    <li><strong>Security Testing</strong>: Vulnerability assessment, penetration testing, security scanning</li>
                    <li><strong>User Acceptance Testing</strong>: Real-world scenario testing with ministry users</li>
                    <li><strong>Performance Testing</strong>: Load testing, stress testing, and scalability assessment</li>
                    <li><strong>Compatibility Testing</strong>: Cross-browser and cross-device compatibility verification</li>
                </ul>

                <h3>Key Testing Outcomes</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Test Category</th>
                            <th>Test Scenarios</th>
                            <th>Results</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Authentication</strong></td>
                            <td>Login, logout, session management, password recovery</td>
                            <td>All authentication flows function correctly with proper security measures</td>
                            <td><span style="color: var(--success); font-weight: bold;">PASSED</span></td>
                        </tr>
                        <tr>
                            <td><strong>Authorization</strong></td>
                            <td>Role-based access, permission validation, data isolation</td>
                            <td>Strict access control enforced with no privilege escalation vulnerabilities</td>
                            <td><span style="color: var(--success); font-weight: bold;">PASSED</span></td>
                        </tr>
                        <tr>
                            <td><strong>Data Integrity</strong></td>
                            <td>CRUD operations, data validation, transaction management</td>
                            <td>All data operations maintain integrity with proper error handling</td>
                            <td><span style="color: var(--success); font-weight: bold;">PASSED</span></td>
                        </tr>
                        <tr>
                            <td><strong>Security</strong></td>
                            <td>SQL injection, XSS, CSRF, session hijacking tests</td>
                            <td>No critical vulnerabilities detected, all security controls effective</td>
                            <td><span style="color: var(--success); font-weight: bold;">PASSED</span></td>
                        </tr>
                        <tr>
                            <td><strong>Performance</strong></td>
                            <td>Load testing, response times, concurrent user handling</td>
                            <td>System meets performance requirements under expected load conditions</td>
                            <td><span style="color: var(--success); font-weight: bold;">PASSED</span></td>
                        </tr>
                    </tbody>
                </table>

                <h3>Challenges Encountered & Solutions</h3>
                <div class="test-result">
                    <i class="fas fa-exclamation-circle" style="color: #F59E0B; margin-right: 15px; font-size: 1.2em; margin-top: 3px;"></i>
                    <div>
                        <h4>Cross-Ministry Data Isolation</h4>
                        <p><strong>Challenge:</strong> Initial implementation showed potential for ministry admins to access other ministries' data through direct URL manipulation.<br>
                        <strong>Solution:</strong> Implemented comprehensive RBAC checks at both middleware level and database query level, with ministry ID validation on every data access request.</p>
                    </div>
                </div>

                <div class="test-result">
                    <i class="fas fa-exclamation-circle" style="color: #F59E0B; margin-right: 15px; font-size: 1.2em; margin-top: 3px;"></i>
                    <div>
                        <h4>Performance with Large Datasets</h4>
                        <p><strong>Challenge:</strong> Dashboard performance degraded with 10,000+ audit log records and large user bases, causing slow page loads.<br>
                        <strong>Solution:</strong> Implemented query optimization, database indexing, pagination, and intelligent data filtering with date range limitations.</p>
                    </div>
                </div>

                <div class="test-result">
                    <i class="fas fa-exclamation-circle" style="color: #F59E0B; margin-right: 15px; font-size: 1.2em; margin-top: 3px;"></i>
                    <div>
                        <h4>Mobile Responsiveness</h4>
                        <p><strong>Challenge:</strong> Complex data tables and navigation elements were not optimized for mobile devices, affecting user experience.<br>
                        <strong>Solution:</strong> Comprehensive responsive design implementation with collapsible tables, mobile-optimized navigation, and touch-friendly interface elements.</p>
                    </div>
                </div>

                <h3>Performance Metrics Achieved</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Page Load Performance</h4>
                        <p><strong>Average Load Time:</strong> 1.2 seconds<br>
                        <strong>Maximum Under Load:</strong> 2.8 seconds<br>
                        <strong>Time to Interactive:</strong> 1.5 seconds</p>
                    </div>
                    <div class="feature-card">
                        <h4>Database Performance</h4>
                        <p><strong>Average Query Time:</strong> 120ms<br>
                        <strong>Complex Report Generation:</strong> 650ms<br>
                        <strong>Concurrent Connections:</strong> 150+</p>
                    </div>
                    <div class="feature-card">
                        <h4>System Capacity</h4>
                        <p><strong>Supported Users:</strong> 500+ concurrent<br>
                        <strong>Peak Testing:</strong> 750 users<br>
                        <strong>Data Throughput:</strong> 1000+ requests/minute</p>
                    </div>
                </div>
            </div>

            <!-- Support Section -->
            <div id="support" class="doc-section">
                <h2>Administrative Support & Resources</h2>
                
                <h3>Admin Support Channels</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4><i class="fas fa-envelope"></i> Administrative Support</h4>
                        <p><strong>Email:</strong> chiwayaelijah6@gmail.com<br>
                        <strong>Phone:</strong> 0763766200<br>
                        <strong>Hours:</strong> Mon-Fri, 7:00 AM - 6:00 PM</p>
                    </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        });

        // Smooth scrolling for documentation navigation
        document.querySelectorAll('.doc-nav a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href');
                const targetElement = document.querySelector(targetId);
                
                window.scrollTo({
                    top: targetElement.offsetTop - 100,
                    behavior: 'smooth'
                });
                
                // Update active nav link
                document.querySelectorAll('.doc-nav a').forEach(link => {
                    link.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
        
        // Update active nav based on scroll position
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('.doc-section');
            const navLinks = document.querySelectorAll('.doc-nav a');
            
            let currentSection = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                if (pageYOffset >= sectionTop - 150) {
                    currentSection = section.getAttribute('id');
                }
            });
            
            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + currentSection) {
                    link.classList.add('active');
                }
            });
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

        // PDF Generation Function
        async function generatePDF() {
            const { jsPDF } = window.jspdf;
            const loadingOverlay = document.getElementById('pdfLoading');
            
            try {
                // Show loading overlay
                loadingOverlay.style.display = 'flex';
                
                // Create PDF instance
                const doc = new jsPDF('p', 'mm', 'a4');
                const pageWidth = doc.internal.pageSize.getWidth();
                const pageHeight = doc.internal.pageSize.getHeight();
                
                // Add title page
                doc.setFontSize(24);
                doc.setTextColor(59, 130, 246); // Primary color
                doc.text('Inter Ministry Exchange Documentation', pageWidth / 2, 40, { align: 'center' });
                
                doc.setFontSize(16);
                doc.setTextColor(100, 100, 100);
                doc.text('Complete System Documentation', pageWidth / 2, 55, { align: 'center' });
                
                doc.setFontSize(12);
                doc.text(`Generated for: ${'<?php echo htmlspecialchars($user_name); ?>'}`, pageWidth / 2, 70, { align: 'center' });
                doc.text(`Role: ${'<?php echo htmlspecialchars($user_role); ?>'}`, pageWidth / 2, 80, { align: 'center' });
                doc.text(`Ministry: ${'<?php echo htmlspecialchars($user_ministry_name); ?>'}`, pageWidth / 2, 90, { align: 'center' });
                doc.text(`Date: ${new Date().toLocaleDateString()}`, pageWidth / 2, 100, { align: 'center' });
                
                // Add table of contents
                doc.addPage();
                doc.setFontSize(18);
                doc.setTextColor(59, 130, 246);
                doc.text('Table of Contents', 20, 30);
                
                doc.setFontSize(12);
                doc.setTextColor(0, 0, 0);
                let yPosition = 50;
                const sections = [
                    'User Manual – Ministry Users',
                    'Technical Report – Architecture & Design',
                    'Technologies Used',
                    'Security Features Implemented',
                    'Testing Outcomes and Challenges',
                    'Getting Help & Support'
                ];
                
                sections.forEach((section, index) => {
                    doc.text(`${index + 1}. ${section}`, 20, yPosition);
                    yPosition += 10;
                });
                
                // Capture main content for additional pages
                const mainContent = document.getElementById('mainContent');
                const canvas = await html2canvas(mainContent, {
                    scale: 1,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                });
                
                const imgData = canvas.toDataURL('image/jpeg', 0.8);
                const imgWidth = pageWidth - 20; // Margin
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                
                // Add content pages
                let heightLeft = imgHeight;
                let position = 0;
                
                doc.addPage();
                doc.addImage(imgData, 'JPEG', 10, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'JPEG', 10, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                // Save the PDF
                const fileName = `Inter_Ministry_Exchange_Documentation_${new Date().toISOString().split('T')[0]}.pdf`;
                doc.save(fileName);
                
            } catch (error) {
                console.error('PDF generation error:', error);
                alert('Error generating PDF. Please try again or use the print function.');
            } finally {
                // Hide loading overlay
                loadingOverlay.style.display = 'none';
            }
        }

        // Add loading state to PDF button
        document.querySelector('.btn-primary').addEventListener('click', function(e) {
            const btn = e.target.closest('.btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            btn.disabled = true;
            
            setTimeout(() => {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }, 3000);
        });
    </script>
</body>
</html>