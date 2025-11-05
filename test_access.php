session_start();

// Bypass login for testing
$_SESSION['user_id'] = 2; // Use the actual Super Admin ID from your database
$_SESSION['role'] = 'super_admin';
$_SESSION['name'] = 'Super Administrator';

header('Location: admin_management.php');
exit();
?>
<?php
session_start();

// Regenerate session ID to prevent fixation
if (!isset($_SESSION['initiated'])) {
	session_regenerate_id(true);
	$_SESSION['initiated'] = true;
}

// Bypass login for testing
$_SESSION['user_id'] = 2; // Use the actual Super Admin ID from your database
$_SESSION['role'] = 'super_admin';
$_SESSION['name'] = 'Super Administrator';

// Output for user testing
echo "<h2>Test Access</h2>";
echo "<p>Access Granted: User ID " . htmlspecialchars($_SESSION['user_id'], ENT_QUOTES, 'UTF-8') . "</p>";
echo "<p>Role: " . htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8') . "</p>";
echo "<p>Name: " . htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8') . "</p>";
echo '<a href="admin_management.php">Go to Admin Management</a>';
?>