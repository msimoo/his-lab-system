<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// 1. Handle Date Filtering (Default to current month)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$datetime_from = $date_from . ' 00:00:00';
$datetime_to = $date_to . ' 23:59:59';
  
// 2. جلب الإجماليات المالية الكبرى
// الإيرادات
$rev_res = $mysqli->query("SELECT SUM(prod_price * prod_qty) as total_rev, COUNT(DISTINCT order_code) as total_orders FROM rpos_orders WHERE order_status = 'Paid' AND created_at BETWEEN '$datetime_from' AND '$datetime_to'");
$rev_data = $rev_res->fetch_object(); 
$total_orders = $rev_data->total_orders ?? 0;

// تكلفة البضاعة المباعة (COGS)
$cogs_res = $mysqli->query("SELECT SUM(o.prod_qty * p.last_purchase_price) as total_cogs FROM rpos_orders o JOIN rpos_products p ON o.prod_id = p.prod_id WHERE o.order_status = 'Paid' AND o.created_at BETWEEN '$datetime_from' AND '$datetime_to'");
$total_cogs = $cogs_res->fetch_object()->total_cogs ?? 0;

// المصاريف
$exp_res = $mysqli->query("SELECT SUM(exp_amount) as total_exp FROM rpos_expenses WHERE created_at BETWEEN '$datetime_from' AND '$datetime_to'");
$total_expenses = $exp_res->fetch_object()->total_exp ?? 0;

// 2. Fetch Totals for the Dashboard
// REVENUE: Total sales from orders
$rev_query = "SELECT SUM(prod_price * prod_qty) as total_rev FROM rpos_orders WHERE order_status = 'Paid' AND created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$rev_res = $mysqli->query($rev_query);
$total_revenue = $rev_res->fetch_object()->total_rev ?? 0;

// COGS: Total cost of items sold (Sales Qty * Purchase Price)
// Note: We join with rpos_products to get the purchase cost of each item sold
$cogs_query = "SELECT SUM(o.prod_qty * p.last_purchase_price) as total_cogs 
               FROM rpos_orders o 
               JOIN rpos_products p ON o.prod_id = p.prod_id 
               WHERE o.order_status = 'Paid' AND o.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$cogs_res = $mysqli->query($cogs_query);
$total_cogs = $cogs_res->fetch_object()->total_cogs ?? 0;

// EXPENSES: Total business overhead
$exp_query = "SELECT SUM(exp_amount) as total_exp FROM rpos_expenses WHERE created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";
$exp_res = $mysqli->query($exp_query);
$total_expenses = $exp_res->fetch_object()->total_exp ?? 0;

// CALCULATIONS
$gross_profit = $total_revenue - $total_cogs;
$net_profit = $gross_profit - $total_expenses;

// الحسابات النهائية
$gross_profit = $total_revenue - $total_cogs;
$profit_margin = ($total_revenue > 0) ? ($net_profit / $total_revenue) * 100 : 0;
$avg_order_value = ($total_orders > 0) ? ($total_revenue / $total_orders) : 0;


require_once('partials/_head.php');
?>

<style>
/* Professional form styling */
.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: all 0.3s ease;
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.15);
}
.card-header {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: #ffffff;
    border: none;
    padding: 1.5rem;
}
.card-body {
    padding: 2rem;
    background: #ffffff;
}
.btn {
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.5s;
}
.btn:hover::before {
    left: 100%;
}
.btn-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,123,255,0.4);
}
.btn-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    border: none;
}
.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108,117,125,0.4);
}
.form-control {
    border-radius: 8px;
    border: 1px solid #ced4da;
    transition: all 0.3s ease;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
}
/* Stats cards styling */
.card-stats {
    border-radius: 15px;
    overflow: hidden;
}
.card-stats .card-body {
    padding: 1.5rem;
}
.card-stats .h2 {
    font-size: 1.5rem;
}
.bg-gradient-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
}
/* Label styling */
label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}
</style>

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
                    <h1 class="text-white"><i class="fas fa-chart-line"></i> <?php echo __('profit_loss_analytics'); ?></h1>
                    <p class="text-white"><?php echo __('detailed_financial_summary'); ?></p>
                </div>
            </div></div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            <div class="row mb-4">
                <div class="col">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="form-inline">
                                <label class="mr-2"><?php echo __('from'); ?>:</label>
                                <input type="date" name="date_from" class="form-control form-control-sm mr-3" value="<?php echo $date_from; ?>">
                                <label class="mr-2"><?php echo __('to'); ?>:</label>
                                <input type="date" name="date_to" class="form-control form-control-sm mr-3" value="<?php echo $date_to; ?>">
                                <button type="submit" class="btn btn-sm btn-primary"><?php echo __('update_report'); ?></button>
                                <button type="button" onclick="window.open('print_profits.php?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>', '_blank')" class="btn btn-sm btn-secondary ml-auto"><i class="fas fa-print"></i> <?php echo __('print_report'); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('total_revenue'); ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-primary">$<?php echo number_format($total_revenue, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('cogs'); ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-warning">$<?php echo number_format($total_cogs, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body text-center" style="background: #f8f9fe;">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('gross_profit'); ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-success">$<?php echo number_format($gross_profit, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 ">
                    <div class="card card-stats mb-4 mb-xl-0 shadow bg-gradient-success">
                        <div class="card-body bg-gradient-success">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-white mb-0"><?php echo __('net_profit'); ?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-white">$<?php echo number_format($net_profit, 2); ?></span>
                                </div>
                                        <div class="col-auto">
                                            <div class="icon icon-shape bg-danger text-white shadow"><i class="fas fa-wallet"></i></div>
                                        </div>
                                    </div>
                                    <p class="mt-3 mb-0 text-sm" style="margin-top: -0.5rem !important;">
                                        <span class="text-white mr-2"><i class="fa fa-arrow-up"></i> <?php echo number_format($profit_margin, 1); ?>%</span>
                                        <span class="text-nowrap">هامش الربح</span>
                                    </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-5">
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <h3 class="mb-0"><?php echo __('product_wise_profit'); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush" id="productwiseprofit">
                                <thead class="thead-light">
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
                                    <?php
                                    $p_res = $mysqli->query("SELECT p.prod_name, SUM(o.prod_qty) as qty, p.last_purchase_price as cost, p.prod_price as price 
                                                             FROM rpos_orders o 
                                                             JOIN rpos_products p ON o.prod_id = p.prod_id 
                                                             WHERE o.order_status = 'Paid' AND o.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'
                                                             GROUP BY p.prod_id ORDER BY qty DESC");
                                    while($row = $p_res->fetch_object()){
                                        $margin = $row->price - $row->cost;
                                        $p_total = $margin * $row->qty;
                                    ?>
                                    <tr>
                                        <td><?php echo $row->prod_name; ?></td>
                                        <td><?php echo $row->qty; ?></td>
                                        <td>$<?php echo number_format($row->cost, 2); ?></td>
                                        <td>$<?php echo number_format($row->price, 2); ?></td>
                                        <td class="text-success">+$<?php echo number_format($margin, 2); ?></td>
                                        <td><strong>$<?php echo number_format($p_total, 2); ?></strong></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <h3 class="mb-0"><?php echo __('expense_summary'); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush">
                                <thead class="thead-light">
                                    <tr>
                                        <th><?php echo __('category'); ?></th>
                                        <th><?php echo __('total'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $cat_res = $mysqli->query("SELECT exp_category, SUM(exp_amount) as total 
                                                               FROM rpos_expenses 
                                                               WHERE created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'
                                                               GROUP BY exp_category");
                                    while($ecat = $cat_res->fetch_object()){
                                    ?>
                                    <tr>
                                        <td><?php echo $ecat->exp_category; ?></td>
                                        <td class="text-danger">-$<?php echo number_format($ecat->total, 2); ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

<script> 

     $(document).ready(function() {
            $('#productwiseprofit').DataTable({
                "pageLength": 15,
                "scrollX":true,
                "order": [[2, "desc"]], // Sort by total sold by default
                "language": {
                    "paginate": { "previous": "<i class='fas fa-angle-left'></i>", "next": "<i class='fas fa-angle-right'></i>" }
                }
            });
        });
 
  </script>  
 
 
 
                
    </div>
            
            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
