<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');

check_login();
$unitOptions = [];
if ($unitRes = $mysqli->query("SELECT unit_name FROM units ORDER BY unit_name")) {
    while ($unitRow = $unitRes->fetch_assoc()) {
        $unitOptions[] = trim($unitRow['unit_name']);
    }
}
if (empty($unitOptions)) {
    $unitOptions = ['pcs', 'tablet', 'capsule', 'bottle', 'ml'];
}

$update = $_GET['update'] ?? '';
$existingProd = null;
if ($update) {
  $tmpStmt = $mysqli->prepare("SELECT prod_sku, prod_img FROM rpos_products WHERE prod_id = ? LIMIT 1");
  $tmpStmt->bind_param('s', $update);
  $tmpStmt->execute();
  $tmpRes = $tmpStmt->get_result();
  $existingProd = $tmpRes->fetch_object();
  $tmpStmt->close();
}

if (isset($_POST['UpdateProduct'])) {
  //Prevent Posting Blank Values
  if (empty($_POST["prod_code"]) || empty($_POST["prod_name"]) || empty($_POST['prod_desc']) || empty($_POST['prod_price'])) {
    $err = "Blank Values Not Accepted";
  } else {
    $prod_code  = $_POST['prod_code'];
    $prod_name = $_POST['prod_name'];
    $prod_sku = trim($_POST['prod_sku'] ?? $existingProd->prod_sku);
    $prod_stock = intval($_POST['prod_stock']);
    $prod_stock = intval($_POST['prod_stock']);
    $prod_batch = $_POST['prod_batch'];
    // expiry may come back empty; convert to null to avoid zero-dates
    $prod_expiry = !empty($_POST['prod_expiry']) ? $_POST['prod_expiry'] : null;
    $prod_variant = $_POST['prod_variant'];
    $prod_unit = $_POST['prod_unit'] ?? 'pcs';
    $prod_cat  = intval($_POST['prod_category']);
    // checkbox or toggle
    $prod_sellable = isset($_POST['prod_sellable']) ? 1 : 0;
    // Image disabled — preserve existing or fallback to blank
    $prod_img = $existingProd->prod_img ?? '';
    $prod_desc = $_POST['prod_desc'];
    $prod_price = $_POST['prod_price'];

    //Insert Captured information to a database table
    $postQuery = "UPDATE rpos_products SET prod_code =?, prod_name =?, prod_sku =?, prod_stock =?, prod_batch =?, prod_expiry =?, prod_variant =?, prod_unit =?, prod_sellable = ?, category_id =?, prod_img =?, prod_desc =?, prod_price =? WHERE prod_id = ?";
    $postStmt = $mysqli->prepare($postQuery);
    //bind paramaters (expiry as string, sellable and category as integers)
    $rc = $postStmt->bind_param('sssissssisssss', $prod_code, $prod_name, $prod_sku, $prod_stock, $prod_batch, $prod_expiry, $prod_variant, $prod_unit, $prod_sellable, $prod_cat, $prod_img, $prod_desc, $prod_price, $update);
    $postStmt->execute();
    //declare a varible which will be passed to alert function
    if ($postStmt) {
      $success = "Product Updated" && header("refresh:1; url=products.php");
    } else {
      $err = "Please Try Again Or Try Later";
    }
  }
}
require_once('partials/_head.php');
?>

<body>
  <!-- Sidenav -->
  <?php
  require_once('partials/_sidebar.php');
  ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php
    require_once('partials/_topnav.php');
    $update = $_GET['update'];
    $ret = "SELECT * FROM  rpos_products WHERE prod_id = '$update' ";
    $stmt = $mysqli->prepare($ret);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($prod = $res->fetch_object()) {
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
                <h3>Please Fill All Fields</h3>
              </div>
              <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                  <div class="form-row">
                    <div class="col-md-4">
                      <label>Product Name</label>
                      <input type="text" value="<?php echo $prod->prod_name; ?>" name="prod_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                      <label>Product Code (disabled)</label>
                      <input type="text" name="prod_code" value="<?php echo $prod->prod_code; ?>" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                      <label>SKU (Barcode)</label>
                      <input type="text" name="prod_sku" value="<?php echo $prod->prod_sku; ?>" class="form-control">
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="col-md-3">
                      <label>Stock Qty</label>
                      <input type="number" name="prod_stock" value="<?php echo $prod->prod_stock; ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                      <label>Unit</label>
                      <select name="prod_unit" class="form-control">
                        <?php foreach ($unitOptions as $u): ?>
                          <option value="<?php echo htmlspecialchars($u); ?>" <?php echo $prod->prod_unit == $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($u); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label>Batch #</label>
                      <input type="text" name="prod_batch" value="<?php echo $prod->prod_batch; ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                      <label>Expiry Date</label>
                      <?php
                        $expiry_val = '';
                        if ($prod->prod_expiry && strpos($prod->prod_expiry, '0000-00-00') !== 0) {
                            $expiry_val = $prod->prod_expiry;
                        }
                      ?>
                      <input type="date" name="prod_expiry" value="<?php echo $expiry_val; ?>" class="form-control">
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="col-md-12">
                      <label>Variant/Notes</label>
                      <input type="text" name="prod_variant" value="<?php echo $prod->prod_variant; ?>" class="form-control">
                    </div>
                  </div>
                  <div class="form-row mt-2">
                    <div class="col-md-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="prod_sellable" value="1" <?php echo $prod->prod_sellable ? 'checked' : ''; ?> >
                        <label class="form-check-label">Sell even if expired or out of stock</label>
                      </div>
                    </div>
                  </div>
                  <hr>
                  <div class="form-row">
                    <div class="col-md-6">
                      <label>Category</label>
                      <select class="form-control" name="prod_category">
                          <option value="0">Uncategorized</option>
                          <?php
                          $cats = $mysqli->query("SELECT category_id,category_name FROM categories ORDER BY category_name");
                          while($c = $cats->fetch_assoc()){
                              $sel = ($prod->category_id == $c['category_id']) ? 'selected' : '';
                              echo "<option value=\"{$c['category_id']}\" $sel>{$c['category_name']}</option>";
                          }
                          ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label>Product Image (disabled)</label>
                      <input type="text" class="form-control" value="<?php echo $prod->prod_img; ?>" readonly>
                    </div>
                    <div class="col-md-6">
                      <label>Product Price</label>
                      <input type="text" name="prod_price" class="form-control" value="<?php echo $prod->prod_price; ?>">
                    </div>
                  </div>
                  <hr>
                  <div class="form-row">
                    <div class="col-md-12">
                      <label>Product Description</label>
                      <textarea rows="5" name="prod_desc" class="form-control" value=""><?php echo $prod->prod_desc; ?></textarea>
                    </div>
                  </div>
                  <br>
                  <div class="form-row">
                    <div class="col-md-6">
                      <input type="submit" name="UpdateProduct" value="Update Product" class="btn btn-success" value="">
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <!-- Footer -->
      <?php
      require_once('partials/_footer.php');
    }
      ?>
      </div>
  </div>
  <!-- Argon Scripts -->
  <?php
  require_once('partials/_scripts.php');
  ?>
</body>

</html>
