<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$unitOptions = [];
$unitRes = $mysqli->query("SELECT unit_name FROM units ORDER BY unit_name");
if ($unitRes) {
    while ($unitRow = $unitRes->fetch_assoc()) {
        $unitOptions[] = trim($unitRow['unit_name']);
    }
}
if (empty($unitOptions)) {
    $unitOptions = ['pcs', 'tablet', 'capsule', 'bottle', 'ml'];
}

$categoryOptions = [];
$categoryRes = $mysqli->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
if ($categoryRes) {
    while ($categoryRow = $categoryRes->fetch_assoc()) {
        $categoryOptions[$categoryRow['category_id']] = $categoryRow['category_name'];
    }
}

if (isset($_POST['saveProducts'])) {
    $prod_ids = $_POST['prod_id'] ?? [];
    $prod_codes = $_POST['prod_code'] ?? [];
    $prod_names = $_POST['prod_name'] ?? [];
    $prod_skus = $_POST['prod_sku'] ?? [];
    $prod_stocks = $_POST['prod_stock'] ?? [];
    $prod_batches = $_POST['prod_batch'] ?? [];
    $prod_expiries = $_POST['prod_expiry'] ?? [];
    $prod_variants = $_POST['prod_variant'] ?? [];
    $prod_units = $_POST['prod_unit'] ?? [];
    $prod_sellables = $_POST['prod_sellable'] ?? [];
    $prod_categories = $_POST['prod_category'] ?? [];
    $prod_imgs = $_POST['prod_img'] ?? [];
    $prod_descs = $_POST['prod_desc'] ?? [];
    $prod_prices = $_POST['prod_price'] ?? [];

    if (!is_array($prod_ids)) $prod_ids = [$prod_ids];
    if (!is_array($prod_codes)) $prod_codes = [$prod_codes];
    if (!is_array($prod_names)) $prod_names = [$prod_names];
    if (!is_array($prod_skus)) $prod_skus = [$prod_skus];
    if (!is_array($prod_stocks)) $prod_stocks = [$prod_stocks];
    if (!is_array($prod_batches)) $prod_batches = [$prod_batches];
    if (!is_array($prod_expiries)) $prod_expiries = [$prod_expiries];
    if (!is_array($prod_variants)) $prod_variants = [$prod_variants];
    if (!is_array($prod_units)) $prod_units = [$prod_units];
    if (!is_array($prod_sellables)) $prod_sellables = [$prod_sellables];
    if (!is_array($prod_categories)) $prod_categories = [$prod_categories];
    if (!is_array($prod_imgs)) $prod_imgs = [$prod_imgs];
    if (!is_array($prod_descs)) $prod_descs = [$prod_descs];
    if (!is_array($prod_prices)) $prod_prices = [$prod_prices];

    $updateQuery = "UPDATE rpos_products SET prod_code=?, prod_name=?, prod_sku=?, prod_stock=?, prod_batch=?, prod_expiry=?, prod_variant=?, prod_unit=?, prod_sellable=?, category_id=?, prod_img=?, prod_desc=?, prod_price=? WHERE prod_id=?";
    $updateStmt = $mysqli->prepare($updateQuery);

    $updatedCount = 0;
    $rowCount = count($prod_ids);
    for ($i = 0; $i < $rowCount; $i++) {
        $prod_id = trim($prod_ids[$i] ?? '');
        if (empty($prod_id)) {
            continue;
        }

        $prod_code = trim($prod_codes[$i] ?? '');
        $prod_name = trim($prod_names[$i] ?? '');
        $prod_sku = trim($prod_skus[$i] ?? '');
        $prod_stock = intval($prod_stocks[$i] ?? 0);
        $prod_batch = trim($prod_batches[$i] ?? '');
        $prod_expiry = trim($prod_expiries[$i] ?? '');
        $prod_expiry = !empty($prod_expiry) ? $prod_expiry : null;
        $prod_variant = trim($prod_variants[$i] ?? '');
        $prod_unit = trim($prod_units[$i] ?? 'pcs');
        $prod_sellable = isset($prod_sellables[$i]) && $prod_sellables[$i] == '1' ? 1 : 0;
        $prod_category = intval($prod_categories[$i] ?? 0);
        $prod_img = trim($prod_imgs[$i] ?? '');
        $prod_desc = trim($prod_descs[$i] ?? '');
        $prod_price = trim($prod_prices[$i] ?? '');

        if (empty($prod_code) || empty($prod_name)) {
            continue;
        }

        $updateStmt->bind_param(
            'sssissssisssss',
            $prod_code,
            $prod_name,
            $prod_sku,
            $prod_stock,
            $prod_batch,
            $prod_expiry,
            $prod_variant,
            $prod_unit,
            $prod_sellable,
            $prod_category,
            $prod_img,
            $prod_desc,
            $prod_price,
            $prod_id
        );
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 0) {
            $updatedCount++;
        }
    }

    if ($updatedCount > 0) {
        $success = "{$updatedCount} product(s) updated successfully.";
        header("refresh:1; url=multi_edit_products.php");
    } else {
        $err = "No changes were saved. Ensure each row has a product code and product name.";
    }
}

require_once('partials/_head.php');
?>
<body>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
      <div class="container-fluid">
        <div class="header-body">
          <h1 class="text-white display-4 font-weight-bold">Bulk Edit Products</h1>
          <p class="text-white opacity-8">Edit multiple items from rpos_products in one table row each.</p>
        </div>
      </div>
    </div>

    <div class="container-fluid mt--7">
      <div class="row mb-3">
        <div class="col">
          <div class="card shadow-sm">
            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
              <div>
                <h3 class="mb-1">Multi edit product list</h3>
                <p class="text-muted mb-0">Update code, price, stock, expiry, description and more in one submit.</p>
              </div>
              <div class="d-flex gap-2">
                <a href="products.php" class="btn btn-outline-secondary">Back to Products</a>
                <button type="button" id="toggleHelp" class="btn btn-outline-info">Toggle Help</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if (isset($success)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <?php endif; ?>
      <?php if (isset($err)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($err); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <?php endif; ?>

      <div class="card shadow">
        <div class="card-body">
          <form method="POST">
            <div class="form-group mb-3">
              <button type="button" id="autoDisableBtn" class="btn btn-warning">Auto-disable unsellable items</button>
              <small class="text-muted ml-2">Unchecks sellable for items with zero stock and zero price.</small>
            </div>
            <div class="table-responsive">
              <table class="table table-hover table-sm align-items-center mb-0" id="bulkEditTable">
                <thead class="thead-light text-uppercase small">
                  <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>SKU</th>
                    <th>Stock</th>
                    <th>Unit</th>
                    <th>Batch</th>
                    <th>Expiry</th>
                    <th>Variant</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Sellable</th>
                    <th>Description</th>
                    <th>Created</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ret = "SELECT p.*, c.category_name FROM rpos_products p LEFT JOIN categories c ON p.category_id = c.category_id ORDER BY p.created_at DESC";
                  $stmt = $mysqli->prepare($ret);
                  $stmt->execute();
                  $res = $stmt->get_result();
                  $rowIndex = 0;
                  while ($prod = $res->fetch_object()) {
                    $createdAt = $prod->created_at ?? $prod->created_at;
                    $categoryName = $prod->category_name ?: 'Uncategorized';
                  ?>
                  <tr>
                    <td>
                      <input type="hidden" name="prod_id[]" value="<?php echo htmlspecialchars($prod->prod_id); ?>">
                      <input type="text" name="prod_code[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_code); ?>">
                    </td>
                    <td>
                      <input type="text" name="prod_name[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_name); ?>">
                    </td>
                    <td><input type="text" name="prod_sku[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_sku); ?>"></td>
                    <td><input type="number" min="0" name="prod_stock[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_stock); ?>"></td>
                    <td>
                      <select name="prod_unit[]" class="form-control form-control-alternative form-control-sm">
                        <?php foreach ($unitOptions as $unit): ?>
                          <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo $prod->prod_unit == $unit ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="text" name="prod_batch[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_batch); ?>"></td>
                    <td><input type="date" name="prod_expiry[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_expiry); ?>"></td>
                    <td><input type="text" name="prod_variant[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_variant); ?>"></td>
                    <td>
                      <select name="prod_category[]" class="form-control form-control-alternative form-control-sm">
                        <option value="0">Uncategorized</option>
                        <?php foreach ($categoryOptions as $catId => $catName): ?>
                          <option value="<?php echo intval($catId); ?>" <?php echo intval($prod->category_id) === intval($catId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($catName); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td><input type="text" name="prod_price[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_price); ?>"></td>
                    <td class="text-center">
                      <input type="hidden" name="prod_sellable[<?php echo $rowIndex; ?>]" value="0">
                      <input type="checkbox" name="prod_sellable[<?php echo $rowIndex; ?>]" value="1" <?php echo $prod->prod_sellable ? 'checked' : ''; ?>>
                    </td>
                    <td><input type="text" name="prod_desc[]" class="form-control form-control-alternative form-control-sm" value="<?php echo htmlspecialchars($prod->prod_desc); ?>"></td>
                    <td><small class="text-muted"><?php echo htmlspecialchars($createdAt); ?></small></td>
                    <input type="hidden" name="prod_img[]" value="<?php echo htmlspecialchars($prod->prod_img); ?>">
                  </tr>
                  <?php $rowIndex++; } ?>
                </tbody>
              </table>
            </div>
            <div class="form-group mt-3">
              <button type="submit" name="saveProducts" class="btn btn-success">Save all changes</button>
            </div>
          </form>
        </div>
      </div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <?php require_once('partials/_scripts.php'); ?>
  <script>
    document.getElementById('toggleHelp').addEventListener('click', function() {
      alert('Edit each item in its own row, then click Save all changes. Use the Category dropdown to change category and the checkbox to toggle sellable state.');
    });

    document.getElementById('autoDisableBtn').addEventListener('click', function() {
      const rows = document.querySelectorAll('#bulkEditTable tbody tr');
      let disabledCount = 0;

      rows.forEach((row, index) => {
        const stockInput = row.querySelector('input[name="prod_stock[]"]');
        const priceInput = row.querySelector('input[name="prod_price[]"]');
        const sellableCheckbox = row.querySelector('input[name="prod_sellable[' + index + ']"]');

        const stock = parseFloat(stockInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;

        if (stock === 0 && price === 0) {
          if (sellableCheckbox.checked) {
            disabledCount++;
            anime({
              targets: sellableCheckbox,
              scale: [1, 1.2, 1],
              duration: 300,
              easing: 'easeOutExpo',
              complete: function() {
                sellableCheckbox.checked = false;
                anime({
                  targets: row,
                  backgroundColor: ['rgba(239, 68, 68, 0.1)', 'rgba(0,0,0,0)'],
                  duration: 500,
                  easing: 'easeOutExpo'
                });
              }
            });
          }
        }
      });

      setTimeout(() => {
        alert(`Disabled ${disabledCount} unsellable items.`);
      }, 350);
    });

    // Enhanced form interactions with Anime.js
    document.querySelectorAll('#bulkEditTable input, #bulkEditTable select').forEach(function(input) {
      input.addEventListener('focus', function() {
        anime({
          targets: this,
          scale: 1.02,
          duration: 200,
          easing: 'easeOutExpo'
        });
        anime({
          targets: this.closest('td'),
          backgroundColor: ['rgba(0,0,0,0)', 'rgba(14, 165, 233, 0.05)'],
          duration: 200,
          easing: 'easeOutExpo'
        });
      });

      input.addEventListener('blur', function() {
        anime({
          targets: this,
          scale: 1,
          duration: 200,
          easing: 'easeOutExpo'
        });
        anime({
          targets: this.closest('td'),
          backgroundColor: ['rgba(14, 165, 233, 0.05)', 'rgba(0,0,0,0)'],
          duration: 200,
          easing: 'easeOutExpo'
        });
      });
    });

    // Animate table rows on load
    anime({
      targets: '#bulkEditTable tbody tr',
      opacity: [0, 1],
      translateX: [-20, 0],
      duration: 600,
      easing: 'easeOutExpo',
      delay: anime.stagger(50)
    });

    // Button hover effects
    document.querySelectorAll('.btn').forEach(function(btn) {
      btn.addEventListener('mouseenter', function() {
        anime({
          targets: this,
          scale: 1.05,
          boxShadow: ['0 4px 6px rgba(0,0,0,0.1)', '0 8px 16px rgba(0,0,0,0.15)'],
          duration: 200,
          easing: 'easeOutExpo'
        });
      });
      btn.addEventListener('mouseleave', function() {
        anime({
          targets: this,
          scale: 1,
          boxShadow: ['0 8px 16px rgba(0,0,0,0.15)', '0 4px 6px rgba(0,0,0,0.1)'],
          duration: 200,
          easing: 'easeOutExpo'
        });
      });
    });
  </script>
</body>
</html>
