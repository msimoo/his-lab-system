<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');
include('config/languages.php');
 
check_login();
check_access(['admin','hr_manager','accountant','superadmin']);

// Load available roles for role management
$roles = [];
$rolesRes = $mysqli->query("SELECT role_id, role_name FROM rpos_roles WHERE role_status='Active' ORDER BY role_name ASC");
if ($rolesRes) {
    while ($roleRow = $rolesRes->fetch_assoc()) {
        $roles[] = $roleRow;
    }
}

//Udpate Staff

if (isset($_POST['UpdateStaff'])) {
  //Prevent Posting Blank Values
  if (empty($_POST["staff_number"]) || empty($_POST["staff_name"]) || empty($_POST['staff_email'])) {
    $err = "Blank Values Not Accepted";
  } else {
    $staff_number = $_POST['staff_number'];
    $staff_name = $_POST['staff_name'];
    $staff_email = $_POST['staff_email'];
    $staff_password = !empty($_POST['staff_password']) ? sha1(md5($_POST['staff_password'])) : '';
    $staff_role_id = !empty($_POST['staff_role_id']) ? intval($_POST['staff_role_id']) : null;
    $staff_dept_id = !empty($_POST['staff_dept_id']) ? intval($_POST['staff_dept_id']) : null;
    $update = intval($_GET['update']);

    // if admin is not permitted to change role, keep existing role
    if (!in_array(strtolower($_SESSION['admin_role'] ?? ''), ['admin', 'superadmin'])) {
        $roleRes = $mysqli->query("SELECT staff_role_id FROM rpos_staff WHERE staff_id = '" . $mysqli->real_escape_string($update) . "' LIMIT 1");
        if ($roleRes && $row = $roleRes->fetch_assoc()) {
            $staff_role_id = intval($row['staff_role_id']);
        }
    }

    // Update Captured information in a database table
    $sql = "UPDATE rpos_staff SET staff_number = ?, staff_name = ?, staff_email = ?, dept_id = ?, staff_role_id = ?";
    $params = [$staff_number, $staff_name, $staff_email, $staff_dept_id, $staff_role_id];
    $types = 'sssii';
    if (!empty($staff_password)) {
        $sql .= ", staff_password = ?";
        $params[] = $staff_password;
        $types .= 's';
    }
    $sql .= " WHERE staff_id = ?";
    $params[] = $update;
    $types .= 'i';

    $postStmt = $mysqli->prepare($sql);
    $postStmt->bind_param($types, ...$params);
    $postStmt->execute();
    //declare a varible which will be passed to alert function
    if ($postStmt) {
      $success = "Staff Updated" && header("refresh:1; url=hrm.php");
    } else {
      $err = "Please Try Again Or Try Later";
    }
  }
}
require_once('partials/_head.php');
?>

<body>
  <!-- Sidenav -->
  <?php
  require_once('partials/_sidebar.php');
  ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php
    require_once('partials/_topnav.php');
    $update = $_GET['update'];
    $ret = "SELECT * FROM  rpos_staff WHERE staff_id = '$update' ";
    $stmt = $mysqli->prepare($ret);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($staff = $res->fetch_object()) {
    ?>
      <!-- Header -->
            <!-- Header -->
      <div dir="rtl" class="header pb-8 pt-5 pt-lg-8 d-flex align-items-center" style=" background-image: url(assets/img/theme/restro00.jpg); background-size: cover; background-position: center top;padding-bottom: 2rem !important;">
        <!-- Mask -->
        <span class="mask bg-gradient-default opacity-8"></span>
        <!-- Header container -->
        <div class="container-fluid d-flex align-items-center">
          <div class="row">
            <div  class="col-lg-7 col-md-10">
                <h1 class="display-2 text-white"><?php echo __('hello'); ?></h1>
                <p class="text-white mt-0 mb-5"><?php echo __('profile_description'); ?></p>
            </div>
          </div>
        </div>
      </div>
      <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
        <div class="container-fluid">
          <div class="header-body">
          </div>
        </div>
      </div>
      <!-- Page content -->
      <div class="container-fluid mt--8">
        <!-- Table -->
        <div class="row">
          <div class="col">
            <div class="card shadow">
              <div class="card-header border-0">
                <h3><?php echo __('please_fill_fields'); ?></h3>
              </div>

              <div class="card-body">
                <form method="POST">
                  <div class="form-row">
                    <div class="col-md-6">
                      <label><?php echo __('staff_number'); ?></label>
                      <input type="text" name="staff_number" class="form-control" value="<?php echo $staff->staff_number; ?>">
                    </div>
                    <div class="col-md-6">
                      <label><?php echo __('staff_name'); ?></label>
                      <input type="text" name="staff_name" class="form-control" value="<?php echo $staff->staff_name; ?>">
                    </div>
                  </div>

                  <div class="form-row">
                    <div class="col-md-6">
                      <label>Department</label>
                      <select name="staff_dept_id" class="form-control">
                        <option value="">-- Select department --</option>
                        <?php $deptRes = $mysqli->query("SELECT dept_id, dept_name, dept_name_en FROM rpos_departments WHERE is_active=1 ORDER BY sort_order, dept_name"); if ($deptRes) while ($dept = $deptRes->fetch_assoc()): ?>
                          <option value="<?php echo intval($dept['dept_id']); ?>" <?php echo (intval($staff->dept_id ?? 0) === intval($dept['dept_id'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['dept_name'] . ($dept['dept_name_en'] ? ' / ' . $dept['dept_name_en'] : '')); ?></option>
                        <?php endwhile; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label><?php echo __('staff_Email'); ?></label>
                      <input type="email" name="staff_email" class="form-control" value="<?php echo $staff->staff_email; ?>">
                    </div>
                    <div class="col-md-6">
                      <label><?php echo __('staff_password'); ?></label>
                      <input type="password" name="staff_password" class="form-control" value="">
                    </div>                  <?php if (in_array(strtolower($_SESSION['admin_role'] ?? ''), ['admin','superadmin'])): ?>
                  <div class="col-md-6">
                    <label><?php echo __('role'); ?></label>
                    <select name="staff_role_id" class="form-control">
                      <option value="">-- <?php echo __('select_role'); ?> --</option>
                      <?php foreach ($roles as $role): ?>
                        <option value="<?php echo intval($role['role_id']); ?>" <?php echo ($staff->staff_role_id == $role['role_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($role['role_name']); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php endif; ?>                  </div>
                  <br>
                  <div class="form-row">
                    <div class="col-md-6">
                      <input type="submit" name="UpdateStaff" value="<?php echo __('staff_update'); ?>" class="btn btn-success" value="">
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <!-- Footer -->
      <?php
      require_once('partials/_footer.php');
    }
      ?>
      </div>
  </div>
  <!-- Argon Scripts -->
  <?php
  require_once('partials/_scripts.php');
  ?>
</body>

</html>
