<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

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
            <div class="row mb-4">
                <div class="col">
                    <div class="card shadow p-3">
            <div class="card-header border-0">
              <?php echo __('select_product_order'); ?>
            </div>
            <div class="table-responsive">
              <table id="myDataTable" class="table align-items-center table-flush table-sm">
                <thead class="thead-light">
                  <tr>
                    <th scope="col"><b><?php echo __('image'); ?></b></th>
                    <th scope="col"><b><?php echo __('product_code'); ?></b></th>
                    <th scope="col"><b><?php echo __('name'); ?></b></th>
                    <th scope="col"><b><?php echo __('price'); ?></b></th>
                    <th scope="col"><b><?php echo __('action'); ?></b></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ret = "SELECT * FROM  rpos_products ";
                  $stmt = $mysqli->prepare($ret);
                  $stmt->execute();
                  $res = $stmt->get_result();
                  while ($prod = $res->fetch_object()) {
                  ?>
                    <tr>
                      <td>
                        <?php
                        if ($prod->prod_img) {
                          echo "<img src='assets/img/products/$prod->prod_img' height='60' width='60 class='img-thumbnail'>";
                        } else {
                          echo "<img src='assets/img/products/default.jpg' height='60' width='60 class='img-thumbnail'>";
                        }

                        ?>
                      </td>
                      <td><?php echo $prod->prod_code; ?></td>
                      <td><?php echo $prod->prod_name; ?></td>
                      <td>$ <?php echo $prod->prod_price; ?></td>
                      <td>
                        <a href="make_oder.php?prod_id=<?php echo $prod->prod_id; ?>&prod_name=<?php echo $prod->prod_name; ?>&prod_price=<?php echo $prod->prod_price; ?>">
                          <button class="btn btn-sm btn-warning">
                            <i class="fas fa-cart-plus"></i>
                            <?php echo __('place_order'); ?>
                          </button>
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
   <script>

        $(document).ready(function() {
            $('#myDataTable').DataTable({
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "pageLength": 10,
                "language": {
                    "search": "",
                    "searchPlaceholder": "Search filter result..."
                }
            });
        });
    </script>
</body>

</html>
