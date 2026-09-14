<div class="col-12 mb-2">
                    <?php if (isset($completed_order_code)) { ?>
                        <div class="alert alert-success d-flex justify-content-between align-items-center">
                            <div><?php echo __('order_submitted'); ?>.</div>
                            <div>
                                <a href="print_receipt.php?order_code=<?php echo htmlspecialchars($completed_order_code); ?>" target="_blank" class="btn btn-sm btn-primary mr-2"><?php echo __('print_receipt'); ?></a>
                                <button onclick="window.open('print_receipt.php?order_code=<?php echo htmlspecialchars($completed_order_code); ?>','Receipt').print()" type="button" class="btn btn-sm btn-success"><?php echo __('print_now'); ?></button>
                            </div>
                        </div>
                    <?php } ?>
</div>
                <div class="col-lg-8">
                    <div class="card shadow">

 
       
                        <!--<div class="card-header border-0">
                            Select Products
                        </div>-->
                        <div class="card-body">
                            <div class="row mb-1 align-items-center" style="margin-bottom: -8px !important;">
                                   <?php if ($enable_barcode) { ?>
        <div class="col-xl-9 mb-0">
            <div class="card setting-card shadow">
                <div class="form-group mb-3  text-center">
                <form id="scanForm" method="post" onsubmit="return false;">
                    <div class="input-group" style="border-radius: 20px;overflow: hidden;margin-top: 3px;margin-left: auto;margin-right: auto;width: 80%;">
                        <div class="input-group-prepend">
                            <span class="input-group-text border-right-0" style="background-color: #000;"><i class="fas fa-barcode text-white"></i></span>
                        </div>
                        <input type="text" id="barcodeInput" name="scan_code" class="form-control" autocomplete="off" placeholder="<?php echo __('barcode_placeholder'); ?>" style="border-color:#997408; border-radius: 0 20px 20px 0; color: black; font-size: 14px;">
                    </div>
                </form>
                  <small class="text-muted">قم بمسح الباركود ليتم إضافة المنتج إلى السلة تلقائيًا.</small>
                </div>
            </div> 
        </div>
          <?php } ?>
                            </div>
                            <div class="row">
                                <?php
                                $query = "SELECT p.*, c.category_name
                                  FROM rpos_products p
                                  LEFT JOIN categories c ON p.category_id = c.category_id";
                                $filters = ["p.prod_sellable = 1"];
                                $params = [];
                                $types = '';
                                if ($selected_cat > 0) {
                                    $filters[] = "p.category_id = ?";
                                    $types .= 'i';
                                    $params[] = $selected_cat;
                                }
                                if ($search_term !== '') {
                                    $like = "%$search_term%";
                                    $filters[] = "(p.prod_name LIKE ? OR p.prod_code LIKE ? OR p.prod_sku LIKE ? OR p.prod_variant LIKE ? )";
                                    $types .= 'ssss';
                                    $params[] = $like;
                                    $params[] = $like;
                                    $params[] = $like;
                                    $params[] = $like;
                                }
                                if (!empty($filters)) {
                                    $query .= " WHERE " . implode(' AND ', $filters);
                                }
                                $query .= " ORDER BY p.created_at DESC";
                                $stmt = $mysqli->prepare($query);
                                if ($stmt) {
                                    if ($types) {
                                        $stmt->bind_param($types, ...$params);
                                    }
                                }
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($prod = $res->fetch_object()) {
                                    // filter out expired items unless explicitly sellable
                                    $today = date('Y-m-d');
                                    if (!$prod->prod_sellable && !empty($prod->prod_expiry) && $prod->prod_expiry < $today) {
                                        continue;
                                    }
                                ?>
                                <div class="col-sm-6 col-md-4 col-lg-3 mb-3">
                                    <div class="card h-100 border-0 shadow-sm product-card shadow" draggable="true" data-prod-id="<?php echo $prod->prod_id; ?>" data-prod-stock="<?php echo intval($prod->prod_stock); ?>">
                                        <div class="card-body text-center position-relative d-flex flex-column justify-content-between">
                                            <?php
                                            if ($prod->prod_img) {
                                                echo "<img src='../admin/assets/img/products/$prod->prod_img' class='img-fluid mx-auto mb-3' style='max-height:60px; border-radius: 50%;'>";
                                            } else {
                                                echo "<img src='../admin/assets/img/products/default.jpg' class='img-fluid mx-auto mb-3' style='max-height:60px; border-radius: 50%;'>";
                                            }
                                            ?>
                                            <div>
                                                <h5 class="card-title mb-2"><?php echo $prod->prod_name; ?></h5>
                                                <div class="product-badges justify-content-center mb-2">
                                                    <?php if (!empty($prod->prod_expiry) && $prod->prod_expiry < date('Y-m-d')) { ?>
                                                        <span class="badge badge-warning"><?php echo __('expired'); ?></span>
                                                    <?php } elseif ($prod->prod_stock <= 5 && $prod->prod_stock > 0) { ?>
                                                        <span class="badge badge-danger"><?php echo __('low_stock'); ?></span>
                                                    <?php } ?>

                                                    <?php if ($prod->prod_stock <= 0) { ?>
                                                        <span class="badge badge-danger"><?php echo __('out_of_stock') ?: 'انتهى من المخزن'; ?></span>
                                                    <?php } ?>

                                                    <?php if (!empty($prod->created_at) && strtotime($prod->created_at) >= strtotime('-7 days')) { ?>
                                                        <span class="badge badge-success"><?php echo __('new'); ?></span>
                                                    <?php } ?>

                                                    <?php if (!empty($prod->is_featured) && $prod->is_featured == 1) { ?>
                                                        <span class="badge badge-info"><?php echo __('best_seller'); ?></span>
                                                    <?php } ?> 
                                                    <span class="stock-info small text-white-50"><?php echo __('stock'); ?>: <span class="stock-value"><?php echo intval($prod->prod_stock); ?></span></span>
                                                </div>
                                            </div> 
                                            <div class="mt-auto">
                                                <span class="price-badge"><?php echo number_format($prod->prod_price,2); ?> SDG</span>
                                                <div class="product-overlay"></div>
                                                <form method="post" class="add-product-form mt-4">
                                                    <input type="hidden" name="prod_id" value="<?php echo $prod->prod_id; ?>">
<button type="button" class="btn btn-sm btn-info add-product-btn w-100" data-prod-id="<?php echo $prod->prod_id; ?>">
    <i class="fas fa-cart-plus"></i> إضافة للسلة
</button>

                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="cartWrapper" class="col-lg-4">
                    <div class="card shadow mb-4 cart-fixed-panel">
                        <div class="card-header border-0 d-flex justify-content-between align-items-center">
                            <?php echo __('cart'); ?>
                            <?php
                            // total quantity rather than number of distinct items
                            $badge_count = 0;
                            if (!empty($_SESSION['cart'])) {
                                foreach ($_SESSION['cart'] as $ci) {
                                    $badge_count += intval($ci['qty']);
                                }
                            }
                            ?>
                            <span id="cartBadge" class="badge badge-pill badge-primary"><?php echo $badge_count; ?></span>
                        </div>
                        <div class="ppy-2">
                            <form method="post">
                                <div id="cartArea" class="table-responsive">
                                <table class="table table-sm" style="table-layout:fixed; width:100%;">
                                    <thead>
                                        <tr width="100%">
                                            <th style="width:50%"><?php echo __('item'); ?></th>
                                            <th style="width:10%"><?php echo __('qty'); ?></th>
                                           <!-- <th style="width:8%">Price</th>-->
                                            <th style="width:40%"><?php echo __('subtotal'); ?></th>
                                            <!--<th style="width:4%"></th>-->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $grand = 0;
                                        $cartItems = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
                                        foreach ($cartItems as $id => $item) {
                                            $line = $item['price'] * $item['qty'];
                                            $grand += $line;
                                        ?>
                                            <tr>
                                                <td><a href="#" data-id="<?php echo $id; ?>" class="text-danger cart-remove">&times;</a><br><?php echo $item['name']; ?> <br> <?php echo $item['price']; ?> SDG</td>
                                                <td>
                                                    <div class="input-group input-group-sm qty-controls" data-id="<?php echo $id; ?>">
                                                        <div class="input-group-prepend">
                                                            <button class="btn btn-outline-secondary qty-decrease" type="button">-</button>
                                                        </div>
                                                        <input type="text" readonly class="form-control text-center cart-qty" name="qty[<?php echo $id; ?>]" value="<?php echo $item['qty']; ?>" style="width:20px;">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-outline-secondary qty-increase" type="button">+</button>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo $line; ?> SDG</td>
                                                <!--<td></td>-->
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                    <tfoot>
                                        <?php
                                        // compute tax/discount
                                        $tax_amt = $show_tax ? $grand * $tax_rate/100 : 0;
                                        $disc_amt = $show_discount ? $grand * $discount_rate/100 : 0;
                                        $total_after = $grand + $tax_amt - $disc_amt;
                                        ?>
                                        
                                        <?php if($show_tax){ ?>
                                        <tr>
                                            <td colspan="3"><strong><?php echo __('tax'); ?> (<?php echo $tax_rate; ?>%)</strong></td>
                                            <td colspan="2"><?php echo number_format($tax_amt,2); ?> SDG</td>
                                        </tr>
                                        <?php } ?>
                                        <?php if($show_discount){ ?>
                                        <tr>
                                            <td colspan="3"><strong><?php echo __('discount'); ?> (<?php echo $discount_rate; ?>%)</strong></td>
                                            <td colspan="2"><?php echo number_format($disc_amt,2); ?> -SDG</td>
                                        </tr>
                                        <?php } ?>
                                        
                                    </tfoot>
                                </table>
                                <div class="cart-summary">
                                   
                                    <div class="d-flex justify-content-between pt-3 border-top" style="border-color: rgba(255,255,255,0.08);">
                                        <span class="font-weight-bold"><?php echo __('total'); ?></span>
                                        <strong id="cartTotal"><?php echo number_format($total_after,2); ?> SDG</strong>
                                    </div>
                                    <?php if($show_tax){ ?>
                                    <div class="d-flex justify-content-between mb-2 text-success">
                                        <span><?php echo __('tax'); ?> (<?php echo $tax_rate; ?>%)</span>
                                        <strong><?php echo number_format($tax_amt,2); ?> SDG</strong>
                                    </div>
                                    <?php } ?>
                                    <?php if($show_discount){ ?>
                                    <div class="d-flex justify-content-between mb-2 text-warning">
                                        <span><?php echo __('discount'); ?> (<?php echo $discount_rate; ?>%)</span>
                                        <strong>-<?php echo number_format($disc_amt,2); ?> SDG</strong>
                                    </div>
                                    <?php } ?>
                                </div>
                            </div>
                        <div class="card-footer bg-white">
                            <div class="d-flex justify-content-between">
                                <button hidden type="button" id="updateCartBtn" class="btn btn-sm btn-outline-primary"><?php echo __('update'); ?></button>
                                <button type="button" id="clearCartBtn" class="btn btn-sm btn-danger"><?php echo __('clear_cart'); ?></button>
                                <button type="button" id="checkoutBtn" name="checkout" class="btn btn-success btn-block ml-2"><?php echo __('checkout'); ?></button>
                            </div>
                        </div>
                                <!-- hidden inputs synced with navbar controls -->
                                <input type="hidden" name="order_id" value="<?php echo $orderid; ?>">
                                <input type="hidden" name="customer_name" id="hiddenCustomerName" value="<?php echo __('window_customer'); ?>">
                                <input type="hidden" name="customer_id" id="hiddenCustomerID" value="0">
                                <input type="hidden" name="store_id" id="hiddenStoreID" value="">
                                <input type="hidden" name="order_code" id="hiddenOrderCode" value="<?php echo htmlspecialchars($order_code); ?>">
                            </form>
                        </div>
                    </div>
                </div>