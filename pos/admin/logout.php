<?php
include __DIR__ . "/../../session_init.php";
unset($_SESSION['admin_id']);
session_destroy();
header("Location: ../../index.php");
exit;
