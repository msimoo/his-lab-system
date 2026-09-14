<?php
$out = fopen("insurance_reports.php", "w");
fwrite($out, "<?php
");
fwrite($out, "/** Insurance Reports v1.0 */
");
fwrite($out, "session_start();
");
fwrite($out, "include('config/config.php');
");
fwrite($out, "include('config/checklogin.php');
");
fwrite($out, "include_once('config/insurance_helpers.php');
");
fwrite($out, "check_login();
");
fwrite($out, "require_once('partials/_head.php');
");
fwrite($out, "?>");
fclose($out);
echo "Helper written
";