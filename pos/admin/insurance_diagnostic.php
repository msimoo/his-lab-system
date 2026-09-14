<?php
/**
 * Insurance Diagnostic Tool
 */
include __DIR__ . "/../../session_init.php";
include("config/config.php");
include_once("config/insurance_helpers.php");
error_reporting(E_ALL);
ini_set("display_errors", 1);
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تشخيص التأمين</title><style>body{font-family:Tajawal,sans-serif;padding:30px;background:#f8f9fe;max-width:1100px;margin:auto}h1{color:#1a2235;border-bottom:3px solid #5e72e4;padding-bottom:10px}h2{color:#32325d;margin-top:25px;font-size:1.2rem}pre{background:#fff;padding:12px;border-radius:10px;border:1px solid #e9ecef;overflow-x:auto}.ok{color:#2dce89;font-weight:bold}.err{color:#f5365c;font-weight:bold}.warn{color:#fb6340;font-weight:bold}table{border-collapse:collapse;width:100%;background:#fff;border-radius:10px;overflow:hidden}th{background:#1a2235;color:#fff;padding:10px;text-align:right}td{padding:8px 10px;border-bottom:1px solid #f0f2f7}.card{display:inline-block;padding:12px 20px;border-radius:12px;margin:5px;font-weight:bold;background:#d4edda;color:#155724}</style></head><body><h1>🧪 تشخيص التأمين الطبي</h1>