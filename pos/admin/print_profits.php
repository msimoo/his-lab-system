<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// Fetch company settings
$settings = [];
$setRes = $mysqli->query("SELECT setting_key, setting_value FROM rpos_settings");
if ($setRes) {
    while($s = $setRes->fetch_assoc()) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
}
$company_name = $settings['company_name'] ?? 'My POS Company';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? ''; 

$rev_query = "SELECT SUM(prod_price * prod_qty) as total_rev FROM rpos_orders WHERE order_status = 'Paid' AND created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$rev_res = $mysqli->query($rev_query);
$total_revenue = $rev_res->fetch_object()->total_rev ?? 0;

$cogs_query = "SELECT SUM(o.prod_qty * p.last_purchase_price) as total_cogs 
               FROM rpos_orders o 
               JOIN rpos_products p ON o.prod_id = p.prod_id 
               WHERE o.order_status = 'Paid' AND o.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$cogs_res = $mysqli->query($cogs_query);
$total_cogs = $cogs_res->fetch_object()->total_cogs ?? 0;

$exp_query = "SELECT SUM(exp_amount) as total_exp FROM rpos_expenses WHERE created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$exp_res = $mysqli->query($exp_query);
$total_expenses = $exp_res->fetch_object()->total_exp ?? 0;

$gross_profit = $total_revenue - $total_cogs;
$net_profit = $gross_profit - $total_expenses;

$product_query = "SELECT p.prod_name, SUM(o.prod_qty) as qty, p.last_purchase_price as cost, p.prod_price as price 
                FROM rpos_orders o 
                JOIN rpos_products p ON o.prod_id = p.prod_id 
                WHERE o.order_status = 'Paid' AND o.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59' 
                GROUP BY p.prod_id ORDER BY qty DESC";
$product_res = $mysqli->query($product_query);

$expense_query = "SELECT exp_category, SUM(exp_amount) as total FROM rpos_expenses WHERE created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59' GROUP BY exp_category";
$expense_res = $mysqli->query($expense_query);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('profit_loss_report') ?? 'Profit / Loss Report'; ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fe; color: #32325d; font-family: 'Open Sans', sans-serif; }
        .report-container { max-width: 1000px; margin: 20px auto; background: #fff; padding: 20px; box-shadow: 0 0 20px rgba(0,0,0,0.08); border-top: 5px solid #5e72e4; }
        .company-title { font-size: 26px; font-weight: 700; color: #5e72e4; margin-bottom: 5px; }
        .section-title { background-color: #f6f9fc; padding: 10px 15px; font-weight: 700; margin-top: 20px; border-left: 4px solid #5e72e4; }
        .text-muted-small { color: #8898aa; font-size: 0.9rem; }
        .table th { background-color: #f6f9fc; }
        .net-box { background-color: #2dce89; color: #fff; padding: 15px; border-radius: 5px; }

        @media print {
            body { background-color: #fff; }
            .report-container { box-shadow: none; margin: 0; padding: 0; border: none; }
            .no-print { display: none !important; }
            .net-box { background-color: transparent !important; color: #000 !important; border: 2px solid #000; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    <div class="container report-container">
        <div class="row mb-3 no-print">
            <div class="col text-right">
                <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> <?php echo __('print_report'); ?></button>
                <button onclick="window.close()" class="btn btn-secondary ml-2"><i class="fas fa-times"></i> <?php echo __('close'); ?></button>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                <div><?php echo htmlspecialchars($company_address); ?></div>
                <div><?php echo htmlspecialchars($company_phone); ?></div>
            </div>
            <div class="col-md-4 text-right">
                <div class="text-muted-small"><strong><?php echo __('date_range'); ?>:</strong> <?php echo htmlspecialchars($date_from); ?> - <?php echo htmlspecialchars($date_to); ?></div>
                <div class="text-muted-small"><strong><?php echo __('printed_on'); ?>:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
        </div>

        <div class="section-title"><?php echo __('summary'); ?></div>
        <div class="row mt-2">
            <div class="col-md-3"><strong><?php echo __('total_revenue'); ?>:</strong> $<?php echo number_format($total_revenue,2); ?></div>
            <div class="col-md-3"><strong><?php echo __('cogs'); ?>:</strong> $<?php echo number_format($total_cogs,2); ?></div>
            <div class="col-md-3"><strong><?php echo __('gross_profit'); ?>:</strong> $<?php echo number_format($gross_profit,2); ?></div>
            <div class="col-md-3"><strong><?php echo __('net_profit'); ?>:</strong> $<?php echo number_format($net_profit,2); ?></div>
        </div>

        <div class="section-title"><?php echo __('product_wise_profit'); ?></div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th><?php echo __('product'); ?></th>
                        <th><?php echo __('sold_qty'); ?></th>
                        <th><?php echo __('unit_cost'); ?></th>
                        <th><?php echo __('unit_price'); ?></th>
                        <th><?php echo __('margin'); ?></th>
                        <th><?php echo __('total_profit'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $product_res->fetch_object()){
                        $margin = $row->price - $row->cost;
                        $p_total = $margin * $row->qty;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row->prod_name); ?></td>
                            <td><?php echo $row->qty; ?></td>
                            <td>$<?php echo number_format($row->cost,2); ?></td>
                            <td>$<?php echo number_format($row->price,2); ?></td>
                            <td><?php echo $margin>=0 ? '+' : '-'; ?>$<?php echo number_format(abs($margin),2); ?></td>
                            <td>$<?php echo number_format($p_total,2); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="section-title"><?php echo __('expense_summary'); ?></div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th><?php echo __('category'); ?></th>
                        <th><?php echo __('total'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($ecat = $expense_res->fetch_object()){ ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ecat->exp_category); ?></td>
                            <td>$<?php echo number_format($ecat->total,2); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="row mt-4">
            <div class="col-md-4 offset-md-8">
                <div class="net-box">
                    <strong><?php echo __('net_profit'); ?>:</strong> $<?php echo number_format($net_profit,2); ?>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12 text-muted-small">
                <?php echo __('report_generated_by'); ?>: <?php echo htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['staff_name'] ?? ''); ?>
            </div>
        </div>

    </div>
</body>
</html>