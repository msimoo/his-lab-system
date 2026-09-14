<?php

//---------Password Reset Token generator-------------------------------------------//
    $length = 30;
    $tk = substr(str_shuffle("QWERTYUIOPLKJHGFDSAZXCVBNM1234567890"),1,$length);
    
//------------Dummy Password Generator----------------------------------------------//
    $length = 10;
    $rc= substr(str_shuffle("QWERTYUIOPLKJHGFDSAZXCVBNM1234567890"),1,$length);

    // helpers for unique ids
    function generateUniqueId($mysqli, $table, $column, $bytes = 5) {
        $tries = 0;
        do {
            $value = bin2hex(random_bytes($bytes));
            $stmt = $mysqli->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            if (!$stmt) break;
            $stmt->bind_param('s', $value);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
            $tries++;
            if ($tries >= 50) {
                throw new Exception('Unable to generate unique ID for ' . $column);
            }
        } while ($exists);
        return $value;
    }

    function generateUniqueOrderCode($mysqli) {
        $tries = 0;
        do {
            $code = substr(str_shuffle("QWERTYUIOPLKJHGFDSAZXCVBNM"),0,4) . '-' . substr(str_shuffle("1234567890"),0,4);
            $stmt = $mysqli->prepare("SELECT 1 FROM rpos_orders WHERE order_code = ? LIMIT 1");
            if (!$stmt) break;
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
            $tries++;
            if ($tries >= 50) {
                throw new Exception('Unable to generate unique order code');
            }
        } while ($exists);
        return $code;
    }

    function generateUniqueProductCode($mysqli) {
        $tries = 0;
        do {
            $code = 'P' . str_pad((string)rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $stmt = $mysqli->prepare("SELECT 1 FROM rpos_products WHERE prod_code = ? LIMIT 1");
            if (!$stmt) break;
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
            $tries++;
            if ($tries >= 100) {
                throw new Exception('Unable to generate unique product code');
            }
        } while ($exists);
        return $code;
    }

    //----------System Generated Numbers------------------------------------------//
    $length = 4;
    $alpha= substr(str_shuffle("QWERTYUIOPLKJHGFDSAZXCVBNM"),1,$length);
    $ln = 4;
    $beta = substr(str_shuffle("1234567890"),1,$length);

    $checksum = bin2hex(random_bytes('12'));
    $operation_id = bin2hex(random_bytes('4'));
    $cus_id = bin2hex(random_bytes('6'));
    $prod_id = bin2hex(random_bytes('5'));
    $orderid = generateUniqueId($mysqli, 'rpos_orders', 'order_id', 5);
    $payid = generateUniqueId($mysqli, 'rpos_payments', 'pay_id', 3);
    $order_code = generateUniqueOrderCode($mysqli);

    $length = 10;
    $mpesaCode = substr(str_shuffle("Q1W2E3R4T5Y6U7I8O9PLKJHGFDSAZXCVBNM"),1,$length);
    
?>
