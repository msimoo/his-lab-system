<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
require_once('config/languages.php');
require_once('partials/_head.php');

$current_lang = $_SESSION['lang'] ?? 'en';
?>

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap4.min.css"/>

<style>
    /* --- PROFESSIONAL PRINT STYLING --- */
    @media print {
        /* Hide everything except the table card */
        .navbar-vertical, .navbar-top, .header, .btn, .dataTables_length, 
        .dataTables_filter, .dataTables_info, .dataTables_paginate, .card-header form {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            margin-top: 0 !important;
            padding: 0 !important;
        }

        .container-fluid {
            padding: 0 !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
        }

        .table-responsive {
            overflow: visible !important;
        }

        table {
            width: 100% !important;
            border: 1px solid #444 !important;
        }

        th, td {
            border: 1px solid #ddd !important;
            padding: 8px !important;
            font-size: 12pt !important;
            color: #000 !important;
        }

        /* Show a report header only when printing */
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 20px;
        }
    }

    /* Hide print header on the actual web screen */
    .print-header {
        display: none;
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
        </div>

        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        
                        <div class="card-header border-0">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <h3 class="mb-0"><?php echo __('payment_reports'); ?></h3>
                                </div>
                                <div class="col-md-9">
                                    <form method="get" class="form-inline justify-content-end">
                                        <select name="store_id" class="form-control form-control-sm mr-2">
                                            <option value="">All Stores</option>
                                            <?php
                                            $store_id = $_GET['store_id'] ?? $_SESSION['selected_store'] ?? '';
                                            $storeRes = $mysqli->query("SELECT store_id, store_name FROM rpos_stores WHERE is_active=1 ORDER BY store_name");
                                            while ($store = $storeRes->fetch_object()) {
                                                $sel = ($store_id === $store->store_id) ? 'selected' : '';
                                                echo "<option value='" . htmlspecialchars($store->store_id, ENT_QUOTES) . "' $sel>" . htmlspecialchars($store->store_name) . "</option>";
                                            }
                                            ?>
                                        </select>
                                        
                                        <input type="date" name="start_date" class="form-control form-control-sm mr-2" value="<?php echo $_GET['start_date'] ?? ''; ?>">
                                        <input type="date" name="end_date" class="form-control form-control-sm mr-2" value="<?php echo $_GET['end_date'] ?? ''; ?>">
                                        
                                        <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                                        
                                        <button type="button" class="btn btn-sm btn-info ml-2" onclick="openPrintReport()">
                                            <i class="fas fa-print"></i> Print Report
                                        </button>

                                        <a href="payments_reports.php" class="btn btn-sm btn-secondary ml-1"><i class="fas fa-sync"></i></a>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive p-4">
                            
                            <div class="print-header">
                                <h2>Payment Report</h2>
                                <p>Generated on: <?php echo date('d M Y, H:i'); ?></p>
                                <hr>
                            </div>

                            <table id="myDataTable" class="table align-items-center table-flush table-hover table-bordered">
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Code</th>
                                        <th scope="col">Method</th>
                                        <th scope="col">Order ID</th>
                                        <th scope="col">Store</th>
                                        <th scope="col">Amount</th>
                                        <th scope="col">Date Paid</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Secure Dynamic Query
                                    $conditions = [];
                                    $params = [];
                                    $types = "";

                                    if (!empty($store_id)) {
                                        $conditions[] = "p.store_id = ?";
                                        $params[] = $store_id; $types .= "s";
                                    }
                                    if (!empty($_GET['start_date'])) {
                                        $conditions[] = "DATE(p.created_at) >= ?";
                                        $params[] = $_GET['start_date']; $types .= "s";
                                    }
                                    if (!empty($_GET['end_date'])) {
                                        $conditions[] = "DATE(p.created_at) <= ?";
                                        $params[] = $_GET['end_date']; $types .= "s";
                                    }

                                    $query = "SELECT p.*, s.store_name FROM rpos_payments p LEFT JOIN rpos_stores s ON p.store_id = s.store_id";
                                    if (!empty($conditions)) { $query .= " WHERE " . implode(" AND ", $conditions); }
                                    $query .= " ORDER BY p.created_at DESC";

                                    $stmt = $mysqli->prepare($query);
                                    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
                                    $stmt->execute();
                                    $res = $stmt->get_result();

                                    $grandTotal = 0;
                                    while ($payment = $res->fetch_object()) {
                                        $grandTotal += $payment->pay_amt;
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo $payment->pay_code; ?></td>
                                            <td><?php echo $payment->pay_method; ?></td>
                                            <td><?php echo $payment->order_code; ?></td>
                                            <td><?php echo htmlspecialchars($payment->store_name ?? $payment->store_id); ?></td>
                                            <td class="font-weight-bold">$ <?php echo number_format($payment->pay_amt, 2); ?></td>
                                            <td><?php echo date('d/M/Y H:i', strtotime($payment->created_at)); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background-color: #f7fafc;">
                                        <th colspan="4" class="text-right"><strong>Report Total:</strong></th>
                                        <th colspan="2"><strong>$ <?php echo number_format($grandTotal, 2); ?></strong></th>
                                    </tr>
                                </tfoot>
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
        function openPrintReport() {
            const params = new URLSearchParams();
            const storeId = document.querySelector('select[name="store_id"]').value;
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            if (storeId) params.set('store_id', storeId);
            if (startDate) params.set('start_date', startDate);
            if (endDate) params.set('end_date', endDate);
            window.open('print_payments_reports.php?' + params.toString(), '_blank');
        }

        $(document).ready(function() {
            $('#myDataTable').DataTable({
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "pageLength": 10,
                "scrollX": true,
                "language": {
                    "search": "",
                    "searchPlaceholder": "Search filter result..."
                }
            });
        });
    </script>
</body>
</html>