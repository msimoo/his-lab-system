<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');
$current_lang = $_SESSION['lang'] ?? 'en';
require_once('partials/_head.php');
?>
<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>

<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
    <!-- Sidenav -->
    <?php
    require_once('partials/_sidebar.php');
    ?>
    <!-- Main content -->
    <div class="main-content">
        <!-- Top navbar -->
        <?php
        require_once('partials/_topnav.php');
        ?>
        <!-- Header -->
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
        <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                </div>
            </div>
        </div>
        <!-- Page content -->
        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        
                        <div class="card-header border-0">
                            <div class="row align-items-center">
                                 <span><?php echo __('orders_records'); ?></span>
                            <div class="col-md-9">
                                <form method="GET" class="form-inline d-inline-block" id="filterForm">
                                    <select name="store_id" class="form-control form-control-sm mr-2">
                                        <option value=""><?php echo __('all_stores'); ?></option>
                                    <?php
                                    $store_id = $_GET['store_id'] ?? $_SESSION['selected_store'] ?? '';
                                    $start_date = $_GET['start_date'] ?? '';
                                    $end_date = $_GET['end_date'] ?? '';
                                    $storeRes = $mysqli->query("SELECT store_id, store_name FROM rpos_stores WHERE is_active=1 ORDER BY store_name");
                                    while ($store = $storeRes->fetch_object()) {
                                        $selected = ($store_id === $store->store_id) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($store->store_id, ENT_QUOTES) . "' $selected>" . htmlspecialchars($store->store_name) . "</option>";
                                    }
                                    ?>
                                </select>
                                <input type="date" name="start_date" class="form-control form-control-sm mr-2" value="<?php echo htmlspecialchars($start_date); ?>">
                                <input type="date" name="end_date" class="form-control form-control-sm mr-2" value="<?php echo htmlspecialchars($end_date); ?>">
                                <button class="btn btn-sm btn-primary" type="submit"><?php echo __('filter'); ?></button>
                                
                                <button type="button" class="btn btn-sm btn-info mr-2" onclick="openPrintOrdersReport();">
                                    <i class="fas fa-print"></i> <?php echo __('print_report'); ?>
                                </button>
                            </form>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="myDataTable" class="table align-items-center table-flush">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="text-success" scope="col"><?php echo __('code'); ?></th>
                                        <th scope="col"><?php echo __('customer'); ?></th> 
                                        <th class="text-success" scope="col"><?php echo __('product'); ?></th>
                                        <th scope="col"><?php echo __('unit_price'); ?></th>
                                        <th class="text-success" scope="col"><?php echo __('qty'); ?></th>
                                        <th scope="col"><?php echo __('total'); ?></th>
                                        <th scop="col"><?php echo __('status'); ?></th>
                                        <th scope="col"><?php echo __('date'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $store_id = $_GET['store_id'] ?? '';
                                    $start_date = $_GET['start_date'] ?? '';
                                    $end_date = $_GET['end_date'] ?? '';

                                    $conditions = [];
                                    $params = [];
                                    $types = '';

                                    if (!empty($store_id)) {
                                        $conditions[] = 'o.store_id = ?';
                                        $params[] = $store_id;
                                        $types .= 's';
                                    }
                                    if (!empty($start_date)) {
                                        $conditions[] = 'DATE(o.created_at) >= ?';
                                        $params[] = $start_date;
                                        $types .= 's';
                                    }
                                    if (!empty($end_date)) {
                                        $conditions[] = 'DATE(o.created_at) <= ?';
                                        $params[] = $end_date;
                                        $types .= 's';
                                    }

                                    $whereClause = '';
                                    if (!empty($conditions)) {
                                        $whereClause = ' WHERE ' . implode(' AND ', $conditions);
                                    }

                                    $ret = "SELECT o.*, s.store_name FROM rpos_orders o LEFT JOIN rpos_stores s ON o.store_id = s.store_id" . $whereClause . " ORDER BY o.created_at DESC";
                                    $stmt = $mysqli->prepare($ret);
                                    if (!empty($params)) {
                                        $stmt->bind_param($types, ...$params);
                                    }
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
                                            <td><?php if ($order->order_status == '') {
                                                    echo "<span class='badge badge-danger'>Not Paid</span>";
                                                } else {
                                                    echo "<span class='badge badge-success'>$order->order_status</span>";
                                                } ?></td>
                                            <td><?php echo date('d/M/Y g:i', strtotime($order->created_at)); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Footer -->
            <?php
            require_once('partials/_footer.php');
            ?>
        </div>
    </div>
    <!-- Argon Scripts -->
    <?php
    require_once('partials/_scripts.php');
    ?>
    <script>
        function openPrintOrdersReport() {
            const params = new URLSearchParams();
            const storeId = document.querySelector('select[name="store_id"]').value;
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            if (storeId) params.set('store_id', storeId);
            if (startDate) params.set('start_date', startDate);
            if (endDate) params.set('end_date', endDate);
            window.open('print_order_report.php?' + params.toString(), '_blank');
        }
        

        $(document).ready(function() {
            $('#myDataTable').DataTable({
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
