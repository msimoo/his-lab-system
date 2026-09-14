<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');
require_once('partials/_head.php');
?>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>

<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
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
                            <?php echo __('paid_orders'); ?>
                        </div>
                        <div class="table-responsive" style="padding: 1.5rem;">
                            <table class="table align-items-center table-flush" id="receiptsTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="text-success" scope="col"><?php echo __('code'); ?></th>
                                        <th scope="col"><?php echo __('customer'); ?></th>
                                        <th class="text-success" scope="col"><?php echo __('product'); ?></th>
                                        <th scope="col"><?php echo __('unit_price'); ?></th>
                                        <th class="text-success" scope="col"><?php echo __('qty'); ?></th>
                                        <th scope="col"><?php echo __('total'); ?></th>
                                        <th class="text-success" scope="col"><?php echo __('date'); ?></th>
                                        <th scope="col"><?php echo __('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = "SELECT * FROM rpos_orders WHERE order_status = 'Paid' ORDER BY `rpos_orders`.`created_at` DESC";
                                    $stmt = $mysqli->prepare($ret);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    while ($order = $res->fetch_object()) {
                                        $total = ($order->prod_price * $order->prod_qty);
                                    ?>
                                        <tr>
                                            <th class="text-success" scope="row"><?php echo $order->order_code; ?></th>
                                            <td><?php echo $order->customer_name; ?></td>
                                            <td class="text-success"><?php echo $order->prod_name; ?></td>
                                            <td>$ <?php echo $order->prod_price; ?></td>
                                            <td class="text-success"><?php echo $order->prod_qty; ?></td>
                                            <td>$ <?php echo $total; ?></td>
                                            <td><?php echo date('d/M/Y g:i', strtotime($order->created_at)); ?></td>
                                            <td>
                                                <a target="_blank" href="print_receipt.php?order_code=<?php echo $order->order_code; ?>">
                                                    <button class="btn btn-sm btn-primary">
                                                        <i class="fas fa-print"></i>
                                                        <?php echo __('print_receipt'); ?>
                                                    </button>
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
            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    
    <?php require_once('partials/_scripts.php'); ?>
     
    <script>
        $(document).ready(function() {
            $('#receiptsTable').DataTable({
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, 100], [10, 25, 50, 100]],
                "scrollX": true,
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
