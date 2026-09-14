<?php
//ob_start();
// fixed header warning and BOM
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/languages.php');

$err = '';
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
      session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin_id;
        $_SESSION['admin_role'] = $admin_role;
        header('Location: dashboard.php');
        exit;
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
            session_regenerate_id(true);
            $_SESSION['staff_id'] = $staff_id;
            $_SESSION['admin_id'] = $staff_id;
            $_SESSION['admin_role'] = strtolower($staff_role_name);

            header('Location: dashboard.php');
            exit;
        } else {
            $err = 'Incorrect credentials';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>POS Management System - Login</title>

    <!-- Fonts -->
    
    <!-- Styles -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            position: relative;
            animation: fadeInUp 0.8s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            color: #333;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .logo p {
            color: #666;
            font-size: 14px;
            font-weight: 300;
        }

        .form-group {
            position: relative;
            margin-bottom: 25px;
        }

        .form-group input {
            width: 100%;
            padding: 15px 20px 15px 50px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 18px;
        }

        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
            font-size: 14px;
        }

        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }

        .particle:nth-child(1) { width: 10px; height: 10px; top: 10%; left: 10%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 15px; height: 15px; top: 20%; left: 80%; animation-delay: 1s; }
        .particle:nth-child(3) { width: 8px; height: 8px; top: 70%; left: 20%; animation-delay: 2s; }
        .particle:nth-child(4) { width: 12px; height: 12px; top: 60%; left: 90%; animation-delay: 3s; }
        .particle:nth-child(5) { width: 6px; height: 6px; top: 30%; left: 50%; animation-delay: 4s; }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 20px;
                padding: 30px 20px;
            }
        }
    </style>
    <!-- Font Awesome for icons -->
   </head>

<body>

    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="login-container">
        <div class="logo">
            <h1>مركز الواحات الطبي</h1>
            <p>Management System</p>
        </div>

        <?php if (!empty($err)): ?>
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $err; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="Email Address" required>
            </div>
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="Password" required>
            </div>
            <button type="submit" name="login" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> Log In
            </button>
        </form>
 
    </div>

    <script>
        // Add some interactive effects
        document.querySelectorAll('.form-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>

</html>