<?php
$admin_id = $_SESSION['admin_id'];
//$login_id = $_SESSION['login_id'];
$current_page = basename($_SERVER['PHP_SELF']);
function sidebar_active($page, $current) {
    return $page === $current ? ' active text-white' : '';
}
$ret = "SELECT * FROM  rpos_admin  WHERE admin_id = '$admin_id'";
$stmt = $mysqli->prepare($ret);
$stmt->execute();
$res = $stmt->get_result();
while ($admin = $res->fetch_object()) {
// Translation function
if (!function_exists('__')) {
    function __($key) {
        global $lang, $current_lang;
        return $lang[$current_lang][$key] ?? $key;
    }
} 
?>
<style>
  .bg-primary {
    background-color: #5e72e4 !important;
  }

  /* Professional Sidebar Styling */
  #sidenav-main {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 0 0 20px 0;
    overflow-y: auto;
    height: 100vh;
  }

  #sidenav-main .navbar-brand {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    transition: all 0.3s ease;
  }

  #sidenav-main .navbar-brand:hover {
    background: rgba(255,255,255,0.2);
    transform: scale(1.05);
  }

  #sidenav-main .navbar-nav .nav-item .nav-link {
    color: rgba(255,255,255,0.8);
    border-radius: 8px;
    margin: 2px 0;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
  }

  #sidenav-main .navbar-nav .nav-item .nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
  }

  #sidenav-main .navbar-nav .nav-item .nav-link:hover::before {
    left: 100%;
  }

  #sidenav-main .navbar-nav .nav-item .nav-link:hover {
    color: #ffffff;
    background: rgba(255,255,255,0.1);
    transform: translateX(5px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
  }

  #sidenav-main .navbar-nav .nav-item.active .nav-link {
    background: linear-gradient(135deg, #ff6b6b, #ffa726);
    color: #ffffff;
    box-shadow: 0 4px 20px rgba(255,107,107,0.4);
    animation: pulse 2s infinite;
  }

  @keyframes pulse {
    0% { box-shadow: 0 4px 20px rgba(255,107,107,0.4); }
    50% { box-shadow: 0 4px 25px rgba(255,107,107,0.6); }
    100% { box-shadow: 0 4px 20px rgba(255,107,107,0.4); }
  }

  #sidenav-main .navbar-nav .nav-item .nav-link i {
    transition: transform 0.3s ease;
  }

  #sidenav-main .navbar-nav .nav-item .nav-link:hover i {
    transform: scale(1.2) rotate(10deg);
  }

  #sidenav-main .navbar-heading {
    color: rgba(255,255,255,0.6);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid rgba(255,255,255,0.2);
    padding-bottom: 5px;
  }

  #sidenav-main hr {
    border-color: rgba(255,255,255,0.2);
  }

  #sidenav-main .input-group-rounded .form-control {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    color: #ffffff;
  }

  #sidenav-main .input-group-rounded .form-control::placeholder {
    color: rgba(255,255,255,0.6);
  }

  #sidenav-main .input-group-rounded .input-group-text {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    color: rgba(255,255,255,0.8);
  }

  /* Collapsible menu styling */
  #sidenav-main .nav .nav-sm .nav-link {
    color: rgba(255,255,255,0.7);
    font-size: 0.9em;
    transition: all 0.3s ease;
  }

  #sidenav-main .nav .nav-sm .nav-link:hover {
    color: #ffffff;
    background: rgba(255,255,255,0.1);
    transform: translateX(10px);
  }

  #sidenav-main .nav .nav-sm .nav-item.active .nav-link {
    background: rgba(255,255,255,0.2);
    color: #ffffff;
  }

  /* User dropdown in mobile */
  #sidenav-main .nav.align-items-center .dropdown-menu {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
  }
  
  /* Financial Settings Link */
  #sidenav-main .nav-item.financial-settings .nav-link {
    background: linear-gradient(135deg, rgba(255,215,0,0.2), rgba(255,165,0,0.2)) !important;
    border-left: 3px solid #ffd700;
  }
  
  #sidenav-main .nav-item.financial-settings .nav-link:hover {
    background: linear-gradient(135deg, rgba(255,215,0,0.3), rgba(255,165,0,0.3)) !important;
  }

  #sidenav-main .nav.align-items-center .dropdown-menu .dropdown-item {
    color: rgba(255,255,255,0.8);
    transition: all 0.3s ease;
  }

  #sidenav-main .nav.align-items-center .dropdown-menu .dropdown-item:hover {
    color: #ffffff;
    background: rgba(255,255,255,0.1);
    transform: translateX(5px);
  }

  /* Navbar toggler */
  #sidenav-main .navbar-toggler {
    border: none;
    background: rgba(255,255,255,0.1);
    color: #ffffff;
    transition: all 0.3s ease;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    padding: 8px 10px;
    z-index: 1050;
  }

  #sidenav-main .navbar-toggler:hover {
    background: rgba(255,255,255,0.2);
    transform: scale(1.1);
  }

  /* Use Font Awesome for visible toggler icons */
  #sidenav-main .navbar-toggler i {
    color: #ffffff;
    font-size: 18px;
    line-height: 1;
    display: inline-block;
  }
  /* Hide default bootstrap toggler icon if present */
  #sidenav-main .navbar-toggler .navbar-toggler-icon { display: none; }

  /* Collapse header */
  #sidenav-main .navbar-collapse-header {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
  }

  /* Scrollbar styling */
  #sidenav-main::-webkit-scrollbar {
    width: 6px;
  }

  #sidenav-main::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
  }

  #sidenav-main::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
  }

  #sidenav-main::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
  }
</style>
  <nav class="navbar navbar-vertical fixed-left navbar-expand-md navbar-light bg-white" id="sidenav-main">
    <div class="container-fluid">
      <!-- Toggler 
      <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#sidenav-collapse-main" aria-controls="sidenav-main" aria-expanded="false" aria-label="Toggle navigation">
        <i class="fas fa-bars" aria-hidden="true"></i>
      </button>-->
      <!-- Brand -->
      <a class="navbar-brand pt-0" href="dashboard.php">
        <img src="assets/logo.png" alt="Logo" class="navbar-brand-img" style="max-height: 40px; width: auto;" />
      </a>
      <!-- User -->
      <ul class="nav align-items-center d-md-none">
        <li class="nav-item dropdown">
          <a class="nav-link nav-link-icon" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="ni ni-bell-55"></i>
          </a>
          <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right" aria-labelledby="navbar-default_dropdown_1">
          </div>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <div class="media align-items-center">
              <span class="avatar avatar-sm rounded-circle">
                <img alt="Image placeholder" src="assets/img/theme/restro00.jpg">
              </span>
            </div>
          </a>
          <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-right">
            <div class=" dropdown-header noti-title">
              <h6 class="text-overflow m-0">Welcome!</h6>
            </div>
            <a href="change_profile.php" class="dropdown-item">
              <i class="ni ni-single-02"></i>
              <span>My profile</span>
            </a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item">
              <i class="ni ni-user-run"></i>
              <span>Logout</span>
            </a>
          </div>
        </li>
      </ul>
      <!-- Collapse -->
      <div class="collapse navbar-collapse" id="sidenav-collapse-main">
        <!-- Collapse header -->
        <div class="navbar-collapse-header d-md-none">
          <div class="row">
            <div class="col-6 collapse-brand">
              <a href="dashboard.php"> 
              </a>
            </div>
            <div class="col-6 collapse-close">
              <button type="button" class="navbar-toggler" data-toggle="collapse" data-target="#sidenav-collapse-main" aria-controls="sidenav-main" aria-expanded="false" aria-label="Toggle sidenav">
                <i class="fas fa-times" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </div>
        <!-- Form -->
        <form class="mt-4 mb-3">
          <div class="input-group input-group-rounded input-group-merge">
            <input id="sidebarNavSearch" type="search" class="form-control form-control-rounded form-control-prepended" placeholder="<?php echo __('search_menu'); ?>" aria-label="Search">
            <div class="input-group-prepend">
              <div class="input-group-text">
                <span class="fa fa-search"></span>
              </div>
            </div>
          </div>
        </form>
        <!-- Navigation -->
        <h6 class="navbar-heading text-muted mb-2"><?php echo __('Dashboard'); ?></h6>
        <ul class="navbar-nav">
          <li class="nav-item<?php echo sidebar_active('dashboard.php', $current_page); ?>">
            <a class="nav-link" href="dashboard.php">
              <i class="ni ni-tv-2 <?php echo sidebar_active('dashboard.php', $current_page); ?>"></i><?php echo __('dashboard'); ?>
            </a>
          </li>
          <li class="nav-item<?php echo sidebar_active('executive_dashboard.php', $current_page); ?>">
            <a class="nav-link" href="executive_dashboard.php">
              <i class="fas fa-chart-pie text-warning"></i> Executive Dashboard
            </a>
          </li>
          

          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'expenses.php')): ?>
            <li class="nav-item<?php echo sidebar_active('expenses.php', $current_page); ?>">
              <a class="nav-link" href="expenses.php">
                <i class="fas fa-user-tie text-danger"></i><?php echo __('expenses'); ?> 
              </a>
            </li>
          <?php endif; ?>
          
            <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'profits.php')): ?>
            <li class="nav-item<?php echo sidebar_active('profits.php', $current_page); ?>">
              <a class="nav-link" href="profits.php">
                <i class="fas fa-user-tie text-success"></i><?php echo __('profits'); ?> 
              </a>
            </li>
          <?php endif; ?>
        </ul>
            <!--<?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'profits.php')): ?>
            <li class="nav-item<?php echo sidebar_active('profits.php', $current_page); ?>">
              <a class="nav-link" href="profits.php">
                <i class="fas fa-user-tie text-success"></i><?php echo __('profits'); ?> 
              </a>
            </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'expenses.php')): ?>
            <li class="nav-item<?php echo sidebar_active('expenses.php', $current_page); ?>">
              <a class="nav-link" href="expenses.php">
                <i class="fas fa-user-tie text-danger"></i><?php echo __('expenses'); ?> 
              </a>
            </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'ai_analytecs.php')): ?>
            <li class="nav-item<?php echo sidebar_active('ai_analytecs.php', $current_page); ?>">
              <a class="nav-link" href="ai_analytecs.php">
                <i class="ni ni-tv-2 <?php echo sidebar_active('ai_analytecs.php', $current_page); ?>"></i><?php echo __('AI_SMART'); ?>
              </a>
            </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'Predictive_alert.php')): ?>
           <li class="nav-item<?php echo sidebar_active('Predictive_alert.php', $current_page); ?>">
            <a class="nav-link" href="Predictive_alert.php">
              <i class="fas fa-brain text-warning"></i> <?php echo __('AI_Insight'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'pos.php')): ?>
          <li class="nav-item<?php echo sidebar_active('pos.php', $current_page); ?>">
            <a class="nav-link" href="pos.php">
              <i class="fas fa-shopping-cart"></i> <?php echo __('pos'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'products.php')): ?>
          <li class="nav-item<?php echo sidebar_active('products.php', $current_page); ?>"> 
            <a class="nav-link" href="products.php">
              <i class="ni ni-bullet-list-67"></i><?php echo __('products'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'categories.php')): ?>
          <li class="nav-item<?php echo sidebar_active('categories.php', $current_page); ?>">
            <a class="nav-link" href="categories.php">
              <i class="fas fa-tags"></i> <?php echo __('categories'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'suppliers.php')): ?>
          <li class="nav-item<?php echo sidebar_active('suppliers.php', $current_page); ?>">
            <a class="nav-link" href="suppliers.php">
              <i class="fas fa-truck"></i> <?php echo __('suppliers'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'customes.php')): ?>
          <li class="nav-item<?php echo sidebar_active('customes.php', $current_page); ?>">
            <a class="nav-link" href="customes.php">
              <i class="fas fa-users"></i><?php echo __('customers'); ?>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item<?php echo sidebar_active('stores.php', $current_page); ?>">
            <a class="nav-link" href="stores.php">
              <i class="fas fa-store"></i><?php echo __('stores'); ?>
            </a>
          </li>
          <li class="nav-item<?php echo sidebar_active('stores_summary.php', $current_page); ?>">
            <a class="nav-link" href="stores_summary.php">
              <i class="fas fa-chart-bar"></i><?php echo __('store_summary'); ?>
            </a>
          </li>
        </ul>-->
<h6 class="navbar-heading text-muted mb-2"><?php echo __('Lab&Reception'); ?></h6>
        <ul class="navbar-nav">
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'shift_management.php')): ?>
          <li class="nav-item<?php echo sidebar_active('shift_management.php', $current_page); ?>">
            <a class="nav-link" href="shift_management.php">
              <i class="fas fa-cash-register"></i> <?php echo __('shift_management'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'patient.php')): ?>
          <li class="nav-item<?php echo sidebar_active('patient.php', $current_page); ?>">
            <a class="nav-link" href="patient.php">
              <i class="fas fa-user-injured"></i> <?php echo __('patients'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinics.php')): ?>
          <li class="nav-item<?php echo sidebar_active('clinics.php', $current_page); ?>">
            <a class="nav-link" href="clinics.php">
              <i class="fas fa-clinic-medical"></i> <?php echo __('clinics'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'doctor_appointments.php')): ?>
          <li class="nav-item<?php echo sidebar_active('doctor_appointments.php', $current_page); ?>">
            <a class="nav-link" href="doctor_appointments.php">
              <i class="fas fa-calendar-alt"></i> <?php echo __('doctor_appointments'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinic_queue.php')): ?>
          <li class="nav-item<?php echo sidebar_active('clinic_queue.php', $current_page); ?>">
            <a class="nav-link" href="clinic_queue.php">
              <i class="fas fa-list-ol"></i> <?php echo __('clinic_queue'); ?>
            </a>
          </li>
          <?php endif; ?>
          <!--
          <li class="nav-item<?php echo sidebar_active('lab.php', $current_page); ?>">
            <a class="nav-link" href="lab.php">
              <i class="fas fa-procedures"></i> <?php echo __('lab'); ?>
            </a>
          </li>
          <li class="nav-item<?php echo sidebar_active('outpatient_management.php', $current_page); ?>">
            <a class="nav-link" href="outpatient_management.php">
              <i class="fas fa-notes-medical"></i> <?php echo __('outpatient_management'); ?>
            </a>
          </li>
          <li class="nav-item<?php echo sidebar_active('inpatient_management.php', $current_page); ?>">
            <a class="nav-link" href="inpatient_management.php">
              <i class="fas fa-notes-medical"></i> <?php echo __('inpatient_management'); ?>
            </a>
          </li>-->
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'lab_management.php')): ?>
          <li class="nav-item<?php echo sidebar_active('lab_management.php', $current_page); ?>">
            <a class="nav-link" href="lab_management.php">
              <i class="fas fa-microscope"></i> <?php echo __('lab_management'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'lab_sample_tracking.php')): ?>
          <li class="nav-item<?php echo sidebar_active('lab_sample_tracking.php', $current_page); ?>">
            <a class="nav-link" href="lab_sample_tracking.php">
              <i class="fas fa-route text-warning"></i> <?php echo __('lab_sample_tracking'); ?>
            </a>
          </li>
          <?php endif; ?>

                    <!--
          <li class="nav-item<?php echo sidebar_active('medical_store_management.php', $current_page); ?>">
            <a class="nav-link" href="medical_store_management.php">
              <i class="fas fa-notes-medical"></i> <?php echo __('medical_store_management'); ?>
            </a>
          </li>-->
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'patient_history.php')): ?>
          <li class="nav-item<?php echo sidebar_active('patient_history.php', $current_page); ?>">
            <a class="nav-link" href="patient_history.php">
              <i class="fas fa-history"></i> <?php echo __('patient_history'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'complaints_desk.php')): ?>
          <li class="nav-item<?php echo sidebar_active('complaints_desk.php', $current_page); ?>">
            <a class="nav-link" href="complaints_desk.php">
              <i class="fas fa-comments text-info"></i> <?php echo __('complaints_desk'); ?>
            </a>
          </li>
          <?php endif; ?>
        </ul>


        <h6 class="navbar-heading text-muted mb-2"><?php echo __('ER In|OutPatient'); ?></h6>
        <ul class="navbar-nav">
          <!--
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinic_queue.php')): ?>
          <li class="nav-item<?php echo sidebar_active('emergency_operations.php', $current_page); ?>">
            <a class="nav-link" href="emergency_operations.php">
              <i class="fas fa-notes-medical"></i> <?php echo __('emergency_operations'); ?>
            </a>
          </li>
          <?php endif; ?>
          -->
          
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'outpatient_management.php')): ?>
          <li class="nav-item<?php echo sidebar_active('outpatient_management.php', $current_page); ?>">
            <a class="nav-link" href="outpatient_management.php">
              <i class="fas fa-stethoscope"></i> <?php echo __('outpatient_management'); ?>
            </a>
          </li>
          <?php endif; ?> 
                  <!-- قسم التأمين الطبي 
        <h6 class="navbar-heading text-muted mb-2"><?php echo __('insurance'); ?></h6>
        <ul class="navbar-nav"> 
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_dashboard.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_dashboard.php', $current_page); ?>">
            <a class="nav-link" href="insurance_dashboard.php">
              <i class="fas fa-file-invoice-dollar text-success"></i> لوحة التأمين
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinic_queue.php')): ?>
            <li class="nav-item<?php echo sidebar_active('insurance_companies.php', $current_page); ?>">
            <a class="nav-link" href="insurance_companies.php">
              <i class="fas fa-building text-danger"></i> شركات التأمين
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinic_queue.php')): ?>
          <li class="nav-item<?php echo sidebar_active('insurance_policies.php', $current_page); ?>">
            <a class="nav-link" href="insurance_policies.php">
              <i class="fas fa-file-contract text-info"></i> عقود التأمين
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'clinic_queue.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_claims.php', $current_page); ?>">
            <a class="nav-link" href="insurance_claims.php">
              <i class="fas fa-file-invoice-dollar text-success"></i> المطالبات التأمينية
            </a>
          </li>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_claims_settlement.php')): ?>
          <li class="nav-item<?php echo sidebar_active('insurance_claims_settlement.php', $current_page); ?>">
            <a class="nav-link" href="insurance_claims_settlement.php">
              <i class="fas fa-handshake text-danger"></i> تسوية المطالبات
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_bulk_rates.php')): ?>
          <li class="nav-item<?php echo sidebar_active('insurance_bulk_rates.php', $current_page); ?>">
            <a class="nav-link" href="insurance_bulk_rates.php">
              <i class="fas fa-magic text-warning"></i> الأسعار الجماعية
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_reports.php')): ?>
          <li class="nav-item<?php echo sidebar_active('insurance_reports.php', $current_page); ?>">
            <a class="nav-link" href="insurance_reports.php">
              <i class="fas fa-chart-bar text-info"></i> تقارير التأمين
            </a>
          </li>
          <?php endif; ?>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_management.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_management.php', $current_page); ?>">
            <a class="nav-link" href="insurance_management.php">
              <i class="fas fa-handshake text-success"></i> إدارة التأمين الطبي
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_analytics.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_analytics.php', $current_page); ?>">
            <a class="nav-link" href="insurance_analytics.php">
              <i class="fas fa-chart-bar text-warning"></i> تحليلات التأمين
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_service_rates.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_service_rates.php', $current_page); ?>">
            <a class="nav-link" href="insurance_service_rates.php">
              <i class="fas fa-tags text-primary"></i> أسعار الخدمات
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_bulk_pricing.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_bulk_pricing.php', $current_page); ?>">
            <a class="nav-link" href="insurance_bulk_pricing.php">
              <i class="fas fa-tags text-primary"></i> التسعير الشامل
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'insurance_settings.php')): ?>
           <li class="nav-item<?php echo sidebar_active('insurance_settings.php', $current_page); ?>">
            <a class="nav-link" href="insurance_settings.php">
              <i class="fas fa-cog text-secondary"></i> إعدادات التأمين
            </a>
          </li>
          <?php endif; ?>
        </ul>
-->



        <h6 class="navbar-heading text-muted mb-2"><?php echo __('Finance'); ?></h6>
        <ul class="navbar-nav">
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'profit_loss.php')): ?>
           <li class="nav-item<?php echo sidebar_active('profit_loss.php', $current_page); ?>">
            <a class="nav-link" href="profit_loss.php">
              <i class="fas fa-chart-line text-success"></i> لوحة الأرباح والخسائر
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'sales_by_user.php')): ?>
            <li class="nav-item<?php echo sidebar_active('sales_by_user.php', $current_page); ?>">
            <a class="nav-link" href="sales_by_user.php">
              <i class="fas fa-user-chart text-info"></i> المبيعات حسب الموظف
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'patient_refunds.php')): ?>
           <li class="nav-item<?php echo sidebar_active('patient_refunds.php', $current_page); ?>">
            <a class="nav-link" href="patient_refunds.php">
              <i class="fas fa-undo text-warning"></i> الاسترجاعات
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'entitlements_summary.php')): ?>
           <li class="nav-item<?php echo sidebar_active('entitlements_summary.php', $current_page); ?>">
            <a class="nav-link" href="entitlements_summary.php">
              <i class="fas fa-handshake text-danger"></i> استحقاقات الموظفين
            </a>
          </li>
          <?php endif; ?>
          
          
         
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'finance_dashboard.php')): ?>
          <li class="nav-item<?php echo sidebar_active('finance_dashboard.php', $current_page); ?>">
            <a class="nav-link" href="finance_dashboard.php">
              <i class="fas fa-chart-pie text-primary"></i> <?php echo __('finance_dashboard'); ?>
            </a>
          </li> 
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'financial_analytics.php')): ?>
           <li class="nav-item<?php echo sidebar_active('financial_analytics.php', $current_page); ?>">
            <a class="nav-link" href="financial_analytics.php">
              <i class="fas fa-chart-bar"></i> <?php echo __('financial_analytics'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'financial_settings.php')): ?>
          <li class="nav-item financial-settings<?php echo sidebar_active('financial_settings.php', $current_page); ?>">
            <a class="nav-link" href="financial_settings.php">
              <i class="fas fa-cog text-warning"></i> الإعدادات المالية
            </a>
          </li>
          <?php endif; ?>
          </ul>
        <!-- Divider 
        <hr class="my-1">
        <?php $inventoryPages = ['stock_purchases.php','stock_receive.php','stock_receive_confirm.php','stock_receive_refund.php','stock_log.php','stock_adjust.php']; ?>
        <?php if (isSuperAdmin($admin->admin_id) || userHasAnyPagePermission($mysqli, $admin->admin_id, $inventoryPages)): ?>
        <h6 class="navbar-heading text-muted mb-2"><?php echo __('inventory'); ?></h6>
        <ul class="navbar-nav">
          <li class="nav-item">
            <a class="nav-link collapsed" href="#inventoryGroup" data-toggle="collapse" aria-expanded="<?php echo in_array($current_page,$inventoryPages) ? 'true' : 'false'; ?>">
              <i class="fas fa-warehouse"></i> <?php echo __('inventory'); ?>
            </a>
            <div id="inventoryGroup" class="collapse <?php echo in_array($current_page,$inventoryPages) ? 'show' : ''; ?>">
              <ul class="nav nav-sm flex-column ml-3">
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_purchases.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_purchases.php', $current_page); ?>">
                  <a class="nav-link" href="stock_purchases.php"><i class="fas fa-receipt"></i> <?php echo __('purchases'); ?></a>
                </li>
                <?php endif; ?>
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_receive.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_receive.php', $current_page); ?>">
                  <a class="nav-link" href="stock_receive.php"><i class="fas fa-box-open"></i> <?php echo __('purchase_order'); ?></a>
                </li>
                <?php endif; ?>
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_receive_confirm.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_receive_confirm.php', $current_page); ?>">
                  <a class="nav-link" href="stock_receive_confirm.php"><i class="fas fa-check-circle"></i> <?php echo __('confirm_receipt'); ?></a>
                </li>
                <?php endif; ?>
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_receive_refund.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_receive_refund.php', $current_page); ?>">
                  <a class="nav-link" href="stock_receive_refund.php"><i class="fas fa-undo"></i> <?php echo __('receive_refund'); ?></a>
                </li>
                <?php endif; ?>
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_log.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_log.php', $current_page); ?>">
                  <a class="nav-link" href="stock_log.php"><i class="fas fa-history"></i> <?php echo __('stock_log'); ?></a>
                </li>
                <?php endif; ?>
                <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'stock_adjust.php')): ?>
                <li class="nav-item<?php echo sidebar_active('stock_adjust.php', $current_page); ?>">
                  <a class="nav-link" href="stock_adjust.php"><i class="fas fa-edit"></i> <?php echo __('adjust_stock'); ?></a>
                </li>
                <?php endif; ?>
              </ul>
            </div>
          </li>
        </ul>
        <?php endif; ?>
          
       
        <hr class="my-1">
        <ul class="navbar-nav">
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'orders.php')): ?>
          <li class="nav-item<?php echo sidebar_active('orders.php', $current_page); ?>">
            <a class="nav-link" href="orders.php">
              <i class="ni ni-cart text-success"></i> <?php echo __('orders'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'refund.php')): ?>
          <li class="nav-item<?php echo sidebar_active('refund.php', $current_page); ?>">
            <a class="nav-link" href="refund.php">
              <i class="fas fa-undo text-success"></i> <?php echo __('refunds'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'payments.php')): ?>
          <li class="nav-item<?php echo sidebar_active('payments.php', $current_page); ?>">
            <a class="nav-link" href="payments.php">
              <i class="ni ni-credit-card text-success"></i> <?php echo __('payments'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'receipts.php')): ?>
          <li class="nav-item<?php echo sidebar_active('receipts.php', $current_page); ?>">
            <a class="nav-link" href="receipts.php">
              <i class="fas fa-file-invoice-dollar text-success"></i> <?php echo __('receipts'); ?>
            </a>
          </li>
          <?php endif; ?>
        </ul>
        
        
        <hr class="my-3">
        
        <h6 class="navbar-heading text-muted"><?php echo __('reporting'); ?></h6>
      
        
        <ul class="navbar-nav mb-md-3">
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'orders_reports.php')): ?>
          <li class="nav-item<?php echo sidebar_active('orders_reports.php', $current_page); ?>">
            <a class="nav-link" href="orders_reports.php">
              <i class="fas fa-shopping-basket"></i> <?php echo __('orders'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'payments_reports.php')): ?>
          <li class="nav-item<?php echo sidebar_active('payments_reports.php', $current_page); ?>">
            <a class="nav-link" href="payments_reports.php">
              <i class="fas fa-funnel-dollar"></i> <?php echo __('payments'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'sales_by_user.php')): ?>
          <li class="nav-item<?php echo sidebar_active('sales_by_user.php', $current_page); ?>">
            <a class="nav-link" href="sales_by_user.php">
              <i class="fas fa-chart-line"></i> <?php echo __('sales_by_user'); ?>
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'audit_logs.php')): ?>
          <li class="nav-item<?php echo sidebar_active('audit_logs.php', $current_page); ?>">
            <a class="nav-link" href="audit_logs.php">
              <i class="fas fa-shield-alt text-warning"></i> <?php echo __('audit_logs'); ?>
            </a>
          </li>
          <?php endif; ?>
        </ul>-->
        <hr class="my-3">
        <ul class="navbar-nav mb-md-3">




          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'hrm.php')): ?>
          <li class="nav-item<?php echo sidebar_active('hrm.php', $current_page); ?>">
            <a class="nav-link" href="hrm.php">
              <i class="fas fa-user-tie text-success"></i><?php echo __('HRM'); ?> 
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'hrm_extras.php')): ?>
          <li class="nav-item<?php echo sidebar_active('hrm_extras.php', $current_page); ?>">
            <a class="nav-link" href="hrm_extras.php">
              <i class="fas fa-user-tie text-success"></i><?php echo __('hrm_extras'); ?> 
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'hrm_loans.php')): ?>
          <li class="nav-item<?php echo sidebar_active('hrm_loans.php', $current_page); ?>">
            <a class="nav-link" href="hrm_loans.php">
              <i class="fas fa-user-tie text-success"></i><?php echo __('hrm_loans'); ?> 
            </a>
          </li>
          <?php endif; ?>
          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'payroll.php')): ?>
          <li class="nav-item<?php echo sidebar_active('payroll.php', $current_page); ?>">
            <a class="nav-link" href="payroll.php">
              <i class="fas fa-user-tie text-success"></i><?php echo __('payroll'); ?> 
            </a>
          </li>
          <?php endif; ?>




          <?php if (isSuperAdmin($admin->admin_id) || userHasPagePermission($mysqli, $admin->admin_id, 'settings.php')): ?>
          <li class="nav-item<?php echo sidebar_active('settings.php', $current_page); ?>">
            <a class="nav-link" href="settings.php">
              <i class="fas fa-cog text-success"></i> <?php echo __('settings'); ?>
            </a>
          </li>
          <?php endif; ?>
          <li class="nav-item">
            <a class="nav-link" href="logout.php">
              <i class="fas fa-sign-out-alt text-danger"></i> <?php echo __('logout'); ?>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<?php } ?>
