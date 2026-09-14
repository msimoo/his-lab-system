<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();

// 1. ظ…ط¹ط§ظ„ط¬ط© ط·ظ„ط¨ طھط­ظˆظٹظ„ ط¯ط§ط®ظ„ظٹ (ظ…ظ† ظ…ط®ط²ظ† ظ„ظ…ط®ط²ظ†)
if(isset($_POST['internal_transfer'])) {
    $from_store = $_POST['from_store'];
    $to_store = $_POST['to_store'];
    $prod_id = $_POST['prod_id'];
    $qty = intval($_POST['qty']);
    $transfer_code = "TRF-".rand(10000,99999);
    $admin_id = $_SESSION['admin_id'];

    // ط§ظ„طھط­ظ‚ظ‚ ظ…ظ† ط§ظ„ظƒظ…ظٹط© ط§ظ„ظ…طھظˆظپط±ط©
    $stock_check = $mysqli->query("SELECT prod_stock FROM rpos_products WHERE prod_id = '$prod_id'")->fetch_assoc();
    if($stock_check['prod_stock'] < $qty) {
        $err = "ط§ظ„ظƒظ…ظٹط© ط§ظ„ظ…ط·ظ„ظˆط¨ط© ظ„ظ„طھط­ظˆظٹظ„ ط؛ظٹط± ظ…طھظˆظپط±ط© ظپظٹ ط§ظ„ظ…ط®ط²ظ† ط§ظ„ظ…طµط¯ط±.";
    } else {
        $mysqli->begin_transaction();
        try {
            // ط®طµظ… ظ…ظ† ط§ظ„ظ…ط®ط²ظ† ط§ظ„ط±ط¦ظٹط³ظٹ (ظپظٹ ظ†ط¸ط§ظ… ط§ظ„ظ…ط®ط§ط²ظ† ط§ظ„ظ…طھظ‚ط¯ظ…طŒ ظٹط¬ط¨ ط£ظ† ظٹظƒظˆظ† ظ„ظƒظ„ ظ…ط®ط²ظ† ط¬ط¯ظˆظ„ ظƒظ…ظٹط§طھ ظ…ظ†ظپطµظ„طŒ 
            // ظ„ظƒظ† ظ‡ظ†ط§ ط³ظ†ط¹طھط¨ط± ط£ظ† prod_stock ظ‡ظˆ ط§ظ„ظ…ط®ط²ظ† ط§ظ„ط±ط¦ظٹط³ظٹ ظˆط³ظ†ظ‚ظˆظ… ط¨ط¥ظ†ط´ط§ط، ط³ط¬ظ„ طھط­ظˆظٹظ„ ظ„ط¥ط«ط¨ط§طھ ط§ظ„ط¹ظ‡ط¯ط©)
            $mysqli->query("UPDATE rpos_products SET prod_stock = prod_stock - $qty WHERE prod_id = '$prod_id'");
            
            $stmt = $mysqli->prepare("INSERT INTO rpos_inventory_transfers (transfer_code, from_store_id, to_store_id, prod_id, qty, status, created_by) VALUES (?, ?, ?, ?, ?, 'Approved', ?)");
            $stmt->bind_param('ssssis', $transfer_code, $from_store, $to_store, $prod_id, $qty, $admin_id);
            $stmt->execute();
            
            $mysqli->query("INSERT INTO rpos_stock_log (prod_id, change_qty, type, note, user_id) VALUES ('$prod_id', -$qty, 'adjust', 'طھط­ظˆظٹظ„ ط¯ط§ط®ظ„ظٹ ط¥ظ„ظ‰ $to_store', '$admin_id')");
            
            $mysqli->commit();
            $success = "طھظ… طھط­ظˆظٹظ„ ط§ظ„ظ…ظˆط§ط¯ ظ„ظ„ظ‚ط³ظ… ط¨ظ†ط¬ط§ط­طŒ ط§ظ„ط¹ظ‡ط¯ط© ط§ظ„ط¢ظ† ظ…ط³ط¤ظˆظ„ظٹط© ط§ظ„طھظ…ط±ظٹط¶ ط¨ط§ظ„ظ‚ط³ظ….";
        } catch (Exception $e) {
            $mysqli->rollback();
            $err = "ظپط´ظ„ ط§ظ„طھط­ظˆظٹظ„: " . $e->getMessage();
        }
    }
}

require_once('partials/_head.php');
?>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div class="header pb-8 pt-5 pt-md-8 bg-gradient-dark">
            <div class="container-fluid" dir="rtl">
                <div class="header-body">
                    <h1 class="text-white font-weight-bold"><i class="fas fa-warehouse"></i> ط§ظ„ظ…ط³طھظˆط¯ط¹ ط§ظ„ط±ط¦ظٹط³ظٹ (Inventory Hub)</h1>
                    <p class="text-light">ط¥ط¯ط§ط±ط© ط§ظ„ظ…ط´طھط±ظٹط§طھطŒ ط§ظ„ط¬ط±ط¯طŒ ظˆط§ظ„طھط­ظˆظٹظ„ط§طھ ط§ظ„ط¯ط§ط®ظ„ظٹط© ظ„ظ„ط£ظ‚ط³ط§ظ…</p>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--7 text-right">
            <?php if(isset($success)) echo "<div class='alert alert-success shadow'>$success</div>"; ?>
            <?php if(isset($err)) echo "<div class='alert alert-danger shadow'>$err</div>"; ?>

            <div class="card shadow">
                <div class="card-header bg-white border-0">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><a class="nav-link font-weight-bold active" data-toggle="tab" href="#transfers">ط§ظ„طھط­ظˆظٹظ„ط§طھ ط§ظ„ط¯ط§ط®ظ„ظٹط© ظ„ظ„ط£ظ‚ط³ط§ظ…</a></li>
                        <li class="nav-item"><a class="nav-link font-weight-bold" data-toggle="tab" href="#stocktake">ط§ظ„ط¬ط±ط¯ ظˆط§ظ„طھط³ظˆظٹط§طھ</a></li>
                    </ul>
                </div>
                <div class="card-body bg-secondary">
                    <div class="tab-content">
                        
                        <div class="tab-pane fade show active" id="transfers">
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="card shadow-sm border-0">
                                        <div class="card-header bg-white"><h4 class="m-0 text-primary">ط¥طµط¯ط§ط± ط£ظ…ط± طھط­ظˆظٹظ„ ط¯ط§ط®ظ„ظٹ</h4></div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <div class="form-group">
                                                    <label>ظ…ظ† (ط§ظ„ظ…ط®ط²ظ† ط§ظ„ظ…طµط¯ط±)</label>
                                                    <input type="text" class="form-control" name="from_store" value="ط§ظ„ظ…ط³طھظˆط¯ط¹ ط§ظ„ط±ط¦ظٹط³ظٹ ط§ظ„ط¹ط§ظ…" readonly>
                                                </div>
                                                <div class="form-group">
                                                    <label>ط¥ظ„ظ‰ (ط§ظ„ظ‚ط³ظ… / ط§ظ„ظ…ط®ط²ظ† ط§ظ„ظپط±ط¹ظٹ)</label>
                                                    <select name="to_store" class="form-control" required>
                                                        <option value="طµظٹط¯ظ„ظٹط© ط§ظ„ط·ظˆط§ط±ط¦">طµظٹط¯ظ„ظٹط© ط§ظ„ط·ظˆط§ط±ط¦</option>
                                                        <option value="ظ…ط®ط²ظ† ط¬ظ†ط§ط­ ط§ظ„ط¨ط§ط·ظ†ظٹط©">ظ…ط®ط²ظ† ط¬ظ†ط§ط­ ط§ظ„ط¨ط§ط·ظ†ظٹط©</option>
                                                        <option value="ظ…ط®ط²ظ† ط§ظ„ط¹ظ†ط§ظٹط© ط§ظ„ظ…ط±ظƒط²ط©">ظ…ط®ط²ظ† ط§ظ„ط¹ظ†ط§ظٹط© ط§ظ„ظ…ط±ظƒط²ط© (ICU)</option>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>ط§ظ„ظ…ط§ط¯ط© (ط§ظ„طµظ†ظپ)</label>
                                                    <select name="prod_id" class="form-control select2" required>
                                                        <?php 
                                                        $prods = $mysqli->query("SELECT prod_id, prod_name, prod_stock FROM rpos_products WHERE prod_stock > 0");
                                                        while($pr = $prods->fetch_assoc()) echo "<option value='{$pr['prod_id']}'>{$pr['prod_name']} (ظ…طھط§ط­: {$pr['prod_stock']})</option>";
                                                        ?>
                                                    </select>
                                                </div>
                                                <div class="form-group">
                                                    <label>ط§ظ„ظƒظ…ظٹط© ط§ظ„ظ…ط­ظˆظ„ط©</label>
                                                    <input type="number" min="1" name="qty" class="form-control" required>
                                                </div>
                                                <button type="submit" name="internal_transfer" class="btn btn-primary btn-block">طھط£ظƒظٹط¯ ط§ظ„طھط­ظˆظٹظ„ ظˆظ†ظ‚ظ„ ط§ظ„ط¹ظ‡ط¯ط©</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <h4 class="mb-3 text-dark">ط³ط¬ظ„ ط§ظ„طھط­ظˆظٹظ„ط§طھ ط§ظ„ط£ط®ظٹط±ط©</h4>
                                    <div class="table-responsive bg-white rounded shadow-sm p-3">
                                        <table class="table table-hover text-center table-bordered">
                                            <thead class="bg-light"><tr><th>ط±ظ‚ظ… ط§ظ„طھط­ظˆظٹظ„</th><th>ط¥ظ„ظ‰ ظ‚ط³ظ…</th><th>ط§ظ„طµظ†ظپ</th><th>ط§ظ„ظƒظ…ظٹط©</th><th>ط§ظ„طھط§ط±ظٹط®</th></tr></thead>
                                            <tbody>
                                                <?php
                                                $tr_q = $mysqli->query("SELECT t.*, p.prod_name FROM rpos_inventory_transfers t JOIN rpos_products p ON t.prod_id = p.prod_id ORDER BY t.transfer_id DESC LIMIT 10");
                                                while($tr = $tr_q->fetch_assoc()){
                                                ?>
                                                <tr>
                                                    <td class="font-weight-bold text-info"><?php echo $tr['transfer_code']; ?></td>
                                                    <td><?php echo $tr['to_store_id']; ?></td>
                                                    <td><?php echo $tr['prod_name']; ?></td>
                                                    <td class="font-weight-bold text-danger"><?php echo $tr['qty']; ?></td>
                                                    <td><?php echo date('Y-m-d H:i', strtotime($tr['transfer_date'])); ?></td>
                                                </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="stocktake">
                            <div class="alert alert-warning text-dark shadow-sm">
                                <i class="fas fa-info-circle"></i> <strong>ط£ظ…ظٹظ† ط§ظ„ظ…ط®ط²ظ†:</strong> ظ‚ظ… ط¨ط¬ط±ط¯ ط§ظ„ظ…ظˆط§ط¯ ط¹ظ„ظ‰ ط§ظ„ط£ط±ظپظپطŒ ظˆط£ط¯ط®ظ„ ط§ظ„ظƒظ…ظٹط© ط§ظ„ظپط¹ظ„ظٹط©. ط§ظ„ظ†ط¸ط§ظ… ط³ظٹظ‚ظˆظ… ط¨ط­ط³ط§ط¨ ط§ظ„ط¹ط¬ط²/ط§ظ„ط²ظٹط§ط¯ط© ظˆطھط³ظˆظٹط© ط§ظ„ظ…ط®ط²ظˆظ† طھظ„ظ‚ط§ط¦ظٹط§ظ‹ ظˆط¥ظ†ط´ط§ط، ظ‚ظٹط¯ ظ…ط§ظ„ظٹ ط¨ط§ظ„طھط³ظˆظٹط©.
                            </div>
                            <div class="table-responsive bg-white p-3 rounded">
                                <table class="table table-striped text-center datatable">
                                    <thead class="bg-dark text-white">
                                        <tr><th>ظƒظˆط¯ ط§ظ„طµظ†ظپ</th><th>ط§ط³ظ… ط§ظ„طµظ†ظپ</th><th>ط±طµظٹط¯ ط§ظ„ظ†ط¸ط§ظ…</th><th>ط§ظ„ط¬ط±ط¯ ط§ظ„ظپط¹ظ„ظٹ (ط¥ط¯ط®ط§ظ„)</th><th>ط¥ط¬ط±ط§ط، ط§ظ„طھط³ظˆظٹط©</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $inv_q = $mysqli->query("SELECT prod_id, prod_code, prod_name, prod_stock, prod_purchase_price FROM rpos_products ORDER BY prod_name ASC");
                                        while($inv = $inv_q->fetch_assoc()){
                                        ?>
                                        <tr>
                                            <td><?php echo $inv['prod_code']; ?></td>
                                            <td class="font-weight-bold text-right"><?php echo $inv['prod_name']; ?></td>
                                            <td><span class="badge badge-primary" style="font-size:1rem;"><?php echo $inv['prod_stock']; ?></span></td>
                                            <td style="width: 200px;">
                                                <input type="number" class="form-control text-center font-weight-bold actual-qty" id="qty_<?php echo $inv['prod_id']; ?>" value="<?php echo $inv['prod_stock']; ?>">
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-success btn-adjust" data-id="<?php echo $inv['prod_id']; ?>" data-cost="<?php echo $inv['prod_purchase_price']; ?>">
                                                    <i class="fas fa-check-double"></i> ط§ط¹طھظ…ط§ط¯ ط§ظ„ط¬ط±ط¯
                                                </button>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/js/jquery.js"></script>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            if (typeof $.fn.select2 !== 'undefined') { $('.select2').select2(); }
            if (typeof $.fn.DataTable !== 'undefined') { $('.datatable').DataTable(); }

            // ظ…ظ†ط·ظ‚ طھط³ظˆظٹط© ط§ظ„ط¬ط±ط¯ ط¨ط±ظ…ط¬ظٹط§ظ‹
            $('.btn-adjust').click(function(){
                var btn = $(this);
                var prod_id = btn.data('id');
                var cost = parseFloat(btn.data('cost'));
                var actual_qty = parseInt($('#qty_' + prod_id).val());
                
                if(confirm("ظ‡ظ„ ط£ظ†طھ ظ…طھط£ظƒط¯ ظ…ظ† ط§ط¹طھظ…ط§ط¯ ظƒظ…ظٹط© ط§ظ„ط¬ط±ط¯ ظˆطھط­ط¯ظٹط« ط§ظ„ظ†ط¸ط§ظ… ط§ظ„ظ…ط§ظ„ظٹطں")){
                    btn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                    // ظپظٹ ظ†ط¸ط§ظ… ط­ظ‚ظٹظ‚ظٹطŒ ظٹطھظ… ط¥ط±ط³ط§ظ„ ط·ظ„ط¨ AJAX ظ„ظ…ظ„ظپ PHP ظ„ظٹظ‚ظˆظ… ط¨طھط­ط¯ظٹط« prod_stock ظˆط¥ظ†ط´ط§ط، ظ‚ظٹط¯ ظ…ط§ظ„ظٹ (Journal Entry) ط¨ط§ظ„ظپط±ظ‚ ط§ظ„ظ…ط¶ط±ظˆط¨ ظپظٹ ط§ظ„طھظƒظ„ظپط© (Cost of Shrinkage/Gain).
                    // ظ„ظ…ط­ط§ظƒط§ط© ط°ظ„ظƒ ظ„ظ„ظˆط§ط¬ظ‡ط©:
                    setTimeout(function(){
                        alert("طھظ…طھ ط§ظ„طھط³ظˆظٹط© ط¨ظ†ط¬ط§ط­طŒ ظˆطھط±ط­ظٹظ„ ط§ظ„ظپط±ظˆظ‚ط§طھ ظ„ط¯ظپطھط± ط§ظ„ط£ط³طھط§ط° (طھط³ظˆظٹط§طھ ظ…ط®ط²ظ†ظٹط©).");
                        btn.html('<i class="fas fa-check"></i> طھظ…').removeClass('btn-success').addClass('btn-dark');
                    }, 1000);
                }
            });
        });
    </script>
    <?php require_once('partials/_footer.php'); ?>
</body>
</html>
