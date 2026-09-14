<?php
// إعدادات المخزن وحفظ السجلات (Stock Adjustments & Logs)

function adjust_stock($mysqli, $prod_id, $delta, $type, $ref_id = null, $note = null, $user_id = null) {
    // 1. تحويل الدلتا إلى رقم صحيح لضمان سلامة العمليات الحسابية
    $delta = intval($delta);
    if ($delta === 0) return; 

    // 2. تحديث كمية المخزن فوراً في قاعدة البيانات
    $stmt = $mysqli->prepare("UPDATE rpos_products SET prod_stock = prod_stock + ? WHERE prod_id = ?");
    if ($stmt) {
        $stmt->bind_param('is', $delta, $prod_id);
        $stmt->execute();
        $stmt->close();
    }

    // 3. فحص التكرار الذكي: الاعتماد على كود الفاتورة (ref_id) لمنع تداخل المنتجات المتشابهة
    $is_duplicate = false;
    if (!empty($ref_id)) {
        $stmt = $mysqli->prepare("SELECT 1 FROM rpos_stock_log 
            WHERE prod_id = ? 
              AND ref_id = ? 
              AND type = ? 
              AND change_qty = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND) 
            LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('sssi', $prod_id, $ref_id, $type, $delta);
            $stmt->execute();
            $stmt->store_result();
            $is_duplicate = $stmt->num_rows > 0;
            $stmt->close();
        }
    }

    // 4. التراجع فقط إذا كان التكرار لنفس المنتج في نفس الفاتورة تماماً
    if ($is_duplicate) {
        $stmt = $mysqli->prepare("UPDATE rpos_products SET prod_stock = prod_stock - ? WHERE prod_id = ?");
        if ($stmt) {
            $stmt->bind_param('is', $delta, $prod_id);
            $stmt->execute();
            $stmt->close();
        }
        return; 
    }

    // 5. حفظ سجل الحركة بشكل سليم شامل رمز الفاتورة لتسهيل الجرد
    $log_id = bin2hex(random_bytes(10));
    $stmt = $mysqli->prepare("INSERT INTO rpos_stock_log (log_id, prod_id, change_qty, type, ref_id, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('ssissss', $log_id, $prod_id, $delta, $type, $ref_id, $note, $user_id);
        $stmt->execute();
        $stmt->close();
    }
}
?>