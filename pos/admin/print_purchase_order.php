<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$receive_id = $_GET['receive_id'] ?? '';

if (empty($receive_id)) {
    die(__('invalid_receive_id') ?? 'Invalid Receive ID.');
}

// Fetch Receive Details
$receive_query = "SELECT r.*, COUNT(p.purchase_id) AS item_count,
                         SUM(p.qty * p.purchase_price) AS purchase_amount,
                         SUM(p.qty * p.purchase_price * (p.tax/100)) AS tax_amount
                  FROM rpos_receives r
                  LEFT JOIN rpos_purchases p ON r.receive_id = p.receive_id
                  WHERE r.receive_id = ?
                  GROUP BY r.receive_id";
$stmt = $mysqli->prepare($receive_query);
$stmt->bind_param('s', $receive_id);
$stmt->execute();
$receive_res = $stmt->get_result();

if ($receive_res->num_rows === 0) {
    die(__('order_not_found') ?? 'Order not found.');
}
$receive = $receive_res->fetch_object();

// Fetch Purchase Items
$items_query = "SELECT p.*, pr.prod_name
                FROM rpos_purchases p
                JOIN rpos_products pr ON p.prod_id = pr.prod_id
                WHERE p.receive_id = ?";
$stmt2 = $mysqli->prepare($items_query);
$stmt2->bind_param('s', $receive_id);
$stmt2->execute();
$items_res = $stmt2->get_result();
$items = [];
while ($item = $items_res->fetch_object()) {
    $items[] = $item;
}

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

$total_purchase = floatval($receive->purchase_amount);
$total_tax = floatval($receive->tax_amount);
$grand_total = $total_purchase + $total_tax;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo __('purchase_order') ?? 'Purchase Order'; ?> - <?php echo htmlspecialchars($receive->receive_id); ?></title>
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
     <style>
        body { background-color: #f8f9fe; color: #32325d; font-family: 'Open Sans', sans-serif; }
        .container { max-width: 800px; margin: 40px auto; background: #fff; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); border-top: 5px solid #5e72e4; }
        .company-title { font-size: 28px; font-weight: 800; color: #5e72e4; margin-bottom: 5px; text-transform: uppercase; }
        .header { border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 30px; display: flex; flex-wrap: wrap; justify-content: space-between; }
        .header .col-sm-6 { flex: 0 0 49%; max-width: 49%; }
        .order-details { margin-bottom: 20px; }
        .table th { background-color: #f6f9fc; }
        .total-section { border-top: 2px solid #000; padding-top: 10px; }
        @media print {
            body { font-size: 12px; background-color: #fff; }
            .no-print { display: none !important; }
            .container { max-width: 100%; margin: 0; padding: 20px; box-shadow: none; border: none; }
            .header { display: flex !important; flex-wrap: nowrap !important; align-items: flex-start; justify-content: space-between; }
            .header .col-sm-6 { flex: 0 0 49% !important; max-width: 49% !important; float: none !important; display: inline-block !important; vertical-align: top !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body dir="rtl">
    <div class="container mt-4">
        <div class="row no-print mb-2"><div class="col text-right"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?php echo __('print') ?? 'Print'; ?></button><button class="btn btn-secondary ml-2" onclick="window.close()"><?php echo __('close') ?? 'Close'; ?></button></div></div>
        <div class="header">
            <div class="col-sm-6">
                <h2 class="company-title"><?php echo htmlspecialchars($company_name); ?></h2>
                <div><?php echo htmlspecialchars($company_address); ?></div>
                <div><?php echo htmlspecialchars($company_phone); ?></div>
            </div>
            <div class="col-sm-6">
                <h3 style="color: #888; font-weight: 300;"><?php echo __('purchase_order') ?? 'Purchase Order'; ?></h3>
                <div><strong><?php echo __('receive_id') ?? 'Receive ID'; ?>:</strong> <?php echo htmlspecialchars($receive->receive_id); ?></div>
                <div><strong><?php echo __('receive_date') ?? 'Receive Date'; ?>:</strong> <?php echo htmlspecialchars($receive->receive_date); ?></div>
                <div><strong><?php echo __('supplier') ?? 'Supplier'; ?>:</strong> <?php echo htmlspecialchars($receive->supplier); ?></div>
            </div>
        </div>

                <div class="order-details">
                    <div class="row">
                        <div class="col-md-6">
                            <strong><?php echo __('ref_number'); ?>:</strong> <?php echo htmlspecialchars($receive->ref_no); ?><br>
                            <strong><?php echo __('note'); ?>:</strong> <?php echo htmlspecialchars($receive->note); ?>
                        </div>
                    </div>
                </div>

                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th><?php echo __('product'); ?></th>
                            <th><?php echo __('quantity'); ?></th>
                            <th><?php echo __('purchase_price'); ?></th>
                            <th><?php echo __('tax'); ?>%</th>
                            <th><?php echo __('line_total'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): 
                            $line_total = $item->qty * $item->purchase_price;
                            $tax_amount = $line_total * ($item->tax / 100);
                            $total_with_tax = $line_total + $tax_amount;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item->prod_name); ?></td>
                            <td><?php echo htmlspecialchars($item->qty); ?></td>
                            <td><?php echo number_format($item->purchase_price, 2); ?></td>
                            <td><?php echo htmlspecialchars($item->tax); ?>%</td>
                            <td><?php echo number_format($total_with_tax, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="total-section">
                    <div class="row">
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <div class="text-right">
                                <strong><?php echo __('purchase_total'); ?>:</strong> <?php echo number_format($total_purchase, 2); ?><br>
                                <strong><?php echo __('tax_total'); ?>:</strong> <?php echo number_format($total_tax, 2); ?><br>
                                <strong><?php //echo __('grand_total'); ?></strong> <?php // echo number_format($grand_total, 2); ?>
                            </div>
                        </div>
                    </div>
                </div> 
            </div>
        </div>
    </div>
</body>
</html>