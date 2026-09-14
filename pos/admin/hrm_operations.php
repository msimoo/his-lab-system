<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// --- 1. HANDLE ATTENDANCE ---
if (isset($_POST['mark_attendance'])) {
    $att_id = bin2hex(random_bytes(10));
    $staff_id = $_POST['staff_id'];
    $shift_date = $_POST['shift_date'];
    $clock_in = $_POST['clock_in'];
    $clock_out = !empty($_POST['clock_out']) ? $_POST['clock_out'] : NULL;
    $att_status = $_POST['att_status'];

    $query = "INSERT INTO rpos_attendance (att_id, staff_id, shift_date, clock_in, clock_out, att_status) VALUES(?,?,?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sissss', $att_id, $staff_id, $shift_date, $clock_in, $clock_out, $att_status);
    $stmt->execute();
    if ($stmt) $success = "Attendance Recorded."; else $err = "Error recording attendance.";
}

// --- 2. HANDLE LEAVE REQUESTS ---
if (isset($_POST['add_leave'])) {
    $leave_id = bin2hex(random_bytes(10));
    $staff_id = $_POST['staff_id'];
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];

    $query = "INSERT INTO rpos_leaves (leave_id, staff_id, leave_type, start_date, end_date, reason) VALUES(?,?,?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sissss', $leave_id, $staff_id, $leave_type, $start_date, $end_date, $reason);
    $stmt->execute();
    if ($stmt) $success = "Leave Request Submitted.";
}

// Handle Leave Status Update (Approve/Reject)
if (isset($_GET['leave_action']) && isset($_GET['id'])) {
    $status = $_GET['leave_action'] == 'approve' ? 'Approved' : 'Rejected';
    $id = $_GET['id'];
    $query = "UPDATE rpos_leaves SET leave_status = ? WHERE leave_id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('ss', $status, $id);
    $stmt->execute();
    if ($stmt) $success = "Leave $status successfully.";
}

// --- 3. HANDLE PERFORMANCE / DISCIPLINE ---
if (isset($_POST['add_perf'])) {
    $perf_id = bin2hex(random_bytes(10));
    $staff_id = $_POST['staff_id'];
    $record_type = $_POST['record_type'];
    $notes = $_POST['notes'];

    $query = "INSERT INTO rpos_performance (perf_id, staff_id, record_type, notes) VALUES(?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('siss', $perf_id, $staff_id, $record_type, $notes);
    $stmt->execute();
    if ($stmt) $success = "Performance Record Saved.";
}

// Fetch Staff for Dropdowns
$staffList = [];
$s_res = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC");
while ($s = $s_res->fetch_object()) { $staffList[] = $s; }

require_once('partials/_head.php');
?>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <h1 class="text-white"><i class="fas fa-users-cog"></i> Advanced HR Operations</h1>
                    <p class="text-white">Manage Attendance, Leaves, and Employee Performance records.</p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            <div class="card shadow">
                <div class="card-header border-0">
                    <ul class="nav nav-tabs" id="hrTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" id="attendance-tab" data-toggle="tab" href="#attendance" role="tab"><i class="fas fa-clock text-primary"></i> Attendance</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="leave-tab" data-toggle="tab" href="#leaves" role="tab"><i class="fas fa-calendar-times text-warning"></i> Leave Requests</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" id="perf-tab" data-toggle="tab" href="#performance" role="tab"><i class="fas fa-star text-success"></i> Performance</a>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content">
                        
                        <div class="tab-pane active" id="attendance" role="tabpanel">
                            <button class="btn btn-sm btn-primary mb-3" data-toggle="modal" data-target="#attModal"><i class="fas fa-plus"></i> Mark Attendance</button>
                            <div class="table-responsive">
                                <table class="table align-items-center table-flush" id="attTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Staff Name</th>
                                            <th>Clock In</th>
                                            <th>Clock Out</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $att_res = $mysqli->query("SELECT a.*, s.staff_name FROM rpos_attendance a JOIN rpos_staff s ON a.staff_id = s.staff_id ORDER BY a.shift_date DESC");
                                        while($row = $att_res->fetch_object()):
                                            $badge = 'badge-success';
                                            if($row->att_status == 'Late') $badge = 'badge-warning';
                                            if($row->att_status == 'Absent') $badge = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($row->shift_date)); ?></td>
                                            <td><strong><?php echo $row->staff_name; ?></strong></td>
                                            <td><?php echo $row->clock_in ? date('h:i A', strtotime($row->clock_in)) : '--'; ?></td>
                                            <td><?php echo $row->clock_out ? date('h:i A', strtotime($row->clock_out)) : '--'; ?></td>
                                            <td><span class="badge badge-pill <?php echo $badge; ?>"><?php echo $row->att_status; ?></span></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane" id="leaves" role="tabpanel">
                            <button class="btn btn-sm btn-warning mb-3" data-toggle="modal" data-target="#leaveModal"><i class="fas fa-plus"></i> Add Leave Request</button>
                            <div class="table-responsive">
                                <table class="table align-items-center table-flush" id="leaveTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Staff Name</th>
                                            <th>Type</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $l_res = $mysqli->query("SELECT l.*, s.staff_name FROM rpos_leaves l JOIN rpos_staff s ON l.staff_id = s.staff_id ORDER BY l.created_at DESC");
                                        while($row = $l_res->fetch_object()):
                                            $l_badge = 'badge-info';
                                            if($row->leave_status == 'Approved') $l_badge = 'badge-success';
                                            if($row->leave_status == 'Rejected') $l_badge = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td><strong><?php echo $row->staff_name; ?></strong></td>
                                            <td><?php echo $row->leave_type; ?></td>
                                            <td><?php echo date('d M', strtotime($row->start_date)); ?> to <?php echo date('d M', strtotime($row->end_date)); ?></td>
                                            <td><span class="badge badge-pill <?php echo $l_badge; ?>"><?php echo $row->leave_status; ?></span></td>
                                            <td>
                                                <?php if($row->leave_status == 'Pending'): ?>
                                                    <a href="hrm_operations.php?leave_action=approve&id=<?php echo $row->leave_id; ?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Approve</a>
                                                    <a href="hrm_operations.php?leave_action=reject&id=<?php echo $row->leave_id; ?>" class="btn btn-sm btn-danger"><i class="fas fa-times"></i> Reject</a>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-secondary" onclick="alert('Reason: <?php echo htmlspecialchars(addslashes($row->reason)); ?>')"><i class="fas fa-eye"></i> View Reason</button>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane" id="performance" role="tabpanel">
                            <button class="btn btn-sm btn-success mb-3" data-toggle="modal" data-target="#perfModal"><i class="fas fa-plus"></i> Record Disciplinary / Award</button>
                            <div class="table-responsive">
                                <table class="table align-items-center table-flush" id="perfTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Date Recorded</th>
                                            <th>Staff Name</th>
                                            <th>Record Type</th>
                                            <th>Notes / Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $p_res = $mysqli->query("SELECT p.*, s.staff_name FROM rpos_performance p JOIN rpos_staff s ON p.staff_id = s.staff_id ORDER BY p.created_at DESC");
                                        while($row = $p_res->fetch_object()):
                                        ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($row->created_at)); ?></td>
                                            <td><strong><?php echo $row->staff_name; ?></strong></td>
                                            <td>
                                                <?php if($row->record_type == 'Warning'): ?>
                                                    <span class="text-danger font-weight-bold"><i class="fas fa-exclamation-triangle"></i> Official Warning</span>
                                                <?php else: ?>
                                                    <span class="text-success font-weight-bold"><i class="fas fa-medal"></i> Commendation</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row->notes); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div> </div>
            </div>
            
            <div class="modal fade" id="attModal" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Record Attendance</h5></div>
                        <div class="modal-body">
                            <select name="staff_id" class="form-control mb-3" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach($staffList as $s) echo "<option value='{$s->staff_id}'>{$s->staff_name}</option>"; ?>
                            </select>
                            <input type="date" name="shift_date" class="form-control mb-3" value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="form-row mb-3">
                                <div class="col"><label>Clock In</label><input type="time" name="clock_in" class="form-control"></div>
                                <div class="col"><label>Clock Out</label><input type="time" name="clock_out" class="form-control"></div>
                            </div>
                            <select name="att_status" class="form-control">
                                <option value="Present">Present (On Time)</option>
                                <option value="Late">Late</option>
                                <option value="Absent">Absent / No-Show</option>
                            </select>
                        </div>
                        <div class="modal-footer"><button type="submit" name="mark_attendance" class="btn btn-primary">Save</button></div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="leaveModal" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Record Leave Request</h5></div>
                        <div class="modal-body">
                            <select name="staff_id" class="form-control mb-3" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach($staffList as $s) echo "<option value='{$s->staff_id}'>{$s->staff_name}</option>"; ?>
                            </select>
                            <select name="leave_type" class="form-control mb-3" required>
                                <option>Sick Leave</option><option>Vacation / Annual Leave</option><option>Emergency Leave</option><option>Unpaid Leave</option>
                            </select>
                            <div class="form-row mb-3">
                                <div class="col"><label>Start Date</label><input type="date" name="start_date" class="form-control" required></div>
                                <div class="col"><label>End Date</label><input type="date" name="end_date" class="form-control" required></div>
                            </div>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Reason for leave..."></textarea>
                        </div>
                        <div class="modal-footer"><button type="submit" name="add_leave" class="btn btn-warning">Submit Request</button></div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="perfModal" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Add Performance Record</h5></div>
                        <div class="modal-body">
                            <select name="staff_id" class="form-control mb-3" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach($staffList as $s) echo "<option value='{$s->staff_id}'>{$s->staff_name}</option>"; ?>
                            </select>
                            <select name="record_type" class="form-control mb-3" required>
                                <option value="Warning">Official Warning (Negative)</option>
                                <option value="Commendation">Commendation / Award (Positive)</option>
                            </select>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Detail the behavior, incident, or reason for award..." required></textarea>
                        </div>
                        <div class="modal-footer"><button type="submit" name="add_perf" class="btn btn-success">Save Record</button></div>
                    </form>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTables for all 3 tables independently
            $('#attTable').DataTable({"pageLength": 10, "order": [[0, "desc"]]});
            $('#leaveTable').DataTable({"pageLength": 10});
            $('#perfTable').DataTable({"pageLength": 10, "order": [[0, "desc"]]});
            
            // Keep active tab after reload
            $('a[data-toggle="tab"]').on('show.bs.tab', function(e) {
                localStorage.setItem('activeTab', $(e.target).attr('href'));
            });
            var activeTab = localStorage.getItem('activeTab');
            if(activeTab){
                $('#hrTabs a[href="' + activeTab + '"]').tab('show');
            }
        });
    </script>
</body>
</html>
