<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Handle Loan Request Submission
if (isset($_POST['add_loan'])) {
    $loan_id = bin2hex(random_bytes(10));
    $staff_id = $_POST['staff_id'];
    $loan_amount = $_POST['loan_amount'];
    $loan_details = $_POST['loan_details'];
    $loan_status = "Pending";

    $query = "INSERT INTO rpos_loans (loan_id, staff_id, loan_amount, loan_details, loan_status) VALUES(?,?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sisss', $loan_id, $staff_id, $loan_amount, $loan_details, $loan_status);
    $stmt->execute();
    if ($stmt) {
        $success = __('loan_request_submitted');
    } else {
        $err = __('please_try_again_later');
    }
}

// Handle Status Updates (Approve / Reject)
if (isset($_GET['update_status']) && isset($_GET['id'])) {
    $status = $_GET['update_status'];
    $id = $_GET['id'];
    $query = "UPDATE rpos_loans SET loan_status = ? WHERE loan_id = ?";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('ss', $status, $id);
    $stmt->execute();
    if ($stmt) {
        $success = sprintf(__('loan_status_updated'), $status);
    } else {
        $err = __('update_failed');
    }
}

// Handle Deletion
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $adn = "DELETE FROM rpos_loans WHERE loan_id = ?";
    $stmt = $mysqli->prepare($adn);
    $stmt->bind_param('s', $id);
    $stmt->execute();
    if ($stmt) {
        $success = __('loan_record_deleted');
    }
}

require_once('partials/_head.php');
?>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body"></div>
            </div>
        </div>
        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <div class="row align-items-center">
                                <div class="col">
                                    <h3 class="mb-0"><?php echo __('staff_loans_advances'); ?></h3>
                                </div>
                                <div class="col text-right">
                                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addLoanModal">
                                        <i class="fas fa-hand-holding-usd"></i> <?php echo __('new_loan_request'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table align-items-center table-flush" id="loansTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col"><?php echo __('staff_name'); ?></th>
                                        <th scope="col"><?php echo __('amount'); ?></th>
                                        <th scope="col"><?php echo __('status'); ?></th>
                                        <th scope="col"><?php echo __('request_date'); ?></th>
                                        <th scope="col"><?php echo __('note'); ?></th>
                                        <th scope="col"><?php echo __('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = "SELECT l.*, s.staff_name FROM rpos_loans l JOIN rpos_staff s ON l.staff_id = s.staff_id ORDER BY l.created_at DESC";
                                    $stmt = $mysqli->prepare($ret);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    while ($loan = $res->fetch_object()) {
                                        // Dynamic Badge Color
                                        $statusBadge = "badge-warning"; // Pending
                                        if($loan->loan_status == 'Approved') $statusBadge = "badge-success";
                                        if($loan->loan_status == 'Rejected') $statusBadge = "badge-danger";
                                        if($loan->loan_status == 'Paid') $statusBadge = "badge-primary";
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $loan->staff_name; ?></strong></td>
                                            <td class="font-weight-bold">$ <?php echo number_format($loan->loan_amount, 2); ?></td>
                                            <td><span class="badge badge-pill <?php echo $statusBadge; ?>"><?php echo $loan->loan_status; ?></span></td>
                                            <td><?php echo date('d/M/Y', strtotime($loan->created_at)); ?></td>
                                            <td><small><?php echo $loan->loan_details; ?></small></td>
                                            <td>
                                                <?php if($loan->loan_status == 'Pending'): ?>
                                                    <a href="hrm_loans.php?update_status=Approved&id=<?php echo $loan->loan_id; ?>" class="btn btn-sm btn-success">
                                                        <i class="fas fa-check"></i> <?php echo __('approve'); ?>
                                                    </a>
                                                    <a href="hrm_loans.php?update_status=Rejected&id=<?php echo $loan->loan_id; ?>" class="btn btn-sm btn-warning">
                                                        <i class="fas fa-times"></i> <?php echo __('reject'); ?>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <a href="hrm_loans.php?delete=<?php echo $loan->loan_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo __('confirm_delete_loan'); ?>');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="addLoanModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?php echo __('record_loan_request'); ?></h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label><?php echo __('employee_name'); ?></label>
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('choose_employee'); ?></option>
                                        <?php
                                        $staff_res = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC");
                                        while ($s = $staff_res->fetch_object()) {
                                            echo "<option value='{$s->staff_id}'>{$s->staff_name}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label><?php echo __('loan_amount'); ?></label>
                                    <input type="number" step="0.01" name="loan_amount" class="form-control" placeholder="<?php echo __('amount'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label><?php echo __('reason_details'); ?></label>
                                    <textarea name="loan_details" class="form-control" rows="3" placeholder="e.g. Salary Advance for Medical reasons"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('close'); ?></button>
                                <button type="submit" name="add_loan" class="btn btn-primary"><?php echo __('submit_request'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('#loansTable').DataTable({
                "pageLength": 10,
                "order": [[3, "desc"]], // Sort by date
                "language": {
                    "paginate": {
                        "previous": "<i class='fas fa-angle-left'></i>",
                        "next": "<i class='fas fa-angle-right'></i>"
                    }
                }
            });
        });
    </script>
</body>
</html>
