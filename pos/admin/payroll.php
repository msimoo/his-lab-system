<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// 1. Handle Salary Payment Processing
if (isset($_POST['process_payment'])) {
    $pay_id = bin2hex(random_bytes(10));
    $staff_id = intval($_POST['staff_id']);
    $month_year = date('m-Y');

    $staff_res = $mysqli->query("SELECT s.*, st.basic_salary, st.housing_allow, st.transport_allow, st.other_allow, st.social_security_pct, st.tax_pct 
                                FROM rpos_staff s 
                                LEFT JOIN rpos_salary_structures st ON s.staff_id = st.staff_id 
                                WHERE s.staff_id = '$staff_id'");

    if ($staff_res && $staff_res->num_rows > 0) {
        $staff = $staff_res->fetch_object();
        $basic = floatval($staff->basic_salary ?: $staff->staff_salary);

        if ($basic <= 0) {
            $err = __('salary_structure_missing');
        } else {
            $structure_allowances = floatval($staff->housing_allow) + floatval($staff->transport_allow) + floatval($staff->other_allow);
            $gross_salary = $basic + $structure_allowances;
        $ss_deduction = $basic * (floatval($staff->social_security_pct) / 100);
        $tax_deduction = $gross_salary * (floatval($staff->tax_pct) / 100);
        $current_month = date('m-Y');
        $extras = floatval($mysqli->query("SELECT SUM(amount) as total FROM rpos_hrm_extras WHERE staff_id = '$staff_id' AND month_year = '$current_month'")->fetch_object()->total);
        $loan_deductions = floatval($mysqli->query("SELECT SUM(loan_amount) as total FROM rpos_loans WHERE staff_id = '$staff_id' AND loan_status = 'Approved'")->fetch_object()->total);
        $total_deductions = $ss_deduction + $tax_deduction + $loan_deductions;
        $total_extras = $structure_allowances + $extras;
        $net_pay = number_format($gross_salary + $extras - $total_deductions, 2, '.', '');

        $check = $mysqli->query("SELECT * FROM rpos_payroll WHERE staff_id = '$staff_id' AND month_year = '$month_year'");
        if ($check->num_rows > 0) {
            $err = __('salary_already_processed');
        } else {
            $query = "INSERT INTO rpos_payroll (pay_id, staff_id, month_year, base_pay, total_extras, loan_deductions, net_pay, pay_status) VALUES(?,?,?,?,?,?,?,'Paid')";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('sisssss', $pay_id, $staff_id, $month_year, number_format($basic, 2, '.', ''), number_format($total_extras, 2, '.', ''), number_format($loan_deductions, 2, '.', ''), $net_pay);

            if ($stmt->execute()) {
                $mysqli->query("UPDATE rpos_loans SET loan_status = 'Paid' WHERE staff_id = '$staff_id' AND loan_status = 'Approved'");
                $success = __('payment_processed');
            } else {
                $err = __('payment_failed');
            }
        }
    }
} else {
        $err = __('staff_not_found');
    }
}

require_once('partials/_head.php');
?>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8" style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                            <h1 class="text-white"><i class="ni ni-money-coins"></i> <?php echo __('payroll_management'); ?></h1>
                            <p class="text-white"><?php echo __('review_earnings_deductions'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <h3 class="mb-0"><?php echo __('run_payroll'); ?>: <?php echo date('F Y'); ?></h3>
                        </div>
                        <div class="card-body">
                            <?php if (isset($success)): ?>
                                <div class="alert alert-success"><?php echo $success; ?></div>
                            <?php endif; ?>
                            <?php if (isset($err)): ?>
                                <div class="alert alert-danger"><?php echo $err; ?></div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table align-items-center table-flush" id="payrollTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th><?php echo __('staff_name'); ?></th>
                                            <th><?php echo __('base_salary'); ?></th>
                                            <th><?php echo __('allowances'); ?></th>
                                            <th><?php echo __('month_extras'); ?></th>
                                            <th><?php echo __('deductions'); ?></th>
                                            <th><?php echo __('net_pay'); ?></th>
                                            <th><?php echo __('action'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $ret = "SELECT s.*, st.basic_salary, st.housing_allow, st.transport_allow, st.other_allow, st.social_security_pct, st.tax_pct 
                                                FROM rpos_staff s 
                                                LEFT JOIN rpos_salary_structures st ON s.staff_id = st.staff_id 
                                                ORDER BY s.staff_name ASC";
                                        $stmt = $mysqli->prepare($ret);
                                        $stmt->execute();
                                        $res = $stmt->get_result();
                                        while ($staff = $res->fetch_object()) {
                                            $sid = $staff->staff_id;
                                            $basic = floatval($staff->basic_salary ?: $staff->staff_salary);
                                            $structure_allowances = floatval($staff->housing_allow) + floatval($staff->transport_allow) + floatval($staff->other_allow);
                                            $gross_salary = $basic + $structure_allowances;
                                            $ss_deduction = $basic * (floatval($staff->social_security_pct) / 100);
                                            $tax_deduction = $gross_salary * (floatval($staff->tax_pct) / 100);
                                            $current_month = date('m-Y');
                                            $e_res = $mysqli->query("SELECT SUM(amount) as total FROM rpos_hrm_extras WHERE staff_id = '$sid' AND month_year = '$current_month'");
                                            $extras = floatval($e_res->fetch_object()->total);
                                            $l_res = $mysqli->query("SELECT SUM(loan_amount) as total FROM rpos_loans WHERE staff_id = '$sid' AND loan_status = 'Approved'");
                                            $loans = floatval($l_res->fetch_object()->total);
                                            $total_deductions = $ss_deduction + $tax_deduction + $loans;
                                            $net = number_format($gross_salary + $extras - $total_deductions, 2);
                                        ?>
                                            <tr>
                                                <td><strong><?php echo $staff->staff_name; ?></strong></td>
                                                <td><?php echo number_format($basic, 2); ?></td>
                                                <td><span class="text-success">+<?php echo number_format($structure_allowances, 2); ?></span></td>
                                                <td><span class="text-success">+<?php echo number_format($extras, 2); ?></span></td>
                                                <td><span class="text-danger">-<?php echo number_format($total_deductions, 2); ?></span></td>
                                                <td><h4 class="mb-0">$<?php echo $net; ?></h4></td>
                                                <td>
                                                    <form method="POST">
                                                        <input type="hidden" name="staff_id" value="<?php echo $sid; ?>">
                                                        <button type="submit" name="process_payment" class="btn btn-sm btn-success" onclick="return confirm('<?php echo sprintf(__('confirm_payment_for'), $staff->staff_name); ?>');">
                                                            <i class="fas fa-check-circle"></i> <?php echo __('mark_paid'); ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-5">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0 bg-secondary">
                            <h3 class="mb-0"><?php echo __('recent_payment_history'); ?></h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <button class="btn btn-sm btn-primary mb-3" onclick="printAllSlips()">
                                    <i class="fas fa-print"></i> <?php echo __('print_all_slips'); ?>
                                </button>
                                <table class="table align-items-center table-flush" id="historyTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th><?php echo __('staff_name'); ?></th>
                                            <th><?php echo __('month'); ?></th>
                                            <th><?php echo __('net_pay'); ?></th>
                                            <th><?php echo __('status'); ?></th>
                                            <th><?php echo __('date'); ?></th>
                                            <th><?php echo __('action'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $h_ret = "SELECT p.*, s.staff_name FROM rpos_payroll p JOIN rpos_staff s ON p.staff_id = s.staff_id ORDER BY p.created_at DESC";
                                        $h_stmt = $mysqli->prepare($h_ret);
                                        $h_stmt->execute();
                                        $h_res = $h_stmt->get_result();
                                        while ($pay = $h_res->fetch_object()) {
                                        ?>
                                            <tr>
                                                <td><?php echo $pay->staff_name; ?></td>
                                                <td><?php echo $pay->month_year; ?></td>
                                                <td><strong>$<?php echo number_format($pay->net_pay, 2); ?></strong></td>
                                                <td><span class="badge badge-success"><?php echo $pay->pay_status; ?></span></td>
                                                <td><?php echo date('d M Y H:i', strtotime($pay->created_at)); ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-primary" onclick="printEmployeeSlip('<?php echo $pay->pay_id; ?>')">
                                                        <i class="fas fa-print"></i> <?php echo __('view_slip'); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('#payrollTable').DataTable({ "pageLength": 10 , scrollX: true  });
            $('#historyTable').DataTable({ "pageLength": 10, scrollX: true , "order": [[4, "desc"]] });
        });

        function printEmployeeSlip(payId) {
            window.open('print_pay_slip.php?pay_id=' + encodeURIComponent(payId), '_blank');
        }

        function printAllSlips(month) {
            month = month || '<?php echo date("Y-m"); ?>';
            window.open('print_pay_slip_all.php?filter_month=' + encodeURIComponent(month), '_blank');
        }
    </script>
</body>
</html>
