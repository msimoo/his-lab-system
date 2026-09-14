<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');
include('config/stock.php');
include('config/languages.php');

check_login();
$unitOptions = [];
if ($unitRes = @$mysqli->query("SELECT unit_name FROM units ORDER BY unit_name")) {
    while ($unitRow = $unitRes->fetch_assoc()) {
        $unitOptions[] = trim($unitRow['unit_name']);
    }
}
if (empty($unitOptions)) {
    $unitOptions = ['pcs', 'tablet', 'capsule', 'bottle', 'ml'];
}
// prepare receive id for the form (random hex string)
$receive_id = bin2hex(random_bytes(5));
$receive_date = date('Y-m-d\TH:i');
if (isset($_POST['receive'])) {
    $errors = [];
    $products = $_POST['products'] ?? [];
    $supplier_id = intval($_POST['supplier'] ?? 0);
    $ref_no = trim($_POST['ref_no'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $receive_id = trim($_POST['receive_id'] ?? $receive_id);
    $receive_date = trim($_POST['receive_date'] ?? $receive_date);
    $supplier_name = '';

    if (empty($supplier_id)) {
        $err = __('supplier_required');
    } elseif (empty($products)) {
        $err = __('no_products');
    } else {
        // resolve selected supplier name from supplier_id
        $supplierQ = $mysqli->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ? LIMIT 1");
        $supplierQ->bind_param('i', $supplier_id);
        $supplierQ->execute();
        $supplierQ->bind_result($supplier_name);
        if (!$supplierQ->fetch()) {
            $supplierQ->close();
            $err = __('supplier_not_found');
        } else {
            $supplierQ->close();
        }
    }

    if (!isset($err)) {
        // normalize receive date for DB storage
        $receive_date_db = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $receive_date)));

        $valid_products = [];
        foreach ($products as $index => $prod) {
            $pid = trim($prod['prod_id'] ?? '');
            $new_name = trim($prod['prod_name'] ?? '');
            $qty = intval($prod['qty'] ?? 0);
            $purchase_price = floatval($prod['purchase_price'] ?? 0);
            $tax = 0; // Tax system disabled - converted to comment
            $sell_price = isset($prod['sell_price']) && strlen(trim($prod['sell_price'])) ? floatval($prod['sell_price']) : null;
            $category_id = intval($prod['category_id'] ?? 0);
            $prod_expiry = trim($prod['prod_expiry'] ?? '');

            if (($pid === '' || $pid === 'new') && $new_name !== '') {
                // create new product if not found
                do {
                    $new_pid = bin2hex(random_bytes(5));
                    $pidCheck = $mysqli->prepare("SELECT 1 FROM rpos_products WHERE prod_id = ? LIMIT 1");
                    $pidCheck->bind_param('s', $new_pid);
                    $pidCheck->execute();
                    $pidCheck->store_result();
                    $pidExists = $pidCheck->num_rows > 0;
                    $pidCheck->close();
                } while ($pidExists);

                $new_code = generateUniqueProductCode($mysqli);
                $new_sku = trim($prod['prod_sku'] ?? '');
                $new_stock = 0;
                $new_batch = '';
                $new_expiry = $prod_expiry ?: null;
                $new_variant = '';
                $new_unit = trim($prod['prod_unit'] ?? 'pcs');
                $new_sellable = 1;
                $new_img = '';
                $new_desc = $new_name;
                $new_price = $sell_price !== null ? $sell_price : $purchase_price;

                $insertProductStmt = $mysqli->prepare("INSERT INTO rpos_products (prod_id, prod_code, prod_name, prod_sku, prod_stock, prod_batch, prod_expiry, prod_variant, prod_unit, prod_sellable, category_id, prod_img, prod_desc, prod_price) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                if ($insertProductStmt) {
                    $insertProductStmt->bind_param('ssssissssiisss', $new_pid, $new_code, $new_name, $new_sku, $new_stock, $new_batch, $new_expiry, $new_variant, $new_unit, $new_sellable, $category_id, $new_img, $new_desc, $new_price);
                    $insertProductStmt->execute();
                    $insertProductStmt->close();
                } else {
                    $errors[] = __('product_create_failed') . " " . ($index + 1);
                    continue;
                }

                $pid = $new_pid;
            }

            if (empty($pid) || $qty <= 0 || $purchase_price <= 0) {
                $errors[] = __('invalid_data') . " " . ($index + 1);
                continue;
            }

            // verify product exists
            $chk = $mysqli->prepare("SELECT prod_id FROM rpos_products WHERE prod_id = ? LIMIT 1");
            $chk->bind_param('s', $pid);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows === 0) {
                $errors[] = __('product_not_exist') . " " . ($index + 1);
                continue;
            }

            if ($prod_expiry !== '') {
                $expiryStmt = $mysqli->prepare("UPDATE rpos_products SET prod_expiry = ? WHERE prod_id = ? LIMIT 1");
                if ($expiryStmt) {
                    $expiryStmt->bind_param('ss', $prod_expiry, $pid);
                    $expiryStmt->execute();
                    $expiryStmt->close();
                }
            }

            $valid_products[] = [
                'pid' => $pid,
                'qty' => $qty,
                'purchase_price' => $purchase_price,
                'tax' => $tax,
                'sell_price' => $sell_price,
                'category_id' => $category_id,
                'expiry' => $prod_expiry,
            ];
        }

        if (empty($errors)) {
            // create receive record (purchase order header)
            $stmt = $mysqli->prepare("INSERT INTO rpos_receives (receive_id, supplier_id, supplier, ref_no, note, receive_date, created_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('sissssi', $receive_id, $supplier_id, $supplier_name, $ref_no, $note, $receive_date_db, $_SESSION['admin_id']);
            $stmt->execute();
            $stmt->close();

            foreach ($valid_products as $prod) {
                $purchase_id = $receive_id . '-' . bin2hex(random_bytes(4));
                $stmt = $mysqli->prepare("INSERT INTO rpos_purchases (purchase_id, receive_id, prod_id, qty, purchase_price, tax, supplier, sell_price, ref_id, purchase_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssiddsdss', $purchase_id, $receive_id, $prod['pid'], $prod['qty'], $prod['purchase_price'], $tax, $supplier_name, $prod['sell_price'], $ref_no, $receive_date_db); // Tax set to 0 as system disabled
                $stmt->execute();
                $stmt->close();
            }

            $success = __('purchase_order_created') ?: 'Purchase order created successfully. Please confirm receipt in the confirmation page.';
        } else {
            $err = implode('<br>', $errors);
        }
    }
}
require_once('partials/_head.php');
?>
<style>
<?php if ($current_lang == 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
.form-control, .btn { text-align: right; }
<?php endif; ?>

/* Professional table styling */
#productsTable {
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-radius: 12px;
    overflow: hidden;
    border: none;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}
#productsTable thead th {
    vertical-align: middle;
    font-weight: 600;
    font-size: 0.85em;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: linear-gradient(135deg, #343a40 0%, #495057 100%);
    color: #ffffff;
    border: none;
    padding: 1rem 0.5rem;
    position: relative;
}
#productsTable thead th::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(90deg, #007bff, #28a745, #ffc107);
}
#productsTable tbody td {
    vertical-align: middle;
    padding: 0.75rem 0.5rem;
    border: none;
    border-bottom: 1px solid #dee2e6;
    transition: all 0.3s ease;
}
#productsTable tbody tr {
    transition: all 0.3s ease;
}
#productsTable tbody tr:hover {
    background: rgba(0,123,255,0.05);
    transform: scale(1.01);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
#productsTable tbody tr:nth-child(even) {
    background: rgba(248,249,250,0.5);
}
#productsTable .form-control {
    border: 1px solid #ced4da;
    border-radius: 6px;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}
#productsTable .form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
    transform: scale(1.02);
}
#productsTable select.form-control {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 1.5em 1.5em;
    padding-right: 2.5rem;
}
.remove-row {
    transition: all 0.3s ease;
    border-radius: 50%;
    width: 20px;
    height: 20px;
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
.btn-success {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
    border: none;
}
.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(40,167,69,0.4);
}
.btn-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    border: none;
}
.btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(108,117,125,0.4);
}
.btn-danger {
    background: linear-gradient(135deg, #dc3545 0%, #bd2130 100%);
    border: none;
}
.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220,53,69,0.4);
}

/* Alert styling */
.alert {
    border-radius: 10px;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

/* Label styling */
label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

/* Additional professional styling */
.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%) !important;
}
</style>
<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-6"></span>
      <div class="container-fluid">
        <div class="header-body">
          <div class="row justify-content-center">
            <div class="col-lg-10 col-md-12">
              <h1 class="display-3 text-white text-center">Purchase Order Management</h1>
              <p class="text-white text-center mt-3">Efficiently manage your stock receipts with our advanced system</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="container-fluid mt--7">
      <div class="row justify-content-center">
        <div class="col-xl-12">
          <div class="card shadow-lg border-0">
            <div class="card-header bg-gradient-primary text-white">
              <h3 class="mb-0"><i class="fas fa-shopping-cart mr-2"></i><?php echo __('purchase_order'); ?></h3>
              
                    <a class="btn btn-sm btn-dark mr-2" href="stock_receive_confirm.php">
                        <?php echo __('confirm_receipt'); ?>
                    </a>
            </div>
            <div class="card-body">
              <?php if(isset($success)): ?><div class="alert alert-success"><?php echo $success;?></div><?php endif; ?>
              <?php if(isset($err)): ?><div class="alert alert-danger"><?php echo $err;?></div><?php endif; ?>
              <form method="post" id="receiveForm">
                 
                <div class="form-row mb-3">
                  <div class="col-md-2">
                    <label><?php echo __('receive_id'); ?></label>
                    <input type="text" name="receive_id" id="receiveId" class="form-control" readonly value="<?php echo htmlspecialchars($receive_id); ?>">
                  </div>
                  <div class="col-md-2">
                    <label><?php echo __('receive_date'); ?></label>
                    <input type="datetime-local" name="receive_date" id="receiveDate" class="form-control" value="<?php echo htmlspecialchars($receive_date); ?>">
                  </div>
                  <div class="col-md-2">
                    <label><?php echo __('expiry_date'); ?></label>
                    <input type="date" id="sameExpiryDate" class="form-control" placeholder="<?php echo __('same_for_all_products') ?? 'Same expiry for all'; ?>">
                  </div>
                  <div class="col-md-2">
                    <label><?php echo __('purchase_total'); ?></label>
                    <input type="text" id="totalPurchaseCost" class="form-control" readonly value="0.00">
                  </div>
                  <div class="col-md-2">
                    <label><?php echo __('Grand_Total'); ?></label>
                    <input type="text" id="grandTotalCost" class="form-control" readonly value="0.00">
                  </div>
                </div>
                <div class="table-responsive">
                  <table id="productsTable" class="table table-bordered table-striped table-hover" style="width: 100%;">
                    <thead class="thead-dark">
                      <tr>
                        <th><?php echo __('product'); ?> <i class="fas fa-box text-light"></i></th>
                        <th><?php echo __('new_product_name'); ?> <i class="fas fa-plus-circle text-light"></i></th>
                        <th><?php echo __('sku'); ?> <i class="fas fa-barcode text-light"></i></th>
                        <th><?php echo __('unit'); ?> <i class="fas fa-balance-scale text-light"></i></th>
                        <th><?php echo __('expiry_date'); ?> <i class="fas fa-calendar-alt text-light"></i></th>
                        <th><?php echo __('quantity'); ?> <i class="fas fa-hashtag text-light"></i></th>
                        <th><?php echo __('purchase_price'); ?> <i class="fas fa-dollar-sign text-light"></i></th>
                        <th><?php echo __('sell_price'); ?> <i class="fas fa-tags text-light"></i></th>
                        <th><?php echo __('category'); ?> <i class="fas fa-folder text-light"></i></th>
                        <th><?php echo __('actions'); ?> <i class="fas fa-cogs text-light"></i></th>
                      </tr>
                    </thead>
                    <tbody id="productsContainer">
                      <tr class="product-row">
                        <td>
                          <select class="form-control prod-select" name="products[0][prod_id]">
                            <option value=""><?php echo __('select_product'); ?></option>
                            <option value="new"><?php echo __('add_new_product'); ?></option>
                            <?php
                            $res = $mysqli->query("SELECT prod_id, prod_name FROM rpos_products ORDER BY prod_name");
                            while($p = $res->fetch_assoc()){
                                echo "<option value='{$p['prod_id']}'>".htmlspecialchars($p['prod_name'])."</option>";
                            }
                            ?>
                          </select>
                        </td>
                        <td>
                          <input type="text" name="products[0][prod_name]" class="form-control new-product-name" placeholder="<?php echo __('Type to create product when not found'); ?>" disabled>
                        </td>
                        <td>
                          <input type="text" name="products[0][prod_sku]" class="form-control" placeholder="<?php echo __('Barcode/SKU'); ?>">
                        </td>
                        <td>
                          <select required name="products[0][prod_unit]" class="form-control">
                            <?php foreach ($unitOptions as $u): ?>
                              <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <input type="date" name="products[0][prod_expiry]" class="form-control prod-expiry">
                        </td>
                        <td>
                          <input required type="number" name="products[0][qty]" class="form-control qty-input" min="1">
                        </td>
                        <td>
                          <input required type="text" name="products[0][purchase_price]" class="form-control purchase-price" placeholder="<?php echo __('cost_per_unit'); ?>">
                        </td>
                        <td>
                          <input required type="text" name="products[0][sell_price]" class="form-control" placeholder="<?php echo __('optional'); ?>">
                        </td>
                        <td>
                          <select required class="form-control" name="products[0][category_id]">
                            <option value="0">Category</option>
                            <?php
                            $cres = $mysqli->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
                            while($c = $cres->fetch_assoc()){
                                echo "<option value='{$c['category_id']}'>".htmlspecialchars($c['category_name'])."</option>";
                            }
                            ?>
                          </select>
                        </td>
                        <td>
                          <button type="button" class="btn btn-danger btn-sm remove-row" style="display: none;" title="<?php echo __('remove'); ?>"><i class="fas fa-trash"></i></button>
                          <input type="hidden" name="products[0][receive_id]" value="<?php echo htmlspecialchars($receive_id); ?>">
                          <input type="hidden" name="products[0][receive_date]" value="<?php echo htmlspecialchars($receive_date); ?>">
                          <input type="hidden" name="products[0][ref_no]" value="">
                          <input type="hidden" name="products[0][note]" value="">
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-3">
                    <label><?php echo __('supplier_for_all'); ?></label>
                    <select class="form-control" name="supplier" required>
                      <option value=""><?php echo __('select_supplier'); ?></option>
                      <?php
                      $sres = $mysqli->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name");
                      while($s = $sres->fetch_assoc()){
                          echo "<option value='".htmlspecialchars($s['supplier_id'])."'>".htmlspecialchars($s['supplier_name'])."</option>";
                      }
                      ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label><?php echo __('ref_number'); ?></label>
                    <input type="text" name="ref_no" class="form-control" id="refNo">
                  </div>
                  <div class="col-md-4">
                    <label><?php echo __('note'); ?></label>
                    <textarea name="note" class="form-control" rows="2" id="note"></textarea>
                  </div>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-6">
                    <button type="button" class="btn btn-primary" id="addProduct"><i class="fas fa-plus"></i> <?php echo __('add_another_product'); ?></button>
                  </div>
                  <div class="col-md-3">
                    <a href="stock_receive_confirm.php" class="btn btn-secondary btn-block"><?php echo __('confirm_receipt_page'); ?></a>
                  </div>
                  <div class="col-md-3">
                    <input type="submit" name="receive" class="btn btn-success btn-block" value="<?php echo __('place_purchase_order'); ?>">
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    let rowIndex = 1;
    const sameExpiryField = document.getElementById('sameExpiryDate');

    document.getElementById('addProduct').addEventListener('click', function() {
      const container = document.getElementById('productsContainer');
      const newRow = document.querySelector('.product-row').cloneNode(true);
      newRow.querySelectorAll('input, select').forEach(el => {
        el.name = el.name.replace('[0]', '[' + rowIndex + ']');
        if (el.tagName === 'SELECT') {
          // reset to first option (usually blank or placeholder)
          el.selectedIndex = 0;
        } else {
          el.value = '';
        }
      });
      if (sameExpiryField && sameExpiryField.value) {
        const expiryInput = newRow.querySelector('.prod-expiry');
        if (expiryInput) {
          expiryInput.value = sameExpiryField.value;
        }
      }
      newRow.querySelector('.remove-row').style.display = 'block';
      container.appendChild(newRow);
      rowIndex++;
      updateTotals();
    });

    if (sameExpiryField) {
      sameExpiryField.addEventListener('change', function() {
        document.querySelectorAll('.prod-expiry').forEach(input => {
          input.value = this.value;
        });
      });
    }

    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('remove-row')) {
        e.target.closest('.product-row').remove();
        updateTotals();
      }
    });

    const barcodeInput = document.getElementById('barcodeInput');
    const receiveForm = document.getElementById('receiveForm');
    if (barcodeInput) {
      barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
          e.preventDefault();
        }
      });
    }
    if (receiveForm) {
      receiveForm.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
          const tag = e.target.tagName.toLowerCase();
          if (tag === 'input' || tag === 'select') {
            e.preventDefault();
          }
        }
      });
    }

    document.addEventListener('change', function(e) {
      if (e.target.classList.contains('prod-select')) {
        const row = e.target.closest('.product-row');
        const selectedValue = e.target.value;
        const prodNameInput = row.querySelector('.new-product-name');
        if (selectedValue === 'new') {
          prodNameInput.removeAttribute('disabled');
          prodNameInput.focus();
        } else {
          prodNameInput.setAttribute('disabled', 'disabled');
          prodNameInput.value = '';
        }
      }
    });

    function updateTotals() {
      let total = 0;
      // Tax system disabled - converted to comment
      // let totalTax = 0;
      document.querySelectorAll('.product-row').forEach(row => {
        const qty = parseFloat(row.querySelector('input[name*="[qty]"]').value) || 0;
        const price = parseFloat(row.querySelector('input[name*="[purchase_price]"]').value) || 0;
        // const tax = parseFloat(row.querySelector('input[name*="[tax]"]').value) || 0;
        total += qty * price;
        // totalTax += qty * price * (tax / 100);
      });
      document.getElementById('totalPurchaseCost').value = total.toFixed(2);
      // document.getElementById('totalTaxCost').value = totalTax.toFixed(2);
      document.getElementById('grandTotalCost').value = total.toFixed(2); // (total + totalTax).toFixed(2);
    }

    document.addEventListener('input', function(e) {
      if (e.target.matches('.qty-input, .purchase-price')) { // Removed .tax-input as tax system disabled
        updateTotals();
      }
    });

    document.getElementById('receiveForm').addEventListener('submit', function(e) {
      const refNo = document.getElementById('refNo').value;
      const note = document.getElementById('note').value;
      const receiveId = document.getElementById('receiveId').value;
      const receiveDate = document.getElementById('receiveDate').value;
      document.querySelectorAll('.product-row').forEach((row, index) => {
        row.querySelector('input[name*="receive_id"]').value = receiveId;
        row.querySelector('input[name*="receive_date"]').value = receiveDate;
        row.querySelector('input[name*="ref_no"]').value = refNo;
        row.querySelector('input[name*="note"]').value = note;
      });
    });
  </script>
</body>
</html>
