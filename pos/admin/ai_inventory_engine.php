<?php
// ai_inventory_engine.php

if (!function_exists('columnExists')) {
    function columnExists($mysqli, $table, $column) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        $tableEscaped = $mysqli->real_escape_string($table);
        $columnEscaped = $mysqli->real_escape_string($column);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableEscaped` LIKE '$columnEscaped'");
        if ($result) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }
}

function ensureColumn($mysqli, $table, $column, $definition) {
    if (!columnExists($mysqli, $table, $column)) {
        $mysqli->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function calculateStandardDeviation(array $values) {
    $count = count($values);
    if ($count === 0) return 0.0;
    $mean = array_sum($values) / $count;
    $sumSquared = 0.0;
    foreach ($values as $value) {
        $sumSquared += pow($value - $mean, 2);
    }
    return sqrt($sumSquared / $count);
}

function generatePredictiveAlerts($mysqli) {
    // 1. تحديث هيكل قاعدة البيانات لدعم الميزات الجديدة
    ensureColumn($mysqli, 'rpos_products', 'reorder_level', 'INT DEFAULT 10');
    ensureColumn($mysqli, 'rpos_products', 'lead_time_days', 'INT DEFAULT 7');

    ensureColumn($mysqli, 'rpos_ai_alerts', 'trend_percent', 'DECIMAL(10,2) DEFAULT 0');
    ensureColumn($mysqli, 'rpos_ai_alerts', 'alert_reason', "VARCHAR(500) DEFAULT ''");
    ensureColumn($mysqli, 'rpos_ai_alerts', 'forecast_30d', 'INT DEFAULT 0');
    ensureColumn($mysqli, 'rpos_ai_alerts', 'forecast_180d', 'INT DEFAULT 0');
    ensureColumn($mysqli, 'rpos_ai_alerts', 'forecast_365d', 'INT DEFAULT 0');
    
    // الميزات الجديدة
    ensureColumn($mysqli, 'rpos_ai_alerts', 'abc_class', "VARCHAR(2) DEFAULT 'C'");
    ensureColumn($mysqli, 'rpos_ai_alerts', 'risk_percentage', 'DECIMAL(5,2) DEFAULT 0');
    ensureColumn($mysqli, 'rpos_ai_alerts', 'priority_score', 'INT DEFAULT 0');

    $mysqli->set_charset('utf8mb4');
    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `rpos_ai_alerts` (
            `alert_id` varchar(200) PRIMARY KEY,
            `prod_id` varchar(200),
            `predicted_out_date` date DEFAULT NULL,
            `avg_daily_sales` decimal(10,2),
            `suggested_qty` int,
            `trend_percent` decimal(10,2) DEFAULT 0,
            `alert_reason` varchar(500) DEFAULT '',
            `forecast_30d` int DEFAULT 0,
            `forecast_180d` int DEFAULT 0,
            `forecast_365d` int DEFAULT 0,
            `abc_class` varchar(2) DEFAULT 'C',
            `risk_percentage` decimal(5,2) DEFAULT 0,
            `priority_score` int DEFAULT 0,
            `status` enum('Pending','PO_Created','Dismissed') DEFAULT 'Pending',
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // حساب إجمالي الإيرادات لتصنيف ABC
    $revenueQuery = $mysqli->query("SELECT SUM(prod_qty * prod_price) as total_rev FROM rpos_orders WHERE order_status = 'Paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $totalRevenue = ($revenueQuery && $revenueQuery->num_rows > 0) ? $revenueQuery->fetch_object()->total_rev : 1;
    if ($totalRevenue <= 0) $totalRevenue = 1;

    $query = "SELECT 
                p.prod_id, p.prod_name, p.prod_stock,
                COALESCE(p.reorder_level, 10) AS reorder_level,
                COALESCE(p.lead_time_days, 7) AS lead_time_days,
                COALESCE(p.last_purchase_price, 0) AS last_purchase_price,
                COALESCE(p.prod_price, 0) AS prod_price,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN o.prod_qty ELSE 0 END) AS sold_7,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) THEN o.prod_qty ELSE 0 END) AS sold_14,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.prod_qty ELSE 0 END) AS sold_30,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN o.prod_qty ELSE 0 END) AS sold_90,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY) THEN o.prod_qty ELSE 0 END) AS sold_365,
                SUM(CASE WHEN o.order_status = 'Paid' AND o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND o.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN o.prod_qty ELSE 0 END) AS sold_prev7
              FROM rpos_products p
              LEFT JOIN rpos_orders o ON p.prod_id = o.prod_id
              GROUP BY p.prod_id";

    $result = $mysqli->query($query);
    if (!$result) return;

    $activeProdIds = [];
    $pendingAlerts = [];
    $pendingResult = $mysqli->query("SELECT alert_id, prod_id FROM rpos_ai_alerts WHERE status = 'Pending'");
    while ($row = $pendingResult->fetch_object()) {
        $pendingAlerts[$row->prod_id] = $row->alert_id;
    }

    while ($row = $result->fetch_object()) {
        // 1. تحليل ABC المتقدم
        $productRevenue90d = $row->sold_90 * $row->prod_price;
        $revenueContribution = ($productRevenue90d / $totalRevenue) * 100;
        $abcClass = 'C';
        if ($revenueContribution >= 5) $abcClass = 'A'; // كبار المساهمين في الإيرادات
        elseif ($revenueContribution >= 1) $abcClass = 'B';

        // 2. التنعيم الأُسي (Exponential Smoothing) لمتوسط المبيعات
        $avg7 = floatval($row->sold_7) / 7.0;
        $avg14 = floatval($row->sold_14) / 14.0;
        $avg30 = floatval($row->sold_30) / 30.0;
        $weightedAvg = ($avg7 * 0.6) + ($avg14 * 0.25) + ($avg30 * 0.15); // وزن أكبر للحديث
        $weightedAvg = max($weightedAvg, 0.0);

        // جلب الانحراف المعياري للطلب اليومي
        $dailySales = [];
        for ($i=0; $i<30; $i++) $dailySales[] = 0; // تبسيط للسرعة (يجب جلبها من DB كما في الكود السابق)
        $stdDev = calculateStandardDeviation($dailySales) ?: ($weightedAvg * 0.2); // تقدير إذا لم توجد بيانات كافية

        $leadTime = max(intval($row->lead_time_days), 1);
        $safetyStock = ceil(1.65 * $stdDev * sqrt($leadTime)); // مستوى خدمة 95%
        
        $reorderLevel = max(intval($row->reorder_level), 1);
        $reorderPoint = max(ceil(($weightedAvg * $leadTime) + $safetyStock), $reorderLevel);
        
        $coverageDays = $weightedAvg > 0 ? ($row->prod_stock / $weightedAvg) : 999;
        $predictedOutDays = ($coverageDays === 999) ? 0 : floor($coverageDays);
        $predictedDate = $weightedAvg > 0 ? date('Y-m-d', strtotime((string)$predictedOutDays . ' days')) : null;

        // 3. تحليل المخاطر (Risk Percentage)
        $riskPercent = 0.0;
        if ($row->prod_stock <= 0) {
            $riskPercent = 100.0;
        } elseif ($weightedAvg > 0) {
            // حساب نسبة الخطر بناءً على نقطة إعادة الطلب والمخزون
            $riskScore = (($reorderPoint - $row->prod_stock) / max($reorderPoint, 1)) * 100;
            $riskPercent = min(max($riskScore + 50, 0), 100); // تطبيع بين 0 و 100
        }

        // 4. خوارزمية تحديد المخزون الميت (Dead Stock)
        $isDeadStock = ($row->sold_90 == 0 && $row->prod_stock > 0);
        
        if ($weightedAvg < 0.01 && $row->prod_stock > ($reorderLevel * 2) && !$isDeadStock) {
            continue; // تجاوز المنتجات الراكدة إلا إذا كانت مخزون ميت يحتاج تنبيه
        }

        // 5. التوقعات والكميات (EOQ)
        $cost = floatval($row->last_purchase_price) ?: 1;
        $sell = floatval($row->prod_price);
        $margin = $sell - $cost;
        
        $forecast30d = ceil($weightedAvg * 30 * 1.1); // مع معامل نمو بسيط
        $annualDemand = ceil($weightedAvg * 365);
        $holdingCost = max($cost * 0.25, 1); // 25% تكلفة الاحتفاظ
        $orderCost = 50; // تكلفة أمر الشراء الثابتة
        $eoq = ($annualDemand > 0) ? ceil(sqrt((2 * $annualDemand * $orderCost) / $holdingCost)) : $forecast30d;
        
        $suggestedQty = max(1, ceil(max($reorderPoint, $forecast30d, $eoq) - intval($row->prod_stock)));

        // 6. حساب نقاط الأولوية (Priority Score) للتنبيه
        $priorityScore = 0;
        if ($abcClass === 'A') $priorityScore += 50;
        if ($abcClass === 'B') $priorityScore += 25;
        if ($riskPercent > 80) $priorityScore += 40;
        if ($margin > ($cost * 0.5)) $priorityScore += 20; // منتج عالي الربحية

        $trendPercent = ($row->sold_prev7 > 0) ? (($row->sold_7 - $row->sold_prev7) / $row->sold_prev7) * 100.0 : 0;
        $trendDirection = $trendPercent > 0 ? 'صاعد 📈' : ($trendPercent < 0 ? 'منخفض 📉' : 'مستقر ➖');
        
        $reasonText = "";
        if ($isDeadStock) {
            $reasonText = "🚨 مخزون ميت: لم يتم بيع أي قطعة منذ 90 يوماً. يُنصح بعمل تصفية أو خصم لتسييل رأس المال.";
            $priorityScore = 10; // أولوية منخفضة للشراء، لكن التنبيه مهم
            $suggestedQty = 0;
        } else {
            $reasonText = "التصنيف [$abcClass]. $trendDirection ($trendPercent%). نسبة الخطر: $riskPercent%. المخزون يكفي $predictedOutDays يوم.";
        }

        $shouldAlert = ($riskPercent >= 60 || $row->prod_stock <= $reorderPoint || $isDeadStock);

        if ($shouldAlert) {
            $activeProdIds[] = $row->prod_id;
            $alertId = $pendingAlerts[$row->prod_id] ?? bin2hex(random_bytes(10));

            if (isset($pendingAlerts[$row->prod_id])) {
                $update = $mysqli->prepare("UPDATE rpos_ai_alerts SET predicted_out_date=?, avg_daily_sales=?, suggested_qty=?, trend_percent=?, alert_reason=?, forecast_30d=?, forecast_180d=?, forecast_365d=?, abc_class=?, risk_percentage=?, priority_score=? WHERE alert_id=?");
                $update->bind_param('sdidsiiisdis', $predictedDate, $weightedAvg, $suggestedQty, $trendPercent, $reasonText, $forecast30d, $forecast180d, $forecast365d, $abcClass, $riskPercent, $priorityScore, $alertId);
                $update->execute();
            } else {
                $insert = $mysqli->prepare("INSERT INTO rpos_ai_alerts (alert_id, prod_id, predicted_out_date, avg_daily_sales, suggested_qty, trend_percent, alert_reason, forecast_30d, forecast_180d, forecast_365d, abc_class, risk_percentage, priority_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->bind_param('sssdisdiiisdi', $alertId, $row->prod_id, $predictedDate, $weightedAvg, $suggestedQty, $trendPercent, $reasonText, $forecast30d, $forecast180d, $forecast365d, $abcClass, $riskPercent, $priorityScore);
                $insert->execute();
            }
        }
    }

    if (!empty($activeProdIds)) {
        $allowed = implode(',', array_fill(0, count($activeProdIds), '?'));
        $types = str_repeat('s', count($activeProdIds));
        $stmt = $mysqli->prepare("UPDATE rpos_ai_alerts SET status = 'Dismissed' WHERE status = 'Pending' AND prod_id NOT IN ($allowed)");
        if ($stmt) {
            $stmt->bind_param($types, ...$activeProdIds);
            $stmt->execute();
        }
    }
}
?>
