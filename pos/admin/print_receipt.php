<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Pharmacy Point Of Sale</title>
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/icons/favicon-16x16.png">
    <link href="assets/css/bootstrap.css" rel="stylesheet" id="bootstrap-css">
    <script src="assets/js/jquery.js"></script>
    <style>
        body { margin-top: 20px; }
        
        /* Thermal Printer specific styles */
        #Receipt {
            max-width: 85mm; /* Preview width */
            margin: 0 auto;
            background: #fff;
            padding: 5mm;
        }

        @media print {
            @page {
                size: 85mm 45mm; /* Sets exact dimensions requested */
                margin: 0;
            }
            body, html {
                margin: 0;
                padding: 0;
                width: 85mm;
                font-family: 'Courier New', Courier, monospace; /* Best for thermal printers */
            }
            #Receipt {
                width: 85mm !important;
                max-width: 85mm !important;
                margin: 0;
                padding: 2mm;
                border: none;
                box-shadow: none;
            }
            .table {
                width: 100%;
                margin-bottom: 0;
            }
            h6 { font-size: 14px; font-weight: bold; margin-bottom: 5px; }
            p, address, th, td {
                font-size: 11px !important;
                margin-bottom: 2px;
                line-height: 1.2;
            }
            .table-bordered th, .table-bordered td {
                padding: 3px !important;
            }
            #print {
                display: none !important;
            }
            /* Hide unnecessary UI elements */
            .container, .row { margin: 0; padding: 0; width: 100%; }
        }
    </style>
</head>

<?php
$order_code = $_GET['order_code'] ?? '';
$items = [];
$grandTotal = 0;
$created_at = '';
$customer_name = '';

if ($order_code !== '') {
    $ret = "SELECT * FROM rpos_orders WHERE order_code = ?";
    $stmt = $mysqli->prepare($ret);
    $stmt->bind_param('s', $order_code);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($order = $res->fetch_object()) {
        $items[] = $order;
        $grandTotal += ($order->prod_price * $order->prod_qty);
        $created_at = $order->created_at;
        $customer_name = $order->customer_name;
    }
    $stmt->close();
}

$tax_rate = floatval(getSetting('tax_rate', '0'));
$disc_rate = floatval(getSetting('discount_rate', '0'));
$tax_amt = $grandTotal * $tax_rate / 100;
$disc_amt = $grandTotal * $disc_rate / 100;
$finalTotal = $grandTotal + $tax_amt - $disc_amt;
?>

<body <?php echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
    <div class="container">
        <div id="Receipt">
            <div class="text-center">
                <strong>Pharmacy</strong><br>
                <?php echo __('point_of_sale'); ?><br>
                Khartoum, Khartoum, Sudan<br>
                (+249) 990233204
            </div>
            <hr style="border-top: 1px dashed #000; margin: 5px 0;">
            
            <div>
                <em><?php echo __('date'); ?>: <?php echo date('d/M/y g:i', strtotime($created_at)); ?></em><br>
                <em><?php echo __('print_receipt'); ?> #: <?php echo htmlspecialchars($order_code); ?></em><br>
                <?php if (!empty($customer_name)) { ?>
                    <strong><?php echo __('customer'); ?>: <?php echo htmlspecialchars($customer_name); ?></strong>
                <?php } ?>
            </div>
            
            <hr style="border-top: 1px dashed #000; margin: 5px 0;">
            <div class="text-center"><h6><?php echo __('receipt'); ?></h6></div>
            
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th><?php echo __('product_name'); ?></th>
                        <th class="text-center"><?php echo __('qty'); ?></th>
                        <th class="text-center"><?php echo __('total'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) {
                        $line = $item->prod_price * $item->prod_qty;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item->prod_name); ?> <br> <small>@ <?php echo number_format($item->prod_price, 2); ?></small></td>
                        <td class="text-center"><?php echo intval($item->prod_qty); ?></td>
                        <td class="text-right"><?php echo number_format($line, 2); ?></td>
                    </tr>
                    <?php } ?>
                    <!-- <tr>
                        <td colspan="2" class="text-right"><strong><?php echo __('subtotal'); ?>:</strong></td>
                        <td class="text-right"><?php echo number_format($grandTotal, 2); ?></td>
                    </tr>
                   <tr>
                        <td colspan="2" class="text-right"><strong><?php echo __('tax'); ?> (<?php echo $tax_rate; ?>%):</strong></td>
                        <td class="text-right"><?php echo number_format($tax_amt, 2); ?></td>
                    </tr>
                    <tr>
                        <td colspan="2" class="text-right"><strong><?php echo __('discount'); ?> (<?php echo $disc_rate; ?>%):</strong></td>
                        <td class="text-right">-<?php echo number_format($disc_amt, 2); ?></td>
                    </tr>-->
                    <tr>
                        <td colspan="2" class="text-right"><strong><?php echo __('total'); ?>:</strong></td>
                        <td class="text-right"><strong><?php echo number_format($finalTotal, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <hr style="border-top: 1px dashed #000; margin: 5px 0;">
            <div class="text-center">
                <small>Thank you for your visit!</small>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-md-12 text-center">
                <button id="print" onclick="printContent('Receipt');" class="btn btn-success btn-lg">
                    <?php echo __('print_receipt'); ?> <span class="fas fa-print"></span>
                </button>
            </div>
        </div>
    </div>

    <script>
        function printContent(el) {
            var restorepage = $('body').html();
            var printcontent = $('#' + el).clone();
            $('body').empty().html(printcontent);
            window.print();
            $('body').html(restorepage);
        }
    </script>
</body>
</html>
