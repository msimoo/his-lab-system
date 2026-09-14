<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
include('config/stock.php');

check_login();

// lightweight AJAX helper for current stock
if (isset($_GET['get_stock']) && isset($_GET['prod_id'])) {
    // FIX 1: Treat $pid as a string instead of an integer
    $pid = $_GET['prod_id']; 
    
    // FIX 2: Bind as 's' (string) instead of 'i'
    $stmt = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ? LIMIT 1");
    $stmt->bind_param('s', $pid); 
    
    $stmt->execute();
    $stmt->bind_result($stock);
    if ($stmt->fetch()) {
        header('Content-Type: application/json');
        echo json_encode(['stock' => intval($stock)]);
    }
    exit;
}

if (isset($_POST['adjust'])) {
    // expect arrays of prod_id and qty; ref and note are common for all rows
    $pids = isset($_POST['prod_id']) ? (array)$_POST['prod_id'] : [];
    $qtys = isset($_POST['qty']) ? (array)$_POST['qty'] : [];
    $ref  = $_POST['ref_no'];
    $note = $_POST['note'];
    if (empty($pids) || empty($qtys)) {
        $err = "Blank Values Not Accepted";
    } else {
        $successCount = 0;
        foreach ($pids as $i => $pidRaw) {
            // FIX 3: Use trim() instead of intval() to keep the alphanumeric ID
            $pid = trim($pidRaw); 
            $qty = isset($qtys[$i]) ? intval($qtys[$i]) : 0;
            
            // FIX 4: Check if the string is not empty instead of > 0
            if ($pid !== '') { 
                // compute previous stock and apply delta via adjust_stock
                $prevStmt = $mysqli->prepare("SELECT prod_stock FROM rpos_products WHERE prod_id = ?");
                
                // FIX 5: Bind as 's' (string)
                $prevStmt->bind_param('s', $pid); 
                
                $prevStmt->execute();
                $prevStmt->bind_result($prevStock);
                $prevStock = 0;
                if ($prevStmt->fetch()) {
                    $prevStock = intval($prevStock);
                }
                $prevStmt->close();

                $delta = $qty - $prevStock;
                if ($delta !== 0) {
                    // Make sure your adjust_stock() function in config/stock.php 
                    // doesn't also force $pid to be an integer!
                    adjust_stock($mysqli, $pid, $delta, 'adjust', $ref, $note, $_SESSION['admin_id']);
                }
                $successCount++;
            }
        }
        if ($successCount > 0) {
            $success = sprintf(__('stock_adjusted'), $successCount);
        } else {
            $err = __('no_valid_adjustments');
        }
    }
}
require_once('partials/_head.php');
?>
<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>

<style>
/* Professional form styling */
.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
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
.form-control {
    border-radius: 8px;
    border: 1px solid #ced4da;
    transition: all 0.3s ease;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
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
.btn-warning {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
    border: none;
    color: #212529;
}
.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(255,193,7,0.4);
}
.btn-danger {
    background: linear-gradient(135deg, #dc3545 0%, #bd2130 100%);
    border: none;
}
.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220,53,69,0.4);
}
.remove-row {
    transition: all 0.3s ease;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.remove-row:hover {
    transform: scale(1.2) rotate(90deg);
    background: #dc3545;
    color: #ffffff;
    box-shadow: 0 4px 10px rgba(220,53,69,0.3);
}
/* Alert styling */
.alert {
    border-radius: 10px;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
.stock-adjust-table {
    border-collapse: separate;
    border-spacing: 0 0.75rem;
}
.stock-adjust-table thead th {
    background: #f8f9fd;
    color: #344767;
    border-bottom: none;
    padding: 1rem 1rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.stock-adjust-table tbody tr {
    background: #ffffff;
    box-shadow: 0 8px 24px rgba(34, 62, 112, 0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stock-adjust-table tbody tr:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 35px rgba(34, 62, 112, 0.12);
}
.stock-adjust-table td {
    vertical-align: middle;
    border: none;
    padding: 1rem 0.9rem;
}
.stock-adjust-table .currentStock {
    display: inline-flex;
    min-width: 90px;
    justify-content: center;
    padding: 0.55rem 0.7rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-weight: 600;
}
.stock-adjust-table .remove-row {
    min-width: 40px;
    min-height: 40px;
    border-radius: 50%;
    padding: 0;
}
.stock-adjust-table .remove-row i {
    font-size: 0.9rem;
}
.stock-adjust-table .form-control {
    min-height: 46px;
}
.add-row-wrapper {
    display: flex;
    justify-content: flex-end;
}
.add-row-wrapper .btn {
    min-width: 220px;
}
/* Label styling */
label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}
</style>
<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>
    <div class="container-fluid mt--8">
      <div class="row"><div class="col"><div class="card">
            <div class="card-header"><h3 class="mb-0"><?php echo __('adjust_stock'); ?></h3></div>
            <div class="card-body">
              <?php if(isset($success)): ?><div class="alert alert-success"><?php echo $success;?></div><?php endif; ?>
              <?php if(isset($err)): ?><div class="alert alert-danger"><?php echo $err;?></div><?php endif; ?>
              <form method="post">
                <div class="table-responsive">
                  <table class="table table-hover align-items-center stock-adjust-table">
                    <thead>
                      <tr>
                        <th><?php echo __('product'); ?></th>
                        <th class="text-center"><?php echo __('current_stock'); ?></th>
                        <th class="text-center"><?php echo __('new_quantity'); ?></th>
                        <th class="text-center"><?php echo __('action'); ?></th>
                      </tr>
                    </thead>
                    <tbody id="adjustRows">
                      <tr class="adjust-row">
                        <td>
                          <div class="form-group mb-0">
                            <select class="form-control prod-select" name="prod_id[]">
                              <option value=""><?php echo __('select_product'); ?></option>
                              <?php
                              $res = $mysqli->query("SELECT prod_id, prod_name, prod_stock FROM rpos_products WHERE prod_sellable = 1 ORDER BY prod_name");
                              while($p = $res->fetch_assoc()){
                                  $stock = intval($p['prod_stock']);
                                  echo "<option value='{$p['prod_id']}' data-stock='{$stock}'>".htmlspecialchars($p['prod_name'])."</option>";
                              }
                              ?>
                            </select>
                          </div>
                        </td>
                        <td class="text-center align-middle">
                          <span class="currentStock">--</span>
                        </td>
                        <td>
                          <input type="number" name="qty[]" class="form-control qty-input" min="0" placeholder="0">
                        </td>
                        <td class="text-center align-middle">
                          <button type="button" class="btn btn-danger remove-row" title="<?php echo __('remove'); ?>" style="visibility: hidden;"><i class="fas fa-trash"></i></button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="form-row mt-2 add-row-wrapper">
                  <button type="button" id="addRow" class="btn btn-secondary"><?php echo __('add_another_product'); ?></button>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-3">
                    <label><?php echo __('ref_number'); ?></label>
                    <input type="text" name="ref_no" class="form-control">
                  </div>
                  <div class="col-md-9">
                    <label><?php echo __('note'); ?></label>
                    <textarea name="note" class="form-control" rows="3"></textarea>
                  </div>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-6">
                    <input type="submit" name="adjust" class="btn btn-warning" value="<?php echo __('apply'); ?>">
                  </div>
                </div>
              </form>
            </div>
          </div></div></div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      function updateRemoveButtons() {
        var rows = document.querySelectorAll('#adjustRows .adjust-row');
        rows.forEach(function(row, index){
          var removeBtn = row.querySelector('.remove-row');
          if (!removeBtn) return;
          removeBtn.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        });
      }

      function bindRowEvents(row){
        var prodSelect = row.querySelector('.prod-select');
        var qtyInput   = row.querySelector('.qty-input');
        var currentStock = row.querySelector('.currentStock');

        if(prodSelect){
          prodSelect.addEventListener('change', function(){
            var pid = this.value;
            if(!pid){
              qtyInput.value = '';
              currentStock.textContent = '--';
              return;
            }
            var opt = this.options[this.selectedIndex];
            var stock = opt ? opt.dataset.stock : null;
            if(stock !== null && stock !== undefined && stock !== ''){
              stock = parseInt(stock, 10);
              currentStock.textContent = '<?php echo __('current_stock'); ?>: ' + stock;
              qtyInput.value = stock;
              return;
            }
            fetch('stock_adjust.php?get_stock=1&prod_id='+encodeURIComponent(pid))
              .then(function(response){ return response.json(); })
              .then(function(data){
                if(data && typeof data.stock !== 'undefined'){
                  currentStock.textContent = '<?php echo __('current_stock'); ?>: ' + data.stock;
                  qtyInput.value = data.stock;
                }
              })
              .catch(function(){
                currentStock.textContent = 'Unable to load stock';
              });
          });
        }
      }

      var baseRow = document.querySelector('.adjust-row');
      if (baseRow) {
        bindRowEvents(baseRow);
      }

      var addBtn = document.getElementById('addRow');
      if(addBtn){
        addBtn.addEventListener('click', function(){
          var container = document.getElementById('adjustRows');
          var newRow = baseRow.cloneNode(true);
          newRow.querySelector('.prod-select').value = '';
          newRow.querySelector('.qty-input').value = '';
          newRow.querySelector('.currentStock').textContent = '--';
          var removeBtn = newRow.querySelector('.remove-row');
          if(removeBtn){
            removeBtn.style.visibility = 'visible';
          }
          container.appendChild(newRow);
          bindRowEvents(newRow);
          updateRemoveButtons();
        });
      }

      document.addEventListener('click', function(e){
        var btn = e.target.closest('.remove-row');
        if(btn){
          var row = btn.closest('.adjust-row');
          if(row){
            row.remove();
            updateRemoveButtons();
          }
        }
      });

      updateRemoveButtons();
    });
  </script>
</body>
</html>
