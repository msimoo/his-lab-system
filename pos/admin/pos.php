<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/code-generator.php');
include('config/stock.php');
require_once('config/languages.php');

check_login();

// الحصول على معرف المستخدم الحالي (أدمن أو موظف)
$current_user_id = $_SESSION['staff_id'] ?? $_SESSION['admin_id'] ?? null;

// 1. التحقق من حالة الوردية الحالية للمستخدم
$shift_query = "SELECT * FROM rpos_shifts WHERE user_id = ? AND status = 'Open' LIMIT 1";
$stmt = $mysqli->prepare($shift_query);
$stmt->bind_param('s', $current_user_id);
$stmt->execute();
$shift_res = $stmt->get_result();
$active_shift = $shift_res->fetch_assoc();

// ensure an order code exists for the current POS session
if (empty($_SESSION['current_order_code'])) {
    $_SESSION['current_order_code'] = generateUniqueOrderCode($mysqli);
}
$order_code = $_SESSION['current_order_code'];

// 2. معالجة فتح وردية جديدة (AJAX أو POST)
if (isset($_POST['open_shift'])) {
    $opening_cash = floatval($_POST['opening_cash']);
    $shift_id = bin2hex(random_bytes(10));
    $open_query = "INSERT INTO rpos_shifts (shift_id, user_id, opening_cash, status, opened_at) VALUES (?, ?, ?, 'Open', NOW())";
    $open_stmt = $mysqli->prepare($open_query);
    $open_stmt->bind_param('ssd', $shift_id, $current_user_id, $opening_cash);
    if ($open_stmt->execute()) {
        header("Location: pos.php"); // إعادة تحميل لتنشيط الوردية
        exit;
    }
}

// 3. معالجة إغلاق الوردية
if (isset($_POST['close_shift'])) {
    $closing_cash = floatval($_POST['closing_cash']);
    $shift_id = $_POST['active_shift_id'];
    $close_query = "UPDATE rpos_shifts SET closing_cash = ?, status = 'Closed', closed_at = NOW() WHERE shift_id = ?";
    $close_stmt = $mysqli->prepare($close_query);
    $close_stmt->bind_param('ds', $closing_cash, $shift_id);
    if ($close_stmt->execute()) {
        $commission_enabled = getSetting('shift_commission_enabled', '0') === '1';
        $commission_pct = floatval(getSetting('shift_commission_pct', '0'));

        if ($commission_enabled && $commission_pct > 0) {
            $shift_stmt = $mysqli->prepare("SELECT user_id, opened_at FROM rpos_shifts WHERE shift_id = ? LIMIT 1");
            if ($shift_stmt) {
                $shift_stmt->bind_param('s', $shift_id);
                $shift_stmt->execute();
                $shift_stmt->bind_result($shift_user_id, $opened_at);
                if ($shift_stmt->fetch()) {
                    $shift_stmt->close();
                    $sales_stmt = $mysqli->prepare(
                        "SELECT COALESCE(SUM(prod_price * prod_qty), 0) AS total_sales
                         FROM rpos_orders
                         WHERE created_by = ?
                           AND created_at BETWEEN ? AND NOW()
                           AND order_status = 'Paid'"
                    );
                    if ($sales_stmt) {
                        $sales_stmt->bind_param('ss', $shift_user_id, $opened_at);
                        $sales_stmt->execute();
                        $sales_stmt->bind_result($total_sales);
                        $sales_stmt->fetch();
                        $sales_stmt->close();
                        $commission_due = round(floatval($total_sales) * ($commission_pct / 100), 2);
                        if ($commission_due > 0) {
                            $insert_commission = $mysqli->prepare(
                                "INSERT INTO rpos_shift_commissions (shift_id, user_id, commission_pct, sales_amount, commission_due, status, created_at)
                                 VALUES (?, ?, ?, ?, ?, 'due', NOW())
                                 ON DUPLICATE KEY UPDATE
                                     commission_pct = VALUES(commission_pct),
                                     sales_amount = VALUES(sales_amount),
                                     commission_due = VALUES(commission_due)"
                            );
                            if ($insert_commission) {
                                $insert_commission->bind_param('ssddd', $shift_id, $shift_user_id, $commission_pct, $total_sales, $commission_due);
                                $insert_commission->execute();
                                $insert_commission->close();
                            }
                        }
                    }
                } else {
                    $shift_stmt->close();
                }
            }
        }

        // يمكنك توجيهه للتقرير أو تسجيل الخروج
        header("Location: dashboard.php?shift_closed=success"); 
        exit;
    }
}

// initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// --- (باقي المنطق الخاص بالضرائب والفلترة كما هو في ملفك الأصلي) ---
$tax_rate = floatval(getSetting('tax_rate', '0'));
$discount_rate = floatval(getSetting('discount_rate', '0'));
$show_tax = getSetting('enable_tax','0') === '1';
$show_discount = getSetting('enable_discount','0') === '1';
$enable_barcode = getSetting('enable_barcode','0') === '1';
$enable_shortcuts = getSetting('enable_shortcuts','0') === '1';

$selected_cat = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$cat_list = [];
$cat_res = $mysqli->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
while ($c = $cat_res->fetch_assoc()) {
    $cat_list[$c['category_id']] = $c['category_name'];
}
// --- (نهاية المنطق الأصلي) ---

require_once('partials/_head.php');
?>

<style>
    body {
        background: linear-gradient(279deg, #050b1b 0%, #0f172a 48%, #172b4d 100%);
        color: #e9ecef;
    }
    .main-content {
        position: relative;
        z-index: 1;
    }
    .header {
        min-height: 20px;
        background: linear-gradient(135deg, rgba(5,11,27,0.95) 0%, rgba(23,43,77,0.95) 100%), url('../admin/assets/img/theme/restro00.jpg');
        background-size: cover;
        background-position: center;
    }
    .header .mask {
        opacity: 0.6 !important;
    }
    .header-body {
        position: relative;
        z-index: 2;
    }
    .page-title-card {
        border-radius: 1.5rem;
        background: rgba(8,16,39,0.85);
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 24px 60px rgba(0,0,0,0.25);
        padding: 2rem;
    }
    .page-title-card h1 {
        font-size: 2.8rem;
        letter-spacing: 0.02em;
        text-shadow: 0 12px 30px rgba(5,10,32,0.55);
    }
    .page-title-card p {
        color: rgba(233,236,239,0.82);
        font-size: 1rem;
    }
    .product-card {
        cursor: pointer;
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        position: relative;
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(15,23,42,0.85);
        overflow: hidden;
    }
    .product-card:hover {
        transform: translateY(-6px) scale(1.015);
        box-shadow: 0 22px 45px rgba(0,0,0,0.30);
        border-color: rgba(94,114,228,0.35);
    }
    .product-card.dragging {
        opacity: 0.55;
        box-shadow: 0 18px 40px rgba(79,70,229,0.18);
    }
    .product-card .card-body {
        position: relative;
        min-height: 290px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .product-card img {
        max-height: 90px;
        object-fit: contain;
        border-radius: 1rem;
        margin-bottom: 0.85rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.20);
        background: rgba(255,255,255,0.06);
    }
    .product-card h5 {
        color: #f8fafc;
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 0.45rem;
    }
    .product-card .product-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        justify-content: center;
        margin-bottom: 0.85rem;
    }
    .product-card .product-badges .badge {
        padding: 0.35rem 0.65rem;
        font-size: 0.68rem;
        font-weight: 700;
        border-radius: 999px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .product-card .product-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(15,23,42,0) 0%, rgba(15,23,42,0.60) 100%);
        pointer-events: none;
    }
    .product-card.out-of-stock {
        animation: out-of-stock-pulse 0.45s ease-in-out;
        border-color: rgba(248,113,113,0.8);
        box-shadow: 0 0 0 0 rgba(248,113,113,0.25);
    }
    .product-card.out-of-stock .stock-info {
        color: #fb7185 !important;
    }
    @keyframes out-of-stock-pulse {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.02); opacity: 0.85; box-shadow: 0 0 0 18px rgba(248,113,113,0.12); }
        100% { transform: scale(1); opacity: 0.9; }
    }
    .price-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: linear-gradient(135deg, #ffd54f 0%, #ffb300 100%);
        color: #071014;
        font-weight: 800;
        font-size: 12px;
        padding: 0.5rem 0.85rem;
        border-radius: 999px;
        box-shadow: 0 10px 24px rgba(0,0,0,0.2);
        z-index: 2;
    }
    .add-product-btn {
        width: 100%;
        border-radius: 1rem;
        background: linear-gradient(135deg, #2dd4bf 0%, #14b8a6 100%);
        color: #040f1a;
        border: none;
        box-shadow: 0 14px 30px rgba(20,184,166,0.28);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .add-product-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 35px rgba(20,184,166,0.32);
    }
    .cart-fixed-panel {
        position: fixed;
        top: 80px; 
        width: 370px;
        max-width: calc(100vw - 24px); 
        display: flex;
        flex-direction: column;
        z-index: 20;
    }
    .cart-fixed-panel .card {
        border-radius: 1.5rem;
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(10,19,40,0.95);
        box-shadow: 0 30px 60px rgba(0,0,0,0.28);
        overflow: hidden;
    }
    .cart-fixed-panel .card-body {
        padding: 1rem;
        flex: 1 1 auto;
        overflow: hidden;
    }
    .cart-fixed-panel #cartArea {
        max-height: calc(100vh - 300px);
        overflow-y: auto;  
        transition: border-color 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
        scrollbar-width: thin;
        scrollbar-color: rgb(55 187 155) rgb(255 255 255 / 85%);
    }
    .cart-fixed-panel #cartArea::-webkit-scrollbar {
        width: 12px;
    }
    .cart-fixed-panel #cartArea::-webkit-scrollbar-track {
        background: rgba(15,23,42,0.82);
        border-radius: 999px;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.05);
    }
    .cart-fixed-panel #cartArea::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, rgba(59,130,246,0.95), rgba(168,85,247,0.95));
        border-radius: 999px;
        border: 3px solid rgba(10,19,40,0.95);
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12);
    }
    .cart-fixed-panel #cartArea::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, rgba(96,165,250,0.95), rgba(196,181,253,0.95));
    }
    .cart-fixed-panel #cartArea::-webkit-scrollbar-corner {
        background: transparent;
    }
    .cart-fixed-panel #cartArea.drag-over {
        border-color: rgba(96,165,250,0.95);
        border-radius: 20px;
        border-style: dashed;
        box-shadow: 0 0 0 1px rgba(56,189,248,0.18), 0 18px 40px rgba(56,189,248,0.14);
         
    }
    .cart-fixed-panel table {
        color: #f8fafc;
        border-color: rgba(255,255,255,0.08);
    }
    .cart-fixed-panel thead th,
    .cart-fixed-panel tbody td,
    .cart-fixed-panel tfoot td {
        border: none;
        color: rgba(241,245,249,0.92);
        font-size: 0.92rem;
    }
    .cart-fixed-panel tbody tr {
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .cart-fixed-panel .cart-remove {
        color: #f87171;
        font-weight: 700;
    }
    .cart-fixed-panel .qty-controls .btn {
        border-radius: 0.85rem;
    }
    .cart-fixed-panel .card-footer {
        padding: 1rem;
        background: rgba(14,23,42,0.98);
        border-top: 1px solid rgba(255,255,255,0.08);
    }
    .cart-fixed-panel .btn-success {
        background: linear-gradient(135deg, #10b981 0%, #047857 100%);
        border: none;
    }
    .cart-fixed-panel .btn-danger {
        background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        border: none;
    }
    .cart-fixed-panel .btn-outline-primary {
        color: #7dd3fc;
        border-color: rgba(125,211,252,0.6);
    }
    .cart-fixed-panel .btn-outline-primary:hover {
        background: rgba(125,211,252,0.12);
    }
    .cart-summary {
        margin-top: 1rem;
        padding: 1rem;
        border-radius: 1rem;
        background: rgba(15,23,42,0.92);
        border: 1px solid rgba(255,255,255,0.08);
    }
    @media (max-width: 991px) {
        .cart-fixed-panel {
            position: relative;
            top: auto;
            right: auto;
            width: 100%;
            height: auto;
            margin-top: 1.5rem;
        }
        .cart-fixed-panel .card {
            box-shadow: 0 24px 50px rgba(0,0,0,0.18);
        }
    }
    .cart-summary strong {
        color: #f8fafc;
        font-size: 1.1rem;
    }
    .modal-content {
        border-radius: 1.5rem;
        background: rgba(15,23,42,0.98);
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 28px 70px rgba(0,0,0,0.35);
    }
    .modal-header {
        border-bottom: none;
        color: #f8fafc;
        background: transparent;
    }
    .modal-body {
        color: rgba(226,232,240,0.92);
    }
    .modal .form-control {
        background: rgba(15,23,42,0.9);
        border: 1px solid rgba(148,163,184,0.18);
        color: #e2e8f0;
    }
    .modal .form-control:focus {
        background: rgba(15,23,42,0.98);
        border-color: #60a5fa;
        box-shadow: 0 0 0 0.2rem rgba(96,165,250,0.18);
    }
    .chart-legend,
    .badge {
        opacity: 0.95;
    }

    /* Ensure product cards are always visible */
    .product-card {
        opacity: 1 !important;
        transform: none !important;
    }
    .product-card.card {
        opacity: 1 !important;
        transform: none !important;
    }
</style>

<body>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(../admin/assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid text-right"> 
            </div>
        </div>

        <div class="container-fluid mt--8">
          
            <div class="row">
                <?php include('pos_content.php'); // يفضل فصل المحتوى الداخلي أو وضعه هنا مباشرة ?>
            </div>
            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <div class="modal fade" id="openShiftModal" tabindex="-1" role="dialog" aria-labelledby="modal-form" aria-hidden="true" 
         data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-body p-0">
                    <div class="card bg-secondary shadow border-0">
                        <div class="card-header bg-transparent pb-2 text-center">
                            <h3 class="mb-0"><?php echo __('Open_new_shift'); ?>   </h3>
                            <small><?php echo __('open_shift_details'); ?></small>
                        </div>
                        <div class="card-body px-lg-5 py-lg-4">
                            <form role="form" method="POST">
                                <div class="form-group mb-3">
                                    <div class="input-group input-group-alternative">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="ni ni-money-coins"></i></span>
                                        </div>
                                        <input class="form-control" name="opening_cash" placeholder="المبلغ الافتتاحي" type="number" step="0.01" required>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <button type="submit" name="open_shift" class="btn btn-primary my-4"><?php echo __('start_working'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="closeShiftModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('Close_shift'); ?> </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="active_shift_id" value="<?php echo $active_shift['shift_id'] ?? ''; ?>">
                        <div class="form-group">
                            <label><?php echo __('Close_shift_details'); ?> </label>
                            <input type="number" name="closing_cash" class="form-control" step="0.01" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo __('close'); ?></button>
                        <button type="submit" name="close_shift" class="btn btn-danger"><?php echo __('adjust_close_confirm'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>

    <script>
    $(document).ready(function() {
        // التحقق من وجود وردية مفتوحة، إذا لم توجد نظهر النافذة إجبارياً
        <?php if (!$active_shift): ?>
            if (window.jQuery && typeof jQuery.fn.modal === 'function') {
                $('#openShiftModal').modal('show');
            } else {
                var modal = document.getElementById('openShiftModal');
                if (modal) {
                    modal.classList.add('show');
                    modal.style.display = 'block';
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                    var backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }
        <?php endif; ?>
        
        // منع إغلاق نافذة الفتح بـ ESC أو النقر خارجها (مفعل عبر data-backdrop)
    });


    
    document.addEventListener('DOMContentLoaded', function(){
        // category dropdown change handler
        var catSel = document.getElementById('categoryFilter');
        if(catSel){
            catSel.addEventListener('change', function(){
                var val = this.value;
                var url = new URL(window.location);
                if(val) url.searchParams.set('category', val);
                else url.searchParams.delete('category');
                window.location = url.toString();
            });
        }
        // search input handler
        var searchInput = document.getElementById('searchInput');
        if(searchInput){
            searchInput.addEventListener('keypress', function(e){
                if(e.key === 'Enter'){
                    e.preventDefault();
                    var url = new URL(window.location);
                    var term = searchInput.value.trim();
                    if(term) url.searchParams.set('search', term);
                    else url.searchParams.delete('search');
                    window.location = url.toString();
                }
            });
        }

        var productSearchInput = document.getElementById('productSearchInput');
        if(productSearchInput){
            productSearchInput.addEventListener('input', function(){
                var query = this.value.toLowerCase().trim();
                document.querySelectorAll('.product-card').forEach(function(card){
                    var cardText = card.textContent.toLowerCase();
                    var colWrapper = card.closest('[class*="col-"]');
                    var visible = query.length === 0 || cardText.indexOf(query) !== -1;
                    if(colWrapper) colWrapper.style.display = visible ? '' : 'none';
                });
            });
        }
        <?php if($enable_barcode){ ?>
        var scanInput = document.getElementById('barcodeInput');
        var scanTimer = null;
        var scanDebounceMs = 220;
        if(scanInput){
            scanInput.focus();
            scanInput.addEventListener('input', function(){
                if(scanTimer){
                    clearTimeout(scanTimer);
                }
                var value = scanInput.value.trim();
                if(value.length === 0){
                    return;
                }
                scanTimer = setTimeout(function(){
                    if(scanInput.value.trim().length > 0){
                        addProductByCode(scanInput.value.trim());
                        scanInput.value = '';
                    }
                }, scanDebounceMs);
            });
            scanInput.addEventListener('keypress', function(e){
                if(e.key === 'Enter'){
                    e.preventDefault();
                    if(scanTimer){
                        clearTimeout(scanTimer);
                    }
                    addProductByCode(scanInput.value.trim());
                    scanInput.value='';
                }
            });
        }
        <?php }
        if($enable_shortcuts){ ?>
        document.addEventListener('keydown', function(e){
            // F3 focus barcode input if available
            if(e.key === 'F3' && scanInput){
                e.preventDefault(); scanInput.focus();
            }
            // F4 perform checkout
            if(e.key === 'F4'){
                e.preventDefault();
                var btn = document.querySelector('button[name="checkout"]');
                if(btn) btn.click();
            }
        });
        <?php } ?>

        // attach handlers for add buttons
        document.querySelectorAll('.add-product-form').forEach(function(form){
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var pid = form.querySelector('input[name="prod_id"]').value;
                ajaxCart('add', {prod_id: pid});
            });
        });
        document.querySelectorAll('.add-product-btn').forEach(function(btn){
            btn.addEventListener('click', function(){
                var pid = btn.dataset.prodId;
                if(pid){
                    ajaxCart('add', {prod_id: pid});
                }
            });
        });

        // enable drag/drop from product tiles to cart area
        initDragDrop();

        // kiosk/fullscreen support
        setupKioskMode();

        // update cart button
        var updateBtn = document.getElementById('updateCartBtn');
        if(updateBtn){
            updateBtn.addEventListener('click', function(){
                var data = {};
                document.querySelectorAll('#cartArea input.cart-qty').forEach(function(inp){
                    data[inp.dataset.id] = inp.value;
                });
                ajaxCart('update', {qty: data});
            });
        }

        var clearBtn = document.getElementById('clearCartBtn');
        if(clearBtn){
            clearBtn.addEventListener('click', function(e){
                e.preventDefault();
                ajaxCart('clear');
            });
        }

        // checkout via AJAX + auto receipt print
        var checkoutBtn = document.getElementById('checkoutBtn');
        if(checkoutBtn){
            checkoutBtn.addEventListener('click', function(){
                var cartForm = document.querySelector('#cartWrapper form');
                if(!cartForm) return;

                // Open the print window during the user gesture to avoid popup blocking
                var receiptWindowName = 'Receipt_' + Date.now();
                var printWindow = window.open('', receiptWindowName);
                if (!printWindow) {
                    alert('Please allow popups for this site to print receipt.');
                }

                var formData = new FormData(cartForm);
                fetch('cart_api.php?action=checkout', {method:'POST', body: formData}).then(function(r){
                    console.log('checkout status', r.status, r.statusText);
                    if (!r.ok) {
                        return r.text().then(function(text){
                            console.error('checkout non-ok response', text);
                            throw new Error('HTTP '+r.status);
                        });
                    }
                    return r.text().then(function(text){
                        console.log('checkout raw response', text);
                        try {
                            return JSON.parse(text);
                        } catch (e){
                            console.error('checkout JSON parse failed', e);
                            throw e;
                        }
                    });
                }).then(function(resp){
                    if(resp.success){
                        var cartArea = document.getElementById('cartArea');
                        var cartBadge = document.getElementById('cartBadge');
                        var cartTotal = document.getElementById('cartTotal');

                        if(resp.html && cartArea){
                            cartArea.innerHTML = resp.html;
                        }
                        if(resp.count !== undefined && cartBadge){
                            cartBadge.textContent = resp.count;
                        }
                        if(resp.grand !== undefined && cartTotal){
                            cartTotal.textContent = formatCartTotal(resp.grand);
                        }
                        updateProductStockLabels(resp);
                        hideZeroStockProducts();
                        if(resp.new_order_code){
                            var orderCodeField = document.getElementById('hiddenOrderCode');
                            if(orderCodeField){
                                orderCodeField.value = resp.new_order_code;
                            }
                        }
                        if(resp.order_code){
                            var receiptUrl = 'print_receipt.php?order_code='+encodeURIComponent(resp.order_code);
                            if (printWindow && !printWindow.closed) {
                                printWindow.location = receiptUrl;
                                printWindow.focus();
                                printWindow.onload = function(){
                                    try{
                                        printWindow.print();
                                    } catch(e) {
                                        console.warn('Auto-print failed', e);
                                    }
                                };
                            } else {
                                var w = window.open(receiptUrl, receiptWindowName);
                                if (w) {
                                    w.focus();
                                    w.onload = function(){ w.print(); };
                                } else {
                                    alert('Please allow popups to print receipt.');
                                }
                            }
                        }
                    } else {
                        alert(resp.message || 'Checkout failed');
                    }
                }).catch(function(err){
                    console.error('checkout error', err);
                    alert('Checkout failed, please try again. See console for details. ' + (err.message || ''));
                });
            });
        }

        // remove links
        document.addEventListener('click', function(e){
            if(e.target.matches('.cart-remove')){
                e.preventDefault();
                var id = e.target.dataset.id;
                ajaxCart('remove', {prod_id:id});
            }
            if(e.target.matches('.qty-increase') || e.target.matches('.qty-decrease')){
                var ctrl = e.target.closest('.qty-controls');
                var input = ctrl.querySelector('.cart-qty');
                var id = ctrl.dataset.id;
                var qty = parseInt(input.value);
                if(e.target.matches('.qty-increase')) qty++;
                else qty = Math.max(0, qty-1);
                input.value = qty;
                // update cart immediately
                var data = {};
                data[id] = qty;
                ajaxCart('update', {qty: data});
            }
            if (e.target.matches('#clearCartBtn') || e.target.closest('#clearCartBtn')) {
                e.preventDefault();
                ajaxCart('clear');
            }
        });

        hideZeroStockProducts();
    });

    function initDragDrop(){
        var cartArea = document.getElementById('cartArea');
        var products = document.querySelectorAll('.product-card');
        if(!cartArea) return;

        products.forEach(function(card){
            card.addEventListener('dragstart', function(e){
                var pid = card.dataset.prodId;
                if(pid){
                    e.dataTransfer.setData('text/plain', pid);
                    card.classList.add('dragging');
                }
            });
            card.addEventListener('dragend', function(){
                card.classList.remove('dragging');
            });
        });

        cartArea.addEventListener('dragover', function(e){
            e.preventDefault();
            cartArea.classList.add('drag-over');
        });
        cartArea.addEventListener('dragleave', function(){
            cartArea.classList.remove('drag-over');
        });
        cartArea.addEventListener('drop', function(e){
            e.preventDefault();
            cartArea.classList.remove('drag-over');
            var pid = e.dataTransfer.getData('text/plain');
            if(pid){
                ajaxCart('add', {prod_id: pid});
            }
        });
    }

    function setupKioskMode(){
        var body = document.body;
        var kioskBtn = document.getElementById('kioskToggleBtn');
        var fullscreenBtn = document.getElementById('fullscreenBtn');
        var exitBtn = document.getElementById('exitFullscreenBtn');
        var kioskPin = '1234'; // set your kiosk exit PIN here
        var allowFullScreenExit = false; // only exit when authorized

        function goFS(){
            var el = document.documentElement;
            if(el.requestFullscreen){ el.requestFullscreen(); }
            else if(el.webkitRequestFullscreen){ el.webkitRequestFullscreen(); }
            else if(el.msRequestFullscreen){ el.msRequestFullscreen(); }
        }
        function exitFS(){
            if(document.exitFullscreen){ document.exitFullscreen(); }
            else if(document.webkitExitFullscreen){ document.webkitExitFullscreen(); }
            else if(document.msExitFullscreen){ document.msExitFullscreen(); }
        }

        var kioskUserName = document.getElementById('kioskUserName');
        function updateKioskUserLabel(enabled){
            if(kioskUserName){
                kioskUserName.classList.toggle('d-none', !enabled);
            }
        }

        function setKiosk(enabled){
            if(enabled){
                body.classList.add('kiosk-mode');
                localStorage.setItem('kioskMode', '1');
                if(kioskBtn) kioskBtn.textContent = '<?php echo __('exit_kiosk_mode'); ?>';
                updateKioskUserLabel(true);
                goFS();
                attachIdleTimer();
            } else {
                body.classList.remove('kiosk-mode');
                localStorage.removeItem('kioskMode');
                if(kioskBtn) kioskBtn.textContent = '<?php echo __('kiosk_mode'); ?>';
                updateKioskUserLabel(false);
                removeIdleTimer();
            }
        }

        function askForExit(){
            var confirmLeave = confirm('<?php echo __('confirm_leave_kiosk') ?? 'Leave kiosk mode?'; ?>');
            if(!confirmLeave){ return false; }
            var pin = prompt('<?php echo __('enter_kiosk_pin') ?? 'Enter kiosk PIN'; ?>');
            if(pin === kioskPin){
                allowFullScreenExit = true;
                setKiosk(false);
                exitFS();
                return true;
            }
            alert('<?php echo __('invalid_pin') ?? 'Invalid PIN'; ?>');
            return false;
        }

        if(kioskBtn){
            kioskBtn.addEventListener('click', function(){
                if(body.classList.contains('kiosk-mode')){
                    askForExit();
                } else {
                    setKiosk(true);
                }
            });
        }

        if(fullscreenBtn){
            fullscreenBtn.addEventListener('click', function(){
                if(!body.classList.contains('kiosk-mode')){
                    setKiosk(true);
                } else {
                    goFS();
                }
            });
        }

        // restore kiosk mode state after page reload
        if(localStorage.getItem('kioskMode') === '1'){
            setKiosk(true);
        }

        if(exitBtn){
            exitBtn.addEventListener('click', function(){
                // allow explicit exit by button
                allowFullScreenExit = true;
                askForExit();
            });
        }

        document.addEventListener('fullscreenchange', function(){
            if(!document.fullscreenElement){
                if(!allowFullScreenExit){
                    // force back into fullscreen unless explicit exit request
                    goFS();
                }
            } else {
                // reset guard while fully in fullscreen
                allowFullScreenExit = false;
            }
        });

        document.addEventListener('keydown', function(e){
            if((e.key === 'Home' || e.key === 'Escape') && body.classList.contains('kiosk-mode')){
                e.preventDefault();
                askForExit();
            }

            if(e.key === 'F11'){
                e.preventDefault();
                allowFullScreenExit = true;
                exitFS();
            }
        });

        var idleTimer = null;
        function attachIdleTimer(){
            clearTimeout(idleTimer);
            var timeoutMs = 2 * 60 * 1000; // 2 minutes idle return
            idleTimer = setTimeout(function(){
                if(body.classList.contains('kiosk-mode')){
                    // reset to POS home page
                    window.location = 'pos.php';
                }
            }, timeoutMs);
        }
        function removeIdleTimer(){
            clearTimeout(idleTimer);
            idleTimer = null;
        }

        ['click','keydown','mousemove','touchstart'].forEach(function(event){
            document.addEventListener(event, function(){
                if(body.classList.contains('kiosk-mode')){
                    attachIdleTimer();
                }
            }, {passive: true});
        });

        // initialize with existing state
        if(body.classList.contains('kiosk-mode')){
            setKiosk(true);
        }
    }

    function playAddSound(){
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;
            var ctx = new AudioContext();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.12;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.08);
        } catch (e) {
            console.warn('Sound unavailable', e);
        }
    }

    function updateProductStockLabels(resp){
        if(!resp.stocks) return;
        Object.keys(resp.stocks).forEach(function(id){
            var valueEl = document.querySelector('.product-card[data-prod-id="'+id+'"] .stock-value');
            var card = document.querySelector('.product-card[data-prod-id="'+id+'"]');
            var wrapper = card ? card.closest('[class*="col-"]') : null;
            if(valueEl){
                valueEl.textContent = resp.stocks[id];
            }
            if(card){
                if(resp.stocks[id] <= 0){
                    card.classList.add('out-of-stock');
                    if(wrapper){
                        wrapper.style.transition = 'opacity 0.45s ease, transform 0.45s ease';
                        wrapper.style.opacity = '1';
                        setTimeout(function(){
                            wrapper.style.opacity = '0';
                            wrapper.style.transform = 'scale(0.96)';
                        }, 20);
                        setTimeout(function(){
                            wrapper.style.display = 'none';
                        }, 480);
                    }
                } else {
                    card.classList.remove('out-of-stock');
                    if(wrapper){
                        wrapper.style.display = '';
                        wrapper.style.opacity = '';
                        wrapper.style.transform = '';
                    }
                }
            }
        });
    }

    function hideZeroStockProducts(){
        document.querySelectorAll('.product-card').forEach(function(card){
            var stockValueEl = card.querySelector('.stock-value');
            if(!stockValueEl) return;
            var stock = parseInt(stockValueEl.textContent);
            if(isNaN(stock)) return;
            if(stock <= 0){
                var wrapper = card.closest('[class*="col-"]');
                if(wrapper){
                    wrapper.style.display = 'none';
                }
            }
        });
    }

    function formatCartTotal(value){
        return value !== undefined ? value + ' SDG' : '';
    }

    function ajaxCart(action, params){
        var url = 'cart_api.php?action='+action;
        var opts = {method: 'POST'};
        if(action === 'add' || action === 'remove'){
            url += '&prod_id='+encodeURIComponent(params.prod_id);
        }
        if(action === 'update'){
            var form = new URLSearchParams();
            for(var id in params.qty){
                form.append('qty['+id+']', params.qty[id]);
            }
            opts.body = form;
        }
        if(action === 'clear'){
            opts.body = new URLSearchParams();
        }

        fetch(url, opts).then(function(r){
            return r.json();
        }).then(function(resp){
            if(resp.success){
                var cartAreaEl = document.getElementById('cartArea');
                if(cartAreaEl && resp.html !== undefined){ cartAreaEl.innerHTML = resp.html; }
                if(resp.grand !== undefined){
                    var totalEl = document.getElementById('cartTotal');
                    if(totalEl) totalEl.textContent = formatCartTotal(resp.grand);
                }
                if(resp.count !== undefined){
                    var bd = document.getElementById('cartBadge');
                    if(bd) bd.textContent = resp.count;
                }
                updateProductStockLabels(resp);
                hideZeroStockProducts();
                if(action === 'add'){ playAddSound(); }
            } else {
                if(resp.message){
                    alert(resp.message);
                }
            }
        }).catch(function(err){
            console.error('Cart API error', err);
            alert('Cart request failed. Please try again.');
        });
    }

    function addProductByCode(code){
        if (!code) return;
        ajaxCart('add', {prod_id: code});
        if(typeof scanInput !== 'undefined' && scanInput){
            scanInput.focus();
        }
    }

    // Ensure product cards are always visible
    document.querySelectorAll('.product-card').forEach(function(card) {
        card.style.opacity = '1';
        card.style.transform = 'none';
    });
    </script>

    <?php if (isset($completed_order_code)) { ?>
    <script>
        // open receipt in new window and auto-print
        (function(){
            var w = window.open('print_receipt.php?order_code=<?php echo $completed_order_code; ?>', 'Receipt');
            if (w) {
                w.onload = function(){ w.print(); };
            }
        })();
    // (باقي أكواد Javascript الأصلية الخاصة بك للسلة والبحث والـ AJAX)
    // ...
    </script>
    
    <?php } ?>

