<?php
/**
 * AJAX Lab Actions Handler
 * Handles all lab operations without page reloads
 */
include('config/config.php');


header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => '', 'action' => ''];

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if (!$action) throw new Exception('No action specified');
    $response['action'] = $action;

    switch ($action) {
        case 'save_results':
            $req_id = intval($_POST['req_id']);
            save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
            $check = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_results WHERE req_id = '$req_id' AND (result_value IS NULL OR result_value = '')");
            $r = $check->fetch_assoc();
            if ($r['c'] == 0) {
                $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
                $response['message'] = 'تم حفظ جميع النتائج بنجاح! العينة جاهزة للاعتماد الطبي.';
                $response['new_status'] = 'Completed';
            } else {
                $response['message'] = "تم حفظ النتائج المدخلة بنجاح. بقي {$r['c']} نتيجة لم تدخل بعد.";
                $response['new_status'] = 'Pending';
            }
            $response['success'] = true;
            break;

        case 'save_single_test':
            $req_id = intval($_POST['req_id']);
            $test_id = intval($_POST['test_id']);
            $filtered = [];
            if (isset($_POST['results']) && is_array($_POST['results'])) {
                $st = $mysqli->prepare("SELECT comp_id FROM rpos_lab_components WHERE test_id = ?");
                $st->bind_param('i', $test_id); $st->execute();
                $cr = $st->get_result();
                while ($row = $cr->fetch_assoc()) {
                    $cid = $row['comp_id'];
                    if (isset($_POST['results'][$cid])) $filtered[$cid] = $_POST['results'][$cid];
                }
                $st->close();
            }
            save_or_update_results($mysqli, $req_id, $filtered);
            $response = ['success' => true, 'message' => 'تم حفظ نتائج هذا الفحص بنجاح.', 'test_id' => $test_id, 'action' => 'save_single_test'];
            break;

        case 'confirm_single_test':
            $req_id = intval($_POST['req_id']);
            $test_id = intval($_POST['test_id']);
            $filtered = [];
            if (isset($_POST['results']) && is_array($_POST['results'])) {
                $st = $mysqli->prepare("SELECT comp_id FROM rpos_lab_components WHERE test_id = ?");
                $st->bind_param('i', $test_id); $st->execute();
                $cr = $st->get_result();
                while ($row = $cr->fetch_assoc()) {
                    $cid = $row['comp_id'];
                    if (isset($_POST['results'][$cid])) $filtered[$cid] = $_POST['results'][$cid];
                }
                $st->close();
            }
            save_or_update_results($mysqli, $req_id, $filtered);
            $hv = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_results WHERE req_id = '$req_id' AND test_id = '$test_id' AND result_value != '' AND result_value IS NOT NULL")->fetch_assoc();
            if ($hv['c'] == 0) throw new Exception('يرجى إدخال نتائج أولاً.');
            $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = ? AND test_id = ?");
            $stmt->bind_param('ii', $req_id, $test_id); $stmt->execute();
            $response = ['success' => true, 'message' => 'تم تأكيد نتائج هذا الفحص جزئياً.', 'test_id' => $test_id, 'action' => 'confirm_single_test'];
            break;

        case 'verify_request':
            $req_id = intval($_POST['req_id']);
            save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
            $mysqli->query("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = '$req_id'");
            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Verified' WHERE req_id = '$req_id'");
            $response = ['success' => true, 'message' => 'تم اعتماد التقرير الطبي نهائياً.', 'action' => 'verify_request'];
            break;

        case 'undo_verify':
            $req_id = intval($_POST['req_id']);
            $mysqli->query("UPDATE rpos_lab_requests SET status = 'Completed' WHERE req_id = '$req_id'");
            $response = ['success' => true, 'message' => 'تم إلغاء الاعتماد.', 'action' => 'undo_verify'];
            break;

        case 'undo_verify_single_test':
            $req_id = intval($_POST['req_id']);
            $test_id = intval($_POST['test_id']);
            $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 0, verified_at = NULL WHERE req_id = ? AND test_id = ?");
            $stmt->bind_param('ii', $req_id, $test_id); $stmt->execute();
            $response = ['success' => true, 'message' => 'تم إلغاء اعتماد الفحص.', 'test_id' => $test_id, 'action' => 'undo_verify_single_test'];
            break;


        case 'confirm_selected_tests':
            $req_id = intval($_POST['req_id']);
            $test_ids = $_POST['test_ids'] ?? [];
            if (!is_array($test_ids) || empty($test_ids)) throw new Exception('لم يتم تحديد أي فحص.');
            
            // Save all results first
            save_or_update_results($mysqli, $req_id, $_POST['results'] ?? []);
            
            $confirmed_count = 0;
            foreach ($test_ids as $tid) {
                $tid = intval($tid);
                if ($tid <= 0) continue;
                
                // Check if test has results
                $hv = $mysqli->query("SELECT COUNT(*) as c FROM rpos_lab_results WHERE req_id = '$req_id' AND test_id = '$tid' AND result_value != '' AND result_value IS NOT NULL")->fetch_assoc();
                if ($hv['c'] == 0) continue;
                
                // Confirm (verify) this test
                $stmt = $mysqli->prepare("UPDATE rpos_lab_results SET verified = 1, verified_at = NOW() WHERE req_id = ? AND test_id = ?");
                $stmt->bind_param('ii', $req_id, $tid);
                $stmt->execute();
                $confirmed_count++;
            }
            
            if ($confirmed_count == 0) throw new Exception('لم يتم تأكيد أي فحص - يرجى إدخال النتائج أولاً.');
            
            $response = ['success' => true, 'message' => "تم تأكيد $confirmed_count فحص/فحوصات بنجاح.", 'action' => 'confirm_selected_tests'];
            break;

        case 'save_results_batch':
            $req_id = intval($_POST['req_id']);
            $test_ids = $_POST['test_ids'] ?? [];
            if (!is_array($test_ids) || empty($test_ids)) throw new Exception('لم يتم تحديد أي فحص.');
            
            // Save results for these specific tests
            $all_results = $_POST['results'] ?? [];
            $filtered_results = [];
            
            foreach ($test_ids as $tid) {
                $tid = intval($tid);
                if ($tid <= 0) continue;
                
                $comp_ids = $mysqli->query("SELECT comp_id FROM rpos_lab_components WHERE test_id = '$tid'");
                while ($comp = $comp_ids->fetch_assoc()) {
                    $cid = $comp['comp_id'];
                    if (isset($all_results[$cid])) {
                        $filtered_results[$cid] = $all_results[$cid];
                    }
                }
            }
            
            save_or_update_results($mysqli, $req_id, $filtered_results);
            
            $response = ['success' => true, 'message' => "تم حفظ النتائج للفحوصات المحددة بنجاح.", 'action' => 'save_results_batch'];
            break;

        case 'get_queue':
            $html = ''; $count = 0;
            $q = $mysqli->query("SELECT r.*, p.name AS n, GROUP_CONCAT(DISTINCT t.test_name SEPARATOR ' + ') as ts,
                                COUNT(DISTINCT res.test_id) as total_tests,
                                COUNT(DISTINCT CASE WHEN res.result_value IS NOT NULL AND res.result_value != '' THEN res.test_id END) as tests_with_results,
                                COUNT(DISTINCT CASE WHEN res.verified = 1 THEN res.test_id END) as tests_verified
                                FROM rpos_lab_requests r 
                                JOIN rpos_patients p ON r.patient_id = p.patient_id 
                                JOIN rpos_lab_results res ON r.req_id = res.req_id 
                                JOIN rpos_lab_tests t ON res.test_id = t.test_id 
                                WHERE r.status IN ('Pending','Completed') AND r.payment_status IN ('Paid','Partially Paid') 
                                GROUP BY r.req_id 
                                ORDER BY FIELD(r.status,'Pending','Completed'), r.req_date ASC");
            $count = $q->num_rows;
            if ($count == 0) {
                $html = "<div class='text-center text-muted mt-5'><i class='fas fa-check-double fa-3x mb-2'></i><br>لا توجد عينات قيد الانتظار.</div>";
            } else {
                while ($req = $q->fetch_assoc()) {
                    if ($req['tests_verified'] == $req['total_tests']) {
                        $sb = '<span class="badge badge-success"><i class="fas fa-check-circle"></i> معتمد</span>';
                    } elseif ($req['tests_with_results'] == $req['total_tests']) {
                        $sb = '<span class="badge badge-info"><i class="fas fa-eye"></i> مراجعة</span>';
                    } elseif ($req['tests_with_results'] > 0) {
                        $sb = '<span class="badge badge-warning"><i class="fas fa-clock"></i> جزئي</span>';
                    } else {
                        $sb = '<span class="badge badge-secondary"><i class="fas fa-clock"></i> إدخال</span>';
                    }
                    $html .= '<div class="sample-card p-3" id="card_' . $req['req_id'] . '" onclick="loadWorkstation(' . $req['req_id'] . ')"><div class="d-flex justify-content-between align-items-start mb-2"><span class="badge badge-dark text-monospace"><i class="fas fa-barcode"></i> ' . htmlspecialchars($req['sample_barcode']) . '</span>' . $sb . '</div><h4 class="mb-1 text-dark font-weight-bold sample-name">' . htmlspecialchars($req['n']) . '</h4><p class="text-sm text-primary font-weight-bold mb-0 sample-test">' . htmlspecialchars(mb_strimwidth($req['ts'], 0, 40, '...', 'UTF-8')) . '</p><div class="mt-2 text-left"><a href="print_barcode_label.php?req_id=' . $req['req_id'] . '" target="_blank" class="btn btn-sm btn-outline-dark" onclick="event.stopPropagation();" title="طباعة باركود العينة"><i class="fas fa-barcode"></i> طباعة باركود</a></div></div>';
                }
            }
            $response = ['success' => true, 'html' => $html, 'count' => $count, 'action' => 'get_queue'];
            break;

        // ==========================================
        // Workbench بسيط: تحديد متعدد + حفظ/تأكيد
        // ==========================================
        case 'get_workbench':
            $req_id = intval($_GET['req_id']);
            $dropdown_types = get_dropdown_types();
            $req = $mysqli->query("SELECT r.*, p.name AS patient_name FROM rpos_lab_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id WHERE r.req_id = '$req_id'")->fetch_assoc();
            if (!$req) throw new Exception('الطلب غير موجود.');
            
            ob_start();
            ?>
            <!-- ===== معلومات المريض ===== -->
            <div style="display:flex;flex-wrap:wrap;gap:15px;padding:12px 15px;background:#f8f9fe;border-radius:10px;margin-bottom:15px;border:1px solid #e9ecef;">
                <div><i class="fas fa-user-injured text-primary"></i> <strong><?php echo htmlspecialchars($req['patient_name']); ?></strong></div>
                <div><i class="fas fa-barcode text-dark"></i> <span class="text-monospace"><?php echo $req['sample_barcode']; ?></span></div>
            </div>

            <form method="POST" action="" class="wb-form" id="wbForm">
                <input type="hidden" name="req_id" value="<?php echo $req['req_id']; ?>">
                
                <!-- ===== شريط التحديد المتعدد ===== -->
                <div class="batch-bar" style="display:none;background:#e8f4fd;border:1px dashed #5e72e4;border-radius:8px;padding:10px 15px;margin-bottom:15px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button type="submit" name="save_results" class="btn btn-info btn-sm"><i class="fas fa-edit"></i> تحديث وحفظ الكل</button>
                            <button type="submit" name="verify_request" class="btn btn-success btn-sm"><i class="fas fa-check-double"></i> اعتماد نهائي ✓</button>
                    <span style="font-weight:bold;"><i class="fas fa-check-square"></i> تم تحديد <span class="batch-count" style="background:#5e72e4;color:#fff;border-radius:50%;padding:0 8px;">0</span> فحص</span>
                    <button type="button" id="saveSelectedTests" class="btn btn-info btn-sm"><i class="fas fa-save"></i> حفظ المحدد</button>
                    <button type="button" id="confirmSelectedTests" class="btn btn-success btn-sm"><i class="fas fa-check-circle"></i> تأكيد المحدد</button>
                    <button type="button" class="btn btn-light btn-sm" onclick="$('.test-check:checked').prop('checked',false);$('#selectAll').prop('checked',false);showBatchBar();">إلغاء</button>
                </div>
                
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm" style="border-collapse:separate;border-spacing:0;border-radius:10px;overflow:hidden;">
                        <thead style="background:#f8f9fe;">
                            <tr>
                                <th style="width:5%;text-align:center;"><input type="checkbox" id="selectAll" title="تحديد الكل"></th>
                                <th style="width:25%;text-align:right;">المكون</th>
                                <th style="width:28%;text-align:center;">النتيجة</th>
                                <th style="width:22%;text-align:center;">المؤشر</th>
                                <th style="width:20%;text-align:right;">النطاق الطبيعي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $tests_q = $mysqli->query("SELECT DISTINCT t.test_id, t.test_name, (SELECT MIN(verified) FROM rpos_lab_results WHERE req_id = '$req_id' AND test_id = t.test_id) as test_verified FROM rpos_lab_results lr JOIN rpos_lab_tests t ON lr.test_id = t.test_id WHERE lr.req_id = '$req_id' ORDER BY t.test_name ASC");
                            $has_any = false;
                            while ($tr = $tests_q->fetch_assoc()):
                                $comps = $mysqli->query("SELECT lc.comp_id, lc.comp_name, lc.normal_range, lc.unit, t.test_id, t.test_name, (SELECT result_value FROM rpos_lab_results WHERE req_id = '$req_id' AND comp_id = lc.comp_id LIMIT 1) as result_value, (SELECT flag FROM rpos_lab_results WHERE req_id = '$req_id' AND comp_id = lc.comp_id LIMIT 1) as flag FROM rpos_lab_components lc JOIN rpos_lab_tests t ON lc.test_id = t.test_id WHERE lc.test_id = '{$tr['test_id']}' ORDER BY lc.comp_id ASC");
                                if ($comps->num_rows > 0):
                                    $has_any = true;
                                    $hs = $mysqli->query("SELECT COUNT(*) as cnt FROM rpos_lab_results WHERE req_id = '$req_id' AND test_id = '{$tr['test_id']}' AND result_value != '' AND result_value IS NOT NULL")->fetch_assoc();
                            ?>
                                <tr style="background:#fff;border-bottom:2px solid #e9ecef;">
                                    <td colspan="5" style="padding:8px 12px;">
                                        <div style="display:flex;align-items:center;justify-content:space-between;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($tr['test_name']); ?></strong>
                                                <?php if ($tr['test_verified'] == 1): ?>
                                                    <span class="badge badge-success">✓ معتمد</span>
                                                <?php else: ?>
                                                    <input type="checkbox" class="test-check" value="<?php echo $tr['test_id']; ?>" style="margin-right:8px;transform:scale(1.2);">
                                                <?php endif; ?>
                                            </div>
                                            <div style="display:flex;gap:5px;">
                                                <?php if ($tr['test_verified'] == 1): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> معتمد</span>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline-block;margin:0;" class="inline-lab-form">
                                                        <input type="hidden" name="req_id" value="<?php echo $req['req_id']; ?>">
                                                        <input type="hidden" name="test_id" value="<?php echo $tr['test_id']; ?>">
                                                        <button type="submit" name="save_single_test" class="btn btn-sm btn-info"><i class="fas fa-save"></i> حفظ</button>
                                                    </form>
                                                    <form method="POST" style="display:inline-block;margin:0;" class="inline-lab-form">
                                                        <input type="hidden" name="req_id" value="<?php echo $req['req_id']; ?>">
                                                        <input type="hidden" name="test_id" value="<?php echo $tr['test_id']; ?>">
                                                        <button type="submit" name="confirm_single_test" class="btn btn-sm btn-success"><i class="fas fa-check-circle"></i> تأكيد</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                while ($c = $comps->fetch_assoc()):
                                    $rt = getResultTypeFromRange($c['normal_range'], $dropdown_types);
                                    $is_dd = ($rt !== 'quantitative');
                                ?>
                                <tr>
                                    <td style="text-align:center;width:5%;"></td>
                                    <td style="text-align:right;">
                                        <strong><?php echo htmlspecialchars($c['comp_name']); ?></strong>
                                        <?php if (!$is_dd && !empty($c['unit'])): ?>
                                            <small class="text-muted">(<?php echo $c['unit']; ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($is_dd): 
                                            $opts = []; $rtk = $rt;
                                            if($rtk=='qualitative') $opts=['Positive','Negative'];
                                            elseif($rtk=='qualitative_universal_testop') $opts=['None Reactive','Reactive']; 
                                            elseif($rtk=='qualitative_blood_group') $opts=['B+ve','AB+ve','O+ve','A+ve','AB-ve','B-ve','O-ve','A-ve']; //ICT-None Reactive/
                                            elseif($rtk=='qualitative_bffm') $opts=['No malaria p.seen','Malaria p.seen(+ve) p.falciprum(+)','Malaria p.seen(+ve) p.falciprum(++)','Malaria p.seen(+ve) p.falciprum(+++)','Malaria p.seen(+ve) p.vivax(+)'];
                                            elseif($rtk=='qualitative_aso') $opts=['More than 200 IU/ML (positive +ve)','Less than 200 IU/ML (Negative -ve)'];
                                            elseif($rtk=='qualitative_widal_titer') $opts=['1/20','1/40','1/80','1/160','1/320'];
                                            elseif($rtk=='qualitative_widal_entrica') $opts=['Titer is significant for entrica','Titer is insignificant for entrica','Titer is doubtful for entrica','Titer is suggestive for entrica'];
                                            elseif($rtk=='qualitative_widal_brucella') $opts=['Titer is significant for brucella','Titer is insignificant for brucella','Titer is doubtful for brucella','Titer is suggestive for brucella'];
                                            elseif($rtk=='qualitative_urine_color') $opts=['Yellow','Red','Deep Yellow','Brown','Pink','White','Turbid'];
                                            elseif($rtk=='qualitative_urine_ph') $opts=['Acidic','Alkaline'];
                                            elseif($rtk=='qualitative_urine_protein') $opts=['Nil','Few','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_sugar') $opts=['Nil','Few','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_acetone') $opts=['Nil','Few','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_bile') $opts=['Nil','Few','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_epith') $opts=['Nil','Few','+','++','+++','++++'];
                                            elseif($rtk=='qualitative_urine_mucus') $opts=['Nil','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_yeast') $opts=['Nil','+','++','+++'];
                                            elseif($rtk=='qualitative_urine_bacteria') $opts=['Nil','+','++','+++'];
                                            elseif($rtk=='qualitative_stool_color') $opts=['Brown','Yellow','Black','Green'];
                                            elseif($rtk=='qualitative_stool_ph') $opts=['Alkaline','Acidic'];
                                            elseif($rtk=='qualitative_stool_consistency') $opts=['Soft','Mucoid','Fluid','Semi-soft'];
                                            elseif($rtk=='qualitative_stool_mucus') $opts=['Not Seen','+','++','+++'];
                                            elseif($rtk=='qualitative_stool_blood') $opts=['Not Seen','+','++','+++'];
                                            elseif($rtk=='qualitative_stool_undigested') $opts=['Not Seen','+','++','+++'];
                                            elseif($rtk=='qualitative_stool_yeast') $opts=['Not Seen','+','++','+++'];
                                            elseif($rtk=='qualitative_stool_bacteria') $opts=['Not Seen','+','++','+++'];
                                        ?>
                                            <select name="results[<?php echo $c['comp_id']; ?>][value]" class="form-control form-control-sm" style="display:inline-block;width:auto;min-width:150px;">
                                                <!--<option value="" <?php echo empty($c['result_value'])?'selected':''; ?>>--</option>-->
                                                <?php foreach($opts as $o): ?><option value="<?php echo htmlspecialchars($o); ?>" <?php echo ($c['result_value']===$o)?'selected':''; ?>><?php echo htmlspecialchars($o); ?></option><?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="results[<?php echo $c['comp_id']; ?>][flag]" value="Normal">
                                        <?php else: ?>
                                            <input type="text" name="results[<?php echo $c['comp_id']; ?>][value]" class="form-control form-control-sm" style="display:inline-block;width:120px;text-align:center;font-weight:bold;" value="<?php echo htmlspecialchars($c['result_value']??''); ?>" autocomplete="off" placeholder="...">
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($is_dd): ?>
                                            <small class="text-muted">قائمة</small>
                                        <?php else: ?>
                                            <div style="display:flex;gap:3px;justify-content:center;">
                                                <label style="padding:2px 6px;border-radius:4px;cursor:pointer;border:1px solid #dee2e6;font-size:0.75rem;font-weight:bold;background:<?php echo $c['flag']=='Low'?'#fff3cd':'#fff'; ?>;color:<?php echo $c['flag']=='Low'?'#856404':'#333'; ?>;">
                                                    <input type="radio" name="results[<?php echo $c['comp_id']; ?>][flag]" value="Low" <?php echo $c['flag']=='Low'?'checked':''; ?> style="display:none;"> L ↓
                                                </label>
                                                <label style="padding:2px 6px;border-radius:4px;cursor:pointer;border:1px solid #dee2e6;font-size:0.75rem;font-weight:bold;background:<?php echo ($c['flag']=='Normal'||empty($c['flag']))?'#d4edda':'#fff'; ?>;color:<?php echo ($c['flag']=='Normal'||empty($c['flag']))?'#155724':'#333'; ?>;">
                                                    <input type="radio" name="results[<?php echo $c['comp_id']; ?>][flag]" value="Normal" <?php echo ($c['flag']=='Normal'||empty($c['flag']))?'checked':''; ?> style="display:none;"> N
                                                </label>
                                                <label style="padding:2px 6px;border-radius:4px;cursor:pointer;border:1px solid #dee2e6;font-size:0.75rem;font-weight:bold;background:<?php echo $c['flag']=='High'?'#f8d7da':'#fff'; ?>;color:<?php echo $c['flag']=='High'?'#721c24':'#333'; ?>;">
                                                    <input type="radio" name="results[<?php echo $c['comp_id']; ?>][flag]" value="High" <?php echo $c['flag']=='High'?'checked':''; ?> style="display:none;"> H ↑
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;font-size:0.85rem;color:#6c757d;">
                                        <?php echo $is_dd ? $dropdown_types[$rt]['label'] : nl2br(htmlspecialchars($c['normal_range']??'')); ?>
                                    </td>
                                </tr>
                            <?php endwhile; endif; endwhile; if (!$has_any): ?><tr><td colspan='5' class='text-center text-danger py-4'>لا توجد مكونات للفحوصات المطلوبة!</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- ===== أزرار الإجراءات ===== -->
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 15px;background:#f8f9fe;border-radius:10px;border:1px solid #e9ecef;">
                    <div></div>
                    <div style="display:flex;gap:8px;">
                        <?php if($req['status'] == 'Pending'): ?>
                            <button type="submit" name="save_results" class="btn btn-primary font-weight-bold"><i class="fas fa-save"></i> حفظ الكل</button>
                        <?php else: ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            <?php
            $html = ob_get_clean();
            $response = ['success' => true, 'html' => $html, 'action' => 'get_workbench'];
            break;

        // ==========================================
        // تحميل الأقسام المختلفة ديناميكياً
        // ==========================================
        case 'get_section':
            $section = $_GET['section'] ?? '';
            $dropdown_types = get_dropdown_types();
            ob_start();
            
            if ($section === 'verified') {
                ?>
                <div class="card shadow border-left-success">
                    <div class="card-header border-0 bg-transparent" dir="rtl">
                        <h3 class="mb-0 text-dark font-weight-bold"><i class="fas fa-archive"></i> النتائج المعتمدة للطباعة والتسليم</h3>
                    </div>
                    <div class="table-responsive p-3">
                        <table style="width:100% !important;" class="table align-items-center table-flush text-right" id="datatable_verified">
                            <thead class="thead-light">
                                <tr><th>الباركود</th><th>المريض</th><th>الفحص المعتمد</th><th>تاريخ الاعتماد</th><th>الطباعة والإدارة</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $vq = $mysqli->query("SELECT res.req_id, res.test_id, MAX(res.verified_at) as verified_at, r.req_code, r.sample_barcode, r.req_date, r.referring_doctor, p.name AS patient_name, p.patient_number, p.age, p.gender, t.test_name FROM rpos_lab_results res JOIN rpos_lab_requests r ON res.req_id = r.req_id JOIN rpos_patients p ON r.patient_id = p.patient_id JOIN rpos_lab_tests t ON res.test_id = t.test_id WHERE res.verified = 1 GROUP BY res.req_id, res.test_id ORDER BY MAX(res.verified_at) DESC");
                                while($vr = $vq->fetch_assoc()):
                                    $vd = !empty($vr['verified_at']) ? date('Y-m-d h:i A', strtotime($vr['verified_at'])) : date('Y-m-d h:i A', strtotime($vr['req_date']));
                                ?>
                                <tr>
                                    <td><span class="badge badge-success text-monospace" style="font-size:14px;"><?php echo htmlspecialchars($vr['sample_barcode']); ?></span></td>
                                    <td><strong class="text-dark" style="font-size:16px;"><?php echo htmlspecialchars($vr['patient_name']); ?></strong></td>
                                    <td><span class="badge badge-info font-weight-bold"><?php echo htmlspecialchars($vr['test_name']); ?></span></td>
                                    <td><?php echo $vd; ?></td>
                                    <td>
                                        <a href="print_lab_result.php?req_id=<?php echo $vr['req_id']; ?>&test_id=<?php echo $vr['test_id']; ?>" target="_blank" class="btn btn-sm btn-primary font-weight-bold shadow-sm"><i class="fas fa-print"></i> طباعة</a>
                                        <a href="print_lab_result.php?req_id=<?php echo $vr['req_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold shadow-sm"><i class="fas fa-print"></i> طباعة الكل</a>
                                        <a href="print_barcode_label.php?req_id=<?php echo $vr['req_id']; ?>" target="_blank" class="btn btn-sm btn-dark font-weight-bold shadow-sm"><i class="fas fa-barcode"></i> باركود</a>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="req_id" value="<?php echo $vr['req_id']; ?>">
                                            <input type="hidden" name="test_id" value="<?php echo $vr['test_id']; ?>">
                                            <button type="submit" name="undo_verify_single_test" class="btn btn-sm btn-outline-danger font-weight-bold shadow-sm"><i class="fas fa-undo"></i> تراجع</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php
            } elseif ($section === 'tests') { /* ... unchanged ... */ ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card shadow">
                            <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة فحص رئيسي جديد</h4></div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="form-group"><label>القسم</label><select name="cat_id" class="form-control" required>
                                        <option value="">-- اختر القسم --</option>
                                        <?php $cats = $mysqli->query("SELECT * FROM rpos_lab_categories ORDER BY cat_name ASC"); while($c=$cats->fetch_assoc()){echo "<option value='{$c['cat_id']}'>".htmlspecialchars($c['cat_name'])."</option>";} ?>
                                    </select></div>
                                    <div class="form-group"><label>اسم الفحص</label><input type="text" name="test_name" class="form-control" required></div>
                                    <div class="form-group"><label>السعر</label><input type="number" step="0.01" name="price" class="form-control" required></div>
                                    <button type="submit" name="add_test" class="btn btn-primary btn-block">حفظ الفحص</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card shadow">
                            <div class="card-header border-0"><h3 class="mb-0">دليل الفحوصات والأسعار</h3></div>
                            <div class="table-responsive p-3">
                                <table class="table align-items-center text-right" id="dt_tests" style="width:100%!important;">
                                    <thead class="thead-light"><tr><th>#</th><th>الاسم</th><th>القسم</th><th>السعر</th><th>الإجراءات</th></tr></thead>
                                    <tbody>
                                        <?php $tst = $mysqli->query("SELECT t.*,c.cat_name FROM rpos_lab_tests t LEFT JOIN rpos_lab_categories c ON t.cat_id=c.cat_id ORDER BY t.test_name ASC"); while($t=$tst->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $t['test_id']; ?></td>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($t['test_name']); ?></td>
                                            <td><span class="badge badge-info"><?php echo htmlspecialchars($t['cat_name']??'بدون قسم'); ?></span></td>
                                            <td class="text-success font-weight-bold"><?php echo number_format($t['price'],2); ?> SDG</td>
                                            <td><a href="lab_management.php?section=tests&delete_test=<?php echo $t['test_id']; ?>" class="btn btn-sm btn-danger">حذف</a></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } elseif ($section === 'components') { /* ... unchanged ... */ ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card shadow">
                            <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة مكون فحص جديد</h4></div>
                            <div class="card-body">
                                <form method="POST" class="component-form">
                                    <div class="form-group"><label>الفحص</label><select name="test_id" class="form-control" required><option value="">-- اختر --</option><?php $tl=$mysqli->query("SELECT test_id,test_name FROM rpos_lab_tests ORDER BY test_name ASC"); while($t=$tl->fetch_assoc()){echo "<option value='{$t['test_id']}'>".htmlspecialchars($t['test_name'])."</option>";} ?></select></div>
                                    <div class="form-group"><label>اسم المكون</label><input type="text" name="comp_name" class="form-control" required></div>
                                    <div class="form-group"><label>نوع الإدخال</label><select name="result_type" class="form-control"><option value="quantitative">إدخال حر</option><?php foreach(get_dropdown_types() as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v['label']; ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>النطاق الطبيعي</label><input type="text" name="normal_range" class="form-control component-dependent" placeholder="مثال: 4.5-11.0"></div>
                                    <div class="form-group"><label>الوحدة</label><input type="text" name="unit" class="form-control component-dependent" placeholder="مثال: g/dL"></div>
                                    <button type="submit" name="add_component" class="btn btn-primary btn-block">حفظ المكون</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card shadow">
                            <div class="card-header border-0"><h3 class="mb-0">النطاقات الطبية والمكونات</h3></div>
                            <div class="table-responsive p-3">
                                <table class="table align-items-center text-right" id="dt_components" style="width:100%!important;">
                                    <thead class="thead-light"><tr><th>الفحص</th><th>المكون</th><th>النطاق</th><th>الوحدة</th><th>الإجراءات</th></tr></thead>
                                    <tbody>
                                        <?php $comps=$mysqli->query("SELECT c.*,t.test_name FROM rpos_lab_components c JOIN rpos_lab_tests t ON c.test_id=t.test_id ORDER BY c.comp_id DESC"); while($c=$comps->fetch_assoc()): $rt=getResultTypeFromRange($c['normal_range'],get_dropdown_types()); $isd=($rt!=='quantitative'); ?>
                                        <tr>
                                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($c['test_name']); ?></td>
                                            <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($c['comp_name']); ?></td>
                                            <td><?php echo $isd?"<span class='badge badge-secondary'>".get_dropdown_types()[$rt]['label']."</span>":nl2br(htmlspecialchars($c['normal_range'])); ?></td>
                                            <td><?php echo $isd?'-':htmlspecialchars($c['unit']); ?></td>
                                            <td><a href="lab_management.php?section=components&delete_component=<?php echo $c['comp_id']; ?>" class="btn btn-sm btn-danger">حذف</a></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } elseif ($section === 'categories') { /* ... unchanged ... */ ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card shadow">
                            <div class="card-header bg-transparent border-0"><h4 class="mb-0">إضافة قسم مختبري جديد</h4></div>
                            <div class="card-body">
                                <form method="POST"><div class="form-group"><label>اسم القسم</label><input type="text" name="cat_name" class="form-control" required></div><div class="form-group"><label>الوصف</label><textarea name="description" class="form-control" rows="3"></textarea></div><button type="submit" name="add_category" class="btn btn-primary btn-block">حفظ القسم</button></form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card shadow"><div class="card-header border-0"><h3 class="mb-0">قائمة الأقسام المختبرية</h3></div><div class="table-responsive p-3"><table class="table align-items-center text-right" id="dt_categories" style="width:100%!important;"><thead class="thead-light"><tr><th>#</th><th>الاسم</th><th>الوصف</th><th>الإجراءات</th></tr></thead><tbody>
                            <?php $cats=$mysqli->query("SELECT * FROM rpos_lab_categories ORDER BY cat_name ASC"); while($cat=$cats->fetch_assoc()): ?>
                            <tr><td><?php echo $cat['cat_id']; ?></td><td><?php echo htmlspecialchars($cat['cat_name']); ?></td><td><?php echo nl2br(htmlspecialchars($cat['description'])); ?></td><td><a href="lab_management.php?section=categories&delete_category=<?php echo $cat['cat_id']; ?>" class="btn btn-sm btn-danger">حذف</a></td></tr>
                            <?php endwhile; ?>
                        </tbody></table></div></div></div>
                </div>
            <?php }
            
            $html = ob_get_clean();
            $response = ['success' => true, 'html' => $html, 'section' => $section, 'action' => 'get_section'];
            break;

        case 'get_barcode_info':
            $req_id = intval($_GET['req_id']);
            $br = $mysqli->query("SELECT r.sample_barcode, p.name AS patient_name, r.req_date FROM rpos_lab_requests r JOIN rpos_patients p ON r.patient_id = p.patient_id WHERE r.req_id = '$req_id'")->fetch_assoc();
            if (!$br) throw new Exception('الطلب غير موجود.');
            $response = ['success' => true, 'barcode' => $br['sample_barcode'], 'patient_name' => $br['patient_name'], 'req_date' => date('d/m/Y', strtotime($br['req_date'])), 'action' => 'get_barcode_info'];
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage(), 'action' => $action ?? ''];
}

echo json_encode($response);
exit;

// ==========================================
// Helper functions
// ==========================================
function get_dropdown_types() {
    return [
        'qualitative' => ['db_val' => 'Positive/Negative', 'label' => 'إيجابي / سلبي (Positive/Negative)'],
        'qualitative_bffm' => ['db_val' => 'BFFM_OPTIONS', 'label' => 'مسحة الملاريا (BFFM Options)'],
        'qualitative_aso' => ['db_val' => 'ASO_OPTIONS', 'label' => 'نتائج ASO'],
        'qualitative_widal_titer' => ['db_val' => 'WIDAL_TITER', 'label' => 'تايتر (Widal Titer 1/20-1/320)'],
        'qualitative_widal_entrica' => ['db_val' => 'WIDAL_COMMENT_ENTRICA', 'label' => 'تعليق Widal (Entrica Comment)'],
        'qualitative_widal_brucella' => ['db_val' => 'WIDAL_COMMENT_BRUCELLA', 'label' => 'تعليق Widal (Brucella Comment)'],
        'qualitative_urine_color' => ['db_val' => 'URINE_COLOR_OPTIONS', 'label' => 'لون البول (Urine Color)'],
        'qualitative_urine_ph' => ['db_val' => 'URINE_PH_OPTIONS', 'label' => 'pH البول'],
        'qualitative_urine_protein' => ['db_val' => 'URINE_PROTEIN_OPTIONS', 'label' => 'بروتين البول'],
        'qualitative_urine_sugar' => ['db_val' => 'URINE_SUGAR_OPTIONS', 'label' => 'سكر البول'],
        'qualitative_urine_acetone' => ['db_val' => 'URINE_ACETONE_OPTIONS', 'label' => 'أسيتون البول'],
        'qualitative_urine_bile' => ['db_val' => 'URINE_BILE_OPTIONS', 'label' => 'صفراوي البول'],
        'qualitative_urine_epith' => ['db_val' => 'URINE_EPITH_OPTIONS', 'label' => 'خلايا طلائية (Epith)'],
        'qualitative_urine_mucus' => ['db_val' => 'URINE_MUCUS_OPTIONS', 'label' => 'مخاط البول'],
        'qualitative_urine_yeast' => ['db_val' => 'URINE_YEAST_OPTIONS', 'label' => 'خميرة البول'],
        'qualitative_urine_bacteria' => ['db_val' => 'URINE_BACTERIA_OPTIONS', 'label' => 'بكتيريا البول'],
        'qualitative_stool_color' => ['db_val' => 'STOOL_COLOR_OPTIONS', 'label' => 'لون البراز'],
        'qualitative_stool_ph' => ['db_val' => 'STOOL_PH_OPTIONS', 'label' => 'pH البراز'],
        'qualitative_stool_consistency' => ['db_val' => 'STOOL_CONSISTENCY_OPTIONS', 'label' => 'قوام البراز'],
        'qualitative_stool_mucus' => ['db_val' => 'STOOL_MUCUS_OPTIONS', 'label' => 'مخاط البراز'],
        'qualitative_stool_blood' => ['db_val' => 'STOOL_BLOOD_OPTIONS', 'label' => 'دم البراز'],
        'qualitative_stool_undigested' => ['db_val' => 'STOOL_UNDIGESTED_OPTIONS', 'label' => 'طعام غير مهضوم'],
        'qualitative_stool_yeast' => ['db_val' => 'STOOL_YEAST_OPTIONS', 'label' => 'خميرة البراز'],
        'qualitative_stool_bacteria' => ['db_val' => 'STOOL_BACTERIA_OPTIONS', 'label' => 'بكتيريا البراز'],
        'qualitative_universal_testop' => ['db_val' => 'ICT_UNIVERSAL_OPTIONS', 'label' => 'ICT- None Reactive/Reactive'], 
        'qualitative_blood_group' => ['db_val' => 'BLOOD_GR_OPTIONS', 'label' => 'BLOOD Grouping'],
    ];
}

function getResultTypeFromRange($range, $dropdown_types) {
    $range = (string)$range;
    foreach($dropdown_types as $key => $data) {
        if(trim($data['db_val']) === trim($range)) return $key;
    }
    return 'quantitative';
}

function save_or_update_results($mysqli, $req_id, $results_data) {
    if (!is_array($results_data)) return;
    foreach ($results_data as $comp_id => $val) {
        $value = $val['value'] ?? '';
        $flag = $val['flag'] ?? 'Normal';
        $comp_id = intval($comp_id);
        $tr = $mysqli->query("SELECT test_id FROM rpos_lab_components WHERE comp_id = '$comp_id'")->fetch_assoc();
        $test_id = $tr['test_id'] ?? 0;
        $chk = $mysqli->query("SELECT result_id FROM rpos_lab_results WHERE req_id = '$req_id' AND comp_id = '$comp_id'");
        if ($chk && $chk->num_rows > 0) {
            $st = $mysqli->prepare("UPDATE rpos_lab_results SET result_value=?, flag=? WHERE req_id=? AND comp_id=?");
            $st->bind_param('ssii', $value, $flag, $req_id, $comp_id); $st->execute();
        } else {
            $st = $mysqli->prepare("INSERT INTO rpos_lab_results (req_id, test_id, comp_id, result_value, flag) VALUES (?, ?, ?, ?, ?)");
            $st->bind_param('iiiss', $req_id, $test_id, $comp_id, $value, $flag); $st->execute();
        }
    }
}
