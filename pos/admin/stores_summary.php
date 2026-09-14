<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');
$current_lang = $_SESSION['lang'] ?? 'en';
require_once('partials/_head.php');
?>

<style>
<?php if ($current_lang === 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
.form-control, .btn { text-align: right; }
<?php endif; ?>
</style>
<body <?php echo $current_lang === 'ar' ? 'dir="ltr"' : ''; ?>>
    
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid"><div class="header-body"></div></div>
        </div>
        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0 d-flex justify-content-between align-items-center">
                            <h3><?php echo __('store_summary'); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-items-center table-flush">
                                <thead class="thead-light">
                                    <tr>
                                        <th><?php echo __('store'); ?></th>
                                        <th><?php echo __('store_active'); ?></th>
                                        <th><?php echo __('orders'); ?></th>
                                        <th><?php echo __('revenue'); ?></th>
                                        <th><?php echo __('products'); ?></th>
                                        <th><?php echo __('low_stock_items'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $sql = "SELECT s.store_id, s.store_name, s.is_active,
                                        (SELECT COUNT(*) FROM rpos_orders o WHERE o.store_id = s.store_id) AS order_count,
                                        (SELECT COALESCE(SUM(o.prod_price * o.prod_qty),0) FROM rpos_orders o WHERE o.store_id = s.store_id) AS total_revenue,
                                        (SELECT COUNT(*) FROM rpos_products p WHERE p.store_id = s.store_id) AS product_count,
                                        (SELECT COUNT(*) FROM rpos_products p WHERE p.store_id = s.store_id AND p.prod_stock <= p.reorder_level) AS low_stock_count
                                        FROM rpos_stores s
                                        ORDER BY s.store_name ASC";
                                    $stmt = $mysqli->prepare($sql);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    while ($row = $res->fetch_object()) {
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row->store_name); ?></td>
                                            <td><?php echo $row->is_active ? __('yes') : __('no'); ?></td>
                                            <td><?php echo number_format($row->order_count); ?></td>
                                            <td>$ <?php echo number_format($row->total_revenue, 2); ?></td>
                                            <td><?php echo number_format($row->product_count); ?></td>
                                            <td><?php echo number_format($row->low_stock_count); ?></td>
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
</body>
</html>
