<?php
include __DIR__ . "/../../session_init.php";
require_once('config/config.php');
require_once('config/checklogin.php');
require_once('config/insurance_helpers.php');
require_once('config/financial_helpers.php');
check_login();
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>أداة الاختبار التفاعلية</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 30px 0; }
        .container { max-width: 800px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .card-header { background: linear-gradient(87deg, #5e72e4 0%, #825ee4 100%); color: white; border-radius: 15px 15px 0 0; }
        .test-step { 
            padding: 20px; 
            margin-bottom: 15px; 
            border-radius: 10px;
            border-left: 4px solid #667eea;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .test-step:hover { transform: translateX(5px); background: #e9ecef; }
        .test-step.active { background: #e7f3ff; border-left-color: #0084ff; }
        .test-step.completed { border-left-color: #28a745; background: #f0f8f5; }
        .test-step.failed { border-left-color: #dc3545; background: #fff5f5; }
        .result-box { 
            padding: 20px; 
            border-radius: 10px; 
            margin-top: 20px;
            display: none;
        }
        .result-box.success { background: #f0f8f5; border-left: 4px solid #28a745; }
        .result-box.error { background: #fff5f5; border-left: 4px solid #dc3545; }
        .spinner { display: none; }
        .progress-bar { background: linear-gradient(87deg, #5e72e4 0%, #825ee4 100%); }
        .btn-test { margin-top: 15px; width: 100%; padding: 12px; font-size: 16px; font-weight: bold; }
        h5 { color: #333; margin-bottom: 10px; font-weight: bold; }
        .info-text { color: #666; font-size: 14px; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-flask"></i> أداة الاختبار التفاعلية</h3>
            <small>اختبر كل مكون من مكونات النظام</small>
        </div>
        <div class="card-body">

            <div class="progress mb-4">
                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
            </div>

            <!-- الاختبار 1: الوردية -->
            <div class="test-step" onclick="runTest('shift', this)">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h5>1️⃣ اختبار الوردية</h5>
                        <p class="info-text">التحقق من وجود وردية مفتوحة</p>
                    </div>
                    <div>
                        <span class="spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        <span class="status-icon" style="display: none;"></span>
                    </div>
                </div>
            </div>

            <!-- الاختبار 2: المريض -->
            <div class="test-step" onclick="runTest('patient', this)">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h5>2️⃣ اختبار المريض</h5>
                        <p class="info-text">التحقق من وجود مريض</p>
                    </div>
                    <div>
                        <span class="spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        <span class="status-icon" style="display: none;"></span>
                    </div>
                </div>
            </div>

            <!-- الاختبار 3: الفحوصات -->
            <div class="test-step" onclick="runTest('tests', this)">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h5>3️⃣ اختبار الفحوصات</h5>
                        <p class="info-text">التحقق من وجود فحوصات مختبرية</p>
                    </div>
                    <div>
                        <span class="spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        <span class="status-icon" style="display: none;"></span>
                    </div>
                </div>
            </div>

            <!-- الاختبار 4: إنشاء طلب -->
            <div class="test-step" onclick="runTest('submit_lab', this)">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h5>4️⃣ إنشاء طلب اختبار</h5>
                        <p class="info-text">إنشاء طلب مختبري تجريبي</p>
                    </div>
                    <div>
                        <span class="spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        <span class="status-icon" style="display: none;"></span>
                    </div>
                </div>
            </div>

            <!-- النتيجة -->
            <div class="result-box" id="resultBox">
                <h5>النتيجة:</h5>
                <p id="resultMessage"></p>
                <div id="resultData" style="background: white; padding: 15px; border-radius: 5px; font-family: monospace; display: none; margin-top: 10px; overflow-x: auto; max-height: 300px;"></div>
                <button class="btn btn-primary btn-sm mt-3" onclick="copyResult()">
                    <i class="fas fa-copy"></i> انسخ النتيجة
                </button>
                <button class="btn btn-info btn-sm mt-3" onclick="openConsole()">
                    <i class="fas fa-bug"></i> فتح Console
                </button>
            </div>

            <!-- أزرار الإجراءات -->
            <div class="mt-4">
                <button class="btn btn-success btn-test" onclick="runFullTest()">
                    <i class="fas fa-play"></i> تشغيل فحص شامل
                </button>
                <button class="btn btn-info btn-test" onclick="window.open('patient.php', '_blank')">
                    <i class="fas fa-user-md"></i> الذهاب لصفحة المرضى
                </button>
                <button class="btn btn-warning btn-test" onclick="resetTests()">
                    <i class="fas fa-redo"></i> إعادة تعيين
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script>
    let currentReqId = null;
    let testResults = {};

    function runTest(test, element) {
        const spinner = $(element).find('.spinner');
        const statusIcon = $(element).find('.status-icon');
        const resultBox = $('#resultBox');

        spinner.show();
        statusIcon.hide();

        $.ajax({
            url: 'test_api.php?test=' + test,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('Test result:', response);

                spinner.hide();

                if (response.success) {
                    $(element).addClass('completed');
                    statusIcon.html('✓').css('color', 'green').show();
                    testResults[test] = response;
                    
                    if (test === 'submit_lab') {
                        currentReqId = response.data.req_id;
                    }

                    showResult(response, 'success');
                    updateProgress();
                } else {
                    $(element).addClass('failed');
                    statusIcon.html('✗').css('color', 'red').show();
                    showResult(response, 'error');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                spinner.hide();
                $(element).addClass('failed');
                statusIcon.html('✗').css('color', 'red').show();
                showResult({
                    success: false,
                    message: 'خطأ في الاتصال: ' + textStatus
                }, 'error');
            }
        });
    }

    function showResult(response, type) {
        const resultBox = $('#resultBox');
        const resultMessage = $('#resultMessage');
        const resultData = $('#resultData');

        resultBox.removeClass('success error').addClass(type);
        resultMessage.text(response.message);

        if (response.data && Object.keys(response.data).length > 0) {
            resultData.html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>').show();
        } else {
            resultData.hide();
        }

        resultBox.show();
    }

    function updateProgress() {
        const completed = Object.keys(testResults).length;
        const total = 4;
        const percent = (completed / total) * 100;
        $('#progressBar').css('width', percent + '%');
    }

    function runFullTest() {
        $.ajax({
            url: 'test_api.php?test=full',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('✓ جميع الاختبارات نجحت! النظام جاهز.');
                    // تشغيل الاختبارات واحداً تلو الآخر
                    runTest('shift', $('.test-step').eq(0)[0]);
                } else {
                    alert('⚠️ توجد مشاكل في النظام. اقرأ النتائج أعلاه.');
                }
            }
        });
    }

    function resetTests() {
        testResults = {};
        $('.test-step').removeClass('active completed failed');
        $('#resultBox').hide();
        $('#progressBar').css('width', '0%');
    }

    function copyResult() {
        const text = $('#resultData').text();
        navigator.clipboard.writeText(text).then(function() {
            alert('✓ تم النسخ');
        });
    }

    function openConsole() {
        console.log('=== Test Results ===');
        console.log(testResults);
        alert('افتح F12 لمشاهدة النتائج في Console');
    }
</script>

</body>
</html>
