<?php
function check_login()
{
    if (empty($_SESSION['admin_id'])) {
        $host = $_SERVER['HTTP_HOST'];
        $uri  = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $extra = "index.php";
        $_SESSION["admin_id"] = "";
        header("Location: http://$host/$extra");
      //  header("Location: http://$host/$extra");
        exit;
    }
}

function check_access(array $allowed_roles = [])
{
    $current_role = strtolower($_SESSION['admin_role'] ?? '');
    $allowed = array_map('strtolower', $allowed_roles);
    if (empty($current_role) || !in_array($current_role, $allowed, true)) {
        $host = $_SERVER['HTTP_HOST'];
        $uri  = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $extra = 'dashboard.php';
        header("Location: http://$host/$extra");
       // header("Location: http://$host$uri/$extra");
        exit;
    }
}
?>
