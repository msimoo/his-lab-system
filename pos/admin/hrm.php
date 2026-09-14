<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// role-based access for HR pages
$allowed_roles = ['admin', 'hr_manager', 'superadmin'];
$admin_role = isset($_SESSION['admin_role']) ? strtolower($_SESSION['admin_role']) : 'guest';
if (!in_array($admin_role, $allowed_roles)) {
    header('Location: dashboard.php?error=' . urlencode('Access denied'));
    exit;
}


// Handle Salary Update via AJAX or Quick Post
if (isset($_POST['update_salary_quick'])) {
    $sid = $_POST['staff_id'];
    $salary = $_POST['staff_salary'];
    $query = "UPDATE rpos_staff SET staff_salary = ? WHERE staff_id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('si', $salary, $sid);
    $stmt->execute();
    if ($stmt) {
        $success = __('salary_updated');
    } else {
        $err = __('error_updating_salary');
    }
}

// Handle Salary Structure Save
if (isset($_POST['save_salary_structure'])) {
    $staff_id = intval($_POST['staff_id']);
    $basic_salary = floatval($_POST['basic_salary']);
    $housing_allow = floatval($_POST['housing_allow']);
    $transport_allow = floatval($_POST['transport_allow']);
    $other_allow = floatval($_POST['other_allow']);
    $social_security_pct = floatval($_POST['social_security_pct']);
    $tax_pct = floatval($_POST['tax_pct']);

    // Validate required fields
    if ($basic_salary <= 0) {
        $err = __('basic_salary_required');
    } else {
        $check = $mysqli->query("SELECT struct_id FROM rpos_salary_structures WHERE staff_id = '$staff_id'");
        if ($check && $check->num_rows > 0) {
            $stmt = $mysqli->prepare("UPDATE rpos_salary_structures SET basic_salary=?, housing_allow=?, transport_allow=?, other_allow=?, social_security_pct=?, tax_pct=? WHERE staff_id=?");
            $stmt->bind_param('ddddddi', $basic_salary, $housing_allow, $transport_allow, $other_allow, $social_security_pct, $tax_pct, $staff_id);
        } else {
            $struct_id = bin2hex(random_bytes(10));
            $stmt = $mysqli->prepare("INSERT INTO rpos_salary_structures (struct_id, staff_id, basic_salary, housing_allow, transport_allow, other_allow, social_security_pct, tax_pct) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sidddddd', $struct_id, $staff_id, $basic_salary, $housing_allow, $transport_allow, $other_allow, $social_security_pct, $tax_pct);
        }

        if ($stmt->execute()) {
            $success = __('salary_structure_saved');
        } else {
            $err = __('salary_structure_failed');
        }
    }
}

// Delete Staff
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $adn = "DELETE FROM rpos_staff WHERE staff_id = ?";
    $stmt = $mysqli->prepare($adn);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    if ($stmt) {
        $success = __('staff_deleted_successfully');
    } else {
        $err = __('try_again_later');
    }
}

// Departments CRUD
if (isset($_POST['add_department'])) {
    $name = trim($_POST['department_name']);
    if ($name != '') {
        $stmt = $mysqli->prepare("INSERT INTO rpos_departments (department_name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $success = __('department_added');
    }
}
if (isset($_GET['delete_department'])) {
    $id = intval($_GET['delete_department']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_departments WHERE department_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $success = __('department_deleted');
}

// Roles CRUD
if (isset($_POST['add_role'])) {
    $name = trim($_POST['role_name']);
    if ($name != '') {
        $stmt = $mysqli->prepare("INSERT INTO rpos_roles (role_name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $success = __('role_added');
    }
}
if (isset($_GET['delete_role'])) {
    $id = intval($_GET['delete_role']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_roles WHERE role_id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $success = __('role_deleted');
}

// Attendance
if (isset($_POST['add_attendance'])) {
    $staff_id = intval($_POST['staff_id']);
    $att_date = $_POST['att_date'];
    $checkin = $_POST['checkin_time'];
    $checkout = $_POST['checkout_time'];
    $status = $_POST['status'];
    $notes = $_POST['notes'];
    $stmt = $mysqli->prepare("INSERT INTO rpos_attendance (staff_id, att_date, checkin_time, checkout_time, status, notes) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('isssss', $staff_id, $att_date, $checkin, $checkout, $status, $notes);
    $stmt->execute();
    $success = __('attendance_recorded');
}

// Leaves
if (isset($_POST['add_leave'])) {
    $staff_id = intval($_POST['staff_id']);
    $type = $_POST['leave_type'];
    $start = $_POST['start_date'];
    $end = $_POST['end_date'];
    $days = floatval($_POST['days']);
    $reason = $_POST['reason'];
    $stmt = $mysqli->prepare("INSERT INTO rpos_leaves (staff_id, leave_type, start_date, end_date, days, reason) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('isssds', $staff_id, $type, $start, $end, $days, $reason);
    $stmt->execute();
    $success = __('leave_requested');
}
if (isset($_GET['change_leave_status'])) {
    $id = intval($_GET['change_leave_status']);
    $status = $_GET['status'] === 'Approved' ? 'Approved' : 'Rejected';
    $stmt = $mysqli->prepare("UPDATE rpos_leaves SET status=? WHERE leave_id=?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $success = __('leave_status_updated');
}

// Performance
if (isset($_POST['add_performance'])) {
    $staff_id = intval($_POST['staff_id']);
    $month = $_POST['review_month'];
    $rating = intval($_POST['rating']);
    $targets_input = trim($_POST['targets']);
    $comments = $_POST['comments'];

    // Ensure JSON column gets valid value or NULL
    if ($targets_input === '') {
        $targets_json = null;
    } else {
        // If already valid JSON, keep it; else store as JSON string
        $decoded = json_decode($targets_input);
        $targets_json = ($decoded !== null || $targets_input === 'null') ? $targets_input : json_encode($targets_input);
    }

    if ($targets_json === null) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_performance (staff_id, review_month, rating, comments) VALUES (?,?,?,?)");
        $stmt->bind_param('isis', $staff_id, $month, $rating, $comments);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO rpos_performance (staff_id, review_month, rating, targets, comments) VALUES (?,?,?,?,?)");
        $stmt->bind_param('isiss', $staff_id, $month, $rating, $targets_json, $comments);
    }

    $stmt->execute();
    $success = __('performance_recorded');
}

// Discipline
if (isset($_POST['add_discipline'])) {
    $staff_id = intval($_POST['staff_id']);
    $incident_date = $_POST['incident_date'];
    $incident_description = $_POST['incident_description'];
    $action_taken = $_POST['action_taken'];
    $stmt = $mysqli->prepare("INSERT INTO rpos_discipline (staff_id, incident_date, incident_description, action_taken) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $staff_id, $incident_date, $incident_description, $action_taken);
    $stmt->execute();
    $success = __('discipline_recorded');
}

$section = isset($_GET['section']) ? $_GET['section'] : 'staff';

require_once('partials/_head.php');
?>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                            <h6 class="h2 text-white d-inline-block mb-0"><?php echo __('human_resource_management'); ?></h6>
                        </div>
                        <div class="col-lg-6 col-5 text-right">
                            <a href="add_staff.php" class="btn btn-sm btn-neutral"><i class="fas fa-plus"></i> <?php echo __('add_new_staff'); ?></a>
                            <a href="payroll.php" class="btn btn-sm btn-success"><i class="fas fa-money-bill-wave"></i> <?php echo __('go_to_payroll'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            <div class="row mb-3">
                <div class="col">
                    <ul class="nav nav-pills">
                        <?php
                        $sections = [
                            'staff' => __('staff'),
                            'departments' => __('department'),
                            'roles' => __('ROLE'),
                            'attendance' => __('attendance'),
                            'leaves' => __('leaves'),
                            'performance' => __('performance'),
                            'discipline' => __('discipline'),
                            'service' => 'سجل الخدمة',
                            'reports' => __('reports')
                        ];
                        foreach ($sections as $key => $label) {
                            $active = $section === $key ? 'active' : '';
                            echo "<li class='nav-item'><a class='nav-link $active' href='hrm.php?section=$key'>$label</a></li>";
                        }
                        ?>
                    </ul>
                </div>
            </div>

            <?php if($section === 'staff'): ?>

            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <h3 class="mb-0"><?php echo __('staff_directory_salary_info'); ?></h3>
                        </div>
                        <div class="card-body">
                            <?php if (isset($success)): ?>
                                <div class="alert alert-success"><?php echo $success; ?></div>
                            <?php endif; ?>
                            <?php if (isset($err)): ?>
                                <div class="alert alert-danger"><?php echo $err; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table align-items-center table-flush" id="hrmTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th><?php echo __('id_number'); ?></th>
                                        <th><?php echo __('name'); ?></th>
                                        <th><?php echo __('email'); ?></th>
                                        <th><?php echo __('department'); ?></th>
                                        <th><?php echo __('ROLE'); ?></th>
                                        <th><?php echo __('status'); ?></th>
                                        <th><?php echo __('salary_structure'); ?></th>
                                        <th><?php echo __('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $salaryStructModals = '';
                                    $ret = "SELECT s.*, d.department_name, r.role_name,
                                            st.basic_salary, st.housing_allow, st.transport_allow, st.other_allow,
                                            st.social_security_pct, st.tax_pct
                                            FROM rpos_staff s
                                            LEFT JOIN rpos_departments d ON s.staff_department_id = d.department_id
                                            LEFT JOIN rpos_roles r ON s.staff_role_id = r.role_id
                                            LEFT JOIN rpos_salary_structures st ON s.staff_id = st.staff_id
                                            ORDER BY s.staff_name ASC";
                                    $stmt = $mysqli->prepare($ret);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    while ($staff = $res->fetch_object()) {
                                        $basic = floatval($staff->basic_salary ?: $staff->staff_salary);
                                        $allowances = floatval($staff->housing_allow) + floatval($staff->transport_allow) + floatval($staff->other_allow);
                                        $gross = $basic + $allowances;
                                        $ss_deduction = $basic * (floatval($staff->social_security_pct) / 100);
                                        $tax_deduction = $gross * (floatval($staff->tax_pct) / 100);
                                        $total_deductions = $ss_deduction + $tax_deduction;
                                        $net_pay = $gross - $total_deductions;
                                    ?>
                                        <tr>
                                            <td><mark><?php echo $staff->staff_number; ?></mark></td>
                                            <td><strong><?php echo $staff->staff_name; ?></strong></td>
                                            <td><?php echo $staff->staff_email; ?></td>
                                            <td><?php echo $staff->department_name ?: '-'; ?></td>
                                            <td><?php echo $staff->role_name ?: '-'; ?></td>
                                            <td><?php echo $staff->staff_status ?? 'Active'; ?></td>
                                            <td>
                                                <?php if ($basic > 0): ?>
                                                    <div><small><?php echo __('base_salary'); ?>: <?php echo number_format($basic, 2); ?></small></div>
                                                    <div><small><?php echo __('allowances'); ?>: +<?php echo number_format($allowances, 2); ?></small></div>
                                                    <div><small><?php echo __('deductions'); ?>: -<?php echo number_format($total_deductions, 2); ?></small></div>
                                                    <div><strong><?php echo __('net_pay'); ?>: <?php echo number_format($net_pay, 2); ?></strong></div>
                                                <?php else: ?>
                                                    <span class="text-muted"><?php echo __('no_salary_structure'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#salaryStruct_<?php echo $staff->staff_id; ?>">
                                                    <i class="fas fa-file-contract"></i> <?php echo __('salary_structure'); ?>
                                                </button>
                                                <div class="btn-group ml-2">
                                                    <a href="update_staff.php?update=<?php echo $staff->staff_id; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                                                    <a href="hrm.php?delete=<?php echo $staff->staff_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo __('confirm_delete_staff'); ?>');"><i class="fas fa-trash"></i></a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php
                                        ob_start();
                                    ?>
                                        <div class="modal fade" id="salaryStruct_<?php echo $staff->staff_id; ?>" tabindex="-1" role="dialog">
                                            <div class="modal-dialog modal-lg" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title"><?php echo __('salary_structure'); ?> - <?php echo $staff->staff_name; ?></h5>
                                                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                                    </div>
                                                    <form method="POST" id="salaryForm_<?php echo $staff->staff_id; ?>" class="salary-structure-form">
                                                        <div class="modal-body row">
                                                            <div id="modalAlert_<?php echo $staff->staff_id; ?>" class="col-12" style="display: none;"></div>
                                                            <input type="hidden" name="save_salary_structure" value="1">
                                                            <input type="hidden" name="staff_id" value="<?php echo $staff->staff_id; ?>">
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('basic_salary'); ?> <span class="text-danger">*</span></label>
                                                                <input type="number" step="0.01" min="0" name="basic_salary" class="form-control" value="<?php echo number_format($basic, 2, '.', ''); ?>" required>
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('housing_allow'); ?></label>
                                                                <input type="number" step="0.01" min="0" name="housing_allow" class="form-control" value="<?php echo number_format(floatval($staff->housing_allow), 2, '.', ''); ?>">
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('transport_allow'); ?></label>
                                                                <input type="number" step="0.01" min="0" name="transport_allow" class="form-control" value="<?php echo number_format(floatval($staff->transport_allow), 2, '.', ''); ?>">
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('other_allow'); ?></label>
                                                                <input type="number" step="0.01" min="0" name="other_allow" class="form-control" value="<?php echo number_format(floatval($staff->other_allow), 2, '.', ''); ?>">
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('social_security_pct'); ?> (%)</label>
                                                                <input type="number" step="0.01" min="0" max="100" name="social_security_pct" class="form-control" value="<?php echo number_format(floatval($staff->social_security_pct), 2, '.', ''); ?>">
                                                            </div>
                                                            <div class="col-md-6 mb-3">
                                                                <label><?php echo __('tax_pct'); ?> (%)</label>
                                                                <input type="number" step="0.01" min="0" max="100" name="tax_pct" class="form-control" value="<?php echo number_format(floatval($staff->tax_pct), 2, '.', ''); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('cancel'); ?></button>
                                                            <button type="submit" name="save_salary_structure" class="btn btn-success">
                                                                <i class="fas fa-save"></i> <?php echo __('save_changes'); ?>
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php
                                        $salaryStructModals .= ob_get_clean();
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php echo $salaryStructModals; ?>

            <?php elseif($section === 'departments'): ?>
            <div class="row">
                <div class="col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('add_department'); ?></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label><?php echo __('department'); ?></label>
                                    <input type="text" name="department_name" class="form-control" required>
                                </div>
                                <button type="submit" name="add_department" class="btn btn-primary"><?php echo __('add'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('department'); ?></div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th><?php echo __('name'); ?></th><th><?php echo __('status'); ?></th><th><?php echo __('actions'); ?></th></tr></thead>
                                <tbody>
                                    <?php $r = $mysqli->query("SELECT * FROM rpos_departments ORDER BY department_name ASC"); while($dep=$r->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dep['department_name']); ?></td>
                                        <td><?php echo $dep['department_status']; ?></td>
                                        <td><a href="hrm.php?section=departments&delete_department=<?php echo $dep['department_id']; ?>" class="btn btn-sm btn-danger"><?php echo __('delete'); ?></a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'roles'): ?>
            <div class="row">
                <div class="col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('add_role'); ?></div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="form-group">
                                    <label><?php echo __('role'); ?></label>
                                    <input type="text" name="role_name" class="form-control" required>
                                </div>
                                <button type="submit" name="add_role" class="btn btn-primary"><?php echo __('add'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('ROLE'); ?></div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th><?php echo __('name'); ?></th><th><?php echo __('status'); ?></th><th><?php echo __('actions'); ?></th></tr></thead>
                                <tbody>
                                    <?php $r = $mysqli->query("SELECT * FROM rpos_roles ORDER BY role_name ASC"); while($role=$r->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                                        <td><?php echo $role['role_status']; ?></td>
                                        <td><a href="hrm.php?section=roles&delete_role=<?php echo $role['role_id']; ?>" class="btn btn-sm btn-danger"><?php echo __('delete'); ?></a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'attendance'): ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('record_attendance'); ?></div>
                        <div class="card-body">
                            <form method="POST" class="form-inline">
                                <div class="form-group mr-2">
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('select_staff'); ?></option>
                                        <?php
                                        $staffQ = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC");
                                        while ($st = $staffQ->fetch_assoc()):
                                        ?>
                                        <option value="<?php echo $st['staff_id']; ?>"><?php echo htmlspecialchars($st['staff_name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group mr-2"><input type="date" name="att_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                                <div class="form-group mr-2"><input type="time" name="checkin_time" class="form-control" required></div>
                                <div class="form-group mr-2"><input type="time" name="checkout_time" class="form-control"></div>
                                <div class="form-group mr-2">
                                    <select name="status" class="form-control">
                                        <option value="Present"><?php echo __('present'); ?></option>
                                        <option value="Absent"><?php echo __('absent'); ?></option>
                                        <option value="Late"><?php echo __('late'); ?></option>
                                        <option value="On Leave"><?php echo __('on_leave'); ?></option>
                                    </select>
                                </div>
                                <button type="submit" name="add_attendance" class="btn btn-success"><?php echo __('save'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('attendance_history'); ?></div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th><?php echo __('staff'); ?></th><th><?php echo __('date'); ?></th><th><?php echo __('checkin'); ?></th><th><?php echo __('checkout'); ?></th><th><?php echo __('status'); ?></th></tr></thead>
                                <tbody>
                                    <?php
                                    $att = $mysqli->query("SELECT a.*, s.staff_name FROM rpos_attendance a JOIN rpos_staff s ON a.staff_id=s.staff_id ORDER BY a.att_date DESC LIMIT 100");
                                    while ($row = $att->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                        <td><?php echo $row['att_date']; ?></td>
                                        <td><?php echo $row['checkin_time']; ?></td>
                                        <td><?php echo $row['checkout_time']; ?></td>
                                        <td><?php echo $row['status']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'leaves'): ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('request_leave'); ?></div>
                        <div class="card-body">
                            <form method="POST" class="row">
                                <div class="form-group col-md-3">
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('select_staff'); ?></option>
                                        <?php $staffQ = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC"); while($st=$staffQ->fetch_assoc()): ?>
                                        <option value="<?php echo $st['staff_id']; ?>"><?php echo htmlspecialchars($st['staff_name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-2"><select name="leave_type" class="form-control"><option value="Annual"><?php echo __('annual'); ?></option><option value="Sick"><?php echo __('sick'); ?></option><option value="Personal"><?php echo __('personal'); ?></option><option value="Other"><?php echo __('other'); ?></option></select></div>
                                <div class="form-group col-md-2"><input type="date" name="start_date" class="form-control" required></div>
                                <div class="form-group col-md-2"><input type="date" name="end_date" class="form-control" required></div>
                                <div class="form-group col-md-1"><input type="number" step="0.5" name="days" class="form-control" required></div>
                                <div class="form-group col-md-2"><input type="text" name="reason" class="form-control" placeholder="Reason"></div>
                                <div class="form-group col-md-12 mt-2"><button class="btn btn-primary" name="add_leave"><?php echo __('submit'); ?></button></div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('leave_requests'); ?></div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th><?php echo __('staff'); ?></th><th><?php echo __('type'); ?></th><th><?php echo __('period'); ?></th><th><?php echo __('days'); ?></th><th><?php echo __('status'); ?></th><th><?php echo __('actions'); ?></th></tr></thead>
                                <tbody>
                                    <?php $leaves = $mysqli->query("SELECT l.*, s.staff_name FROM rpos_leaves l JOIN rpos_staff s ON l.staff_id=s.staff_id ORDER BY l.start_date DESC"); while($lv=$leaves->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($lv['staff_name']); ?></td>
                                        <td><?php echo $lv['leave_type']; ?></td>
                                        <td><?php echo $lv['start_date']; ?> - <?php echo $lv['end_date']; ?></td>
                                        <td><?php echo $lv['days']; ?></td>
                                        <td><?php echo $lv['status']; ?></td>
                                        <td>
                                            <?php if($lv['status']==='Pending'): ?>
                                                <a href="hrm.php?section=leaves&change_leave_status=<?php echo $lv['leave_id']; ?>&status=Approved" class="btn btn-sm btn-success"><?php echo __('approve'); ?></a>
                                                <a href="hrm.php?section=leaves&change_leave_status=<?php echo $lv['leave_id']; ?>&status=Rejected" class="btn btn-sm btn-danger"><?php echo __('reject'); ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'performance'): ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('performance_reviews'); ?></div>
                        <div class="card-body">
                            <form method="POST" class="row">
                                <div class="form-group col-md-3">
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('select_staff'); ?></option>
                                        <?php $staffQ = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC"); while($st=$staffQ->fetch_assoc()): ?>
                                        <option value="<?php echo $st['staff_id']; ?>"><?php echo htmlspecialchars($st['staff_name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-2"><input type="month" name="review_month" class="form-control" required></div>
                                <div class="form-group col-md-2"><input type="number" min="1" max="5" name="rating" class="form-control" required placeholder="Rating"></div>
                                <div class="form-group col-md-3"><input type="text" name="targets" class="form-control" placeholder="Targets"></div>
                                <div class="form-group col-md-2"><input type="text" name="comments" class="form-control" placeholder="Comments"></div>
                                <div class="form-group col-md-12 mt-2"><button class="btn btn-primary" name="add_performance"><?php echo __('save'); ?></button></div>
                            </form>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table">
                                <thead><tr><th><?php echo __('staff'); ?></th><th><?php echo __('review_month'); ?></th><th><?php echo __('rating'); ?></th><th><?php echo __('targets'); ?></th><th><?php echo __('comments'); ?></th></tr></thead>
                                <tbody>
                                    <?php $perfs = $mysqli->query("SELECT p.*, s.staff_name FROM rpos_performance p JOIN rpos_staff s ON p.staff_id=s.staff_id ORDER BY p.review_month DESC"); while($p=$perfs->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($p['staff_name']); ?></td>
                                        <td><?php echo $p['review_month']; ?></td>
                                        <td><?php echo $p['rating']; ?></td>
                                        <td><?php echo $p['targets'] ?? '' ; ?></td>
                                        <td><?php echo htmlspecialchars($p['comments']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'discipline'): ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('discipline_records'); ?></div>
                        <div class="card-body">
                            <form method="POST" class="row">
                                <div class="form-group col-md-3">
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('select_staff'); ?></option>
                                        <?php $staffQ = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC"); while($st=$staffQ->fetch_assoc()): ?>
                                        <option value="<?php echo $st['staff_id']; ?>"><?php echo htmlspecialchars($st['staff_name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-2"><input type="date" name="incident_date" class="form-control" required></div>
                                <div class="form-group col-md-3"><input type="text" name="incident_description" class="form-control" placeholder="Incident" required></div>
                                <div class="form-group col-md-3"><input type="text" name="action_taken" class="form-control" placeholder="Action Taken"></div>
                                <div class="form-group col-md-1"><button class="btn btn-primary" name="add_discipline"><?php echo __('add'); ?></button></div>
                            </form>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table">
                                <thead><tr><th><?php echo __('staff'); ?></th><th><?php echo __('date'); ?></th><th><?php echo __('incident'); ?></th><th><?php echo __('action_taken'); ?></th><th><?php echo __('status'); ?></th></tr></thead>
                                <tbody>
                                    <?php $dis = $mysqli->query("SELECT d.*, s.staff_name FROM rpos_discipline d JOIN rpos_staff s ON d.staff_id=s.staff_id ORDER BY d.incident_date DESC"); while($d=$dis->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($d['staff_name']); ?></td>
                                        <td><?php echo $d['incident_date']; ?></td>
                                        <td><?php echo htmlspecialchars($d['incident_description']); ?></td>
                                        <td><?php echo htmlspecialchars($d['action_taken']); ?></td>
                                        <td><?php echo $d['status']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif($section === 'service'): ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><h3 class="mb-0">سجل الخدمة</h3></div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-info text-white shadow-sm">
                                        <div class="card-body">
                                            <h5 class="card-title">حضور اليوم</h5>
                                            <p class="display-4"><?php echo $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_attendance WHERE att_date = '" . date('Y-m-d') . "'")->fetch_assoc()['cnt']; ?></p>
                                            <p class="small">سجلات حضور اليوم</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-warning text-dark shadow-sm">
                                        <div class="card-body">
                                            <h5 class="card-title">إجازات معلقة</h5>
                                            <p class="display-4"><?php echo $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_leaves WHERE status = 'Pending'")->fetch_assoc()['cnt']; ?></p>
                                            <p class="small">طلبات إجازة في الانتظار</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-danger text-white shadow-sm">
                                        <div class="card-body">
                                            <h5 class="card-title">سجل الجزاءات</h5>
                                            <p class="display-4"><?php echo $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_discipline")->fetch_assoc()['cnt']; ?></p>
                                            <p class="small">أحداث ضبط السلوك</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-success text-white shadow-sm">
                                        <div class="card-body">
                                            <h5 class="card-title">مراجعات الأداء</h5>
                                            <p class="display-4"><?php echo $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_performance")->fetch_assoc()['cnt']; ?></p>
                                            <p class="small">ملفات الأداء المسجلة</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header">سجل أحداث الخدمة</div>
                        <div class="table-responsive p-4">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>الموظف</th>
                                        <th>التاريخ</th>
                                        <th>نوع السجل</th>
                                        <th>التفاصيل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $eventsQuery = "SELECT staff_name, att_date AS event_date, 'Attendance' AS event_type, CONCAT('Status: ', status, ' | Checkin: ', checkin_time, ' | Checkout: ', checkout_time) AS details FROM rpos_attendance a JOIN rpos_staff s ON a.staff_id=s.staff_id "
                                        . "UNION ALL "
                                        . "SELECT staff_name, start_date AS event_date, 'Leave' AS event_type, CONCAT('Type: ', leave_type, ' | Days: ', days, ' | Status: ', status) AS details FROM rpos_leaves l JOIN rpos_staff s ON l.staff_id=s.staff_id "
                                        . "UNION ALL "
                                        . "SELECT staff_name, incident_date AS event_date, 'Discipline' AS event_type, CONCAT('Issue: ', incident_description, ' | Action: ', action_taken, ' | Status: ', status) AS details FROM rpos_discipline d JOIN rpos_staff s ON d.staff_id=s.staff_id "
                                        . "ORDER BY event_date DESC LIMIT 150";
                                    $events = $mysqli->query($eventsQuery);
                                    while ($event = $events->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($event['staff_name']); ?></td>
                                        <td><?php echo $event['event_date']; ?></td>
                                        <td><?php echo $event['event_type']; ?></td>
                                        <td><?php echo htmlspecialchars($event['details']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="row">
                <div class="col-lg-12">
                    <div class="card shadow mb-4">
                        <div class="card-header"><?php echo __('reports'); ?></div>
                        <div class="card-body">
                            <?php
                            $totalStaff = $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_staff")->fetch_assoc()['cnt'];
                            $activeStaff = $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_staff WHERE staff_status='Active'")->fetch_assoc()['cnt'];
                            $deptCount = $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_departments")->fetch_assoc()['cnt'];
                            $roleCount = $mysqli->query("SELECT COUNT(*) AS cnt FROM rpos_roles")->fetch_assoc()['cnt'];
                            ?>
                            <div class="row">
                                <div class="col-md-3"><div class="alert alert-info"><?php echo __('total_staff'); ?>: <?php echo $totalStaff; ?></div></div>
                                <div class="col-md-3"><div class="alert alert-success"><?php echo __('active_staff'); ?>: <?php echo $activeStaff; ?></div></div>
                                <div class="col-md-3"><div class="alert alert-warning"><?php echo __('department'); ?>: <?php echo $deptCount; ?></div></div>
                                <div class="col-md-3"><div class="alert alert-primary"><?php echo __('ROLE'); ?>: <?php echo $roleCount; ?></div></div>
                            </div>
                            <p><?php echo __('staff_by_status'); ?>: <strong><?php echo $activeStaff; ?></strong> <?php echo __('active'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php require_once('partials/_footer.php'); ?>

        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('#hrmTable').DataTable({
                "pageLength": 10,
                scrollX: true,
                "language": {
                    "paginate": {
                        "previous": "<i class='fas fa-angle-left'></i>",
                        "next": "<i class='fas fa-angle-right'></i>"
                    }
                }
            });

            // Handle salary structure form submission
            $('.salary-structure-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var modalId = form.closest('.modal').attr('id');
                var alertDiv = $('#modalAlert_' + modalId.split('_')[1]);
                var submitBtn = form.find('button[type="submit"]');

                // Clear previous alerts
                alertDiv.hide().removeClass('alert-success alert-danger');

                // Disable submit button and show loading
                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> <?php echo __('saving'); ?>...');

                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: form.serialize(),
                    success: function(response) {
                        // Check if the response contains success message
                        if (response.includes('<?php echo __('salary_structure_saved'); ?>') ||
                            response.includes('alert-success')) {
                            // Show success message
                            alertDiv.removeClass('alert-danger').addClass('alert-success')
                                   .html('<i class="fas fa-check-circle"></i> <?php echo __('salary_structure_saved'); ?>')
                                   .show();

                            // Close modal after 2 seconds
                            setTimeout(function() {
                                $('#' + modalId).modal('hide');
                                // Refresh the page to show updated data
                                window.location.reload();
                            }, 2000);
                        } else if (response.includes('<?php echo __('salary_structure_failed'); ?>') ||
                                 response.includes('alert-danger')) {
                            // Show error message
                            alertDiv.removeClass('alert-success').addClass('alert-danger')
                                   .html('<i class="fas fa-exclamation-triangle"></i> <?php echo __('salary_structure_failed'); ?>')
                                   .show();
                        } else {
                            // Fallback: close modal and reload page
                            $('#' + modalId).modal('hide');
                            window.location.reload();
                        }
                    },
                    error: function() {
                        alertDiv.removeClass('alert-success').addClass('alert-danger')
                               .html('<i class="fas fa-exclamation-triangle"></i> <?php echo __('error_occurred'); ?>')
                               .show();
                    },
                    complete: function() {
                        // Re-enable submit button
                        submitBtn.prop('disabled', false).html('<i class="fas fa-save"></i> <?php echo __('save_changes'); ?>');
                    }
                });
            });

            // Clear modal alerts when modal is opened
            $('.modal').on('show.bs.modal', function() {
                var modalId = $(this).attr('id');
                if (modalId && modalId.startsWith('salaryStruct_')) {
                    $('#modalAlert_' + modalId.split('_')[1]).hide();
                }
            });
        });
    </script>
</body>
</html>
