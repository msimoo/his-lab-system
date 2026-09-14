<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

// Delete Customer
if (isset($_GET['delete'])) {
  $id = $_GET['delete'];
  $adn = "DELETE FROM rpos_customers WHERE customer_id = ?";
  $stmt = $mysqli->prepare($adn);
  $stmt->bind_param('s', $id);
  $stmt->execute();
  $stmt->close();
  if ($stmt) {
    $success = __('deleted') && header("refresh:1; url=customes.php");
  } else {
    $err = __('try_again');
  }
}

// Handle AJAX request for Payments (Full or Partial)
if (isset($_POST['pay_remaining_customer']) && isset($_POST['customer_account_id'])) {
    $accountId = $_POST['customer_account_id'];
    $payAmountRaw = trim($_POST['payment_amount'] ?? '');
    $resp = ['success' => false, 'message' => __('failed_to_update')];

    // Fetch current account status
    $qry = $mysqli->prepare("SELECT total_amount, paid_amount, remaining_amount, status, customer_id, customer_name, invoice_id FROM rpos_customer_accounts WHERE account_id = ? LIMIT 1");
    $qry->bind_param('s', $accountId);
    $qry->execute();
    $result = $qry->get_result();

    if ($item = $result->fetch_assoc()) {
        $paid = floatval($item['paid_amount']);
        $remaining = floatval($item['remaining_amount']);
        
        if ($remaining <= 0) {
            $resp['message'] = __('already_paid');
        } else {
            // Determine payment amount
            $payment = ($payAmountRaw !== '') ? floatval($payAmountRaw) : $remaining;

            // Strict Validation
            if ($payment <= 0) {
                $resp['message'] = "Invalid payment amount. Must be greater than 0.";
            } elseif ($payment > $remaining) {
                $resp['message'] = "Payment exceeds the remaining balance. Maximum allowed is $" . number_format($remaining, 2);
            } else {
                $newPaid = $paid + $payment;
                $newRemaining = $remaining - $payment;
                $newStatus = $newRemaining <= 0 ? 'Paid' : 'Partial';

                // Update Account
                $update = $mysqli->prepare("UPDATE rpos_customer_accounts SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE account_id = ?");
                $update->bind_param('ddss', $newPaid, $newRemaining, $newStatus, $accountId);
                
                if ($update->execute()) {
                    // Log to History Table
                    $history = $mysqli->prepare("INSERT INTO rpos_customer_account_history (account_id, customer_id, customer_name, invoice_id, payment_amount, previous_paid, new_paid, previous_remaining, new_remaining, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
                    $createdBy = $_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? null;
                    $history->bind_param('sissdddddss', $accountId, intval($item['customer_id']), $item['customer_name'], $item['invoice_id'], $payment, $paid, $newPaid, $remaining, $newRemaining, $newStatus, $createdBy);
                    $history->execute();
                    $history->close();

                    $resp['success'] = true;
                    $resp['message'] = $newRemaining <= 0 ? "Successfully paid in full!" : "Partial payment of $" . number_format($payment, 2) . " accepted.";
                }
                $update->close();
            }
        }
    }
    $qry->close();
    header('Content-Type: application/json');
    echo json_encode($resp);
    exit;
}

// AJAX: Fetch all accounts to refresh local JS data seamlessly
if (isset($_GET['fetch_customer_accounts'])) {
    $customerAccounts = [];
    $accRes = $mysqli->query("SELECT account_id, customer_id, customer_name, invoice_id, total_amount, paid_amount, remaining_amount, status, created_at FROM rpos_customer_accounts ORDER BY created_at DESC");
    if ($accRes) {
        while ($rowAcc = $accRes->fetch_assoc()) {
            $customerAccounts[$rowAcc['customer_id']][] = $rowAcc;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($customerAccounts);
    exit;
}

// Preload accounts for initial page load
$customerAccounts = [];
$accRes = $mysqli->query("SELECT account_id, customer_id, customer_name, invoice_id, total_amount, paid_amount, remaining_amount, status, created_at FROM rpos_customer_accounts ORDER BY created_at DESC");
if ($accRes) {
    while ($rowAcc = $accRes->fetch_assoc()) {
        $customerAccounts[$rowAcc['customer_id']][] = $rowAcc;
    }
}

require_once('partials/_head.php');
?>
<style>
  <?php if ($current_lang == 'ar'): ?>
    body { direction: ltr !important; text-align: left !important; }
  <?php endif; ?>
  .modal-summary p { margin-bottom: 5px; font-size: 1.1em; }
  .progress-sm { height: 8px; border-radius: 4px; background-color: #e9ecef; margin-top: 5px; }
</style>

<body <?php //echo $current_lang == 'ar' ? 'dir="ltr"' : ''; ?>>
  <?php require_once('partials/_sidebar.php'); ?>
  
  <div class="main-content">
    <?php require_once('partials/_topnav.php'); ?>
    
    <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
      <span class="mask bg-gradient-dark opacity-8"></span>
      <div class="container-fluid"><div class="header-body"></div></div>
    </div>

    <div class="container-fluid mt--8">
      <div class="row">
        <div class="col">
          <div class="card shadow">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
              <h3 class="mb-0"><?php echo __('customer_Directory_Accounts'); ?></h3>
              <a href="add_customer.php" class="btn btn-sm btn-success">
                <i class="fas fa-user-plus"></i> <?php echo __('add_new_customer'); ?>
              </a>
            </div>
            <div class="table-responsive p-3">
              <table class="table align-items-center table-flush table-hover" id="customersTable">
                <thead class="thead-light">
                  <tr>
                    <th scope="col"><?php echo __('full_name'); ?></th>
                    <th scope="col"><?php echo __('contact_number'); ?></th>
                    <th scope="col"><?php echo __('email'); ?></th>
                    <th scope="col" class="text-center">Financial Account</th>
                    <th scope="col"><?php echo __('actions'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ret = "SELECT * FROM rpos_customers ORDER BY created_at DESC";
                  $stmt = $mysqli->prepare($ret);
                  $stmt->execute();
                  $res = $stmt->get_result();
                  while ($cust = $res->fetch_object()) {
                  ?>
                    <tr>
                      <td class="font-weight-bold text-dark"><?php echo $cust->customer_name; ?></td>
                      <td><?php echo $cust->customer_phoneno; ?></td>
                      <td><?php echo $cust->customer_email; ?></td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-info view-customer-account-btn shadow-sm" data-customer-id="<?php echo $cust->customer_id; ?>" data-customer-name="<?php echo htmlspecialchars($cust->customer_name); ?>">
                          <i class="fas fa-file-invoice-dollar"></i> View Ledgers
                        </button>
                      </td>
                      <td>
                        <a href="update_customer.php?update=<?php echo $cust->customer_id; ?>" class="btn btn-sm btn-primary">
                          <i class="fas fa-user-edit"></i>
                        </a>
                        <a href="customes.php?delete=<?php echo $cust->customer_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('WARNING: Are you sure you want to delete this customer? All their data will be lost.')">
                          <i class="fas fa-trash"></i>
                        </a>
                      </td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="customerAccountModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
          <div class="modal-content">
            <div class="modal-header bg-secondary">
              <h5 class="modal-title font-weight-bold" id="modalCustomerName">Customer Account</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body bg-secondary">
              <div class="card shadow-sm">
                <div class="card-body">
                  <div id="customerAccountModalContent"></div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-dark" data-dismiss="modal"><?php echo __('close'); ?></button>
            </div>
          </div>
        </div>
      </div>
 
      <?php require_once('partials/_footer.php'); ?>
    </div>
  </div>

  <?php require_once('partials/_scripts.php'); ?>

  <script>
    // Data preloaded from PHP
    let customerAccounts = <?php echo json_encode($customerAccounts); ?>;

    function formatCurrency(value) {
      return parseFloat(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderCustomerAccountModal(customerId, filterTerm = '') {
      const rows = customerAccounts[customerId] || [];
      
      let html = '<div class="row mb-3 align-items-center">';
      html += '<div class="col-md-6"><span class="badge badge-primary">ID: ' + customerId + '</span></div>';
      html += '<div class="col-md-6"><input type="text" id="accountSearchInput" class="form-control form-control-sm" placeholder="Search Invoices..." value="'+$('<div>').text(filterTerm).html()+'"></div></div>';
      
      if (!rows.length) {
        html += '<div class="alert alert-info text-center mt-3"><i class="fas fa-info-circle"></i> No financial records or loans found for this customer.</div>';
      } else {
        html += '<div class="table-responsive"><table class="table align-items-center table-flush table-hover">';
        html += '<thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Progress</th><th>Total</th><th>Paid</th><th>Remaining</th><th>Status</th><th class="text-center">Action</th></tr></thead><tbody>';

        const search = filterTerm.trim().toLowerCase();
        let sumTotal = 0, sumPaid = 0, sumRemaining = 0;

        rows.forEach(function(record) {
          const matches = !search || record.invoice_id.toLowerCase().includes(search) || record.status.toLowerCase().includes(search);
          if (!matches) return;

          let tAmt = parseFloat(record.total_amount);
          let pAmt = parseFloat(record.paid_amount);
          let rAmt = parseFloat(record.remaining_amount);

          sumTotal += tAmt; sumPaid += pAmt; sumRemaining += rAmt;

          // Calculate Progress Bar Width
          let progressPct = (tAmt > 0) ? (pAmt / tAmt) * 100 : 0;
          let progressColor = progressPct === 100 ? 'bg-success' : (progressPct > 0 ? 'bg-warning' : 'bg-danger');

          // Formatting Date
          let dateObj = new Date(record.created_at);
          let formattedDate = dateObj.toLocaleDateString() + ' ' + dateObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

          html += '<tr>';
          html += '<td class="font-weight-bold text-dark">' + record.invoice_id + '</td>';
          html += '<td style="font-size: 0.9em;">' + formattedDate + '</td>';
          html += '<td><div class="progress progress-sm"><div class="progress-bar ' + progressColor + '" style="width: ' + progressPct + '%"></div></div><small class="text-muted">' + Math.round(progressPct) + '% Paid</small></td>';
          html += '<td>$' + formatCurrency(tAmt) + '</td>';
          html += '<td class="text-success font-weight-bold">$' + formatCurrency(pAmt) + '</td>';
          html += '<td class="text-danger font-weight-bold">$' + formatCurrency(rAmt) + '</td>';
          
          let badgeStatus = record.status === 'Paid' ? 'badge-success' : (record.status === 'Partial' ? 'badge-warning' : 'badge-danger');
          html += '<td><span class="badge ' + badgeStatus + '">' + record.status.toUpperCase() + '</span></td>';
          
          html += '<td class="text-center">';
          if (rAmt > 0) {
            // Feature: Split buttons for Partial and Full pay
            html += '<button class="btn btn-sm btn-success pay-full-btn mr-1" data-id="'+record.account_id+'" data-rem="'+rAmt+'" title="Pay Full Remaining"><i class="fas fa-check-double"></i> Full</button>';
            html += '<button class="btn btn-sm btn-warning pay-partial-btn" data-id="'+record.account_id+'" data-rem="'+rAmt+'" title="Pay Partial Amount"><i class="fas fa-edit"></i> Partial</button>';
          } else {
            html += '<span class="text-success font-weight-bold"><i class="fas fa-check-circle"></i> Settled</span>';
          }
          html += ' <a target="_blank" href="print_customer.php?customer_id='+customerId+'&account_id='+record.account_id+'" class="btn btn-sm btn-outline-dark ml-2" title="Print Receipt"><i class="fas fa-print"></i></a>';
          html += '</td></tr>';
        });

        html += '</tbody></table></div>';
        
        // Dashboard Summary Section
        let overallPct = (sumTotal > 0) ? (sumPaid / sumTotal) * 100 : 0;
        let overallColor = overallPct === 100 ? 'bg-success' : (overallPct > 50 ? 'bg-warning' : 'bg-danger');

        html += '<div class="row mt-4 pt-3 border-top">';
        html += '<div class="col-md-6">';
        html += '  <h5 class="text-muted mb-1">Overall Account Health</h5>';
        html += '  <div class="progress" style="height: 15px;"><div class="progress-bar progress-bar-striped ' + overallColor + '" style="width: ' + overallPct + '%"></div></div>';
        html += '  <small class="text-muted">' + Math.round(overallPct) + '% of all loans paid off.</small>';
        html += '</div>';
        html += '<div class="col-md-6 modal-summary text-right">';
        html += '  <p class="text-muted"><strong>Total Lifetime Loans:</strong> $' + formatCurrency(sumTotal) + '</p>';
        html += '  <p class="text-success"><strong>Total Amount Paid:</strong> $' + formatCurrency(sumPaid) + '</p>';
        html += '  <h3 class="text-danger mt-2 border-top pt-2"><strong>Total Remaining Debt:</strong> $' + formatCurrency(sumRemaining) + '</h3>';
        html += '</div></div>';
      }

      $('#customerAccountModalContent').html(html);

      // Re-bind Search event
      $('#accountSearchInput').on('input', function() {
        renderCustomerAccountModal(customerId, $(this).val());
      });

      // Event: PAY FULL BUTTON
      $('.pay-full-btn').on('click', function() {
        const accId = $(this).data('id');
        const rem = parseFloat($(this).data('rem'));
        
        if (confirm("Are you sure you want to PAY IN FULL?\n\nAmount to pay: $" + rem.toFixed(2))) {
          processPayment(customerId, accId, rem);
        }
      });

      // Event: PAY PARTIAL BUTTON
      $('.pay-partial-btn').on('click', function() {
        const accId = $(this).data('id');
        const rem = parseFloat($(this).data('rem'));
        
        const amount = prompt("Enter PARTIAL payment amount:\n(Maximum allowed: $" + rem.toFixed(2) + ")", "");
        
        if (amount === null) return; // User cancelled prompt
        const pAmount = parseFloat(amount);

        if (isNaN(pAmount) || pAmount <= 0) {
          alert("Error: Please enter a valid number greater than 0.");
          return;
        }
        if (pAmount > rem) {
          alert("Error: You cannot pay more than the remaining balance ($" + rem.toFixed(2) + ").");
          return;
        }

        if (confirm("Please confirm your partial payment:\n\nAmount to pay: $" + pAmount.toFixed(2) + "\nRemaining balance will be: $" + (rem - pAmount).toFixed(2))) {
          processPayment(customerId, accId, pAmount);
        }
      });
    }

    // Helper Function to send AJAX request
    function processPayment(customerId, accId, amount) {
      $.post('customes.php', { 
        pay_remaining_customer: 1, 
        customer_account_id: accId, 
        payment_amount: amount 
      }, function(res) {
        if (res.success) {
          alert("Success: " + res.message);
          // Refresh JSON data in background and re-render modal seamlessly
          $.getJSON('customes.php?fetch_customer_accounts=1', function(updated) {
            customerAccounts = updated;
            renderCustomerAccountModal(customerId, $('#accountSearchInput').val());
          });
        } else {
          alert("Error: " + res.message);
        }
      }, 'json').fail(function() {
          alert("A network error occurred while processing the payment.");
      });
    }

    $(document).ready(function() {
      // Basic DataTable for main list
      if (!$.fn.dataTable.isDataTable('#customersTable')) {
          $('#customersTable').DataTable({ language: { search: "", searchPlaceholder: "Search Customers..." } });
      }

      // Open Modal
      $('.view-customer-account-btn').on('click', function() {
        const cid = $(this).data('customer-id');
        const cname = $(this).data('customer-name');
        
        $('#modalCustomerName').html('<i class="fas fa-user-circle"></i> Account Ledger: ' + cname);
        renderCustomerAccountModal(cid);
        $('#customerAccountModal').modal('show');
      });
    });
  </script>
</body>
</html>