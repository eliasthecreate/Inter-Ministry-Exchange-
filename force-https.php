// force-https.php - Simple HTTPS redirect
if ($_SERVER['HTTPS'] != "on") {
    $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: " . $redirect_url);
    exit();
}
?>
<?php
// force-https.php - Secure HTTPS redirect
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES, 'UTF-8');
    $uri = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
    $redirect_url = "https://{$host}{$uri}";
    header("Location: {$redirect_url}", true, 301);
    exit();
}
?>