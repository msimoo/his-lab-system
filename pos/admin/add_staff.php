<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');

check_login();

// load departments and roles
$departments = [];
$resp = $mysqli->query("SELECT dept_id, dept_name, dept_name_en FROM rpos_departments WHERE is_active=1 ORDER BY sort_order, dept_name ASC");
if ($resp) {
    while ($row = $resp->fetch_assoc()) {
        $departments[] = $row;
    }
}
$roles = [];
$resp = $mysqli->query("SELECT role_id, role_name FROM rpos_roles WHERE role_status='Active' ORDER BY role_name ASC");
if ($resp) {
    while ($row = $resp->fetch_assoc()) {
        $roles[] = $row;
    }
}

//Add Staff
if (isset($_POST['addStaff'])) {
  //Prevent Posting Blank Values
  if (empty($_POST["staff_number"]) || empty($_POST["staff_name"]) || empty($_POST['staff_email']) || empty($_POST['staff_password'])) {
    $err = "Blank Values Not Accepted";
  } else {
    $staff_number = $_POST['staff_number'];
    $staff_name = $_POST['staff_name'];
    $staff_email = $_POST['staff_email'];
    $staff_password = sha1(md5($_POST['staff_password']));
    $staff_status = $_POST['staff_status'] ?? 'Active';
    $staff_department_id = !empty($_POST['staff_department_id']) ? intval($_POST['staff_department_id']) : null;
    $staff_role_id = !empty($_POST['staff_role_id']) ? intval($_POST['staff_role_id']) : null;
    $staff_join_date = !empty($_POST['staff_join_date']) ? $_POST['staff_join_date'] : date('Y-m-d');

    //Insert Captured information to a database table
    $postQuery = "INSERT INTO rpos_staff (staff_number, staff_name, staff_email, staff_password, staff_status, dept_id, staff_role_id, staff_join_date) VALUES(?,?,?,?,?,?,?,?)";
    $postStmt = $mysqli->prepare($postQuery);
    //bind paramaters
    $rc = $postStmt->bind_param('sssssiis', $staff_number, $staff_name, $staff_email, $staff_password, $staff_status, $staff_department_id, $staff_role_id, $staff_join_date);
    $postStmt->execute();
    //declare a varible which will be passed to alert function
    if ($postStmt) {
      $success = "Staff Added" && header("refresh:1; url=hrm.php");
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
    ?>
    <!-- Header -->
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
              <h3>Please Fill All Fields</h3>
            </div>
            <div class="card-body">
              <form method="POST">
                <div class="form-row">
                  <div class="col-md-6">
                    <label>Staff Number</label>
                    <input type="text" name="staff_number" class="form-control" value="<?php echo $alpha; ?>-<?php echo $beta; ?>">
                  </div>
                  <div class="col-md-6">
                    <label>Staff Name</label>
                    <input type="text" name="staff_name" class="form-control" value="">
                  </div>
                </div>
                <hr>
                <div class="form-row">
                  <div class="col-md-6">
                    <label>Department</label>
                    <select name="staff_department_id" class="form-control">
                      <option value="">-- Select Department --</option>
                      <?php foreach($departments as $dep){ ?>
                        <option value="<?php echo $dep['dept_id']; ?>"><?php echo htmlspecialchars($dep['dept_name'] . (!empty($dep['dept_name_en']) ? ' / ' . $dep['dept_name_en'] : '')); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label>Role</label>
                    <select name="staff_role_id" class="form-control">
                      <option value="">-- Select Role --</option>
                      <?php foreach($roles as $role){ ?>
                        <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                </div>
                <div class="form-row mt-2">
                  <div class="col-md-4">
                    <label>Status</label>
                    <select name="staff_status" class="form-control">
                      <option value="Active">Active</option>
                      <option value="Inactive">Inactive</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label>Join Date</label>
                    <input type="date" name="staff_join_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                  </div>
                  <div class="col-md-4"></div>
                </div>

                <div class="form-row">
                  <div class="col-md-6">
                    <label>Staff Email</label>
                    <input type="email" name="staff_email" class="form-control" value="">
                  </div>
                  <div class="col-md-6">
                    <label>Staff Password</label>
                    <input type="password" name="staff_password" class="form-control" value="">
                  </div>
                </div>
                <br>
                <div class="form-row">
                  <div class="col-md-6">
                    <input type="submit" name="addStaff" value="Add Staff" class="btn btn-success" value="">
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
      ?>
    </div>
  </div>
  <!-- Argon Scripts -->
  <?php
  require_once('partials/_scripts.php');
  ?>
</body>

</html>
