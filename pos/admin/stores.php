<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();
require_once('partials/_head.php');

if (isset($_POST['add_store'])) {
    $store_id = bin2hex(random_bytes(6));
    $store_name = trim($_POST['store_name']);
    $store_address = trim($_POST['store_address']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if (empty($store_name)) {
        $err = __('store_name_required');
    } else {
        $stmt = $mysqli->prepare("INSERT INTO rpos_stores (store_id, store_name, store_address, is_active) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $store_id, $store_name, $store_address, $is_active);
        if ($stmt->execute()) {
            $success = __('store_added_success');
        } else {
            $err = __('store_add_failed');
        }
        $stmt->close();
    }
}

if (isset($_GET['delete_store'])) {
    $delete_id = $_GET['delete_store'];
    $stmt = $mysqli->prepare("DELETE FROM rpos_stores WHERE store_id = ?");
    $stmt->bind_param('s', $delete_id);
    if ($stmt->execute()) {
        $success = __('store_deleted_success');
    } else {
        $err = __('store_delete_failed');
    }
    $stmt->close();
}

$stores = [];
$res = $mysqli->query("SELECT * FROM rpos_stores ORDER BY created_at DESC");
while ($row = $res->fetch_object()) {
    $stores[] = $row;
}
?>
<body>
    <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header  pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>

    <div class="container-fluid mt--7">
      <div class="row">
        <div class="col-xl-4">
          <div class="card shadow">
            <div class="card-header">
              <h3><?php echo __('add_store'); ?></h3>
            </div>
            <div class="card-body">
              <?php if(isset($err)){ ?><div class="alert alert-danger"><?php echo $err; ?></div><?php } ?>
              <?php if(isset($success)){ ?><div class="alert alert-success"><?php echo $success; ?></div><?php } ?>
              <form method="POST">
                <div class="form-group">
                  <label><?php echo __('store_name'); ?></label>
                  <input type="text" name="store_name" class="form-control" required>
                </div>
                <div class="form-group">
                  <label><?php echo __('store_address'); ?></label>
                  <input type="text" name="store_address" class="form-control">
                </div>
                <div class="form-group form-check">
                  <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                  <label class="form-check-label" for="is_active"><?php echo __('store_active'); ?></label>
                </div>
                <button type="submit" name="add_store" class="btn btn-primary"><?php echo __('add_store'); ?></button>
              </form>
            </div>
          </div>
        </div>
        <div class="col-xl-8">
          <div class="card shadow">
            <div class="card-header">
              <h3><?php echo __('store_list'); ?></h3>
            </div>
            <div class="table-responsive">
              <table class="table align-items-center table-flush">
                <thead class="thead-light">
                  <tr>
                    <th><?php echo __('store_name'); ?></th>
                    <th><?php echo __('store_address'); ?></th>
                    <th><?php echo __('store_active'); ?></th>
                    <th><?php echo __('actions'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($stores as $store): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($store->store_name); ?></td>
                      <td><?php echo htmlspecialchars($store->store_address); ?></td>
                      <td><?php echo $store->is_active ? __('yes') : __('no'); ?></td>
                      <td><a class="btn btn-sm btn-danger" href="stores.php?delete_store=<?php echo urlencode($store->store_id); ?>" onclick="return confirm('<?php echo __('confirm_delete_store'); ?>');"><?php echo __('delete'); ?></a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php require_once('partials/_footer.php'); ?>
    </div>
    </div>
  </div>
  <?php require_once('partials/_scripts.php'); ?>
</body>
</html>