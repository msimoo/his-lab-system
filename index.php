<?php
ob_start();
include __DIR__ . "/session_init.php";
include('pos/admin/config/config.php');
include('pos/admin/config/languages.php');

$err = '';
$redirect = '';
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = sha1(md5($_POST['password']));

    // Try admin login first
    $stmt = $mysqli->prepare("SELECT admin_id, admin_role FROM rpos_admin WHERE admin_email = ? AND admin_password = ?");
    $stmt->bind_param('ss', $email, $password);
    $stmt->execute();
    $stmt->bind_result($admin_id, $admin_role);
    $rs = $stmt->fetch();
    $stmt->close();

    if ($rs) {
        $_SESSION['admin_id'] = $admin_id;
        $_SESSION['admin_role'] = $admin_role;
        $redirect = 'pos/admin/dashboard.php';
    } else {
        // Try staff login
        $stmt = $mysqli->prepare("SELECT s.staff_id, r.role_name
            FROM rpos_staff s
            LEFT JOIN rpos_roles r ON s.staff_role_id = r.role_id
            WHERE s.staff_email = ? AND s.staff_password = ? AND s.staff_status = 'Active' LIMIT 1");
        $stmt->bind_param('ss', $email, $password);
        $stmt->execute();
        $stmt->bind_result($staff_id, $staff_role_name);
        $rs2 = $stmt->fetch();
        $stmt->close();

        if ($rs2) {
            $_SESSION['staff_id'] = $staff_id;
            $_SESSION['admin_id'] = $staff_id;
            $_SESSION['admin_role'] = strtolower($staff_role_name);

            // Redirect based on role
            if (strtolower($staff_role_name) == 'pos') {
                $redirect = 'pos/cashier/dashboard.php';
            } elseif (strtolower($staff_role_name) == 'accountant') {
                $redirect = 'pos/account/dashboard.php';
            } else {
                // HR_USER or others to admin
                $redirect = 'pos/admin/dashboard.php';
            }
        } else {
            $err = 'بيانات الدخول غير صحيحة — Incorrect credentials';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Al-Wahat Medical Center — Healthcare Information & Laboratory Management System">
    <title>مركز الواحات الطبي — تسجيل الدخول</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" href="pos/admin/assets/pharmacy.ico" type="image/x-icon">

    <style>
        /* =========================================
           RESET & BASE
           ========================================= */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { height: 100%; scroll-behavior: smooth; }

        body {
            height: 100%;
            font-family: 'Tajawal', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: #080e1a;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            direction: rtl;
        }

        /* =========================================
           ANIMATED GRADIENT BACKGROUND
           ========================================= */
        .bg-canvas {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(ellipse 900px 600px at 15% 20%, rgba(6, 182, 212, 0.12), transparent),
                radial-gradient(ellipse 700px 500px at 85% 80%, rgba(99, 102, 241, 0.10), transparent),
                radial-gradient(ellipse 500px 400px at 50% 50%, rgba(16, 185, 129, 0.06), transparent),
                linear-gradient(160deg, #070d19 0%, #0c1829 30%, #111f38 60%, #0e1a30 100%);
        }

        /* =========================================
           FLOATING PARTICLES
           ========================================= */
        .particles {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0;
            animation: particleFloat linear infinite;
        }

        @keyframes particleFloat {
            0%   { opacity: 0; transform: translateY(100vh) scale(0); }
            10%  { opacity: 1; }
            90%  { opacity: 1; }
            100% { opacity: 0; transform: translateY(-10vh) scale(1); }
        }

        /* =========================================
           GRID PATTERN OVERLAY
           ========================================= */
        .grid-overlay {
            position: fixed;
            inset: 0;
            z-index: 1;
            background-image:
                linear-gradient(rgba(255,255,255,0.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.015) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
        }

        /* =========================================
           MAIN LAYOUT
           ========================================= */
        .login-scene {
            position: relative;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 1100px;
            display: grid;
            grid-template-columns: 1fr 440px;
            gap: 0;
            border-radius: 28px;
            overflow: hidden;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.06),
                0 30px 80px rgba(0,0,0,0.5),
                0 0 120px rgba(6,182,212,0.06);
            animation: wrapperIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes wrapperIn {
            from { opacity: 0; transform: translateY(40px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* =========================================
           LEFT PANEL — SHOWCASE
           ========================================= */
        .showcase-panel {
            background:
                linear-gradient(170deg, rgba(6,182,212,0.08), rgba(99,102,241,0.05)),
                rgba(12, 22, 42, 0.95);
            backdrop-filter: blur(40px);
            -webkit-backdrop-filter: blur(40px);
            padding: 48px 44px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
            border-left: 1px solid rgba(255,255,255,0.06);
        }

        .showcase-panel::before {
            content: '';
            position: absolute;
            top: -120px;
            left: -120px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(6,182,212,0.15), transparent 70%);
            pointer-events: none;
        }

        .showcase-panel::after {
            content: '';
            position: absolute;
            bottom: -80px;
            right: -80px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(99,102,241,0.12), transparent 70%);
            pointer-events: none;
        }

        .showcase-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 50px;
            background: rgba(6,182,212,0.12);
            border: 1px solid rgba(6,182,212,0.2);
            color: #67e8f9;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 24px;
            width: fit-content;
            animation: fadeSlide 0.6s 0.3s both;
        }

        .showcase-badge .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #34d399;
            animation: pulse-dot 2s ease-in-out infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(52,211,153,0.5); }
            50%      { opacity: 0.7; box-shadow: 0 0 0 6px rgba(52,211,153,0); }
        }

        .showcase-title {
            font-size: 34px;
            font-weight: 800;
            color: #fff;
            line-height: 1.35;
            margin-bottom: 12px;
            animation: fadeSlide 0.6s 0.4s both;
        }

        .showcase-title span {
            background: linear-gradient(135deg, #06b6d4, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .showcase-subtitle {
            font-size: 15px;
            color: rgba(255,255,255,0.55);
            line-height: 1.65;
            margin-bottom: 36px;
            max-width: 420px;
            animation: fadeSlide 0.6s 0.5s both;
        }

        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* HEARTBEAT PULSE SVG */
        .pulse-line {
            margin-bottom: 36px;
            opacity: 0.5;
            animation: fadeSlide 0.6s 0.55s both;
        }

        .pulse-line svg {
            width: 100%;
            height: 50px;
        }

        .pulse-line svg path {
            stroke: url(#pulseGrad);
            stroke-width: 2.5;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-dasharray: 600;
            stroke-dashoffset: 600;
            animation: drawPulse 3s ease-in-out infinite;
        }

        @keyframes drawPulse {
            0%   { stroke-dashoffset: 600; }
            50%  { stroke-dashoffset: 0; }
            100% { stroke-dashoffset: -600; }
        }

        /* FEATURE CARDS */
        .features {
            display: flex;
            flex-direction: column;
            gap: 14px;
            position: relative;
            z-index: 2;
        }

        .feature-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: default;
            direction: rtl;
        }

        .feature-card:nth-child(1) { animation: fadeSlide 0.5s 0.6s both; }
        .feature-card:nth-child(2) { animation: fadeSlide 0.5s 0.7s both; }
        .feature-card:nth-child(3) { animation: fadeSlide 0.5s 0.8s both; }

        .feature-card:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.12);
            transform: translateX(-6px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .feature-icon.lab {
            background: linear-gradient(135deg, rgba(6,182,212,0.2), rgba(6,182,212,0.05));
            color: #22d3ee;
            border: 1px solid rgba(6,182,212,0.15);
        }

        .feature-icon.clinic {
            background: linear-gradient(135deg, rgba(168,85,247,0.2), rgba(168,85,247,0.05));
            color: #c084fc;
            border: 1px solid rgba(168,85,247,0.15);
        }

        .feature-icon.finance {
            background: linear-gradient(135deg, rgba(16,185,129,0.2), rgba(16,185,129,0.05));
            color: #34d399;
            border: 1px solid rgba(16,185,129,0.15);
        }

        .feature-text h4 {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 2px;
        }

        .feature-text p {
            font-size: 12.5px;
            color: rgba(255,255,255,0.45);
            font-weight: 400;
        }

        /* =========================================
           RIGHT PANEL — LOGIN FORM
           ========================================= */
        .login-panel {
            background: #ffffff;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .login-panel::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #06b6d4, #6366f1, #a855f7);
            border-radius: 0 0 0 0;
        }

        /* LOGO / BRAND */
        .brand-header {
            text-align: center;
            margin-bottom: 32px;
            animation: fadeSlide 0.5s 0.3s both;
        }

        .brand-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            border-radius: 20px;
            background: linear-gradient(135deg, #0891b2, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(6,182,212,0.25);
            position: relative;
            overflow: hidden;
        }

        .brand-logo::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 40%, rgba(255,255,255,0.15));
        }

        .brand-logo i {
            font-size: 30px;
            color: #fff;
            z-index: 1;
            position: relative;
        }

        .brand-header h1 {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }

        .brand-header p {
            font-size: 13.5px;
            color: #9ca3af;
            font-weight: 400;
        }

        /* ERROR ALERT */
        .alert-error {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 20px;
            animation: shakeError 0.5s ease both;
        }

        .alert-error i {
            font-size: 16px;
            flex-shrink: 0;
        }

        @keyframes shakeError {
            0%, 100% { transform: translateX(0); }
            15%, 45%, 75% { transform: translateX(-4px); }
            30%, 60% { transform: translateX(4px); }
        }

        /* FORM */
        .login-form {
            animation: fadeSlide 0.5s 0.4s both;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrap .icon-lead {
            position: absolute;
            right: 16px;
            color: #9ca3af;
            font-size: 16px;
            pointer-events: none;
            transition: color 0.25s ease;
        }

        .input-wrap input {
            width: 100%;
            padding: 14px 48px 14px 48px;
            border-radius: 14px;
            border: 1.5px solid #e5e7eb;
            background: #f9fafb;
            font-size: 15px;
            font-family: 'Tajawal', 'Inter', sans-serif;
            color: #111827;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            direction: ltr;
            text-align: left;
        }

        .input-wrap input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        .input-wrap input:focus {
            outline: none;
            border-color: #06b6d4;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(6,182,212,0.08), 0 4px 16px rgba(6,182,212,0.06);
        }

        .input-wrap input:focus ~ .icon-lead {
            color: #06b6d4;
        }

        /* Password toggle */
        .pwd-toggle {
            position: absolute;
            left: 14px;
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
            transition: color 0.2s;
        }

        .pwd-toggle:hover { color: #6366f1; }

        /* Remember + Forgot */
        .form-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .remember-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember-wrap input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid #d1d5db;
            border-radius: 5px;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .remember-wrap input[type="checkbox"]:checked {
            background: linear-gradient(135deg, #06b6d4, #6366f1);
            border-color: transparent;
        }

        .remember-wrap input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
        }

        .remember-wrap label {
            font-size: 13px;
            color: #6b7280;
            cursor: pointer;
            user-select: none;
        }

        .forgot-link {
            font-size: 12.5px;
            color: #06b6d4;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .forgot-link:hover { color: #6366f1; }

        /* SUBMIT BUTTON */
        .btn-login {
            width: 100%;
            padding: 15px 24px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #0891b2, #6366f1);
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Tajawal', sans-serif;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 6px 20px rgba(6,182,212,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.6s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(6,182,212,0.35);
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 4px 16px rgba(6,182,212,0.2);
        }

        .btn-login .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2.5px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        .btn-login.loading .btn-text { display: none; }
        .btn-login.loading .spinner { display: block; }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* DIVIDER */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 24px 0;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        .divider span {
            font-size: 12px;
            color: #9ca3af;
            white-space: nowrap;
            font-weight: 500;
        }

        /* QUICK ACCESS ROLES */
        .quick-roles {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .role-chip {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 12px 8px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1.5px solid #e5e7eb;
            transition: all 0.25s ease;
            cursor: default;
        }

        .role-chip:hover {
            border-color: #06b6d4;
            background: #f0fdfa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(6,182,212,0.08);
        }

        .role-chip i {
            font-size: 18px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .role-chip:nth-child(1) i { color: #0891b2; background: rgba(6,182,212,0.1); }
        .role-chip:nth-child(2) i { color: #7c3aed; background: rgba(124,58,237,0.1); }
        .role-chip:nth-child(3) i { color: #059669; background: rgba(5,150,105,0.1); }

        .role-chip span {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
        }

        /* FOOTER */
        .login-footer {
            text-align: center;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #f3f4f6;
            animation: fadeSlide 0.5s 0.6s both;
        }

        .login-footer p {
            font-size: 11.5px;
            color: #9ca3af;
        }

        .login-footer a {
            color: #06b6d4;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .login-footer a:hover { color: #6366f1; }

        /* =========================================
           FLOATING MEDICAL ICONS (background deco)
           ========================================= */
        .deco-icons {
            position: fixed;
            inset: 0;
            z-index: 2;
            pointer-events: none;
            overflow: hidden;
        }

        .deco-icon {
            position: absolute;
            color: rgba(255,255,255,0.03);
            font-size: 48px;
            animation: floatIcon 20s ease-in-out infinite;
        }

        .deco-icon:nth-child(1) { top: 8%; left: 5%; animation-delay: 0s; font-size: 40px; }
        .deco-icon:nth-child(2) { top: 18%; right: 8%; animation-delay: -4s; font-size: 55px; }
        .deco-icon:nth-child(3) { bottom: 25%; left: 12%; animation-delay: -8s; font-size: 35px; }
        .deco-icon:nth-child(4) { bottom: 10%; right: 15%; animation-delay: -12s; font-size: 45px; }
        .deco-icon:nth-child(5) { top: 50%; left: 3%; animation-delay: -6s; font-size: 38px; }
        .deco-icon:nth-child(6) { top: 35%; right: 3%; animation-delay: -10s; font-size: 42px; }

        @keyframes floatIcon {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25%      { transform: translateY(-20px) rotate(5deg); }
            50%      { transform: translateY(-8px) rotate(-3deg); }
            75%      { transform: translateY(-25px) rotate(8deg); }
        }

        /* =========================================
           CURRENT TIME WIDGET
           ========================================= */
        .time-widget {
            position: fixed;
            top: 24px;
            left: 28px;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 18px;
            border-radius: 50px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            font-weight: 500;
            font-family: 'Inter', monospace;
            direction: ltr;
        }

        .time-widget i { color: #06b6d4; }

        /* =========================================
           RESPONSIVE
           ========================================= */

        /* Tablets */
        @media (max-width: 1024px) {
            .login-wrapper {
                max-width: 900px;
                grid-template-columns: 1fr 400px;
            }

            .showcase-panel { padding: 36px 32px; }
            .showcase-title { font-size: 28px; }
        }

        /* Mobile */
        @media (max-width: 768px) {
            body { overflow: auto; }

            .login-scene {
                padding: 16px;
                align-items: flex-start;
                padding-top: 60px;
            }

            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 480px;
                border-radius: 24px;
            }

            .showcase-panel {
                padding: 32px 28px 28px;
                order: 2;
                border-left: none;
                border-top: 1px solid rgba(255,255,255,0.06);
            }

            .showcase-title { font-size: 24px; }
            .showcase-subtitle { font-size: 14px; margin-bottom: 24px; }
            .pulse-line { margin-bottom: 24px; }
            .feature-card { padding: 12px 16px; }
            .feature-icon { width: 42px; height: 42px; font-size: 18px; }

            .login-panel {
                padding: 36px 28px;
                order: 1;
            }

            .brand-logo { width: 64px; height: 64px; }
            .brand-logo i { font-size: 26px; }
            .brand-header h1 { font-size: 21px; }

            .quick-roles { grid-template-columns: repeat(3, 1fr); gap: 8px; }

            .time-widget { top: 12px; left: 12px; padding: 6px 14px; font-size: 12px; }
            .deco-icons { display: none; }
        }

        /* Small phones */
        @media (max-width: 400px) {
            .login-panel { padding: 28px 20px; }
            .showcase-panel { padding: 24px 20px; }
            .brand-header h1 { font-size: 19px; }
            .input-wrap input { padding: 12px 44px; font-size: 14px; }
            .btn-login { padding: 13px 20px; font-size: 15px; }
            .quick-roles { grid-template-columns: repeat(3, 1fr); gap: 6px; }
            .role-chip { padding: 10px 6px; }
            .role-chip span { font-size: 10px; }
        }

        /* Very tall screens */
        @media (min-height: 900px) and (min-width: 769px) {
            .login-wrapper { min-height: 600px; }
        }

        /* Landscape phones */
        @media (max-height: 500px) and (orientation: landscape) {
            .login-scene { padding: 12px; align-items: flex-start; }
            body { overflow: auto; }
            .showcase-panel { display: none; }
            .login-wrapper { grid-template-columns: 1fr; max-width: 420px; }
        }

        /* =========================================
           PRINT — HIDE DECORATIONS
           ========================================= */
        @media print {
            .bg-canvas, .particles, .grid-overlay, .deco-icons, .time-widget { display: none !important; }
            .login-wrapper { box-shadow: none; }
        }
    </style>
</head>

<body>

    <!-- Background layers -->
    <div class="bg-canvas"></div>
    <div class="grid-overlay"></div>

    <!-- Floating particles (generated by JS) -->
    <div class="particles" id="particles"></div>

    <!-- Floating decorative medical icons -->
    <div class="deco-icons">
        <i class="deco-icon fas fa-heartbeat"></i>
        <i class="deco-icon fas fa-microscope"></i>
        <i class="deco-icon fas fa-dna"></i>
        <i class="deco-icon fas fa-stethoscope"></i>
        <i class="deco-icon fas fa-pills"></i>
        <i class="deco-icon fas fa-vial"></i>
    </div>

    <!-- Live clock widget -->
    <div class="time-widget">
        <i class="far fa-clock"></i>
        <span id="liveClock">--:--</span>
    </div>

    <!-- ========== MAIN LOGIN CARD ========== -->
    <div class="login-scene">
        <div class="login-wrapper">

            <!-- ===== LEFT: SHOWCASE PANEL ===== -->
            <div class="showcase-panel">
                <div class="showcase-badge">
                    <span class="dot"></span>
                    HIS & LIMS v2.0
                </div>

                <h2 class="showcase-title">
                    أهلاً بكم في<br><span>مركز الواحات الطبي</span>
                </h2>

                <p class="showcase-subtitle">
                    نظام إدارة متكامل للمستشفيات والعيادات والمختبرات الطبية — سجّل دخولك للوصول إلى لوحة التحكم المناسبة لصلاحياتك.
                </p>

                <!-- SVG Heartbeat Pulse -->
                <div class="pulse-line">
                    <svg viewBox="0 0 500 60" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="pulseGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#06b6d4" />
                                <stop offset="50%" stop-color="#6366f1" />
                                <stop offset="100%" stop-color="#a855f7" />
                            </linearGradient>
                        </defs>
                        <path d="M0,30 L80,30 L100,30 L120,8 L140,52 L160,8 L180,52 L200,30 L220,30 L260,30 L280,8 L300,52 L320,8 L340,52 L360,30 L380,30 L500,30" />
                    </svg>
                </div>

                <!-- Feature Highlights -->
                <div class="features">
                    <div class="feature-card">
                        <div class="feature-icon lab">
                            <i class="fas fa-microscope"></i>
                        </div>
                        <div class="feature-text">
                            <h4>المختبر الطبي</h4>
                            <p>تتبع العينات والنتائج والتقارير المختبرية</p>
                        </div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon clinic">
                            <i class="fas fa-stethoscope"></i>
                        </div>
                        <div class="feature-text">
                            <h4>العيادات والمواعيد</h4>
                            <p>حجز المواعيد وشاشة الاستدعاء الذكية</p>
                        </div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon finance">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>الشؤون المالية</h4>
                            <p>إدارة الحسابات والفواتير والتقارير المالية</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== RIGHT: LOGIN FORM ===== -->
            <div class="login-panel">
                <div class="brand-header">
                    <div class="brand-logo">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <h1>تسجيل الدخول</h1>
                    <p>مركز الواحات الطبي — Management System</p>
                </div>

                <?php if (!empty($err)): ?>
                    <div class="alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $err; ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" class="login-form" id="loginForm">
                    <div class="input-group">
                        <label for="email">البريد الإلكتروني</label>
                        <div class="input-wrap">
                            <input type="email" id="email" name="email" placeholder="user@example.com" required autocomplete="email" autofocus>
                            <i class="icon-lead fas fa-envelope"></i>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password">كلمة المرور</label>
                        <div class="input-wrap">
                            <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                            <i class="icon-lead fas fa-lock"></i>
                            <button type="button" class="pwd-toggle" id="pwdToggle" aria-label="Toggle password visibility">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-meta">
                        <label class="remember-wrap">
                            <input type="checkbox" name="remember" id="remember">
                            <label for="remember">تذكرني</label>
                        </label>
                        <a href="pos/admin/forgot_pwd.php" class="forgot-link">نسيت كلمة المرور؟</a>
                    </div>

                    <input type="hidden" name="login" value="1">
                    <button type="submit" class="btn-login" id="btnLogin">
                        <span class="btn-text"><i class="fas fa-sign-in-alt"></i> تسجيل الدخول</span>
                        <span class="spinner"></span>
                    </button>
                </form>

                <!-- Role quick-access indicators -->
                <div class="divider">
                    <span>الأدوار المتاحة</span>
                </div>

                <div class="quick-roles">
                    <div class="role-chip">
                        <i class="fas fa-user-md"></i>
                        <span>طبيب</span>
                    </div>
                    <div class="role-chip">
                        <i class="fas fa-flask"></i>
                        <span>مختبر</span>
                    </div>
                    <div class="role-chip">
                        <i class="fas fa-cash-register"></i>
                        <span>كاشير</span>
                    </div>
                </div>

                <div class="login-footer">
                    <p>&copy; <?php echo date('Y'); ?> — تصميم وتطوير <a href="https://elsamani.rf.gd/?i=1" target="_blank">Mohamed Omer Elsamani</a></p>
                </div>
            </div>

        </div>
    </div>

    <!-- =========================================
         SCRIPTS
         ========================================= -->
    <script>
    (function() {
        'use strict';

        // ── Live Clock ──
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('liveClock').textContent = h + ':' + m + ':' + s;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // ── Floating Particles ──
        const container = document.getElementById('particles');
        const colors = ['rgba(6,182,212,0.4)', 'rgba(99,102,241,0.35)', 'rgba(168,85,247,0.3)', 'rgba(16,185,129,0.3)'];
        for (let i = 0; i < 35; i++) {
            const el = document.createElement('div');
            el.className = 'particle';
            const size = Math.random() * 4 + 2;
            el.style.cssText =
                'width:' + size + 'px;height:' + size + 'px;' +
                'left:' + (Math.random() * 100) + '%;' +
                'background:' + colors[Math.floor(Math.random() * colors.length)] + ';' +
                'animation-duration:' + (Math.random() * 15 + 10) + 's;' +
                'animation-delay:' + (Math.random() * 10) + 's;';
            container.appendChild(el);
        }

        // ── Password Toggle ──
        const pwdInput = document.getElementById('password');
        const pwdToggle = document.getElementById('pwdToggle');
        pwdToggle.addEventListener('click', function() {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            this.querySelector('i').className = isPassword ? 'far fa-eye-slash' : 'far fa-eye';
        });

        // ── Submit Loading State ──
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnLogin');
            btn.classList.add('loading');
            btn.disabled = true;
        });

        // ── Input Focus Elevation ──
        document.querySelectorAll('.input-wrap input').forEach(function(input) {
            input.addEventListener('focus', function() {
                this.closest('.input-group').style.transform = 'translateY(-2px)';
                this.closest('.input-group').style.transition = 'transform 0.25s ease';
            });
            input.addEventListener('blur', function() {
                this.closest('.input-group').style.transform = 'translateY(0)';
            });
        });

        // ── Feature Cards Parallax on Mouse (desktop only) ──
        if (window.matchMedia('(min-width: 769px)').matches) {
            const showcase = document.querySelector('.showcase-panel');
            if (showcase) {
                showcase.addEventListener('mousemove', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = ((e.clientX - rect.left) / rect.width - 0.5) * 8;
                    const y = ((e.clientY - rect.top) / rect.height - 0.5) * 8;
                    this.querySelectorAll('.feature-card').forEach(function(card, i) {
                        const factor = (i + 1) * 0.4;
                        card.style.transform = 'translate(' + (x * factor) + 'px, ' + (y * factor) + 'px)';
                    });
                });
                showcase.addEventListener('mouseleave', function() {
                    this.querySelectorAll('.feature-card').forEach(function(card) {
                        card.style.transform = 'translate(0, 0)';
                        card.style.transition = 'transform 0.5s ease';
                    });
                });
            }
        }

        // ── Redirect after successful login ──
        <?php if (!empty($redirect)): ?>
        window.location.href = '<?php echo $redirect; ?>';
        <?php endif; ?>

    })();
    </script>

</body>
</html>