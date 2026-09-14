<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// Handle Deletion
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $adn = "DELETE FROM rpos_hrm_extras WHERE extra_id = ?";
    $stmt = $mysqli->prepare($adn);
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $stmt->close();
    if ($stmt) {
        $success = __('record_deleted');
    } else {
        $err = __('try_again_later');
    }
}

// Handle Adding Allowance/Incentive
if (isset($_POST['add_extra'])) {
    $extra_id = bin2hex(random_bytes(10));
    $staff_id = $_POST['staff_id'];
    $type = $_POST['type']; // Allowance or Incentive
    $amount = $_POST['amount'];
    $description = $_POST['description'];
    $month_year = date('m-Y'); // Link to current month automatically

    $query = "INSERT INTO rpos_hrm_extras (extra_id, staff_id, type, amount, description, month_year) VALUES(?,?,?,?,?,?)";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('sissss', $extra_id, $staff_id, $type, $amount, $description, $month_year);
    $stmt->execute();
    if ($stmt) {
        $success = __('extra_added_successfully');
    } else {
        $err = __('please_try_again_later');
    }
}

require_once('partials/_head.php');
?>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    
                </div>
            </div>
        </div>
        <div class="container-fluid mt--8">
            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <div class="row align-items-center">
                                <div class="col"> 
                                    <h3 class="mb-0"><?php echo __('Staff_Allowances_Incentives'); ?></h3>
                                </div>
                                <div class="col text-right">
                                    <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addExtraModal">
                                        <i class="fas fa-plus"></i><?php echo __('Add_New_Record'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table align-items-center table-flush" id="extrasTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col"><?php echo __('staff_name'); ?></th>
                                        <th scope="col"><?php echo __('type'); ?></th>
                                        <th scope="col"><?php echo __('amount'); ?></th>
                                        <th scope="col"><?php echo __('description_reason'); ?></th>
                                        <th scope="col"><?php echo __('month_year'); ?></th>
                                        <th scope="col"><?php echo __('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = "SELECT e.*, s.staff_name FROM rpos_hrm_extras e JOIN rpos_staff s ON e.staff_id = s.staff_id ORDER BY e.month_year DESC";
                                    $stmt = $mysqli->prepare($ret);
                                    $stmt->execute();
                                    $res = $stmt->get_result();
                                    while ($extra = $res->fetch_object()) {
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $extra->staff_name; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo ($extra->type == 'Incentive') ? 'badge-success' : 'badge-info'; ?>">
                                                    <?php echo __(''.strtolower($extra->type).'' ); ?>
                                                </span>
                                            </td>
                                            <td class="text-success font-weight-bold">$ <?php echo $extra->amount; ?></td>
                                            <td><?php echo $extra->description; ?></td>
                                            <td><?php echo $extra->month_year; ?></td>
                                            <td>
                                                <a href="hrm_extras.php?delete=<?php echo $extra->extra_id; ?>" class="btn btn-sm btn-danger">
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

            <div class="modal fade" id="addExtraModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?php echo __('hrm_add_extra_payment'); ?></h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label><?php echo __('select_staff_member'); ?></label>
                                    <select name="staff_id" class="form-control" required>
                                        <option value=""><?php echo __('choose_employee'); ?></option>
                                        <?php
                                        $staff_res = $mysqli->query("SELECT staff_id, staff_name FROM rpos_staff ORDER BY staff_name ASC");
                                        while ($s = $staff_res->fetch_object()) {
                                            echo "<option value='{$s->staff_id}'>{$s->staff_name}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?php echo __('type'); ?></label>
                                            <select name="type" class="form-control">
                                                <option value="Allowance"><?php echo __('allowance'); ?></option>
                                                <option value="Incentive"><?php echo __('incentive'); ?></option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?php echo __('amount'); ?></label>
                                            <input type="number" name="amount" class="form-control" placeholder="0.00" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label><?php echo __('description_reason'); ?></label>
                                    <textarea name="description" class="form-control" rows="2" placeholder="<?php echo __('description_placeholder'); ?>"></textarea>
                                    <small class="text-muted"><?php echo sprintf(__('current_month_notice'), date('M Y')); ?></small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('cancel'); ?></button>
                                <button type="submit" name="add_extra" class="btn btn-primary"><?php echo __('save_changes'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <?php require_once('partials/_scripts.php'); ?>
    <script>
        $(document).ready(function() {
            $('#extrasTable').DataTable({
                "pageLength": 10,
                "order": [[4, "desc"]], // Sort by Month/Year
                "language": {
                    "paginate": {
                        "previous": "<i class='fas fa-angle-left'></i>",
                        "next": "<i class='fas fa-angle-right'></i>"
                    }
                }
            });
        });
    </script>
</body>

</html>
