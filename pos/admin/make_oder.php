<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');
include('config/stock.php');

check_login();
require_once('config/languages.php');

$current_lang = $_SESSION['lang'] ?? 'en';

if (isset($_POST['make'])) {
  // Prevent Posting Blank Values
  if (empty($_POST["order_code"]) || empty($_POST["customer_name"]) || empty($_GET['prod_price'])) {
    $err = "Blank Values Not Accepted";
  } else {
    // Force server-generated unique IDs
    $order_id = $orderid;
    $order_code  = $order_code;
    $customer_id = $_POST['customer_id'];
    $customer_name = $_POST['customer_name'];
    $prod_id  = $_GET['prod_id'];
    $prod_name = $_GET['prod_name'];
    $prod_price = floatval($_GET['prod_price']);
    $prod_qty = intval($_POST['prod_qty']);
    $payment_type = $_POST['payment_type']; // pay_now or buy_with_loan

    // Calculate total amount
    $total_amount = $prod_price * $prod_qty;

    // 1. Insert Order into rpos_orders
    $created_by = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? null;
    $postQuery = "INSERT INTO rpos_orders (prod_qty, order_id, order_code, customer_id, customer_name, prod_id, prod_name, prod_price, total_amount, payment_type, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)";
    $postStmt = $mysqli->prepare($postQuery);
    $postStmt->bind_param('sssssssssss', $prod_qty, $order_id, $order_code, $customer_id, $customer_name, $prod_id, $prod_name, $prod_price, $total_amount, $payment_type, $created_by);
    $postStmt->execute();

    if ($postStmt) {
      // 2. Adjust Stock
      adjust_stock($mysqli, $prod_id, -intval($prod_qty), 'sale', $order_code, 'Order placed', $created_by);

      // 3. Logic for Loans
      if ($payment_type == 'buy_with_loan') {
        $account_id = bin2hex(random_bytes(10)); // Generate unique account ID
        $status = 'Partial';
        $paid_amount = 0;
        
        // Insert into rpos_customer_accounts
        $loanQuery = "INSERT INTO rpos_customer_accounts (account_id, customer_id, customer_name, invoice_id, total_amount, paid_amount, remaining_amount, status, created_at) VALUES (?,?,?,?,?,?,?,?, NOW())";
        $loanStmt = $mysqli->prepare($loanQuery);
        $loanStmt->bind_param('sisssdds', $account_id, $customer_id, $customer_name, $order_code, $total_amount, $paid_amount, $total_amount, $status);
        $loanStmt->execute();

        // Optional: Add initial history record
        $histQuery = "INSERT INTO rpos_customer_account_history (account_id, customer_id, customer_name, invoice_id, payment_amount, previous_paid, new_paid, previous_remaining, new_remaining, status, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $note = "Initial Loan created from order: " . $order_code;
        $histStmt = $mysqli->prepare($histQuery);
        $histStmt->bind_param('sissdddddsss', $account_id, $customer_id, $customer_name, $order_code, $paid_amount, $paid_amount, $paid_amount, $total_amount, $total_amount, $status, $note, $created_by);
        $histStmt->execute();

        $success = "Order & Loan Record Created" && header("refresh:1; url=customes.php");
      } else {
        $success = "Order Submitted" && header("refresh:1; url=payments.php");
      }
    } else {
      $err = "Please Try Again Or Try Later";
    }
  }
}
require_once('partials/_head.php');
?>

<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid">
        <div class="header-body"></div>
      </div>
    </div>
    <div class="container-fluid mt--8">
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0">
              <h3><?php echo __('please_fill_fields'); ?></h3>
            </div>
            <div class="card-body">
              <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                  <div class="col-md-4">
                    <label><?php echo __('customer_name'); ?></label>
                    <select class="form-control" name="customer_name" id="custName" onChange="getCustomer(this.value)">
                      <option value=""><?php echo __('select_customer_name'); ?></option>
                      <?php
                      $ret = "SELECT * FROM rpos_customers";
                      $stmt = $mysqli->prepare($ret);
                      $stmt->execute();
                      $res = $stmt->get_result();
                      while ($cust = $res->fetch_object()) {
                      ?>
                        <option value="<?php echo $cust->customer_name; ?>"><?php echo $cust->customer_name; ?></option>
                      <?php } ?>
                    </select>
                    <input type="hidden" name="order_id" value="<?php echo $orderid; ?>">
                  </div>

                  <div class="col-md-4">
                    <label><?php echo __('customer_id'); ?></label>
                    <input type="text" name="customer_id" readonly id="customerID" class="form-control">
                  </div>

                  <div class="col-md-4">
                    <label><?php echo __('order_code'); ?></label>
                    <input type="text" name="order_code" readonly value="<?php echo htmlspecialchars($order_code ?? ($alpha . '-' . $beta), ENT_QUOTES); ?>" class="form-control">
                  </div>
                </div>

                <hr>
                
                <div class="form-row">
                  <div class="col-md-6">
                    <label>Payment Method</label>
                    <select name="payment_type" class="form-control" required>
                      <option value="pay_now">Pay Now (Cash/Instant)</option>
                      <option value="buy_with_loan">Buy with Loan (Credit)</option>
                    </select>
                  </div>
                </div>

                <hr>

                <?php
                $prod_id = $_GET['prod_id'];
                $ret = "SELECT * FROM rpos_products WHERE prod_id = '$prod_id'";
                $stmt = $mysqli->prepare($ret);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($prod = $res->fetch_object()) {
                ?>
                  <div class="form-row">
                    <div class="col-md-6">
                      <label><?php echo __('product_price'); ?></label>
                    <input type="text" readonly name="prod_price_display" id="prodPriceDisplay" value="$ <?php echo $prod->prod_price; ?>" class="form-control">
                    <input type="hidden" id="prodPriceValue" value="<?php echo $prod->prod_price; ?>">
                  </div>
                  <div class="col-md-6">
                    <label><?php echo __('product_quantity'); ?></label>
                    <input type="number" name="prod_qty" id="prodQty" class="form-control" required min="1" value="1">
                    </div>
                  </div>
                <?php } ?>

                <div class="form-row mt-3">
                  <div class="col-md-12">
                    <div id="loanAmountRow" style="display: none;" class="alert alert-info p-2">
                      <strong><?php echo __('loan_amount') ?? 'Loan Amount'; ?>:</strong> <span id="loanAmountValue">0.00</span>
                    </div>
                    <div id="totalAmountRow" class="alert alert-success p-2">
                      <strong><?php echo __('total_amount') ?? 'Total Amount'; ?>:</strong> <span id="totalAmountValue">0.00</span>
                    </div>
                  </div>
                </div>

                <br>
                <div class="form-row">
                  <div class="col-md-6">
                    <input type="submit" name="make" value="<?php echo __('make_order'); ?>" class="btn btn-success">
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
    // Existing script to fill Customer ID based on Name
    function getCustomer(val) {
      $.ajax({
        type: "POST",
        url: "customer_ajax.php", // Ensure you have this file to return customer details
        data: 'custName=' + val,
        success: function(data) {
          $('#customerID').val(data);
        }
      });
    }

    function refreshLoanAndTotal() {
      const price = parseFloat($('#prodPriceValue').val() || 0);
      const qty = parseInt($('#prodQty').val() || 0, 10);
      const paymentType = $('select[name="payment_type"]').val();
      const total = price * qty;
      $('#totalAmountValue').text(total.toFixed(2));

      if (paymentType === 'buy_with_loan') {
        $('#loanAmountRow').show();
        $('#loanAmountValue').text(total.toFixed(2));
      } else {
        $('#loanAmountRow').hide();
      }
    }

    $(document).ready(function() {
      $('#prodQty, select[name="payment_type"]').on('input change', refreshLoanAndTotal);
      refreshLoanAndTotal();
    });
  </script>
</body>
</html>