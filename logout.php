<?php
session_start();
session_destroy();
header("Location: loginSignup.php");
exit();
?>