<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');

check_login();
require_once('config/languages.php');

$current_lang = $_SESSION['lang'] ?? 'en';

if (isset($_POST['addProduct'])) {
  // Determine if the form submitted multiple products or just one product
  $prod_names = $_POST['prod_name'] ?? [];
  if (!is_array($prod_names)) {
    $prod_names = [$prod_names];
  }

  $prod_codes = $_POST['prod_code'] ?? [];
  if (!is_array($prod_codes)) {
    $prod_codes = [$prod_codes];
  }

  $prod_skus = $_POST['prod_sku'] ?? [];
  if (!is_array($prod_skus)) {
    $prod_skus = [$prod_skus];
  }

  $prod_stocks = $_POST['prod_stock'] ?? [];
  if (!is_array($prod_stocks)) {
    $prod_stocks = [$prod_stocks];
  }

  $prod_batches = $_POST['prod_batch'] ?? [];
  if (!is_array($prod_batches)) {
    $prod_batches = [$prod_batches];
  }

  $prod_expiries = $_POST['prod_expiry'] ?? [];
  if (!is_array($prod_expiries)) {
    $prod_expiries = [$prod_expiries];
  }

  $prod_variants = $_POST['prod_variant'] ?? [];
  if (!is_array($prod_variants)) {
    $prod_variants = [$prod_variants];
  }

  $prod_units = $_POST['prod_unit'] ?? [];
  if (!is_array($prod_units)) {
    $prod_units = [$prod_units];
  }

  $prod_sellables = $_POST['prod_sellable'] ?? [];
  if (!is_array($prod_sellables)) {
    $prod_sellables = [$prod_sellables];
  }

  $prod_categories = $_POST['prod_category'] ?? [];
  if (!is_array($prod_categories)) {
    $prod_categories = [$prod_categories];
  }

  $prod_descs = $_POST['prod_desc'] ?? [];
  if (!is_array($prod_descs)) {
    $prod_descs = [$prod_descs];
  }

  $prod_prices = $_POST['prod_price'] ?? [];
  if (!is_array($prod_prices)) {
    $prod_prices = [$prod_prices];
  }

  $prod_expiry_all = $_POST['prod_expiry_all'] ?? '';

  // Prevent Posting Blank Values (at least the first product must have required fields)
  if (empty($prod_codes[0]) || empty($prod_names[0]) || empty($prod_descs[0]) || empty($prod_prices[0])) {
    $err = "Blank Values Not Accepted";
  } else {
    // Handle image upload once and reuse for all products
    $prod_img = $_FILES['prod_img']['name'] ?? '';
    if (!empty($prod_img) && isset($_FILES['prod_img']['tmp_name'])) {
      move_uploaded_file($_FILES["prod_img"]["tmp_name"], "assets/img/products/" . $prod_img);
    }

    // Prepare statement once and reuse for each row (if there are multiple items)
    $postQuery = "INSERT INTO rpos_products (prod_id, prod_code, prod_name, prod_sku, prod_stock, prod_batch, prod_expiry, prod_variant, prod_unit, prod_sellable, category_id, prod_img, prod_desc, prod_price) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $postStmt = $mysqli->prepare($postQuery);

    $inserted = 0;
    $totalRows = max(
      count($prod_names),
      count($prod_codes),
      count($prod_skus),
      count($prod_stocks),
      count($prod_batches),
      count($prod_expiries),
      count($prod_variants),
      count($prod_sellables),
      count($prod_categories),
      count($prod_descs),
      count($prod_prices)
    );

    for ($i = 0; $i < $totalRows; $i++) {
      $prod_name = trim($prod_names[$i] ?? '');
      $prod_code_raw = trim($prod_codes[$i] ?? '');
      $prod_code = !empty($prod_code_raw) ? $prod_code_raw : generateUniqueProductCode($mysqli);
      $prod_sku = trim($prod_skus[$i] ?? '');
      $prod_stock = intval($prod_stocks[$i] ?? 0);
      $prod_batch = trim($prod_batches[$i] ?? '');
      $prod_expiry = !empty($prod_expiry_all) ? $prod_expiry_all : trim($prod_expiries[$i] ?? '');
      $prod_variant = trim($prod_variants[$i] ?? '');
      $prod_unit = trim($prod_units[$i] ?? 'pcs');
      $prod_sellable = intval($prod_sellables[$i] ?? 0);
      $prod_cat = intval($prod_categories[$i] ?? 0);
      $prod_desc = trim($prod_descs[$i] ?? '');
      $prod_price = trim($prod_prices[$i] ?? '');

      // Skip rows that don't have the required fields.
      if (empty($prod_code) || empty($prod_name) || empty($prod_desc) || empty($prod_price)) {
        continue;
      }

      $prod_id = bin2hex(random_bytes(5));

      $rc = $postStmt->bind_param(
        'ssssissssissss',
        $prod_id,
        $prod_code,
        $prod_name,
        $prod_sku,
        $prod_stock,
        $prod_batch,
        $prod_expiry,
        $prod_variant,
        $prod_unit,
        $prod_sellable,
        $prod_cat,
        $prod_img,
        $prod_desc,
        $prod_price
      );

      if ($rc) {
        $postStmt->execute();
        if ($postStmt->affected_rows > 0) {
          $inserted++;
        }
      }
    }

    if ($inserted > 0) {
      $success = "Product(s) Added";
      header("refresh:1; url=add_product.php");
    } else {
      $err = "Please Try Again Or Try Later";
    }
  }
}
require_once('partials/_head.php');
?>

<style>
  .product-row { border: 1px solid rgba(13,110,253,0.18); border-radius: 1rem; }
  .product-row .btn-outline-danger { min-width: 45px; }
  .form-control-alternative { background-color: #f8f9ff; border: 1px solid #d9e2f5; }
  .product-row h5 { font-weight: 700; }
  .product-row small.text-muted { font-size: 0.95rem; }
</style>

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
      <!-- Table -->
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3><?php echo __('please_fill_fields'); ?></h3>
            </div>
            <div class="card-body">
              <form method="POST" enctype="multipart/form-data">
                <div class="row align-items-end mb-4">
                  <div class="col-md-4 mb-3">
                    <label>Expiry Date for all products (optional)</label>
                    <input type="date" name="prod_expiry_all" class="form-control form-control-alternative">
                  </div>
                  <div class="col-md-4 mb-3">
                    <label><?php echo __('product_image'); ?> (optional)</label>
                    <input type="file" name="prod_img" accept="image/*" class="form-control form-control-alternative">
                    <small class="text-muted">يمكن رفع صورة واحدة لجميع المنتجات.</small>
                  </div>
                  <div class="col-md-4 text-right mb-3">
                    <button id="addProductRow" class="btn btn-success btn-block"><i class="fas fa-plus mr-1"></i> إضافة منتج آخر</button>
                  </div>
                </div>

                <div id="productRows">
                  <div class="product-row card card-body mb-4 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                      <div>
                        <h5 class="mb-1">منتج 1</h5>
                        <small class="text-muted">املأ بيانات المنتج بدقة لتحسين عملية التخزين والطلب.</small>
                      </div>
                      <button type="button" class="btn btn-sm btn-outline-danger remove-product-row" title="إزالة المنتج"><i class="fas fa-trash-alt"></i></button>
                    </div>
                    <div class="form-row">
                      <div class="col-md-4 mb-3">
                        <label><?php echo __('product_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" name="prod_name[]" class="form-control form-control-alternative" placeholder="اسم المنتج" required>
                      </div>
                      <div class="col-md-4 mb-3">
                        <label><?php echo __('product_code'); ?></label>
                        <input type="text" name="prod_code[]" class="form-control form-control-alternative" placeholder="رمز المنتج أو تركه للتوليد التلقائي" value="<?php echo $alpha; ?>-<?php echo $beta; ?>">
                      </div>
                      <div class="col-md-4 mb-3">
                        <label><?php echo __('product_sku'); ?> (Barcode)</label>
                        <input type="text" name="prod_sku[]" class="form-control form-control-alternative" placeholder="باركود المنتج">
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="col-md-2 mb-3">
                        <label><?php echo __('stock_qty'); ?></label>
                        <input type="number" min="0" name="prod_stock[]" class="form-control form-control-alternative" value="0">
                      </div>
                      <div class="col-md-2 mb-3">
                        <label><?php echo __('unit'); ?></label>
                        <select name="prod_unit[]" class="form-control form-control-alternative text-uppercase text-muted">
                          <?php 
                          $units=$mysqli->query("SELECT unit_name FROM units WHERE unit_status = 'Active' ORDER BY unit_name");
                          if($units && $units->num_rows>0){
                            while($u= $units->fetch_assoc()){
                              $unitName = $u['unit_name'] ?? 'pcs';
                              echo "<option value=\"" . htmlspecialchars($unitName) . "\">" . htmlspecialchars($unitName) . "</option>";
                            }
                          } else {
                            echo "<option value=\"pcs\">pcs</option>";
                          }
                          ?>  
                        </select>
                      </div>
                      <div class="col-md-3 mb-3">
                        <label><?php echo __('batch'); ?></label>
                        <input type="text" name="prod_batch[]" class="form-control form-control-alternative" placeholder="رقم الدفعة">
                      </div>
                      <div class="col-md-3 mb-3">
                        <label><?php echo __('expiry_date'); ?></label>
                        <input type="date" name="prod_expiry[]" class="form-control form-control-alternative">
                      </div>
                      <div class="col-md-2 mb-3">
                        <label><?php echo __('product_price'); ?></label>
                        <input type="text" min="0" name="prod_price[]" class="form-control form-control-alternative" placeholder="السعر">
                      </div>
                    </div>
                    <div class="form-row align-items-end">
                      <div class="col-md-6 mb-3">
                        <label><?php echo __('variant_notes'); ?></label>
                        <input type="text" name="prod_variant[]" class="form-control form-control-alternative" placeholder="المواصفات أو النوع">
                      </div>
                      <div class="col-md-6 mb-3">
                        <input type="hidden" name="prod_sellable[]" value="0">
                        <div class="custom-control custom-switch">
                          <input type="checkbox" class="custom-control-input" id="sellableSwitch1" name="prod_sellable[]" value="1" checked>
                          <label class="custom-control-label" for="sellableSwitch1"><?php echo __('sell_even_if_expired'); ?></label>
                        </div>
                      </div>
                    </div>
                    <div class="form-row">
                      <div class="col-md-12">
                        <label><?php echo __('product_description'); ?></label>
                        <textarea rows="4" name="prod_desc[]" class="form-control form-control-alternative" placeholder="وصف المنتج"></textarea>
                      </div>
                    </div>
                    <div class="form-row mt-3">
                      <div class="col-md-12">
                        <label><?php echo __('product_category'); ?></label>
                        <select class="form-control form-control-alternative" name="prod_category[]">
                          <option value="0">Uncategorized</option>
                          <?php
                          $cats = $mysqli->query("SELECT category_id,category_name FROM categories ORDER BY category_name");
                          while($c = $cats->fetch_assoc()){
                            echo "<option value=\"{$c['category_id']}\">{$c['category_name']}</option>";
                          }
                          ?>
                        </select>
                      </div>
                    </div>
                  </div>
                </div>

                <br>
                <div class="form-row">
                  <div class="col-md-6">
                    <input type="submit" name="addProduct" value="<?php echo __('add_product'); ?>" class="btn btn-success">
                  </div>
                </div>
              </form>
              <script>
                (function() {
                  const container = document.getElementById('productRows');
                  const addBtn = document.getElementById('addProductRow');

                  function updateRowTitles() {
                    container.querySelectorAll('.product-row').forEach((row, index) => {
                      const heading = row.querySelector('h5');
                      if (heading) {
                        heading.textContent = 'منتج ' + (index + 1);
                      }
                      const switchInput = row.querySelector('.custom-control-input');
                      if (switchInput) {
                        const switchId = 'sellableSwitch' + (index + 1);
                        switchInput.id = switchId;
                        const switchLabel = row.querySelector('.custom-control-label');
                        if (switchLabel) {
                          switchLabel.htmlFor = switchId;
                        }
                      }
                    });
                  }

                  addBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    const firstRow = container.querySelector('.product-row');
                    if (!firstRow) {
                      return;
                    }

                    const newRow = firstRow.cloneNode(true);
                    newRow.querySelectorAll('input, textarea, select').forEach((input) => {
                      if (input.type === 'checkbox') {
                        input.checked = false;
                      } else if (input.type === 'hidden' && input.name === 'prod_sellable[]') {
                        input.value = '0';
                      } else if (input.tagName === 'SELECT') {
                        input.selectedIndex = 0;
                      } else {
                        input.value = '';
                      }
                    });
                    newRow.querySelectorAll('input[name="prod_code[]"]').forEach((input) => { input.value = ''; });
                    newRow.querySelector('textarea[name="prod_desc[]"]').value = '';
                    container.appendChild(newRow);
                    updateRowTitles();
                  });

                  container.addEventListener('click', function(event) {
                    if (event.target.closest('.remove-product-row')) {
                      event.preventDefault();
                      const rows = container.querySelectorAll('.product-row');
                      if (rows.length > 1) {
                        event.target.closest('.product-row').remove();
                        updateRowTitles();
                      }
                    }
                  });

                  updateRowTitles();
                })();
              </script>
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
</body>

</html>
