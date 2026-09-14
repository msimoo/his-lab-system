<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');
include('config/languages.php');

check_login();

//Add Customer
if (isset($_POST['addCustomer'])) {
  //Prevent Posting Blank Values
  
  if (empty($_POST["customer_phoneno"]) || empty($_POST["customer_name"]) || empty($_POST['customer_email'])) {
    $err = __('blank_values');
  } else {
    $customer_name = $_POST['customer_name'];
    $customer_phoneno = $_POST['customer_phoneno'];
    $customer_email = $_POST['customer_email'];
    //$customer_password = sha1(md5($_POST['customer_password'])); //Hash This 
    $customer_details = trim($_POST['customer_details'] ?? '');
    $customer_id = $_POST['customer_id'];

    //Insert Captured information to a database table
    $postQuery = "INSERT INTO rpos_customers (customer_id, customer_name, customer_phoneno, customer_email, customer_details) VALUES(?,?,?,?,?)";
    $postStmt = $mysqli->prepare($postQuery);
    
    //bind paramaters
    $rc = $postStmt->bind_param('sssss', $customer_id, $customer_name, $customer_phoneno, $customer_email, $customer_details);
    $postStmt->execute();
    
    //declare a varible which will be passed to alert function
    if ($postStmt) {
      $success = __('customer_added') && header("refresh:1; url=customes.php");
    } else {
      $err = __('try_again');
    }
  }
}

require_once('partials/_head.php');
?>
<style>
<?php if ($current_lang == 'ar'): ?>
body { direction: ltr !important; text-align: left !important; }
<?php endif; ?>
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
              <form method="POST">
                <div class="form-row">
                  <div class="col-md-6">
                    <label><?php echo __('customer_name'); ?></label>
                    <input type="text" name="customer_name" class="form-control">
                    <input type="hidden" name="customer_id" value="<?php echo $cus_id; ?>" class="form-control">
                  </div>
                  <div class="col-md-6">
                    <label><?php echo __('customer_phone_number'); ?></label>
                    <input type="text" name="customer_phoneno" class="form-control" value="">
                  </div>
                </div>
                <div class="form-row mt-3">
                  <div class="col-md-12">
                    <label><?php echo __('important_details'); ?></label>
                    <textarea name="customer_details" class="form-control" rows="2"></textarea>
                  </div>
                </div>
                <hr>
                <div class="form-row">
                  <div class="col-md-6">
                    <label><?php echo __('customer_email'); ?></label>
                    <input type="email" name="customer_email" class="form-control" value="">
                  </div>
                  <!--<div class="col-md-6">
                    <label><?php echo __('customer_password'); ?></label>
                    <input type="password" name="customer_password" class="form-control" value="">
                  </div>-->
                </div>
                <br>
                <div class="form-row">
                  <div class="col-md-6">
                    <input type="submit" name="addCustomer" value="<?php echo __('add_customer'); ?>" class="btn btn-success" value="">
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
      ?>
    </div>
  </div>
  <!-- Argon Scripts -->
  <?php
  require_once('partials/_scripts.php');
  ?>
</body>

</html>
