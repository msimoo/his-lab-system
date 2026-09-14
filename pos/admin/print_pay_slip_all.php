<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

// 1. Handle Month Filtering
// HTML5 month input uses YYYY-MM format. Our database uses MM-YYYY.
$input_month = $_GET['filter_month'] ?? date('Y-m'); // Default to current month
$date_obj = DateTime::createFromFormat('Y-m', $input_month);

if ($date_obj) {
    $db_month = $date_obj->format('m-Y'); // Format for DB search (e.g., 04-2026)
    $display_month = $date_obj->format('F Y'); // Format for display (e.g., April 2026)
} else {
    die(__('invalid_date_format') ?? 'Invalid date format.');
}
 
// 2. Fetch Payroll Data for ALL employees for the selected month
$pay_query = "SELECT p.*, s.staff_name, s.staff_number, s.staff_email 
              FROM rpos_payroll p 
              JOIN rpos_staff s ON p.staff_id = s.staff_id 
              WHERE p.month_year = ?
              ORDER BY s.staff_name ASC";
$stmt = $mysqli->prepare($pay_query);
$stmt->bind_param('s', $db_month);
$stmt->execute();
$result = $stmt->get_result();
$pay_records = [];
while ($row = $result->fetch_object()) {
    $pay_records[] = $row;
}

// 3. Fetch Company Settings
$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) {
    while($s = $setRes->fetch_assoc()){
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $settings['company_name'] ?? 'Company Name';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('batch_pay_slips') ?? 'Batch Pay Slips'; ?> - <?php echo $display_month; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fe;
            color: #32325d;
            font-family: 'Open Sans', sans-serif;
        }
        
        /* The Wrapper ensures each slip breaks to a new page when printing */
        .payslip-wrapper {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-top: 5px solid #5e72e4;
            page-break-after: always; /* THIS IS THE MAGIC PRINTING RULE */
        }
        
        /* Remove page break from the very last slip so it doesn't print a blank page at the end */
        .payslip-wrapper:last-of-type {
            page-break-after: auto;
        }

        .company-title {
            font-size: 28px;
            font-weight: 800;
            color: #5e72e4;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .slip-header {
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
        }
        .slip-header .col-sm-6 {
            flex: 0 0 49%;
            max-width: 49%;
            float: none;
            display: inline-block;
            vertical-align: top;
        }
        .section-title {
            background-color: #f6f9fc;
            padding: 8px 15px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #5e72e4;
        }
        .table th { background-color: #f6f9fc; }
        .net-pay-box {
            background-color: #2dce89;
            color: white;
            padding: 15px;
            text-align: right;
            border-radius: 5px;
            margin-top: 20px;
        }
        .net-pay-box h3 {
            color: white;
            margin: 0;
            font-weight: 800;
        }
        .signatures {
            margin-top: 60px;
            display: flex;
            justify-content: space-between;
        }
        .sign-line {
            width: 250px;
            border-top: 1px solid #000;
            text-align: center;
            padding-top: 5px;
            font-weight: bold;
        }
        
        /* Hide controls when printing */
        @media print {
            body { background-color: #fff; }
            .payslip-wrapper {
                box-shadow: none;
                margin: 0;
                padding: 0;
                border: none;
            }
            .no-print { display: none !important; }
            .net-pay-box {
                background-color: transparent !important;
                color: #000 !important;
                border: 2px solid #000;
            }
            .net-pay-box h3 { color: #000 !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>

<body dir="rtl">
    <div class="container">
        
        <div class="row no-print mt-4 mb-4">
            <div class="col-12 text-center">
                <div class="card shadow p-3">
                    <form method="GET" class="form-inline justify-content-center">
                        <label class="font-weight-bold mr-3"><?php echo __('select_payroll_month') ?? 'Select Payroll Month:'; ?></label>
                        <input type="month" name="filter_month" class="form-control mr-3" value="<?php echo htmlspecialchars($input_month); ?>" required>
                        <button type="submit" class="btn btn-info mr-3"><?php echo __('load_batch') ?? 'Load Batch'; ?></button>
                        
                        <?php if (count($pay_records) > 0): ?>
                            <button type="button" onclick="window.print()" class="btn btn-primary">
                                <i class="fas fa-print"></i> <?php echo __('print_all_slips') ?? 'Print All'; ?> <?php echo count($pay_records); ?> <?php echo __('slips') ?? 'Slips'; ?>
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" onclick="window.close()" class="btn btn-secondary ml-3">
                            <i class="fas fa-times"></i> <?php echo __('close') ?? 'Close'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php if (count($pay_records) === 0): ?>
            <div class="alert alert-warning text-center mt-5 no-print" style="max-width: 800px; margin: 0 auto;">
                <h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> <?php echo __('no_payroll_found') ?? 'No Payroll Found'; ?></h4>
                <p><?php echo __('no_payroll_records_found_for') ?? 'No processed payroll records were found for'; ?> <strong><?php echo $display_month; ?></strong>.</p>
                <hr>
                <p class="mb-0"><?php echo __('please_go_to_payroll_management') ?? 'Please go to the'; ?> <a href="payroll.php"><?php echo __('payroll_management') ?? 'Payroll Management'; ?></a> <?php echo __('page_to_process_salaries') ?? 'page to process staff salaries for this month.'; ?></p>
            </div>
        <?php else: ?>
<!--
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><?php echo __('payroll_summary') ?? 'Payroll Summary'; ?> - <?php echo $display_month; ?></h5>
                </div>
                <div class="table-responsive p-3">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th><?php echo __('employee_name') ?? 'Employee Name'; ?></th>
                                <th class="text-right"><?php echo __('basic_salary') ?? 'Basic Salary'; ?></th>
                                <th class="text-right"><?php echo __('allowances_incentives') ?? 'Allowances & Incentives'; ?></th>
                                <th class="text-right"><?php echo __('loan_repayments_advances') ?? 'Loan Repayments / Advances'; ?></th>
                                <th class="text-right"><?php echo __('gross_total') ?? 'Gross Total'; ?></th>
                                <th class="text-right"><?php echo __('net_pay') ?? 'Net Pay'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pay_records as $pay): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pay->staff_name); ?></td>
                                    <td class="text-right">$ <?php echo number_format($pay->base_pay, 2); ?></td>
                                    <td class="text-right">$ <?php echo number_format($pay->total_extras, 2); ?></td>
                                    <td class="text-right text-danger">$ <?php echo number_format($pay->loan_deductions, 2); ?></td>
                                    <td class="text-right text-success">$ <?php echo number_format(($pay->base_pay + $pay->total_extras), 2); ?></td>
                                    <td class="text-right">$ <?php echo number_format($pay->net_pay, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
                            -->
            
                <div class="payslip-wrapper">
                    
                    <div class="row slip-header">
                        <div class="col-sm-6">
                            <h3 style="color: #888; font-weight: 300;"><?php echo __('salary_slip') ?? 'SALARY SLIP'; ?></h3>
                            <div><strong><?php echo __('month') ?? 'Month'; ?>:</strong> <?php echo $display_month; ?></div>
                            <div><strong><?php echo __('date_processed') ?? 'Date Processed'; ?>:</strong> <?php echo date('d M Y', strtotime($pay->created_at)); ?></div>
                            <div><strong><?php echo __('transaction_id') ?? 'Transaction ID'; ?>:</strong> <?php echo substr($pay->pay_id, 0, 8); ?></div>
                        </div>
                        <div class="col-sm-6">
                            <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                            <div><?php echo htmlspecialchars($company_address); ?></div>
                            <div><?php echo htmlspecialchars($company_phone); ?></div>
                        </div>
                    </div>

                   <!-- <div class="row">
                        <div class="col-sm-6">
                            <table class="table table-sm table-borderless">
                                <tr><td width="40%"><strong><?php echo __('employee_name') ?? 'Employee Name'; ?>:</strong></td><td><?php echo htmlspecialchars($pay->staff_name); ?></td></tr>
                                <tr><td><strong><?php echo __('employee_id') ?? 'Employee ID'; ?>:</strong></td><td><?php echo htmlspecialchars($pay->staff_number); ?></td></tr>
                            </table>
                        </div>
                        <div class="col-sm-6">
                            <table class="table table-sm table-borderless">
                                <tr><td width="40%"><strong><?php echo __('email') ?? 'Email'; ?>:</strong></td><td><?php echo htmlspecialchars($pay->staff_email); ?></td></tr>
                                <tr><td><strong><?php echo __('status') ?? 'Status'; ?>:</strong></td><td><span class="badge badge-success" style="font-size: 14px;"><?php echo htmlspecialchars($pay->pay_status); ?></span></td></tr>
                            </table>
                        </div>
                    </div>-->

                    <div class="row">
                        <div class="col-sm-12">
                            <div class="section-title"><?php echo __('earnings_deductions_summary') ?? 'Earnings & Deductions Summary'; ?></div>
                            
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th class="text-right"><?php echo __('employee_name') ?? 'Employee Name'; ?></th>
                                        <!--<th><?php echo __('description') ?? 'Description'; ?></th>-->
                                        <th class="text-right"><?php echo __('basic_salary') ?? 'Basic Salary'; ?></th>
                                        <th class="text-right"><?php echo __('allowances_incentives') ?? 'Allowances & Incentives'; ?></th>
                                        <th class="text-right"><?php echo __('earnings') ?? 'Earnings (+)'; ?></th>
                                        <th class="text-right"><?php echo __('loan_repayments_advances') ?? 'Loan Repayments / Advances'; ?></th>
                                        <th class="text-right"><?php echo __('deductions') ?? 'Deductions (-)'; ?></th>
                                        <td class="text-right"><strong><?php echo __('Gross_Totals') ?? 'Gross Totals'; ?></strong></td>
                                        <td class="text-right"><strong><?php echo __('net_payable_amount') ?? 'Net Payable Amount'; ?></strong></td>
                                    </tr>
                                </thead>
                                <tbody>
                                    
            <?php foreach ($pay_records as $pay): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pay->staff_name); ?></td>
                                        <td class="text-right">$ <?php echo number_format($pay->base_pay, 2); ?></td>
                                        <td class="text-right">$ <?php echo number_format($pay->total_extras, 2); ?></td>
                                        <td></td>
                                        <td class="text-right text-danger">$ <?php echo number_format($pay->loan_deductions, 2); ?></td>
                                        <td class="text-right text-danger">$ <?php echo number_format($pay->loan_deductions, 2); ?></td>
                                        <td class="text-right text-success">$ <?php echo number_format(($pay->base_pay + $pay->total_extras), 2); ?></td>
                                        <td class="text-right net-pay-box"><strong>$ <?php echo number_format($pay->net_pay, 2); ?></strong></td>
                                    </tr>
            <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background-color: #f6f9fc; font-weight: bold;">
                                        
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!--<div class="row">
                        <div class="col-sm-6 offset-sm-6">
                            <div class="net-pay-box">
                                <small><?php echo __('net_payable_amount') ?? 'Net Payable Amount'; ?></small>
                                <h3>$ <?php echo number_format($pay->net_pay, 2); ?></h3>
                            </div>
                        </div>
                    </div>-->

                    <div class="signatures">
                        <div class="sign-line">
                            <?php echo __('employee_signature') ?? 'Employee Signature'; ?>
                        </div>
                        <div class="sign-line">
                            <?php echo __('authorized_signatory') ?? 'Authorized Signatory'; ?>
                        </div>
                    </div>

                    <div class="text-center mt-5" style="font-size: 12px; color: #888;">
                        <p><?php echo __('computer_generated_notice') ?? 'This is a computer-generated document. No seal is required.'; ?></p>
                    </div>

                </div> 
        <?php endif; ?>

    </div>
</body>
</html>
