<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

// handle actions and edit mode
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'categories';
$editData = null;
$editUnit = null;

$mysqli->query("CREATE TABLE IF NOT EXISTS `units` (
    `unit_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `unit_name` VARCHAR(100) NOT NULL UNIQUE,
    `unit_status` VARCHAR(50) NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $res = $mysqli->query("SELECT category_id, category_name FROM categories WHERE category_id = $eid LIMIT 1");
    if ($res && $res->num_rows) {
        $editData = $res->fetch_assoc();
    }
}

if (isset($_GET['edit_unit'])) {
    $uid = intval($_GET['edit_unit']);
    $res = $mysqli->query("SELECT unit_id, unit_name, unit_status FROM units WHERE unit_id = $uid LIMIT 1");
    if ($res && $res->num_rows) {
        $editUnit = $res->fetch_assoc();
        $tab = 'units';
    }
}

if (isset($_POST['add_category'])) {
    $name = trim($_POST['category_name']);
    if ($name === '') {
        $err = __('name_required');
    } else {
        $stmt = $mysqli->prepare("INSERT INTO categories (category_name) VALUES(?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('category_added');
        } else {
            $err = __('failed_to_add');
        }
    }
}
if (isset($_POST['update_category'])) {
    $id = intval($_POST['category_id']);
    $name = trim($_POST['category_name']);
    if ($id && $name !== '') {
        $stmt = $mysqli->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('category_updated');
            $editData = null;
        } else {
            $err = __('no_change');
        }
    }
}
if (isset($_POST['add_unit'])) {
    $name = trim($_POST['unit_name']);
    if ($name === '') {
        $err = __('name_required');
        $tab = 'units';
    } else {
        $stmt = $mysqli->prepare("INSERT INTO units (unit_name) VALUES(?)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('unit_added');
        } else {
            $err = __('failed_to_add');
        }
        $tab = 'units';
    }
}
if (isset($_POST['update_unit'])) {
    $id = intval($_POST['unit_id']);
    $name = trim($_POST['unit_name']);
    $status = trim($_POST['unit_status']);
    if ($id && $name !== '') {
        $stmt = $mysqli->prepare("UPDATE units SET unit_name = ?, unit_status = ? WHERE unit_id = ?");
        $stmt->bind_param('ssi', $name, $status, $id);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('unit_updated');
            $editUnit = null;
        } else {
            $err = __('no_change');
        }
    }
    $tab = 'units';
}
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id) {
        $stmt = $mysqli->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('category_deleted');
        } else {
            $err = __('delete_failed');
        }
    }
}
if (isset($_GET['delete_unit'])) {
    $id = intval($_GET['delete_unit']);
    if ($id) {
        $stmt = $mysqli->prepare("DELETE FROM units WHERE unit_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows) {
            $success = __('unit_deleted');
        } else {
            $err = __('delete_failed');
        }
    }
    $tab = 'units';
}

require_once('partials/_head.php');
?>
<?php if ($current_lang == 'ar'): ?>
<style>
body { direction: ltr !important; text-align: left !important; }
</style>
<?php endif; ?>
<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <!-- Sidenav -->
  <?php require_once('partials/_sidebar.php'); ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php require_once('partials/_topnav.php'); ?>
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
            <div class="row mb-4">
                <div class="col">
                    <div class="card shadow p-3">
            <div class="table-responsive">
      <?php if(isset($success)):?><div class="alert alert-success"><?php echo $success;?></div><?php endif;?>
      <?php if(isset($err)):?><div class="alert alert-danger"><?php echo $err;?></div><?php endif; ?>
      <ul class="nav nav-pills mb-4">
        <li class="nav-item">
          <a class="nav-link <?php echo $tab === 'categories' ? 'active' : ''; ?>" href="categories.php?tab=categories"><?php echo __('category'); ?></a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php echo $tab === 'units' ? 'active' : ''; ?>" href="categories.php?tab=units"><?php echo __('unit'); ?></a>
        </li>
      </ul>

      <?php if ($tab === 'categories'): ?>
      <form method="post" class="form-inline mb-3" style='margin-left: 22px'>
        <input type="text" name="category_name" class="form-control mr-2" placeholder="<?php echo __('new_category'); ?>" value="<?php echo isset($editData['category_name'])?htmlspecialchars($editData['category_name']):''; ?>">
        <?php if($editData){ ?>
            <input type="hidden" name="category_id" value="<?php echo $editData['category_id']; ?>">
            <button type="submit" name="update_category" class="btn btn-sm btn-success"><?php echo __('update'); ?></button>
            <a href="categories.php?tab=categories" class="btn btn-sm btn-secondary ml-1"><?php echo __('cancel'); ?></a>
        <?php } else { ?>
            <button type="submit" name="add_category" class="btn btn-sm btn-primary"><?php echo __('add'); ?></button>
        <?php } ?>
      </form>
      <div class="table-responsive">
        <table class="table align-items-center table-flush table-sm">
          <thead class="thead-light"><tr><th scope="col"><?php echo __('name'); ?></th><th scope="col"></th></tr></thead>
          <tbody>
<?php
$res = $mysqli->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
while($cat = $res->fetch_assoc()){
    echo "<tr>";
    echo "<td>".htmlspecialchars($cat['category_name'])."</td>";
    echo "<td>";
    echo "<a href='categories.php?edit={$cat['category_id']}&tab=categories' class='btn btn-sm btn-primary mr-1'>" . __('edit') . "</a>";
    echo "<a href='categories.php?delete={$cat['category_id']}&tab=categories' class='btn btn-sm btn-danger' onclick=\"return confirm('" . __('delete_confirm') . "');\">" . __('delete') . "</a>";
    echo "</td>";
    echo "</tr>";
}
?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <form method="post" class="form-inline mb-3" style='margin-left: 22px'>
        <input type="text" name="unit_name" class="form-control mr-2" placeholder="<?php echo __('new_unit'); ?>" value="<?php echo isset($editUnit['unit_name'])?htmlspecialchars($editUnit['unit_name']):''; ?>">
        <select name="unit_status" class="form-control mr-2">
          <option value="Active" <?php echo isset($editUnit['unit_status']) && $editUnit['unit_status'] === 'Active' ? 'selected' : ''; ?>><?php echo __('active'); ?></option>
          <option value="Inactive" <?php echo isset($editUnit['unit_status']) && $editUnit['unit_status'] === 'Inactive' ? 'selected' : ''; ?>><?php echo __('inactive'); ?></option>
        </select>
        <?php if($editUnit){ ?>
            <input type="hidden" name="unit_id" value="<?php echo $editUnit['unit_id']; ?>">
            <button type="submit" name="update_unit" class="btn btn-sm btn-success"><?php echo __('update'); ?></button>
            <a href="categories.php?tab=units" class="btn btn-sm btn-secondary ml-1"><?php echo __('cancel'); ?></a>
        <?php } else { ?>
            <button type="submit" name="add_unit" class="btn btn-sm btn-primary"><?php echo __('add'); ?></button>
        <?php } ?>
      </form>
      <div class="table-responsive">
        <table class="table align-items-center table-flush table-sm">
          <thead class="thead-light"><tr><th scope="col"><?php echo __('name'); ?></th><th scope="col"><?php echo __('status'); ?></th><th scope="col"></th></tr></thead>
          <tbody>
<?php
$res = $mysqli->query("SELECT unit_id, unit_name, unit_status FROM units ORDER BY unit_name");
while($unit = $res->fetch_assoc()){
    echo "<tr>";
    echo "<td>".htmlspecialchars($unit['unit_name'])."</td>";
    echo "<td>".htmlspecialchars($unit['unit_status'])."</td>";
    echo "<td>";
    echo "<a href='categories.php?edit_unit={$unit['unit_id']}&tab=units' class='btn btn-sm btn-primary mr-1'>" . __('edit') . "</a>";
    echo "<a href='categories.php?delete_unit={$unit['unit_id']}&tab=units' class='btn btn-sm btn-danger' onclick=\"return confirm('" . __('delete_confirm') . "');\">" . __('delete') . "</a>";
    echo "</td>";
    echo "</tr>";
}
?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
          </div></div>
        </div>
      </div>
      <!-- Footer -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
</div>
  </div>
  <!-- Argon Scripts -->
  <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
