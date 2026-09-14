<?php
/**
 * Update Customer
 * Fixed SQL binding parameter count mismatch and added validation
 */
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');

check_login();

// Validate customer ID
$update = isset($_GET['update']) ? intval($_GET['update']) : 0;
if (!$update) {
    header('Location: customers.php');
    exit;
}

$err = '';
$success = '';

// Update Customer
if (isset($_POST['updateCustomer'])) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phoneno = trim($_POST['customer_phoneno'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $customer_details = trim($_POST['customer_details'] ?? '');

    // Validation
    if (empty($customer_name)) {
        $err = __('customer_name_required');
    } elseif (empty($customer_phoneno)) {
        $err = __('customer_phone_required');
    } elseif (empty($customer_email)) {
        $err = __('customer_email_required');
    } elseif (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $err = __('invalid_email_format');
    } else {
        // FIXED: Changed bind_param from 'ssssss' (6 types) to 'ssssi' (5 types matching 5 placeholders)
        $postQuery = "UPDATE rpos_customers 
                      SET customer_name = ?, customer_phoneno = ?, customer_email = ?, customer_details = ? 
                      WHERE customer_id = ?";
        $postStmt = $mysqli->prepare($postQuery);
        
        if ($postStmt) {
            $postStmt->bind_param('ssssi', $customer_name, $customer_phoneno, $customer_email, $customer_details, $update);
            $postStmt->execute();
            
            if ($postStmt->affected_rows >= 0) {
                $success = __('Customer updated successfully');
            } else {
                $err = __('no_change') . ': ' . $mysqli->error;
            }
            $postStmt->close();
        } else {
            $err = __('database_error') . ': ' . $mysqli->error;
        }
    }
}

require_once('partials/_head.php');
?>

<body>
  <!-- Sidenav -->
  <?php require_once('partials/_sidebar.php'); ?>
  <!-- Main content -->
  <div class="main-content">
    <!-- Top navbar -->
    <?php require_once('partials/_topnav.php'); ?>
    
    <?php
    $ret = "SELECT * FROM rpos_customers WHERE customer_id = ?";
    $stmt = $mysqli->prepare($ret);
    $stmt->bind_param('i', $update);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) {
        echo '<div class="container-fluid mt--8"><div class="alert alert-danger">' . __('Customer not found') . '</div></div>';
        require_once('partials/_footer.php');
        require_once('partials/_scripts.php');
        echo '</body></html>';
        exit;
    }
    
    $cust = $res->fetch_object();
    $stmt->close();
    ?>
    
    <!-- Header -->
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
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
              <h3><i class="fas fa-user-edit"></i> <?php echo __('Update Customer'); ?> - <?php echo htmlspecialchars($cust->customer_name); ?></h3>
            </div>
            <div class="card-body">
              <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                  <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
              <?php endif; ?>
              <?php if ($err): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                  <i class="fas fa-exclamation-circle"></i> <?php echo $err; ?>
                  <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
              <?php endif; ?>

              <form method="POST">
                <div class="form-row">
                  <div class="col-md-6">
                    <label><?php echo __('Customer Name'); ?> *</label>
                    <input type="text" name="customer_name" value="<?php echo htmlspecialchars($cust->customer_name); ?>" class="form-control" required>
                  </div>
                  <div class="col-md-6">
                    <label><?php echo __('Phone Number'); ?> *</label>
                    <input type="text" name="customer_phoneno" value="<?php echo htmlspecialchars($cust->customer_phoneno); ?>" class="form-control" required>
                  </div>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-12">
                    <label><?php echo __('Details'); ?></label>
                    <textarea name="customer_details" class="form-control" rows="2"><?php echo htmlspecialchars($cust->customer_details ?? ''); ?></textarea>
                  </div>
                </div>
                <hr>
                <div class="form-row">
                  <div class="col-md-6">
                    <label><?php echo __('Email'); ?> *</label>
                    <input type="email" name="customer_email" value="<?php echo htmlspecialchars($cust->customer_email); ?>" class="form-control" required>
                  </div>
                </div>
                <br>
                <div class="form-row">
                  <div class="col-md-6">
                    <button type="submit" name="updateCustomer" class="btn btn-success">
                      <i class="fas fa-save"></i> <?php echo __('Update Customer'); ?>
                    </button>
                    <a href="customers.php" class="btn btn-secondary ml-2">
                      <i class="fas fa-arrow-left"></i> <?php echo __('Back'); ?>
                    </a>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
      <!-- Footer -->
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>
  <!-- Argon Scripts -->
  <?php require_once('partials/_scripts.php'); ?>
</body>

</html>
