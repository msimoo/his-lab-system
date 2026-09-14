<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

// --- منطق المعالجة (تغيير الحالة والحذف) ---
if(isset($_GET['toggle_sellable'])){
    $pid = trim($_GET['toggle_sellable']);
    $stmt = $mysqli->prepare("SELECT prod_sellable FROM rpos_products WHERE prod_id = ? LIMIT 1");
    $stmt->bind_param('s', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    if($row = $res->fetch_assoc()){
        $new = (int)$row['prod_sellable'] ? 0 : 1;
        $stmt2 = $mysqli->prepare("UPDATE rpos_products SET prod_sellable = ? WHERE prod_id = ?");
        $stmt2->bind_param('is', $new, $pid);
        $stmt2->execute();
    }
    header("Location: products.php"); exit;
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $mysqli->prepare("DELETE FROM rpos_products WHERE prod_id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $success = "Product removed";
}

require_once('partials/_head.php');
?>

<style>
    :root {
        --accent: #6366f1;
        --accent-hover: #4f46e5;
        --bg-main: #f8fafc;
        --glass-white: rgba(255, 255, 255, 0.9);
    }

    body { background-color: var(--bg-main); font-family: 'Inter', sans-serif; }

    /* البلوك العلوي العبقري - Smart Hub */
    .smart-hub {
        background: var(--glass-white);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(226, 232, 240, 0.8);
        border-radius: 20px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        padding: 1.25rem;
        margin-bottom: 2rem;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 1.5rem;
        align-items: center;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* شريط البحث الاحترافي */
    .search-zone { position: relative; }
    .search-zone i {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 1.1rem;
    }
    .search-input {
        width: 100%;
        height: 54px;
        padding: 0 20px 0 55px;
        background: #f1f5f9;
        border: 1px solid transparent;
        border-radius: 14px;
        font-size: 1rem;
        color: #1e293b;
        transition: all 0.2s;
    }
    .search-input:focus {
        background: #fff;
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    /* مجموعة الأزرار الموحدة */
    .action-group {
        display: flex;
        gap: 10px;
        background: #f1f5f9;
        padding: 6px;
        border-radius: 16px;
    }

    .btn-hub {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 42px;
        padding: 0 18px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.875rem;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-add { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }
    .btn-add:hover { background: var(--accent-hover); transform: translateY(-1px); }

    .btn-export { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
    .btn-export:hover { background: #f8fafc; color: var(--accent); }

    /* تحسينات الجدول */
    .table-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    .table thead th {
        background: #f8fafc;
        padding: 1.2rem;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        color: #64748b;
        border: none;
    }
    .table tbody td { padding: 1.2rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .product-row {
        transition: transform 0.15s ease, background-color 0.15s ease;
    }
    .product-row:hover {
        background-color: rgba(14, 165, 233, 0.08);
        transform: translateY(-1px);
    }

    /* Responsive */
    @media (max-width: 991px) {
        .smart-hub { grid-template-columns: 1fr; gap: 1rem; }
        .action-group { width: 100%; justify-content: space-between; }
        .btn-hub { flex: 1; }
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>

        <div class="header pb-8 pt-5 pt-md-8" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
            <div class="container-fluid text-center">
                <h1 class="text-white display-4 font-weight-bold"><?php echo __('products_management'); ?></h1>
                <p class="text-light opacity-7">إدارة المخزون، الأسعار، وحالة المنتجات في مكان واحد</p>
            </div>
        </div>

        <div class="container-fluid mt--7">
            <div class="smart-hub">
                <div class="search-zone">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="هل تبحث عن منتج محدد؟ اكتب الاسم، الكود أو التصنيف هنا...">
                </div>

                <div class="action-group">
                    <a href="add_product.php" class="btn-hub btn-add text-white">
                        <i class="fas fa-plus-circle"></i>
                        <span>منتج جديد</span>
                    </a>
                    <a href="multi_edit_products.php" class="btn-hub btn-export">
                        <i class="fas fa-edit"></i>
                        <span>تعديل متعدد</span>
                    </a>
                    <a href="products.php?export=1" class="btn-hub btn-export">
                        <i class="fas fa-file-csv"></i>
                        <span>تصدير CSV</span>
                    </a>
                </div>
            </div>

            <div class="table-card shadow-lg">
                <div class="table-responsive">
                    <table id="productsTable" class="table align-items-center table-flush">
                        <thead>
                            <tr>
                                <th><?php echo __('code'); ?></th>
                                <th><?php echo __('name'); ?></th>
                                <th><?php echo __('qty'); ?></th>
                                <th><?php echo __('expiry_date'); ?></th>
                                <th><?php echo __('sellable'); ?></th>
                                <th><?php echo __('price'); ?></th>
                                <th class="text-right">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $ret = "SELECT p.*, c.category_name FROM rpos_products p LEFT JOIN categories c ON p.category_id = c.category_id ORDER BY p.created_at DESC";
                            $stmt = $mysqli->prepare($ret); $stmt->execute(); $res = $stmt->get_result();
                            while ($prod = $res->fetch_object()) {
                            ?>
                            <tr class="product-row">
                                <td class="font-weight-bold text-primary">#<?php echo $prod->prod_code; ?></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="h5 mb-0 text-dark"><?php echo $prod->prod_name; ?></span>
                                        <small class="text-muted"><?php echo $prod->category_name; ?></small>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-pill <?php echo ($prod->prod_stock <= $prod->reorder_level) ? 'badge-danger' : 'badge-success'; ?>">
                                        <?php echo $prod->prod_stock; ?> <?php echo $prod->prod_unit; ?>
                                    </span>
                                </td>
                                <td><i class="far fa-calendar-alt mr-1"></i> <?php echo ($prod->prod_expiry == '0000-00-00' ? 'N/A' : $prod->prod_expiry); ?></td>
                                <td>
                                    <span class="badge badge-dot">
                                        <i class="<?php echo $prod->prod_sellable ? 'bg-success' : 'bg-warning'; ?>"></i>
                                        <?php echo $prod->prod_sellable ? 'نشط' : 'متوقف'; ?>
                                    </span>
                                </td>
                                <td><span class="h4 mb-0">$<?php echo number_format($prod->prod_price, 2); ?></span></td>
                                <td class="text-right">
                                    <div class="dropdown">
                                        <a class="btn btn-sm btn-icon-only text-light" href="#" role="button" data-toggle="dropdown">
                                          <i class="fas fa-ellipsis-v"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-right dropdown-menu-arrow">
                                            <a class="dropdown-item" href="update_product.php?update=<?php echo $prod->prod_id; ?>"><i class="fas fa-edit text-primary"></i> تعديل</a>
                                            <a class="dropdown-item" href="products.php?toggle_sellable=<?php echo $prod->prod_id; ?>"><i class="fas fa-sync text-warning"></i> تبديل الحالة</a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger" href="products.php?delete=<?php echo $prod->prod_id; ?>" onclick="return confirm('حذف المنتج نهائياً؟')"><i class="fas fa-trash"></i> حذف</a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>

    <script>
        // بحث ذكي وسريع (Client-side) مع تأثيرات Anime.js
    </script>
</body>
