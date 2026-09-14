<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
include('config/languages.php');
check_login();

$missing_products = [];
$missing_sell_price_products = [];
$profit_rate = floatval(getSetting('profit_rate', '1'));
$sell_price_profit_rate = floatval(getSetting('sell_price_profit_rate', '1'));
$salary_expense = floatval(getSetting('salary_expense', '0'));
$other_expenses = floatval(getSetting('other_expenses', '0'));
$capital_share_pct = floatval(getSetting('capital_share_pct', '0'));
$commission_enabled = getSetting('shift_commission_enabled', '0') === '1';
$commission_pct = floatval(getSetting('shift_commission_pct', '0'));
$adminUsers = getAllAdminUsers($mysqli);
$controlledPages = getControlledPages();
$permission_admin_id = '';
$permission_pages = [];
$permission_message = '';
$permission_error = '';
$full_profit_summary = [];
$profit_update_count = 0;
$sell_price_update_count = 0;

// Profit Enhancers settings (feature toggles and parameters)
$enable_margin_optimizer = getSetting('enable_margin_optimizer','0') === '1';
$optimizer_max_increase_pct = floatval(getSetting('optimizer_max_increase_pct','5'));
$optimizer_min_margin_target = floatval(getSetting('optimizer_min_margin_target','10'));

$enable_surge_pricing = getSetting('enable_surge_pricing','0') === '1';
$surge_hours = getSetting('surge_hours','08:00-10:00,17:00-19:00');
$surge_pct = floatval(getSetting('surge_pct','10'));

$enable_bundles = getSetting('enable_bundles','0') === '1';
$bundle_rules = getSetting('bundle_rules','');

$capital_share_by_category = getSetting('capital_share_by_category','{}');

$enable_predictive_reorder = getSetting('enable_predictive_reorder','0') === '1';
$reorder_lookahead_days = intval(getSetting('reorder_lookahead_days','30'));
$min_bulk_discount_pct = floatval(getSetting('min_bulk_discount_pct','2'));

$shrinkage_alert_threshold_pct = floatval(getSetting('shrinkage_alert_threshold_pct','2'));

$enable_subscriptions = getSetting('enable_subscriptions','0') === '1';
$subscription_discount_pct = floatval(getSetting('subscription_discount_pct','5'));

$enable_price_ai = getSetting('enable_price_ai','0') === '1';
$ai_training_window_days = intval(getSetting('ai_training_window_days','90'));
$ai_confidence_threshold = floatval(getSetting('ai_confidence_threshold','0.7'));

/*
 * Profit Enhancers - Usage Notes
 * --------------------------------
 * These settings are feature toggles and parameters only. Implementation notes:
 *
 * 1) Margin Optimizer
 *    - Toggle: 'enable_margin_optimizer'
 *    - Params: 'optimizer_max_increase_pct', 'optimizer_min_margin_target'
 *    - Behaviour: analyze recent sales (30-90 days), compute current margin per product,
 *      and propose price increases up to 'optimizer_max_increase_pct' when product
 *      demand remains strong and margin < target. Changes SHOULD be applied as
 *      recommendations (not auto) until tested.
 *
 * 2) Surge Pricing
 *    - Toggle: 'enable_surge_pricing'
 *    - Params: 'surge_hours' (comma-separated ranges), 'surge_pct'
 *    - Behaviour: at checkout, if current time falls in a surge range, apply temporary
 *      markup of surge_pct to displayed prices. Do not persist to product table.
 *
 * 3) Smart Bundles
 *    - Toggle: 'enable_bundles'
 *    - Params: 'bundle_rules' (JSON array of rules)
 *    - Behaviour: when a main product is added to cart, suggest offer product at
 *      discounted bundle price. Rules should be validated server-side before use.
 *
 * 4) Capital Share by Category
 *    - Param: 'capital_share_by_category' (JSON mapping category => pct)
 *    - Behaviour: override global capital share for specific categories when
 *      computing 'Capital Share Cost' in Full Profit Forecast.
 *
 * 5) Predictive Reorder
 *    - Toggle: 'enable_predictive_reorder'
 *    - Params: 'reorder_lookahead_days', 'min_bulk_discount_pct'
 *    - Behaviour: forecast demand to create suggested PO quantities and estimate
 *      potential bulk discounts; requires purchase history ingestion.
 *
 * 6) Shrinkage Detection
 *    - Param: 'shrinkage_alert_threshold_pct'
 *    - Behaviour: compare expected stock (receipts - sales) vs actual inventory and
 *      alert when discrepancy > threshold.
 *
 * 7) Subscriptions
 *    - Toggle: 'enable_subscriptions'
 *    - Params: 'subscription_discount_pct'
 *    - Behaviour: allow creating recurring orders with dedicated pricing and
 *      guaranteed margin settings.
 *
 * 8) Price AI
 *    - Toggle: 'enable_price_ai'
 *    - Params: 'ai_training_window_days', 'ai_confidence_threshold'
 *    - Behaviour: optional ML pipeline to estimate price elasticity and suggest
 *      optimal price deltas. Start in 'recommendation' mode and log outcomes.
 *
 * Implementation guidance:
 * - Start all features in 'recommendation' mode; record A/B test metrics.
 * - Log every automated suggestion (old_price,new_price,reason,user_id,timestamp).
 * - Protect customer-facing prices: apply surges/bundles at cart level only.
 * - Ensure JSON fields are validated with json_decode before use.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // دالة مساعدة لحفظ الإعدادات مباشرة في قاعدة البيانات (لتجنب الاعتماد على دوال خارجية قد تكون مفقودة)
    function save_db_setting($mysqli, $key, $value) {
        $stmt = $mysqli->prepare("INSERT INTO rpos_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('sss', $key, $value, $value);
        $stmt->execute();
        $stmt->close();
    }


    // ==========================================
    // 1. حفظ الإعدادات العامة (Settings)
    // ==========================================
    if (isset($_POST['save_settings'])) {
        // معالجة رفع شعار الهيدر (Header Logo)
        if (isset($_FILES['header_logo_file']) && $_FILES['header_logo_file']['error'] == 0) {
            $target_dir = "../admin/assets/img/theme/";
            if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $file_name = time() . '_' . basename($_FILES["header_logo_file"]["name"]);
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["header_logo_file"]["tmp_name"], $target_file)) {
                $_POST['header_logo'] = "assets/img/theme/" . $file_name; // تحديث المسار
            }
        }

        // معالجة رفع شعار القائمة الجانبية (Sidebar Logo)
        if (isset($_FILES['sidebar_logo_file']) && $_FILES['sidebar_logo_file']['error'] == 0) {
            $target_dir = "../admin/assets/img/brand/";
            if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $file_name = time() . '_' . basename($_FILES["sidebar_logo_file"]["name"]);
            $target_file = $target_dir . $file_name;
            if (move_uploaded_file($_FILES["sidebar_logo_file"]["tmp_name"], $target_file)) {
                $_POST['sidebar_logo'] = "assets/img/brand/" . $file_name; // تحديث المسار
            }
        }

        // حفظ جميع المتغيرات في قاعدة البيانات
        $settings_to_save = [
            'tax_rate' => $_POST['tax_rate'] ?? '0',
            'discount_rate' => $_POST['discount_rate'] ?? '0',
            'enable_barcode' => isset($_POST['enable_barcode']) ? '1' : '0',
            'enable_shortcuts' => isset($_POST['enable_shortcuts']) ? '1' : '0',
            'enable_tax' => isset($_POST['enable_tax']) ? '1' : '0',
            'enable_discount' => isset($_POST['enable_discount']) ? '1' : '0',
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'header_logo' => trim($_POST['header_logo'] ?? getSetting('header_logo')),
            'sidebar_logo' => trim($_POST['sidebar_logo'] ?? getSetting('sidebar_logo')),
            'enable_margin_optimizer' => isset($_POST['enable_margin_optimizer']) ? '1' : '0',
            'optimizer_max_increase_pct' => trim($_POST['optimizer_max_increase_pct'] ?? '5'),
            'optimizer_min_margin_target' => trim($_POST['optimizer_min_margin_target'] ?? '10'),
            'enable_surge_pricing' => isset($_POST['enable_surge_pricing']) ? '1' : '0',
            'surge_hours' => trim($_POST['surge_hours'] ?? ''),
            'surge_pct' => trim($_POST['surge_pct'] ?? '10'),
            'enable_bundles' => isset($_POST['enable_bundles']) ? '1' : '0',
            'bundle_rules' => trim($_POST['bundle_rules'] ?? ''),
            'capital_share_by_category' => trim($_POST['capital_share_by_category'] ?? '{}'),
            'enable_predictive_reorder' => isset($_POST['enable_predictive_reorder']) ? '1' : '0',
            'reorder_lookahead_days' => trim($_POST['reorder_lookahead_days'] ?? '30'),
            'min_bulk_discount_pct' => trim($_POST['min_bulk_discount_pct'] ?? '2'),
            'shrinkage_alert_threshold_pct' => trim($_POST['shrinkage_alert_threshold_pct'] ?? '2'),
            'enable_subscriptions' => isset($_POST['enable_subscriptions']) ? '1' : '0',
            'subscription_discount_pct' => trim($_POST['subscription_discount_pct'] ?? '5'),
            'enable_price_ai' => isset($_POST['enable_price_ai']) ? '1' : '0',
            'ai_training_window_days' => trim($_POST['ai_training_window_days'] ?? '90'),
            'ai_confidence_threshold' => trim($_POST['ai_confidence_threshold'] ?? '0.7')
        ];

        foreach ($settings_to_save as $key => $value) {
            save_db_setting($mysqli, $key, $value);
        }
        $success = 'تم حفظ إعدادات النظام بنجاح.';
    }


    // ==========================================
    // 2. حفظ صلاحيات المستخدمين (مستقل)
    // ==========================================
    if (isset($_POST['save_page_permissions'])) {
        $permission_admin_id = trim($_POST['permission_admin_id'] ?? '');
        $selected_pages = $_POST['permission_pages'] ?? [];

        if (empty($permission_admin_id)) {
            $permission_error = 'يجب اختيار مسؤول لحفظ الصلاحيات.';
        } else {
            // حذف الصلاحيات القديمة لهذا المستخدم
            $del_stmt = $mysqli->prepare("DELETE FROM rpos_admin_page_permissions WHERE admin_id = ?");
            $del_stmt->bind_param('s', $permission_admin_id);
            $del_stmt->execute();
            $del_stmt->close();

            // إضافة الصلاحيات الجديدة
            if (!empty($selected_pages) && is_array($selected_pages)) {
                $ins_stmt = $mysqli->prepare("INSERT INTO rpos_admin_page_permissions (admin_id, page_name) VALUES (?, ?)");
                foreach ($selected_pages as $page) {
                    $page = trim($page);
                    if ($page !== '') {
                        $ins_stmt->bind_param('ss', $permission_admin_id, $page);
                        $ins_stmt->execute();
                    }
                }
                $ins_stmt->close();
            }
            $permission_message = 'تم حفظ صلاحيات المستخدم بنجاح.';
            
            // تحديث المصفوفة لتعكس الواجهة
            $permission_pages = $selected_pages;
        }
    }

    if (isset($_POST['profit_rate'])) {
        $profit_rate = floatval(trim($_POST['profit_rate'] ?? '1')) ?: 1;
        setSetting('profit_rate', (string) $profit_rate);
    }

    if (isset($_POST['sell_price_profit_rate'])) {
        $sell_price_profit_rate = floatval(trim($_POST['sell_price_profit_rate'] ?? '1')) ?: 1;
        setSetting('sell_price_profit_rate', (string) $sell_price_profit_rate);
    }

    if (isset($_POST['salary_expense'])) {
        $salary_expense = floatval(trim($_POST['salary_expense'] ?? '0'));
        setSetting('salary_expense', (string) $salary_expense);
    }

    if (isset($_POST['other_expenses'])) {
        $other_expenses = floatval(trim($_POST['other_expenses'] ?? '0'));
        setSetting('other_expenses', (string) $other_expenses);
    }

    if (isset($_POST['capital_share_pct'])) {
        $capital_share_pct = floatval(trim($_POST['capital_share_pct'] ?? '0'));
        setSetting('capital_share_pct', (string) $capital_share_pct);
    }

    $commission_enabled = isset($_POST['shift_commission_enabled']) ? '1' : '0';
    setSetting('shift_commission_enabled', $commission_enabled);

    if (isset($_POST['commission_pct'])) {
        $commission_pct = max(0, floatval(trim($_POST['commission_pct'] ?? '0')));
        setSetting('shift_commission_pct', (string) $commission_pct);
    }

 
    if (isset($_POST['show_missing_items']) || isset($_POST['fill_purchase_prices'])) {
        $missing_products = [];
        $query = "SELECT prod_id, prod_name, prod_price, COALESCE(last_purchase_price, 0) AS last_purchase_price FROM rpos_products WHERE COALESCE(last_purchase_price, 0) = 0 AND prod_price > 0 ORDER BY prod_name";
        if ($result = $mysqli->query($query)) {
            while ($row = $result->fetch_assoc()) {
                $missing_products[] = $row;
            }
            $result->free();
        }

        if (isset($_POST['fill_purchase_prices']) && !empty($missing_products)) {
            if ($profit_rate <= 0) {
                $err = 'Profit rate must be greater than zero to calculate purchase prices.';
            } else {
                $updateStmt = $mysqli->prepare("UPDATE rpos_products SET last_purchase_price = ?, last_purchase_date = NOW() WHERE prod_id = ? LIMIT 1");
                if ($updateStmt) {
                    foreach ($missing_products as $item) {
                        $calculated_cost = round(floatval($item['prod_price']) / $profit_rate, 2);
                        $updateStmt->bind_param('ds', $calculated_cost, $item['prod_id']);
                        if ($updateStmt->execute()) {
                            $profit_update_count++;
                        }
                    }
                    $updateStmt->close();
                }
                $success = "Updated {$profit_update_count} product" . ($profit_update_count === 1 ? '' : 's') . " with calculated purchase price.";
                $missing_products = [];
            }
        }
    }

    if (isset($_POST['show_missing_sell_price']) || isset($_POST['fill_sell_prices'])) {
        $missing_sell_price_products = [];
        $query = "SELECT prod_id, prod_name, COALESCE(last_purchase_price, 0) AS last_purchase_price, prod_price FROM rpos_products WHERE (prod_price IS NULL OR prod_price = 0 OR prod_price = '') AND COALESCE(last_purchase_price, 0) > 0 ORDER BY prod_name";
        if ($result = $mysqli->query($query)) {
            while ($row = $result->fetch_assoc()) {
                $missing_sell_price_products[] = $row;
            }
            $result->free();
        }

        if (isset($_POST['fill_sell_prices']) && !empty($missing_sell_price_products)) {
            if ($sell_price_profit_rate <= 0) {
                $err = 'Profit rate must be greater than zero to calculate sell prices.';
            } else {
                $updateStmt = $mysqli->prepare("UPDATE rpos_products SET prod_price = ? WHERE prod_id = ? LIMIT 1");
                if ($updateStmt) {
                    foreach ($missing_sell_price_products as $item) {
                        $calculated_sell = round(floatval($item['last_purchase_price']) * $sell_price_profit_rate, 2);
                        $updateStmt->bind_param('ds', $calculated_sell, $item['prod_id']);
                        if ($updateStmt->execute()) {
                            $sell_price_update_count++;
                        }
                    }
                    $updateStmt->close();
                }
                $success = "Updated {$sell_price_update_count} product" . ($sell_price_update_count === 1 ? '' : 's') . " with calculated sell price.";
                $missing_sell_price_products = [];
            }
        }
    }

    if (isset($_POST['calculate_full_profit'])) {
        $query = "SELECT COALESCE(SUM(prod_stock * COALESCE(last_purchase_price,0)),0) AS total_cost, COALESCE(SUM(prod_stock * COALESCE(prod_price,0)),0) AS total_revenue FROM rpos_products WHERE COALESCE(last_purchase_price,0) > 0 AND COALESCE(prod_price,0) > 0";
        if ($result = $mysqli->query($query)) {
            $summary = $result->fetch_assoc();
            $result->free();
            $total_cost = floatval($summary['total_cost']);
            $total_revenue = floatval($summary['total_revenue']);
            $gross_profit = $total_revenue - $total_cost;
            $capital_share_amount = round($total_cost * ($capital_share_pct / 100), 2);
            $expected_profit = round($gross_profit - $salary_expense - $other_expenses - $capital_share_amount, 2);
            $profit_margin = $total_revenue > 0 ? round(($expected_profit / $total_revenue) * 100, 2) : 0;

            $full_profit_summary = [
                'total_cost' => $total_cost,
                'total_revenue' => $total_revenue,
                'gross_profit' => $gross_profit,
                'capital_share' => $capital_share_amount,
                'salary_expense' => $salary_expense,
                'other_expenses' => $other_expenses,
                'expected_profit' => $expected_profit,
                'profit_margin' => $profit_margin,
            ];

            if ($total_revenue <= 0) {
                $info = 'No valid product stock with both purchase and sell price was found.';
            }
        }
    }

    if (isset($_POST['mark_commission_paid']) && !empty($_POST['mark_commission_paid'])) {
        $commission_id = intval($_POST['mark_commission_paid']);
        $paid_by = $_SESSION['admin_name'] ?? $_SESSION['admin_id'] ?? '';
        $stmt = $mysqli->prepare("UPDATE rpos_shift_commissions SET status = 'paid', paid_at = NOW(), paid_by = ? WHERE commission_id = ? AND status = 'due' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $paid_by, $commission_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Commission payment recorded successfully.';
            } else {
                $info = 'Commission record was already marked paid or not found.';
            }
            $stmt->close();
        }
    }
 
}

if (empty($permission_pages) && !empty($permission_admin_id)) {
    $permission_pages = getUserPagePermissions($mysqli, $permission_admin_id);
}

if (isset($_POST['show_missing_items']) && empty($missing_products)) {
    $info = 'No missing-cost products were found.';
}

if (isset($_POST['show_missing_sell_price']) && empty($missing_sell_price_products)) {
    $info = 'No products without sell price were found.';
}

if (isset($_POST['calculate_full_profit']) && empty($full_profit_summary) && empty($err)) {
    $info = 'No available stock data matched the expense forecast criteria.';
}

if (isset($_POST['fill_purchase_prices']) && $profit_update_count === 0 && empty($err)) {
    $info = 'No items were updated. Either no missing-cost products exist or the profit rate is not valid.';
}

if (isset($_POST['fill_sell_prices']) && $sell_price_update_count === 0 && empty($err)) {
    $info = 'No items were updated. Either no missing sell price products exist or the profit rate is not valid.';
}

if (isset($_POST['save_settings']) && empty($success) && empty($err)) {
    $success = 'Settings saved successfully';
}

$commission_history = [];
$commission_history_sql = "
    SELECT c.*, s.opened_at, s.closed_at, u.admin_name AS staff_name
    FROM rpos_shift_commissions c
    JOIN rpos_shifts s ON c.shift_id = s.shift_id
    LEFT JOIN rpos_admin u ON c.user_id = u.admin_id
    ORDER BY c.created_at DESC
    LIMIT 20
";
if ($result = $mysqli->query($commission_history_sql)) {
    while ($row = $result->fetch_assoc()) {
        $commission_history[] = $row;
    }
    $result->free();
}
/*
if (isset($_POST['train_ai'])) {
    require_once('config/ai_api_client.php');
    $ai_response = trigger_ai_training();
    if (isset($ai_response['status']) && $ai_response['status'] == 'success') {
        $ai_success = $ai_response['message'];
        $ai_status = $ai_response['training_status'] ?? get_ai_training_status();
    } else {
        $ai_error = $ai_response['message'] ?? 'فشل في بدء التدريب';
        $ai_status = get_ai_training_status();
    }
} else {
    $ai_status = get_ai_training_status();
    if (!is_array($ai_status) || !in_array($ai_status['status'] ?? '', ['running', 'submitted'], true)) {
        $auto_start = ensure_ai_training_started();
        if (isset($auto_start['status']) && $auto_start['status'] == 'submitted') {
            $ai_success = 'تم بدء التدريب تلقائياً لأن النظام لم يكن يعمل.';
        }
        $ai_status = $auto_start;
    }
}*/

require_once('partials/_head.php');
/* 
كيف تعمل هذه الجزئية بالضبط
الجزء Full Profit Forecast يحسب الربح المتوقع بناءً على رصيد المنتجات الحالية، وليس على المبيعات الفعلية.

المعادلات هي:

Total Purchase Capital = مجموع تكلفة مخزون المنتجات
= prod_stock × last_purchase_price لكل منتج

Total Revenue = مجموع سعر البيع لمخزون المنتجات
= prod_stock × prod_price لكل منتج

Gross Profit = Total Revenue - Total Purchase Capital

Capital Share Cost = Total Purchase Capital × (Capital Share % / 100)

Salary + Expenses = Salary Expense + Other Expenses

Expected Net Profit = Gross Profit - Capital Share Cost - Salary Expense - Other Expenses

Expected Margin = Expected Net Profit / Total Revenue × 100

لماذا في حالتك النتيجة 10,397,842.33؟
لأنك حاليًا أدخلت:

Salary Expense = 0
Other Expenses = 0
Capital Share % = 0
فبالتالي:

Capital Share Cost = 0
Salary + Expenses = 0
لذا Expected Net Profit = Gross Profit
وهذا صحيح رياضيًا، لكنه يعني أنك لم تخصم مصاريف التشغيل الحقيقية بعد.

كيف تزيد صافي الربح؟
1. ارفع إجمالي الإيرادات
ارفع السعر إذا السوق يسمح.
استخدم عروض خاصة لزيادة كمية البيع.
ركز على المنتجات ذات هامش ربح أعلى.
حَسّن عرض المنتج أو عبواته لتزيد القيمة للعميل.
2. خفّض تكلفة الشراء
تفاوض مع الموردين على تكلفة أقل.
اشتري كميات ذكية من المنتجات الأكثر مبيعًا فقط.
امسح المنتجات الراكدّة أو أعد تسعيرها بسرعة.
استخدم أداة Purchase Price Recovery لتصحيح الأسعار إذا كانت تكلفة الشراء غير مضبوطة.
3. قلل المصاريف الثابتة
أدخل قيمة حقيقية للرواتب والمصاريف الأخرى.
راجع الإيجار، الكهرباء، النقل، والعمولات.
قلل الهدر والتكاليف غير الضرورية.
4. ضبط Capital Share %
إذا كان هذا الحقل يمثل رأس المال المقيد في المخزون، فخفضه يقلل من تكلفة الحصة الرأسمالية.
لكن لا تخفضه كثيرًا إذا تريد أن تحسب قيمة رأس المال المحجوز بشكل واقعي.
نصيحة عملية
إذا تريد زيادة Expected Margin من 13.52% إلى مثلاً 20%:

خفّض التكاليف بنسبة 6-7%
أو ارفع الأسعار بحوالي 6-7%
أو اجمع بين التقليل في التكاليف وزيادة المبيعات
في هذه الصفحة يمكن أن يكون أفضل تأثير لك هو ضبط الأسعار والتكلفة الفعلية للشراء وتحميل المصاريف الحقيقية.

الخلاصة
هذا القسم مجرد “توقع” يعتمد على بيانات المخزون والاسعار الحالية.

إذا وضعت قيم حقيقية للرواتب والمصاريف وcapital share، فسيعطيك صورة أدق عن صافي الربح. لزيادة الربح فعليًا:

زِد الإيرادات،
وَقلّل التكلفة，
وَحسّن التحكم في المصاريف.

*/
?>

<style>
    /* Custom Styles for a Premium Look */
    .setting-card {
        border-radius: 15px;
        border: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        background: #fff;
    }
    .setting-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(50, 50, 93, 0.1), 0 5px 15px rgba(0, 0, 0, 0.07) !important;
    }
    .card-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: white;
        margin-bottom: 15px;
    }
    
    /* Modern Toggle Switch */
    .custom-switch-wrap {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        background: #f8f9fe;
        border-radius: 10px;
        margin-bottom: 15px;
    }
    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
        margin: 0;
    }
    .switch input { 
        opacity: 0;
        width: 0;
        height: 0;
    }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: #cbd5e1;
        transition: .4s;
        border-radius: 34px;
    }
    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    input:checked + .slider { background-color: #2dce89; }
    input:checked + .slider:before { transform: translateX(24px); }
    
    .form-control, .form-control-file {
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        padding: 10px 15px;
        transition: all 0.2s;
    }
    .form-control:focus {
        border-color: #5e72e4;
        box-shadow: 0 0 0 3px rgba(94, 114, 228, 0.1);
    }
    .profit-card {
        border-radius: 20px;
        overflow: hidden;
        background: linear-gradient(145deg, #0f172a, #1e293b);
        color: #e2e8f0;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
    }
    .profit-card .card-icon {
        width: 56px;
        height: 56px;
        border-radius: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: #fff;
        margin-bottom: 18px;
    }
    .profit-card .card-icon i {
        margin: 0;
    }
    .profit-card .btn-outline-info,
    .profit-card .btn-info {
        min-height: 46px;
    }
    .profit-card .action-copy {
        display: flex;
        gap: 0.8rem;
        flex-wrap: wrap;
    }
    .profit-card .missing-list {
        max-height: 260px;
        overflow: auto;
        margin-top: 1rem;
        padding: 1rem;
        border-radius: 1rem;
        background: rgba(15, 23, 42, 0.75);
        border: 1px solid rgba(148, 163, 184, 0.18);
    }
    .profit-card .missing-list .item-row {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 0.75rem;
        padding: 0.85rem 0;
        border-bottom: 1px solid rgba(226, 232, 240, 0.08);
    }
    .profit-card .missing-list .item-row:last-child {
        border-bottom: none;
    }
    .profit-card .missing-list .item-row span {
        font-size: 0.9rem;
        color: #cbd5e1;
    }
    .profit-card .missing-list .item-title {
        color: #f8fafc;
        font-weight: 600;
    }
    .profit-card .summary-grid {
        display: grid;
        grid-template-columns: minmax(150px, 1fr) minmax(120px, 1fr);
        gap: 0.9rem 1.5rem;
        margin-top: 1rem;
        padding: 1rem;
        border-radius: 1rem;
        background: rgba(15, 23, 42, 0.85);
        border: 1px solid rgba(148, 163, 184, 0.18);
    }
    .profit-card .summary-grid .summary-label {
        color: #94a3b8;
        font-size: 0.9rem;
    }
    .profit-card .summary-grid .summary-value {
        color: #f8fafc;
        font-weight: 700;
        text-align: right;
    }
    .volume-control {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        background: rgba(226, 232, 240, 0.08);
        border-radius: 50px;
        padding: 0.35rem 0.5rem;
        border: 1.5px solid rgba(148, 163, 184, 0.25);
    }
    .volume-control .btn-volume {
        width: 38px;
        height: 38px;
        padding: 0;
        border-radius: 50%;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        background: rgba(59, 130, 246, 0.15);
        color: #60a5fa;
        transition: all 0.25s ease;
        flex-shrink: 0;
    }
    .volume-control .btn-volume:hover {
        background: rgba(59, 130, 246, 0.3);
        transform: scale(1.1);
    }
    .volume-control .btn-volume:active {
        transform: scale(0.95);
    }
    .volume-control input[type="number"] {
        flex: 1;
        border: none;
        background: transparent;
        color: #e2e8f0;
        text-align: center;
        font-weight: 600;
        font-size: 1.1rem;
        min-width: 70px;
    }
    .volume-control input[type="number"]::-webkit-outer-spin-button,
    .volume-control input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    .volume-control input[type="number"]:focus {
        outline: none;
    }
    .profit-card {
        max-width: 450px;
    }
    .profit-badges {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
        flex-wrap: wrap;
    }
    .profit-badge {
        background: rgba(59, 130, 246, 0.15);
        color: #60a5fa;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid rgba(59, 130, 246, 0.25);
    }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(../admin/assets/img/theme/restro00.jpg); background-size: cover; background-position: center;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-primary opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                            <h6 class="h2 text-white d-inline-block mb-0"><?php echo __('POS_Settings'); ?></h6>
                            <p class="text-white mt-2 mb-0">Manage your system configurations, branding, and POS behavior.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            <form method="post" enctype="multipart/form-data">
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success mb-4"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <?php if (!empty($err)): ?>
                    <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($err); ?></div>
                <?php endif; ?>
                <?php if (!empty($info)): ?>
                    <div class="alert alert-info mb-4"><?php echo htmlspecialchars($info); ?></div>
                <?php endif; ?>





                
<?php if (isSuperAdmin($_SESSION['admin_id'] ?? '')): ?>
<form method="post" id="permissionsForm">
    <div class="row mb-4">
        <div class="col-xl-12">
            <div class="card setting-card shadow p-4">
                <div class="d-flex align-items-center mb-4">
                    <div class="card-icon bg-gradient-primary shadow-primary mr-3">
                        <i class="fas fa-user-lock"></i>
                    </div>
                    <div>
                        <h4 class="mb-1">إعدادات صلاحيات المستخدم</h4>
                        <p class="text-muted mb-0">حدد الصفحات المسموح الوصول إليها لكل مسؤول في النظام.</p>
                    </div>
                </div>

                <?php if (!empty($permission_message)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($permission_message); ?></div>
                <?php endif; ?>
                <?php if (!empty($permission_error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($permission_error); ?></div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-lg-4">
                        <label class="form-control-label">اختر المسؤول</label>
                        <select id="permissionAdminSelect" name="permission_admin_id" class="form-control" required>
                            <option value="">اختر المسؤول</option>
                            <?php foreach ($adminUsers as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['admin_id']); ?>" <?php echo $permission_admin_id === $user['admin_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['admin_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-2 permission-load-message">اختر مسؤولاً لتحميل الصلاحيات.</div>
                    </div>
                    <div class="form-group col-lg-8">
                        <label class="form-control-label">صلاحيات الصفحات</label>
                        <div id="permissionPagesList" class="row">
                            <?php foreach ($controlledPages as $page => $label): ?>
                                <div class="col-sm-6">
                                    <div class="custom-control custom-checkbox mb-3">
                                        <?php $permissionId = 'perm_' . md5($page); ?>
                                        <input type="checkbox" class="custom-control-input" id="<?php echo htmlspecialchars($permissionId); ?>" name="permission_pages[]" value="<?php echo htmlspecialchars($page); ?>" <?php echo in_array($page, $permission_pages, true) ? 'checked' : ''; ?> <?php echo empty($permission_admin_id) ? 'disabled' : ''; ?> >
                                        <label class="custom-control-label" for="<?php echo htmlspecialchars($permissionId); ?>"><?php echo htmlspecialchars(__($label)); ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="form-text text-muted">يمكنك تعديل صلاحيات الوصول لصفحات الإدارة هنا.</small>
                    </div>
                </div>

                <div class="text-right">
                    <button type="submit" name="save_page_permissions" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i> حفظ صلاحيات المستخدم
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php else: ?>
<div class="row mb-4">
    <div class="col-xl-12">
        <div class="alert alert-warning shadow-sm">
            <strong>تنبيه:</strong> قسم صلاحيات المستخدمين خاص بالمشرف الأعلى فقط.
        </div>
    </div>
</div>
<?php endif; ?>










                <div class="row">
                    
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card setting-card shadow p-4 h-100">
                            <div class="card-icon bg-gradient-info shadow-info">
                                <i class="ni ni-building"></i>
                            </div>
                            <h4 class="mb-4">Company Profile</h4>
                            
                            <div class="form-group">
                                <label class="form-control-label">Company Name</label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars(getSetting('company_name', 'My POS Company')); ?>" placeholder="Enter company name">
                            </div>
                            <div class="form-group">
                                <label class="form-control-label">Contact Phone</label>
                                <input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars(getSetting('company_phone', '+123456789')); ?>" placeholder="e.g. +123 456 789">
                            </div>
                            <div class="form-group mb-0">
                                <label class="form-control-label">Business Address</label>
                                <textarea name="company_address" class="form-control" rows="3" placeholder="Enter full address"><?php echo htmlspecialchars(getSetting('company_address', 'Company City, Street 123')); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card setting-card shadow p-4 h-100">
                            <div class="card-icon bg-gradient-warning shadow-warning">
                                <i class="ni ni-image"></i>
                            </div>
                            <h4 class="mb-4">Branding & Assets</h4>

                            <div class="form-group">
                                <label class="form-control-label text-primary">Header Logo</label>
                                <input type="text" name="header_logo" class="form-control mb-2" value="<?php echo htmlspecialchars(getSetting('header_logo', 'assets/img/theme/repos.png')); ?>" placeholder="Logo URL">
                                <div class="custom-file mt-1">
                                    <input type="file" name="header_logo_file" class="form-control-file" id="headerLogo">
                                    <small class="form-text text-muted mt-1"><i class="ni ni-cloud-upload-96 mr-1"></i> Upload new (JPG/PNG/SVG - Max 2MB)</small>
                                </div>
                            </div>
                            
                            <hr class="my-3">

                            <div class="form-group mb-0">
                                <label class="form-control-label text-primary">Sidebar Logo</label>
                                <input type="text" name="sidebar_logo" class="form-control mb-2" value="<?php echo htmlspecialchars(getSetting('sidebar_logo', 'assets/img/brand/repos.png')); ?>" placeholder="Logo URL">
                                <div class="custom-file mt-1">
                                    <input type="file" name="sidebar_logo_file" class="form-control-file" id="sidebarLogo">
                                    <small class="form-text text-muted mt-1"><i class="ni ni-cloud-upload-96 mr-1"></i> Upload new (JPG/PNG/SVG - Max 2MB)</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-12 mb-4">
                        <div class="card setting-card shadow p-4 h-100">
                            <div class="card-icon bg-gradient-success shadow-success">
                                <i class="ni ni-settings-gear-65"></i>
                            </div>
                            <h4 class="mb-4">POS Preferences</h4>

                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="form-control-label">Sales Tax (%)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="tax_rate" class="form-control" value="<?php echo getSetting('tax_rate', '0'); ?>">
                                        <div class="input-group-append">
                                            <span class="input-group-text"><i class="fas fa-percent"></i></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label class="form-control-label">Default Discount (%)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="discount_rate" class="form-control" value="<?php echo getSetting('discount_rate', '0'); ?>">
                                        <div class="input-group-append">
                                            <span class="input-group-text"><i class="fas fa-percent"></i></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-2">
                                <div class="custom-switch-wrap">
                                    <label class="form-control-label mb-0" for="enable_tax">Apply Tax to Cart</label>
                                    <label class="switch">
                                        <input type="checkbox" id="enable_tax" name="enable_tax" <?php echo getSetting('enable_tax','0')=='1'?'checked':'';?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="custom-switch-wrap">
                                    <label class="form-control-label mb-0" for="enable_discount">Apply Discount to Cart</label>
                                    <label class="switch">
                                        <input type="checkbox" id="enable_discount" name="enable_discount" <?php echo getSetting('enable_discount','0')=='1'?'checked':'';?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="custom-switch-wrap">
                                    <label class="form-control-label mb-0" for="enable_barcode">Enable Barcode Scanning</label>
                                    <label class="switch">
                                        <input type="checkbox" id="enable_barcode" name="enable_barcode" <?php echo getSetting('enable_barcode','0')=='1'?'checked':'';?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="custom-switch-wrap mb-1">
                                    <div>
                                        <label class="form-control-label mb-0" for="enable_shortcuts">Enable Keyboard Shortcuts</label>
                                        <small class="d-block text-muted" style="font-size: 0.75rem;">F3: Focus Barcode | F4: Checkout</small>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" id="enable_shortcuts" name="enable_shortcuts" <?php echo getSetting('enable_shortcuts','0')=='1'?'checked':'';?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8 col-lg-12 mb-4">
                        <div class="card setting-card shadow p-4 h-100">
                            <div class="card-icon bg-gradient-primary shadow-primary">
                                <i class="fas fa-lightbulb"></i>
                            </div>
                            <h4 class="mb-3">Profit Enhancers (Advanced)</h4>
                            <p class="text-muted">مجموعة إعدادات لتحسين صافي الربح باستخدام قواعد ذكية، عروض حزم، تسعير ذكي، وجدولة رأس المال.</p>

                            <div class="form-row">
                                <div class="col-md-6 mb-3">
                                    <label>Enable Margin Optimizer</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">يُفعّل توصيات تغيير الأسعار لرفع الهامش.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_margin_optimizer" <?php echo $enable_margin_optimizer ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Max Increase (%)</label>
                                    <input type="number" step="0.1" name="optimizer_max_increase_pct" class="form-control" value="<?php echo htmlspecialchars($optimizer_max_increase_pct); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Min Margin Target (%)</label>
                                    <input type="number" step="0.1" name="optimizer_min_margin_target" class="form-control" value="<?php echo htmlspecialchars($optimizer_min_margin_target); ?>">
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Enable Surge Pricing</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">رفع مؤقت للأسعار خلال ساعات الذروة.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_surge_pricing" <?php echo $enable_surge_pricing ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Surge Hours (comma list)</label>
                                    <input type="text" name="surge_hours" class="form-control" value="<?php echo htmlspecialchars($surge_hours); ?>">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label>Surge %</label>
                                    <input type="number" step="0.1" name="surge_pct" class="form-control" value="<?php echo htmlspecialchars($surge_pct); ?>">
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Enable Smart Bundles</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">تجميع منتجات لزيادة متوسط قيمة السلة وتحرير المخزون البطيء.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_bundles" <?php echo $enable_bundles ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12 mb-3">
                                    <label>Bundle Rules (JSON)</label>
                                    <textarea name="bundle_rules" class="form-control" rows="4"><?php echo htmlspecialchars($bundle_rules); ?></textarea>
                                    <small class="text-muted">مثال: [{"main":"prod_id","offer":"prod_id","discount_pct":10}]</small>
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Capital Share by Category (JSON)</label>
                                    <textarea name="capital_share_by_category" class="form-control" rows="3"><?php echo htmlspecialchars($capital_share_by_category); ?></textarea>
                                    <small class="text-muted">تخصيص نسبة حصة رأس المال لفئات المنتجات لتقدير أدق.</small>
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Enable Predictive Reorder</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">تنبؤ أوامر الشراء لتقليل التكلفة عبر الشراء بالجملة.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_predictive_reorder" <?php echo $enable_predictive_reorder ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Lookahead Days</label>
                                    <input type="number" name="reorder_lookahead_days" class="form-control" value="<?php echo htmlspecialchars($reorder_lookahead_days); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Min Bulk Discount %</label>
                                    <input type="number" step="0.1" name="min_bulk_discount_pct" class="form-control" value="<?php echo htmlspecialchars($min_bulk_discount_pct); ?>">
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Shrinkage Alert Threshold (%)</label>
                                    <input type="number" step="0.1" name="shrinkage_alert_threshold_pct" class="form-control" value="<?php echo htmlspecialchars($shrinkage_alert_threshold_pct); ?>">
                                    <small class="text-muted">تنبيه عند انحراف المخزون أعلى من النسبة.</small>
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Enable Subscriptions</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">تمكين خطط اشتراك للعملاء لزيادة الإيرادات المتكررة.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_subscriptions" <?php echo $enable_subscriptions ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>Subscription Discount %</label>
                                    <input type="number" step="0.1" name="subscription_discount_pct" class="form-control" value="<?php echo htmlspecialchars($subscription_discount_pct); ?>">
                                </div>

                                <div class="col-12"><hr></div>

                                <div class="col-md-6 mb-3">
                                    <label>Enable Price AI (Elasticity)</label>
                                    <div class="custom-switch-wrap">
                                        <span class="small text-muted">توصيات تسعير مبنية على استجابة الطلب للتغيّر في السعر.</span>
                                        <label class="switch">
                                            <input type="checkbox" name="enable_price_ai" <?php echo $enable_price_ai ? 'checked' : ''; ?>>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>AI Training Window (days)</label>
                                    <input type="number" name="ai_training_window_days" class="form-control" value="<?php echo htmlspecialchars($ai_training_window_days); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label>AI Confidence (0-1)</label>
                                    <input type="number" step="0.01" min="0" max="1" name="ai_confidence_threshold" class="form-control" value="<?php echo htmlspecialchars($ai_confidence_threshold); ?>">
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card profit-card shadow p-4 h-100 anime-card">
                            <div class="card-icon bg-gradient-info shadow-info">
                                <i class="ni ni-wallet"></i>
                            </div>
                            <h4 class="mb-3"><?php echo __('shift_commission_settings'); ?></h4>
                            <p class="text-muted mb-4"><?php echo __('shift_commission_help'); ?></p>

                            <div class="custom-switch-wrap mb-3">
                                <label class="form-control-label mb-0" for="enable_commission"><?php echo __('commission_toggle'); ?></label>
                                <label class="switch">
                                    <input type="checkbox" id="enable_commission" name="shift_commission_enabled" <?php echo $commission_enabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>

                            <div class="form-group">
                                <label class="form-control-label"><?php echo __('commission_percentage'); ?></label>
                                <div class="input-group">
                                    <input type="number" step="0.1" min="0" max="100" name="commission_pct" class="form-control" value="<?php echo htmlspecialchars($commission_pct); ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="form-text text-light mt-2"><?php echo __('commission_description'); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card profit-card shadow p-4 h-100 anime-card">
                            <div class="card-icon bg-gradient-purple shadow-purple">
                                <i class="ni ni-chart-bar-32"></i>
                            </div>
                            <h4 class="mb-3">Purchase Price Recovery</h4>
                            <p class="text-muted mb-4">Automatically populate missing purchase costs from each item's sell price.</p>

                            <div class="form-group">
                                <label class="form-control-label">Profit Rate</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0.01" name="profit_rate" class="form-control" value="<?php echo htmlspecialchars($profit_rate); ?>" placeholder="e.g. 1.20">
                                    <div class="input-group-append">
                                        <span class="input-group-text">Sell ÷ Rate</span>
                                    </div>
                                </div>
                                <small class="form-text text-light mt-2">Use the rate to calculate purchase price for items missing cost.</small>
                            </div>

                            <div class="action-copy">
                                <button type="submit" name="show_missing_items" class="btn btn-outline-info btn-block">Show missing items</button>
                                <button type="submit" name="fill_purchase_prices" class="btn btn-info btn-block">Apply profit rate</button>
                            </div>

                            <?php if (!empty($missing_products)): ?>
                                <div class="missing-list">
                                    <?php foreach ($missing_products as $product): ?>
                                        <div class="item-row">
                                            <span class="item-title"><?php echo htmlspecialchars($product['prod_name']); ?></span>
                                            <span>Sell: <?php echo number_format($product['prod_price'], 2); ?></span>
                                            <span class="text-right">Cost ≈ <?php echo number_format(floatval($product['prod_price']) / max(1, $profit_rate), 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (isset($_POST['show_missing_items'])): ?>
                                <div class="alert alert-info mt-4">No products were found without purchase cost.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card profit-card shadow p-4 h-100 anime-card">
                            <div class="card-icon bg-gradient-danger shadow-danger">
                                <i class="ni ni-money-coins"></i>
                            </div>
                            <h4 class="mb-3">Sell Price Generator</h4>
                            <p class="text-muted mb-4">Calculate selling prices from purchase cost × profit margin.</p>

                            <div class="form-group mb-3">
                                <label class="form-control-label mb-3">Profit Multiplier</label>
                                <div class="volume-control">
                                    <button type="button" class="btn-volume" onclick="decrementVolume(this)">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <input type="number" step="0.05" min="0.01" name="sell_price_profit_rate" class="volume-input" value="<?php echo htmlspecialchars($sell_price_profit_rate); ?>" placeholder="1.20">
                                    <button type="button" class="btn-volume" onclick="incrementVolume(this)">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                <small class="form-text text-light mt-2 d-block">Cost × Rate = Sell Price</small>
                            </div>

                            <div class="action-copy">
                                <button type="submit" name="show_missing_sell_price" class="btn btn-outline-danger btn-block">Show missing sell price</button>
                                <button type="submit" name="fill_sell_prices" class="btn btn-danger btn-block">Generate sell prices</button>
                            </div>

                            <?php if (!empty($missing_sell_price_products)): ?>
                                <div class="missing-list">
                                    <?php foreach ($missing_sell_price_products as $product): ?>
                                        <div class="item-row">
                                            <span class="item-title"><?php echo htmlspecialchars($product['prod_name']); ?></span>
                                            <span>Cost: <?php echo number_format($product['last_purchase_price'], 2); ?></span>
                                            <span class="text-right">Sell ≈ <?php echo number_format(floatval($product['last_purchase_price']) * max(1, $sell_price_profit_rate), 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (isset($_POST['show_missing_sell_price'])): ?>
                                <div class="alert alert-info mt-4">No products were found without sell price.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card profit-card shadow p-4 h-100 anime-card">
                            <div class="card-icon bg-gradient-warning shadow-warning">
                                <i class="ni ni-bulb-61"></i>
                            </div>
                            <h4 class="mb-3"><?php echo __('full_profit_forecast'); ?></h4>
                            <p class="text-muted mb-4"><?php echo __('estimate_net_profit'); ?></p>

                            <div class="form-row">
                                <div class="form-group col-12 mb-3">
                                    <label class="form-control-label"><?php echo __('salary_expense'); ?></label>
                                    <input type="number" step="0.01" min="0" name="salary_expense" class="form-control" value="<?php echo htmlspecialchars($salary_expense); ?>" placeholder="0.00">
                                </div>
                                <div class="form-group col-12 mb-3">
                                    <label class="form-control-label"><?php echo __('other_expenses'); ?></label>
                                    <input type="number" step="0.01" min="0" name="other_expenses" class="form-control" value="<?php echo htmlspecialchars($other_expenses); ?>" placeholder="0.00">
                                </div>
                                <div class="form-group col-12 mb-3">
                                    <label class="form-control-label"><?php echo __('capital_share_pct'); ?></label>
                                    <input type="number" step="0.1" min="0" max="100" name="capital_share_pct" class="form-control" value="<?php echo htmlspecialchars($capital_share_pct); ?>" placeholder="e.g. 15">
                                </div>
                            </div>

                            <div class="action-copy">
                                <button type="submit" name="calculate_full_profit" class="btn btn-outline-warning btn-block"><?php echo __('calculate_expected_profit'); ?></button>
                            </div>

                            <?php if (!empty($full_profit_summary)): ?>
                                <div class="summary-grid">
                                    <div class="summary-label"><?php echo __('total_purchase_capital'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['total_cost'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('total_revenue'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['total_revenue'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('gross_profit'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['gross_profit'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('capital_share_cost'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['capital_share'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('salary_plus_expenses'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['salary_expense'] + $full_profit_summary['other_expenses'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('expected_net_profit'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['expected_profit'], 2); ?></div>
                                    <div class="summary-label"><?php echo __('expected_margin'); ?></div>
                                    <div class="summary-value"><?php echo number_format($full_profit_summary['profit_margin'], 2); ?>%</div>
                                </div>
                                <div class="profit-badges">
                                    <div class="profit-badge">
                                        <i class="fas fa-coins"></i> <?php echo __('product_capital_share'); ?>: <?php echo number_format($full_profit_summary['capital_share'], 2); ?>
                                    </div>
                                    <div class="profit-badge">
                                        <i class="fas fa-dollar-sign"></i> <?php echo __('operating_expense'); ?>: <?php echo number_format($full_profit_summary['salary_expense'] + $full_profit_summary['other_expenses'], 2); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
<!--
                        <div class="card setting-card shadow p-4 h-100">
                            <div class="card-icon bg-gradient-primary shadow-primary">
                                <i class="ni ni-atom"></i>
                            </div>
                            <h4 class="mb-4">ذكاء اصطناعي</h4>
                            <p class="text-muted mb-3">تدريب نماذج التنبؤ بالمخزون والمبيعات</p>
                            
                            <button type="submit" name="train_ai" class="btn btn-primary btn-block">
                                <i class="ni ni-button-play mr-2"></i>ابدأ تدريب الذكاء الاصطناعي
                            </button>
                            
                            <?php if (isset($ai_status) && is_array($ai_status)): ?>
                                <div class="alert alert-info mt-3" id="ai-training-status-card">
                                    <strong>حالة التدريب:</strong> <span id="ai-status-text"><?php echo htmlspecialchars($ai_status['status'] ?? 'غير معروفة'); ?></span><br>
                                    <strong>بدء عند:</strong> <span id="ai-status-started"><?php echo htmlspecialchars($ai_status['started_at'] ?? '---'); ?></span><br>
                                    <strong>مكتمل:</strong> <span id="ai-status-completed"><?php echo intval($ai_status['completed'] ?? 0); ?></span> من <span id="ai-status-total"><?php echo intval($ai_status['total_products'] ?? 0); ?></span><br>
                                    <strong>تشغيل حالياً:</strong> <span id="ai-status-running"><?php echo intval($ai_status['running'] ?? 0); ?></span> منتج
                                </div>
                            <?php endif; ?>

                            <?php if (isset($ai_success)): ?>
                                <div class="alert alert-success mt-3">
                                    <i class="ni ni-check-bold"></i> <?php echo $ai_success; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (isset($ai_error)): ?>
                                <div class="alert alert-danger mt-3">
                                    <i class="ni ni-fat-remove"></i> <?php echo $ai_error; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                            -->           
                </div>



                <div class="row mt-4">
                    <div class="col">
                        <div class="card shadow">
                            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                                <h3 class="mb-0"><?php echo __('commission_history'); ?></h3>
                            </div>
                            <div class="table-responsive p-3">
                                <table class="table align-items-center table-flush" id="commissionHistoryTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th><?php echo __('staff'); ?></th>
                                            <th><?php echo __('shift_time'); ?></th>
                                            <th><?php echo __('sales_amount'); ?></th>
                                            <th><?php echo __('commission_percentage'); ?></th>
                                            <th><?php echo __('due_amount'); ?></th>
                                            <th><?php echo __('status'); ?></th>
                                            <th><?php echo __('action'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($commission_history)): ?>
                                            <?php foreach ($commission_history as $commission): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($commission['staff_name'] ?: $commission['user_id']); ?></td>
                                                    <td>
                                                        <small>Open: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($commission['opened_at']))); ?></small><br>
                                                        <small>Close: <?php echo htmlspecialchars($commission['closed_at'] ? date('Y-m-d H:i', strtotime($commission['closed_at'])) : 'N/A'); ?></small>
                                                    </td>
                                                    <td><?php echo number_format($commission['sales_amount'], 2); ?></td>
                                                    <td><?php echo number_format($commission['commission_pct'], 2); ?>%</td>
                                                    <td><?php echo number_format($commission['commission_due'], 2); ?></td>
                                                    <td><?php echo $commission['status'] === 'paid' ? '<span class="badge badge-success">'.__('paid').'</span>' : '<span class="badge badge-warning">'.__('due').'</span>'; ?></td>
                                                    <td>
                                                        <?php if ($commission['status'] === 'due'): ?>
                                                            <button type="submit" name="mark_commission_paid" value="<?php echo intval($commission['commission_id']); ?>" class="btn btn-sm btn-success"><?php echo __('mark_as_paid'); ?></button>
                                                        <?php else: ?>
                                                            <small><?php echo htmlspecialchars($commission['paid_at'] ? date('Y-m-d H:i', strtotime($commission['paid_at'])) : ''); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center"><?php echo __('no_commission_records'); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-2 mb-5">
                    <div class="col text-right">
                        <button type="submit" name="save_settings" class="btn btn-lg btn-success btn-icon px-5 shadow">
                            <span class="btn-inner--icon"><i class="ni ni-check-bold"></i></span>
                            <span class="btn-inner--text">Save All Settings</span>
                        </button>
                    </div>
                </div>
                
            </form>
            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>
    <script>
        function incrementVolume(btn) {
            const input = btn.parentElement.querySelector('.volume-input');
            const currentValue = parseFloat(input.value) || 1;
            input.value = (currentValue + 0.05).toFixed(2);
        }

        function decrementVolume(btn) {
            const input = btn.parentElement.querySelector('.volume-input');
            const currentValue = parseFloat(input.value) || 1;
            const newValue = Math.max(0.01, currentValue - 0.05);
            input.value = newValue.toFixed(2);
        }

        function refreshAIStatus() {
            fetch('config/ai_training_status.php', { cache: 'no-store' })
                .then(response => response.json())
                .then(data => {
                    if (data && typeof data.status !== 'undefined') {
                        document.getElementById('ai-status-text').textContent = data.status;
                        document.getElementById('ai-status-started').textContent = data.started_at || '---';
                        document.getElementById('ai-status-completed').textContent = data.completed || 0;
                        document.getElementById('ai-status-total').textContent = data.total_products || 0;
                        document.getElementById('ai-status-running').textContent = data.running || 0;
                    }
                })
                .catch(() => {
                    // ignore polling errors
                });
        }

        function setPermissionInputsState(enabled) {
            document.querySelectorAll('#permissionPagesList input[type=checkbox]').forEach(function(input) {
                input.disabled = !enabled;
            });
        }

        function loadPagePermissions(adminId) {
            const messageBox = document.querySelector('.permission-load-message');
            if (!adminId) {
                setPermissionInputsState(false);
                document.querySelectorAll('#permissionPagesList input[type=checkbox]').forEach(function(input) {
                    input.checked = false;
                });
                if (messageBox) {
                    messageBox.textContent = 'اختر مسؤولاً لتحميل الصلاحيات.';
                }
                return;
            }

            fetch('get_page_permissions.php?admin_id=' + encodeURIComponent(adminId), { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        if (messageBox) {
                            messageBox.textContent = data.message || 'فشل تحميل صلاحيات المستخدم.';
                        }
                        setPermissionInputsState(false);
                        return;
                    }

                    const selectedPages = data.permissions || [];
                    document.querySelectorAll('#permissionPagesList input[type=checkbox]').forEach(function(input) {
                        input.checked = selectedPages.indexOf(input.value) !== -1;
                        input.disabled = false;
                    });
                    if (messageBox) {
                        messageBox.textContent = 'تم تحميل صلاحيات المستخدم.';
                    }
                })
                .catch(function() {
                    if (messageBox) {
                        messageBox.textContent = 'فشل الاتصال بخادم الصلاحيات.';
                    }
                    setPermissionInputsState(false);
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            var status = document.getElementById('ai-status-text');
            var status = document.getElementById('ai-status-text');
            if (status && status.textContent === 'running') {
                setInterval(refreshAIStatus, 10000);
            }

            if (typeof anime !== 'undefined') {
                document.querySelectorAll('.anime-card').forEach(function(card, index) {
                    anime({
                        targets: card,
                        translateY: [40, 0],
                        opacity: [0, 1],
                        duration: 850,
                        delay: 120 + (index * 90),
                        easing: 'easeOutExpo'
                    });
                });
            }

            var permissionAdminSelect = document.getElementById('permissionAdminSelect');
            if (permissionAdminSelect) {
                permissionAdminSelect.addEventListener('change', function() {
                    loadPagePermissions(this.value);
                });
                if (permissionAdminSelect.value) {
                    loadPagePermissions(permissionAdminSelect.value);
                }
            }
        });
    </script>
    <?php require_once('partials/_scripts.php'); ?>
</body>
</html>
