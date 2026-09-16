<?php
/**
 * Quick diagnostic & password reset helper.
 * DELETE THIS FILE after use.
 */
include('pos/admin/config/config.php');

$target_email = 'admin@admin.com';
$target_pass  = 'admin@2026';
$hashed       = sha1(md5($target_pass));

echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px;border-radius:12px;max-width:800px;margin:40px auto;'>\n";
echo "=== AL-WAHAT LOGIN DIAGNOSTIC ===\n\n";

echo "Target email:    $target_email\n";
echo "Target password: $target_pass\n";
echo "Expected hash:   $hashed\n\n";

// 1. Check if table exists
$tableCheck = $mysqli->query("SHOW TABLES LIKE 'rpos_admin'");
if ($tableCheck->num_rows === 0) {
    echo "❌ Table 'rpos_admin' does NOT exist!\n";
    echo "</pre>";
    exit;
}
echo "✅ Table 'rpos_admin' exists\n\n";

// 2. Show all admin users
echo "--- All Admin Users ---\n";
$result = $mysqli->query("SELECT admin_id, admin_name, admin_email, admin_password, admin_role FROM rpos_admin");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  ID:    " . $row['admin_id'] . "\n";
        echo "  Name:  " . $row['admin_name'] . "\n";
        echo "  Email: " . $row['admin_email'] . "\n";
        echo "  Hash:  " . $row['admin_password'] . "\n";
        echo "  Role:  " . $row['admin_role'] . "\n";
        $match = ($row['admin_password'] === $hashed) ? '✅ MATCH' : '❌ NO MATCH';
        echo "  Password check: $match\n";
        echo "  ----\n";
    }
} else {
    echo "  ⚠️  No admin users found in database!\n";
}

// 3. Check if email exists
echo "\n--- Email Lookup ---\n";
$stmt = $mysqli->prepare("SELECT admin_id, admin_password FROM rpos_admin WHERE admin_email = ?");
$stmt->bind_param('s', $target_email);
$stmt->execute();
$stmt->bind_result($aid, $apass);
if ($stmt->fetch()) {
    echo "  ✅ Email '$target_email' found (ID: $aid)\n";
    echo "  Stored hash:   $apass\n";
    echo "  Expected hash:  $hashed\n";
    if ($apass === $hashed) {
        echo "  ✅ Password MATCHES — login should work!\n";
    } else {
        echo "  ❌ Password MISMATCH — this is why login fails!\n";
        echo "\n  🔧 To fix, click the link below to reset:\n";
    }
} else {
    echo "  ❌ Email '$target_email' NOT found in rpos_admin\n";
    echo "  ℹ️  You may need to insert a new admin user.\n";
}
$stmt->close();

echo "\n</pre>";

// --- RESET FORM ---
if (isset($_GET['reset']) && $_GET['reset'] === 'yes') {
    // Check if user exists
    $check = $mysqli->prepare("SELECT admin_id FROM rpos_admin WHERE admin_email = ?");
    $check->bind_param('s', $target_email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        // Update password
        $upd = $mysqli->prepare("UPDATE rpos_admin SET admin_password = ? WHERE admin_email = ?");
        $upd->bind_param('ss', $hashed, $target_email);
        $upd->execute();
        echo "<p style='text-align:center;color:green;font-size:18px;font-weight:bold;'>✅ Password reset successfully! You can now login.</p>";
        $upd->close();
    } else {
        // Insert new admin
        $newId = sha1(md5($target_email . time()));
        $ins = $mysqli->prepare("INSERT INTO rpos_admin (admin_id, admin_name, admin_email, admin_password, admin_role) VALUES (?, 'Admin', ?, ?, 'admin')");
        $ins->bind_param('sss', $newId, $target_email, $hashed);
        $ins->execute();
        echo "<p style='text-align:center;color:green;font-size:18px;font-weight:bold;'>✅ Admin user created! You can now login.</p>";
        $ins->close();
    }
    $check->close();
    echo "<p style='text-align:center;'><a href='index.php' style='color:#06b6d4;font-size:16px;'>← Go to Login Page</a></p>";
} else {
    echo "<p style='text-align:center;'><a href='?reset=yes' style='background:#06b6d4;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold;'>🔧 Reset Password to admin@2026</a></p>";
    echo "<p style='text-align:center;margin-top:12px;'><a href='index.php' style='color:#888;font-size:14px;'>← Back to Login</a></p>";
}
?>
