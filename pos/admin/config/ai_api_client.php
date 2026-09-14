<?php
// ai_api_client.php

class GeniusAIClient {
    private $apiUrl = "http://127.0.0.1:8000";

    // جلب التنبؤ العبقري للمبيعات
    public function getForecast($prod_id) {
        $url = $this->apiUrl . "/predict/" . urlencode($prod_id);
        if (function_exists('curl_version')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($res === false || $httpCode !== 200) {
                return [];
            }
        } else {
            $res = @file_get_contents($url);
            if ($res === false) {
                return [];
            }
        }

        $data = json_decode($res, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    // ميزة "ذكاء الموردين": أي مورد هو الأفضل لهذا المنتج؟
    public function suggestBestSupplier($prod_id, $mysqli) {
        // خوارزمية بسيطة تحلل سرعة الاستلام والسعر التاريخي للمورد
        $q = "SELECT supplier AS supp_name, AVG(DATEDIFF(NOW(), purchase_date)) AS delivery_speed, MIN(purchase_price) AS best_price 
              FROM rpos_purchases WHERE prod_id = '$prod_id' GROUP BY supplier ORDER BY best_price ASC, delivery_speed ASC LIMIT 1";
        $res = $mysqli->query($q);
        return $res ? $res->fetch_assoc() : null;
    }
}
