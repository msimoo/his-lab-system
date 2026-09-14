<?php
include __DIR__ . "/../../session_init.php";
include('config/config.php');
include('config/checklogin.php');
check_login();
include('config/languages.php');

// --- 1. FILTERING ---
// Default to the last 30 days for fresh insights
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$where_clause = "order_status = 'Paid' AND created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'";

// --- 2. ALGORITHMIC INTELLIGENCE QUERIES ---

// A. Product Time-of-Day Affinity (Morning, Afternoon, Evening)
$affinity_query = "
    SELECT 
        prod_name, 
        SUM(prod_qty) as total_qty,
        SUM(CASE WHEN HOUR(created_at) BETWEEN 5 AND 11 THEN prod_qty ELSE 0 END) as morning_qty,
        SUM(CASE WHEN HOUR(created_at) BETWEEN 12 AND 16 THEN prod_qty ELSE 0 END) as afternoon_qty,
        SUM(CASE WHEN HOUR(created_at) BETWEEN 17 AND 23 THEN prod_qty ELSE 0 END) as evening_qty,
        SUM(CASE WHEN HOUR(created_at) BETWEEN 0 AND 4 THEN prod_qty ELSE 0 END) as night_qty
    FROM rpos_orders 
    WHERE $where_clause
    GROUP BY prod_id, prod_name
    ORDER BY total_qty DESC
";
$affinity_res = $mysqli->query($affinity_query);
$products = [];
$morning_winner = ['name' => 'N/A', 'qty' => 0];
$afternoon_winner = ['name' => 'N/A', 'qty' => 0];
$evening_winner = ['name' => 'N/A', 'qty' => 0];

while ($row = $affinity_res->fetch_assoc()) {
    // Determine the "Peak Time" for this specific product
    $max_time = max($row['morning_qty'], $row['afternoon_qty'], $row['evening_qty'], $row['night_qty']);
    $peak_time = 'Variable';
    if ($max_time > 0) {
        if ($max_time == $row['morning_qty']) $peak_time = 'Morning';
        elseif ($max_time == $row['afternoon_qty']) $peak_time = 'Afternoon';
        elseif ($max_time == $row['evening_qty']) $peak_time = 'Evening';
        elseif ($max_time == $row['night_qty']) $peak_time = 'Late Night';
    }
    $row['peak_time'] = $peak_time;
    $products[] = $row;

    // Find overall winners per time slot
    if ($row['morning_qty'] > $morning_winner['qty']) $morning_winner = ['name' => $row['prod_name'], 'qty' => $row['morning_qty']];
    if ($row['afternoon_qty'] > $afternoon_winner['qty']) $afternoon_winner = ['name' => $row['prod_name'], 'qty' => $row['afternoon_qty']];
    if ($row['evening_qty'] > $evening_winner['qty']) $evening_winner = ['name' => $row['prod_name'], 'qty' => $row['evening_qty']];
}

// B. Hourly Peak Detection (For the Line Chart)
$hourly_query = "
    SELECT HOUR(created_at) as hour_of_day, COUNT(order_id) as total_orders, SUM(prod_qty) as volume 
    FROM rpos_orders 
    WHERE $where_clause 
    GROUP BY HOUR(created_at) 
    ORDER BY hour_of_day ASC
";
$hourly_res = $mysqli->query($hourly_query);
$hourly_labels = [];
$hourly_data = [];
$peak_hour = ['hour' => 0, 'volume' => 0];

for ($i = 0; $i < 24; $i++) {
    $hourly_labels[] = sprintf("%02d:00", $i);
    $hourly_data[$i] = 0; // Initialize with 0
}

while ($row = $hourly_res->fetch_assoc()) {
    $h = (int)$row['hour_of_day'];
    $hourly_data[$h] = (int)$row['volume'];
    if ($row['volume'] > $peak_hour['volume']) {
        $peak_hour = ['hour' => $h, 'volume' => $row['volume']];
    }
}

// C. Day of Week Trends (For the Bar Chart)
$weekly_query = "
    SELECT DAYNAME(created_at) as day_name, DAYOFWEEK(created_at) as day_num, SUM(prod_qty) as volume 
    FROM rpos_orders 
    WHERE $where_clause 
    GROUP BY day_name, day_num 
    ORDER BY day_num ASC
";
$weekly_res = $mysqli->query($weekly_query);
$weekly_labels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$weekly_data = [0, 0, 0, 0, 0, 0, 0];
$busiest_day = ['name' => 'N/A', 'volume' => 0];

while ($row = $weekly_res->fetch_assoc()) {
    $idx = (int)$row['day_num'] - 1; // MySQL DAYOFWEEK: 1=Sunday, 7=Saturday
    $weekly_data[$idx] = (int)$row['volume'];
    if ($row['volume'] > $busiest_day['volume']) {
        $busiest_day = ['name' => $row['day_name'], 'volume' => $row['volume']];
    }
}

require_once('partials/_head.php');
?>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
.card { border: none; border-radius: 1rem; box-shadow: 0 18px 50px rgba(15,23,42,0.08); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.card:hover { transform: translateY(-4px); box-shadow: 0 22px 60px rgba(15,23,42,0.12); }
.card-header { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #ffffff; border: none; }
.card-body { background: #ffffff; }
.btn { border-radius: 0.9rem; transition: transform 0.25s ease, box-shadow 0.25s ease; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 10px 24px rgba(0,0,0,0.12); }
.form-control { border-radius: 0.85rem; border: 1px solid rgba(206,212,218,0.85); }
.form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.18); }
.table thead th { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: none; }
.table tbody tr:hover { background: #f2f6ff; }
.table-responsive { border-radius: 1rem; overflow: hidden; box-shadow: 0 12px 36px rgba(15,23,42,0.06); }
.alert { border-radius: 1rem; }
.badge { border-radius: 0.75rem; }
.header .text-white { text-shadow: 0 2px 15px rgba(0,0,0,0.2); }
.custom-scrollbar { max-height: 580px; overflow: auto; }
@media (max-width: 767px) { .form-inline .form-group { width: 100%; margin-bottom: 0.75rem; } }
</style>

<body>
    <?php require_once('partials/_sidebar.php'); ?>
    <div class="main-content">
        <?php require_once('partials/_topnav.php'); ?>
        
        <div style="background-image: url(assets/img/theme/restro00.jpg); background-size: cover;" class="header pb-8 pt-5 pt-md-8">
            <span class="mask bg-gradient-dark opacity-8"></span>
            <div class="container-fluid">
                <div class="header-body">
                    <div class="row align-items-center py-4">
                        <div class="col-lg-6 col-7">
                    <h1 class="text-white"><i class="fas fa-brain"></i> <?php echo __('Sales_Intelligence_Engine');?></h1>
                    <p class="text-white"><?php echo __('Sales_Intelligence_Engine_details');?></p>
                </div>
            </div>
                </div>
            </div>
        </div>

        <div class="container-fluid mt--8">
            
            <div class="row mb-4">
                <div class="col">
                    <div class="card shadow p-3">
                        <form method="GET" class="form-inline">
                            <label class="mr-2 font-weight-bold"><?php echo __('Analysis_Period');?>:</label>
                            <input type="date" name="date_from" class="form-control form-control-sm mr-2" value="<?php echo $date_from; ?>">
                            <label class="mr-2">to</label>
                            <input type="date" name="date_to" class="form-control form-control-sm mr-2" value="<?php echo $date_to; ?>">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-sync"></i><?php echo __('Generate_Insights');?> </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow border-left-warning">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Morning_Favorite');?> </h5>
                                    <span class="h4 font-weight-bold mb-0 text-warning"><?php echo $morning_winner['name']; ?></span>
                                    <div class="text-xs text-muted mt-1">(5 AM - 11 AM)</div>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-warning text-white rounded-circle shadow"><i class="fas fa-sun"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Evening_Favorite');?></h5>
                                    <span class="h4 font-weight-bold mb-0 text-info"><?php echo $evening_winner['name']; ?></span>
                                    <div class="text-xs text-muted mt-1">(5 PM - 11 PM)</div>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-info text-white rounded-circle shadow"><i class="fas fa-moon"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Peak_Rush_Hour');?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-danger"><?php echo sprintf("%02d:00", $peak_hour['hour']); ?></span>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-danger text-white rounded-circle shadow"><i class="fas fa-clock"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6">
                    <div class="card card-stats mb-4 mb-xl-0 shadow">
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <h5 class="card-title text-uppercase text-muted mb-0"><?php echo __('Busiest_Day');?></h5>
                                    <span class="h2 font-weight-bold mb-0 text-success"><?php echo $busiest_day['name']; ?></span>
                                </div>
                                <div class="col-auto"><div class="icon icon-shape bg-success text-white rounded-circle shadow"><i class="fas fa-calendar-week"></i></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-xl-8">
                    <div class="card shadow h-100">
                        <div class="card-header bg-transparent">
                            <h3 class="mb-0"><?php echo __('Hourly_SalesVolumeTrend');?></h3>
                        </div>
                        <div class="card-body">
                            <canvas id="hourlyChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card shadow h-100">
                        <div class="card-header bg-transparent">
                            <h3 class="mb-0"><?php echo __('Weekly_Distribution');?></h3>
                        </div>
                        <div class="card-body">
                            <canvas id="weeklyChart" height="220"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="card shadow">
                        <div class="card-header border-0">
                            <h3 class="mb-0"><?php echo __('Item_BehaviorTimeAffinityAnalytics');?></h3>
                        </div>
                        <div class="table-responsive p-4">
                            <table class="table align-items-center table-flush" id="insightsTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th><?php echo __('product');?></th>
                                        <th><?php echo __('Algorithm_Conclusion');?> (<?php echo __('Peak');?>)</th>
                                        <th><?php echo __('total_sales');?></th>
                                        <th><?php echo __('Morning');?> (5A-11AM)</th>
                                        <th><?php echo __('Afternoon');?> (12P-4PM)</th>
                                        <th><?php echo __('Evening');?> (5P-11PM)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $p): 
                                        // Badge logic for peak time
                                        $badge = 'badge-secondary';
                                        if ($p['peak_time'] == 'Morning') $badge = 'badge-warning';
                                        if ($p['peak_time'] == 'Afternoon') $badge = 'badge-success';
                                        if ($p['peak_time'] == 'Evening') $badge = 'badge-info';
                                        if ($p['peak_time'] == 'Late Night') $badge = 'badge-dark';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($p['prod_name']); ?></strong></td>
                                            <td><span class="badge badge-pill <?php echo $badge; ?>"><?php echo $p['peak_time']; ?> Heavy</span></td>
                                            <td><h3><?php echo $p['total_qty']; ?></h3></td>
                                            <td><?php echo $p['morning_qty']; ?> units</td>
                                            <td><?php echo $p['afternoon_qty']; ?> units</td>
                                            <td><?php echo $p['evening_qty']; ?> units</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php require_once('partials/_footer.php'); ?>
        </div>
    </div>

    <?php require_once('partials/_scripts.php'); ?>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        $(document).ready(function() {
            $('#insightsTable').DataTable({
                "pageLength": 15,
                "order": [[2, "desc"]], // Sort by total sold by default
                "scrollX": true,
                "language": {
                    "paginate": { "previous": "<i class='fas fa-angle-left'></i>", "next": "<i class='fas fa-angle-right'></i>" }
                }
            });
        });

        // Hourly Trend Line Chart
        const ctxHourly = document.getElementById('hourlyChart').getContext('2d');
        new Chart(ctxHourly, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($hourly_labels); ?>,
                datasets: [{
                    label: 'Items Sold',
                    data: <?php echo json_encode(array_values($hourly_data)); ?>,
                    borderColor: '#5e72e4',
                    backgroundColor: 'rgba(94, 114, 228, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4 // Smooth curves
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        });

        // Weekly Distribution Bar Chart
        const ctxWeekly = document.getElementById('weeklyChart').getContext('2d');
        new Chart(ctxWeekly, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($weekly_labels); ?>,
                datasets: [{
                    label: 'Items Sold',
                    data: <?php echo json_encode($weekly_data); ?>,
                    backgroundColor: '#2dce89',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        });
    </script>
</body>
</html>
