<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

$message = '';
if (isset($_POST['seed_medicine'])) {
    $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
    $mysqli->query("DELETE FROM rpos_products");
    $mysqli->query("ALTER TABLE rpos_products AUTO_INCREMENT = 1");
    $mysqli->query("SET FOREIGN_KEY_CHECKS=1");

    $medicineCategories = [
        1 => 'Pain Relief',
        2 => 'Antibiotics',
        3 => 'Antihistamines',
        4 => 'Cardiovascular',
        5 => 'Respiratory'
    ];

    $categoryIds = [];
    $catStmt = $mysqli->prepare("SELECT category_id FROM categories WHERE category_name = ? LIMIT 1");
    $insStmt = $mysqli->prepare("INSERT INTO categories (category_name) VALUES(?)");
    foreach ($medicineCategories as $key => $name) {
        $catStmt->bind_param('s', $name);
        $catStmt->execute();
        $catStmt->bind_result($catId);
        if ($catStmt->fetch()) {
            $categoryIds[$key] = $catId;
        } else {
            $catStmt->free_result();
            $insStmt->bind_param('s', $name);
            $insStmt->execute();
            $categoryIds[$key] = $insStmt->insert_id;
        }
        $catStmt->free_result();
    }
    $catStmt->close();
    $insStmt->close();

    $medicineItems = [
        ['m001','MED-0001','Paracetamol 500mg Tablet','PARA-500',250,'BATCH-001','2027-12-31','500mg','tablet',1,1,'paracetamol.jpg','Pain reliever and fever reducer.',2.50,'Medicine',20,2.30,'2026-03-01 10:00:00'],
        ['m002','MED-0002','Ibuprofen 400mg Tablet','IBU-400',180,'BATCH-002','2027-06-30','400mg','tablet',1,1,'ibuprofen.jpg','Anti-inflammatory painkiller.',3.00,'Medicine',20,2.80,'2026-03-05 10:00:00'],
        ['m003','MED-0003','Amoxicillin 500mg Capsule','AMOX-500',300,'BATCH-003','2026-12-31','500mg','capsule',1,2,'amoxicillin.jpg','Broad spectrum antibiotic.',5.20,'Medicine',15,4.90,'2026-03-10 10:00:00'],
        ['m004','MED-0004','Metformin 850mg Tablet','MET-850',120,'BATCH-004','2028-03-31','850mg','tablet',1,2,'metformin.jpg','Type 2 diabetes blood sugar regulator.',3.80,'Medicine',10,3.60,'2026-02-25 10:00:00'],
        ['m005','MED-0005','Loratadine 10mg Tablet','LORA-10',210,'BATCH-005','2027-09-30','10mg','tablet',1,3,'loratadine.jpg','Non-drowsy antihistamine for allergies.',1.90,'Medicine',20,1.70,'2026-03-12 10:00:00'],
        ['m006','MED-0006','Omeprazole 20mg Capsule','OMEP-20',160,'BATCH-006','2027-11-30','20mg','capsule',1,3,'omeprazole.jpg','Gastric acid reducer for reflux.',4.10,'Medicine',15,3.90,'2026-03-07 10:00:00'],
        ['m007','MED-0007','Cetirizine 10mg Tablet','CETI-10',190,'BATCH-007','2028-01-31','10mg','tablet',1,3,'cetirizine.jpg','Allergy relief antihistamine.',2.20,'Medicine',20,2.00,'2026-03-02 10:00:00'],
        ['m008','MED-0008','Azithromycin 250mg Tablet','AZI-250',140,'BATCH-008','2027-05-31','250mg','tablet',1,2,'azithromycin.jpg','Antibiotic for respiratory infections.',8.50,'Medicine',10,8.20,'2026-03-11 10:00:00'],
        ['m009','MED-0009','Atorvastatin 10mg Tablet','ATOR-10',100,'BATCH-009','2028-04-30','10mg','tablet',1,4,'atorvastatin.jpg','Cholesterol-lowering statin.',6.70,'Medicine',8,6.50,'2026-02-28 10:00:00'],
        ['m010','MED-0010','Salbutamol 100mcg Inhaler','SALB-100',90,'BATCH-010','2029-03-31','100mcg','inhaler',1,5,'salbutamol.jpg','Bronchodilator for asthma relief.',22.00,'Medicine',5,21.50,'2026-03-08 10:00:00'],
    ];
    $stmt = $mysqli->prepare("INSERT INTO rpos_products (prod_id, prod_code, prod_name, prod_sku, prod_stock, prod_batch, prod_expiry, prod_variant, prod_unit, prod_sellable, category_id, prod_img, prod_desc, prod_price, created_at, prod_category, reorder_level, last_purchase_price, last_purchase_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($medicineItems as $item) {
        $createdAt = date('Y-m-d H:i:s');
        $categoryId = isset($categoryIds[$item[10]]) ? $categoryIds[$item[10]] : $item[10];
        $stmt->bind_param('ssssissssiiissdisss', $item[0], $item[1], $item[2], $item[3], $item[4], $item[5], $item[6], $item[7], $item[8], $item[9], $categoryId, $item[11], $item[12], $item[13], $createdAt, $item[14], $item[15], $item[16], $item[17]);
        $stmt->execute();
    }
    $stmt->close();
    $message = __('success') . ' - medicine products seeded.';
}

require_once('partials/_head.php');
?>
<body>
<?php require_once('partials/_sidebar.php'); ?>
<div class="main-content">
<?php require_once('partials/_topnav.php'); ?>
<div class="container-fluid mt--8">
 <div class="row"><div class="col"><div class="card shadow mb-3"><div class="card-header"><h3>Seed Medicine Products</h3></div><div class="card-body">
 <?php if($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
 <p>This tool clears all products and inserts a fixed medical inventory dataset.</p>
 <form method="post"><button type="submit" name="seed_medicine" class="btn btn-primary">Run Seed</button></form>
 </div></div></div></div>
<?php require_once('partials/_footer.php'); ?>
</div></div>
<?php require_once('partials/_scripts.php'); ?>
</body>
</html>
