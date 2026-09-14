-- إضافة حقل حد إعادة الطلب في جدول المنتجات
ALTER TABLE `rpos_products` ADD COLUMN `reorder_level` INT DEFAULT 10;

-- جدول تخزين التنبيهات الذكية التي تم إنشاؤها
CREATE TABLE IF NOT EXISTS `rpos_ai_alerts` (
  `alert_id` varchar(200) PRIMARY KEY,
  `prod_id` varchar(200),
  `predicted_out_date` date,
  `avg_daily_sales` decimal(10,2),
  `suggested_qty` int,
  `status` enum('Pending', 'PO_Created', 'Dismissed') DEFAULT 'Pending',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);


<?php
// ai_inventory_engine.php

function generatePredictiveAlerts($mysqli) {
    // 1. جلب المنتجات التي تم بيعها في آخر 7 أيام لحساب معدل البيع
    $query = "SELECT 
                p.prod_id, 
                p.prod_name, 
                p.prod_stock, 
                p.reorder_level,
                p.prod_purchase_price,
                SUM(o.prod_qty) as total_sold_7_days
              FROM rpos_products p
              JOIN rpos_orders o ON p.prod_id = o.prod_id
              WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              GROUP BY p.prod_id";
              
    $result = $mysqli->query($query);
    $alerts = [];

    while ($row = $result->fetch_object()) {
        $avg_daily_sales = $row->total_sold_7_days / 7; // متوسط البيع اليومي
        
        if ($avg_daily_sales > 0) {
            $days_until_empty = floor($row->prod_stock / $avg_daily_sales);
            
            // إذا كان المخزون سينفد خلال أقل من 10 أيام أو وصل لحد إعادة الطلب
            if ($days_until_empty <= 10 || $row->prod_stock <= $row->reorder_level) {
                
                // خوارزمية اقتراح الكمية: (البيع اليومي * 30 يوم) - المخزون الحالي
                $suggested_qty = ceil(($avg_daily_sales * 30) - $row->prod_stock);
                if ($suggested_qty < 0) $suggested_qty = 50; // حد أدنى افتراضي

                $predicted_date = date('Y-m-d', strtotime("+$days_until_empty days"));
                
                // حفظ التنبيه في قاعدة البيانات إذا لم يكن موجوداً مسبقاً لهذا اليوم
                $alert_id = bin2hex(random_bytes(10));
                $check = $mysqli->query("SELECT * FROM rpos_ai_alerts WHERE prod_id = '$row->prod_id' AND status = 'Pending'");
                
                if ($check->num_rows == 0) {
                    $stmt = $mysqli->prepare("INSERT INTO rpos_ai_alerts (alert_id, prod_id, predicted_out_date, avg_daily_sales, suggested_qty) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssdi', $alert_id, $row->prod_id, $predicted_date, $avg_daily_sales, $suggested_qty);
                    $stmt->execute();
                }
            }
        }
    }
}
?>
