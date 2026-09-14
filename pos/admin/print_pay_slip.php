<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$pay_id = $_GET['pay_id'] ?? '';

// Fetch Payroll and Staff Details
$pay_query = "SELECT p.*, s.staff_name, s.staff_number, s.staff_email 
              FROM rpos_payroll p 
              JOIN rpos_staff s ON p.staff_id = s.staff_id 
              WHERE p.pay_id = ?";
$stmt = $mysqli->prepare($pay_query);
$stmt->bind_param('s', $pay_id);
$stmt->execute();
$res = $stmt->get_result();
 
if ($res->num_rows === 0) {
    die(__('invalid_pay_slip_id') ?? 'Invalid Pay Slip ID.');
}
$pay = $res->fetch_object();

// Fetch Company Settings
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

// Format the month (e.g., "04-2026" to "April 2026")
$month_obj = DateTime::createFromFormat('m-Y', $pay->month_year);
$display_month = $month_obj ? $month_obj->format('F Y') : $pay->month_year;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('pay_slip') ?? 'Pay Slip'; ?> - <?php echo htmlspecialchars($pay->staff_name); ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
     
    <style>
        body {
            background-color: #f8f9fe;
            color: #32325d;
            font-family: 'Open Sans', sans-serif;
        }
        .payslip-container {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-top: 5px solid #5e72e4;
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
        .row-flex {
            display: flex;
            flex-wrap: nowrap;
            align-items: flex-start;
            justify-content: space-between;
        }
        .row-flex .col-sm-6 {
            flex: 0 0 49%;
            max-width: 49%;
            float: none;
            display: inline-block;
            vertical-align: top;
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
        
        @media print {
            body { background-color: #fff; }
            .payslip-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
                border: none;
            }
            .slip-header {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: flex-start;
                justify-content: space-between;
            }
            .slip-header .col-sm-6 {
                flex: 0 0 49% !important;
                max-width: 49% !important;
                float: none !important;
                display: inline-block !important;
                vertical-align: top !important;
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
        
        <div class="row no-print mt-4 mb-2">
            <div class="col-12 text-center">
                <button onclick="window.print()" class="btn btn-primary btn-lg">
                    <i class="fas fa-print"></i> <?php echo __('print_pay_slip') ?? 'Print Pay Slip'; ?>
                </button>
                <button onclick="window.close()" class="btn btn-secondary btn-lg ml-2">
                    <i class="fas fa-times"></i> <?php echo __('close') ?? 'Close'; ?>
                </button>
            </div>
        </div>

        <div class="payslip-container" id="payslip">
            
            <div class="row slip-header">
                <div class="col-sm-6">
                    <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                    <div><?php echo htmlspecialchars($company_address); ?></div>
                    <div><?php echo htmlspecialchars($company_phone); ?></div>
                </div>
                <div class="col-sm-6>
                    <h3 style="color: #888; font-weight: 300;"><?php echo __('salary_slip') ?? 'SALARY SLIP'; ?></h3>
                    <div><strong><?php echo __('month') ?? 'Month'; ?>:</strong> <?php echo $display_month; ?></div>
                    <div><strong><?php echo __('date_processed') ?? 'Date Processed'; ?>:</strong> <?php echo date('d M Y', strtotime($pay->created_at)); ?></div>
                    <div><strong><?php echo __('transaction_id') ?? 'Transaction ID'; ?>:</strong> <?php echo substr($pay->pay_id, 0, 8); ?></div>
                </div>
            </div>

            <div class="row row-flex">
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
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="section-title"><?php echo __('earnings_deductions_summary') ?? 'Earnings & Deductions Summary'; ?></div>
                    
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th class="text-right"><?php echo __('description') ?? 'Description'; ?></th>
                                <th class="text-right" width="25%"><?php echo __('earnings') ?? 'Earnings (+)'; ?></th>
                                <th class="text-right" width="25%"><?php echo __('deductions') ?? 'Deductions (-)'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-right"><?php echo __('basic_salary') ?? 'Basic Salary'; ?></td>
                                <td class="text-right">$ <?php echo number_format($pay->base_pay, 2); ?></td>
                                <td class="text-right"></td>
                            </tr>
                            <tr>
                                <td><?php echo __('allowances_incentives') ?? 'Allowances & Incentives'; ?></td>
                                <td class="text-right">$ <?php echo number_format($pay->total_extras, 2); ?></td>
                                <td class="text-right"></td>
                            </tr>
                            <tr>
                                <td><?php echo __('loan_repayments_advances') ?? 'Loan Repayments / Advances'; ?></td>
                                <td class="text-right"></td>
                                <td class="text-right text-danger">$ <?php echo number_format($pay->loan_deductions, 2); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #f6f9fc; font-weight: bold;">
                                <td class="text-right"><strong><?php echo __('Gross_Totals') ?? 'Gross Totals'; ?>:</strong></td>
                                <td class="text-right text-success">$ <?php echo number_format(($pay->base_pay + $pay->total_extras), 2); ?></td>
                                <td class="text-right text-danger">$ <?php echo number_format($pay->loan_deductions, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6 offset-sm-6">
                    <div class="net-pay-box">
                        <small><?php echo __('net_payable_amount') ?? 'Net Payable Amount'; ?></small>
                        <h3>$ <?php echo number_format($pay->net_pay, 2); ?></h3>
                    </div>
                </div>
            </div>

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
    </div>
</body>
</html>
