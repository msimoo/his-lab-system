$(document).ready(function() {
        // تهيئة الجدول
        var table = $('#ppatient').DataTable({ 
            "pageLength": 10, 
            scrollX: true,
            "language": { "search": "بحث:", "paginate": { "previous": "السابق", "next": "التالي" }, "info": "عرض _START_ إلى _END_ من _TOTAL_ مُدخل" }
        });
        
        // تعديل المريض (Event Delegation)
        $(document).on('click', '.btn-edit-patient', function() {
            $('#edit_patient_id').val($(this).data('id'));
            $('#edit_name').val($(this).data('name'));
            $('#edit_phone').val($(this).data('phone'));
            $('#edit_age').val($(this).data('age'));
            $('#edit_gender').val($(this).data('gender'));
            $('#edit_blood').val($(this).data('blood'));
            $('#edit_history').val($(this).data('history'));
            $('#editPatientModal').modal('show');
        });

        // طلب المختبر (Event Delegation)
        $(document).on('click', '.btn-lab-request', function() {
            $('#modal_patient_id').val($(this).data('id'));
            $('#modal_patient_name').text($(this).data('name'));
            $('.lab-test-checkbox').prop('checked', false);
            $('#labRequestTotal').text('0.00 SDG');
            $('#amount_paid_input').val('');
            $('#labTestSearch').val('');
            filterLabTests();
            $('#labRequestModal').modal('show');
        });

        // عرض التفاصيل الشاملة للمريض (Event Delegation)
        $(document).on('click', '.btn-patient-detail', function() {
            var patientId = $(this).data('id');
            var patientName = $(this).data('name');
            $('#detail_patient_name').text(patientName);
            
            // إظهار التحميل
            $('#detailInsuranceInfo').html('<div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted fa-2x"></i></div>');
            $('#servicesListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل الخدمات...</p></div>');
            $('#claimsListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل المطالبات...</p></div>');
            $('#historyListContainer').html('<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p>جاري تحميل السجل...</p></div>');
            
            $('#patientDetailModal').modal('show');
            
            // تحميل البيانات عبر AJAX
            loadPatientDetails(patientId);
        });

        // عرض الإيصالات السابقة (Event Delegation)
        $(document).on('click', '.btn-patient-receipts', function() {
            var patientId = $(this).data('id');
            $('#receipts_modal_patient_name').text($(this).data('name'));
            $('#patientReceiptsBody').html('<tr><td colspan="6">جاري التحميل...</td></tr>');
            $('#patientReceiptsModal').modal('show');

            $.ajax({
                url: 'patient.php',
                method: 'GET',
                dataType: 'json',
                data: { action: 'get_patient_receipts', patient_id: patientId },
                success: function(response) {
                    if (response.receipts && response.receipts.length > 0) {
                        var rows = '';
                        response.receipts.forEach(function(receipt) {
                            rows += '<tr>' +
                                '<td>' + receipt.req_code + '</td>' +
                                '<td>' + receipt.req_date + '</td>' +
                                '<td>' + receipt.sample_barcode + '</td>' +
                                '<td>' + parseFloat(receipt.amount_paid).toFixed(2) + ' / ' + parseFloat(receipt.total_amount).toFixed(2) + ' SDG</td>' +
                                '<td>' + receipt.payment_status + '</td>' +
                                '<td><a href="print_lab_receipt.php?req_id=' + receipt.req_id + '" target="_blank" class="btn btn-sm btn-outline-success"><i class="fas fa-print"></i></a></td>' +
                            '</tr>';
                        });
                        $('#patientReceiptsBody').html(rows);
                    } else {
                        $('#patientReceiptsBody').html('<tr><td colspan="6">لا توجد إيصالات سابقة.</td></tr>');
                    }
                }
            });
        });

        function filterLabTests() {
            var query = $('#labTestSearch').val().trim().toLowerCase();
            $('.test-option').each(function() {
                var testName = $(this).find('.test-name').text().toLowerCase();
                $(this).toggle(query === '' || testName.indexOf(query) !== -1);
            });
        }

        $('#labTestSearch').on('input', filterLabTests);
        $('#clearLabTestSearch').on('click', function() {
            $('#labTestSearch').val('');
            filterLabTests();
            $('#labTestSearch').focus();
        });

        // حساب المبلغ الإجمالي ديناميكياً
        $(document).on('change', '.lab-test-checkbox', function() {
            var total = 0;
            $('.lab-test-checkbox:checked').each(function() {
                total += parseFloat($(this).data('price')) || 0;
            });
            $('#labRequestTotal').text(total.toFixed(2) + ' SDG');
            $('#amount_paid_input').val(total.toFixed(2));
        });

        // إرسال طلب المختبر عبر AJAX وطباعة الإيصال
        $('#labRequestForm').on('submit', function(e) {
            e.preventDefault();
            
            // التحقق من الفحوصات
            var selectedCount = $('.lab-test-checkbox:checked').length;
            if (selectedCount === 0) { 
                alert('الرجاء اختيار فحص واحد على الأقل.');
                return; 
            }
            
            // التحقق من المبلغ المدفوع
            var amountPaid = parseFloat($('#amount_paid_input').val());
            if (isNaN(amountPaid) || amountPaid <= 0) {
                alert('الرجاء إدخال مبلغ مدفوع صحيح.');
                return;
            }

            var formData = $(this).serializeArray();

            $.ajax({
                type: 'POST',
                url: 'patient.php',
                data: $.param(formData),
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                timeout: 30000,
                success: function(response) {
                    if (response && response.success) {
                        $('#labRequestModal').modal('hide');
                        var successMsg = $('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                            '<i class="fas fa-check-circle"></i> ' + response.message + '<br>' +
                            'رقم الطلب: <strong>' + response.req_code + '</strong><br>' +
                            '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                            '</div>');
                        $('.container-fluid').prepend(successMsg);
                        setTimeout(function() {
                            var printUrl = 'print_lab_receipt.php?req_id=' + response.req_id;
                            window.open(printUrl, '_blank', 'width=500,height=700');
                        }, 500);
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        alert('❌ خطأ: ' + (response.error || 'حدث خطأ غير معروف'));
                    }
                },
                error: function(jqXHR, textStatus) {
                    alert('فشلت العملية: ' + (textStatus === 'timeout' ? 'انتهت المهلة' : 'خطأ في الخادم (' + jqXHR.status + ')'));
                }
            });
        });

        // =====================================================================
        // دوال تفاصيل المريض (Patient Detail Modal)
        // =====================================================================
        
        window.loadPatientDetails = function(patientId) {
            var serviceType = $('#serviceTypeFilter').val() || 'all';
            
            $.ajax({
                url: 'patient.php',
                method: 'GET',
                dataType: 'json',
                data: { 
                    action: 'get_patient_details', 
                    patient_id: patientId,
                    service_type: serviceType
                },
                success: function(res) {
                    if (!res.success) { 
                        alert('خطأ: ' + res.error);
                        $('#patientDetailModal').modal('hide');
                        return; 
                    }
                    
                    // حفظ معرف المريض للاستخدام في طلب الخدمة
                    window._detailPatientId = patientId;
                    window._detailPatientName = res.patient.name;
                    window._detailPolicy = res.policy;
                    
                    // تعبئة بيانات المريض
                    $('#detail_patient_number').text(res.patient.patient_number || '');
                    $('#detail_phone').text(res.patient.phone || '');
                    $('#detail_age').text(res.patient.age || '');
                    $('#detail_gender').text(res.patient.gender == 'Male' ? 'ذكر' : 'أنثى');
                    $('#detail_blood').text(res.patient.blood_group || '-');
                    $('#detail_history').text(res.patient.medical_history || 'لا يوجد');
                    
                    // معلومات التأمين
                    if (res.policy) {
                        var pol = res.policy;
                        var endDate = new Date(pol.end_date);
                        var daysLeft = Math.ceil((endDate - new Date()) / (1000*60*60*24));
                        var expiryClass = daysLeft > 30 ? 'text-success' : (daysLeft > 0 ? 'text-warning' : 'text-danger');
                        
                        $('#detailInsuranceInfo').html(
                            '<div class="card border-info p-3 mb-0">' +
                                '<p class="mb-1"><strong>' + htmlEncode(pol.company_name) + '</strong></p>' +
                                '<p class="mb-1 small">عقد: ' + htmlEncode(pol.policy_number) + '</p>' +
                                '<p class="mb-1 small">تغطية الشركة: <strong class="text-success">' + pol.coverage_percentage + '%</strong></p>' +
                                '<p class="mb-0 small ' + expiryClass + '"><i class="fas fa-clock"></i> ' + daysLeft + ' يوم متبقي</p>' +
                            '</div>'
                        );
                        
                        $('#detailCoverageInfo').html(
                            '<div class="small">' +
                                '<div class="d-flex justify-content-between"><span>الحد السنوي</span><span>' + (parseFloat(pol.annual_limit) > 0 ? parseFloat(pol.annual_limit).toLocaleString() : 'غير محدود') + '</span></div>' +
                                '<div class="d-flex justify-content-between"><span>المستخدم</span><span>' + (parseFloat(pol.used_amount) || 0).toLocaleString() + '</span></div>' +
                                '<div class="progress my-1" style="height:4px;"><div class="progress-bar bg-info" style="width:' + (parseFloat(pol.annual_limit) > 0 ? Math.min(100, (parseFloat(pol.used_amount)/parseFloat(pol.annual_limit))*100) : 0) + '%"></div></div>' +
                                '<div class="d-flex justify-content-between text-' + (parseFloat(pol.used_amount) >= parseFloat(pol.annual_limit) && parseFloat(pol.annual_limit) > 0 ? 'danger' : 'success') + '"><span>المتبقي</span><span>' + (parseFloat(pol.annual_limit) > 0 ? Math.max(0, parseFloat(pol.annual_limit) - parseFloat(pol.used_amount)).toLocaleString() : '-') + '</span></div>' +
                            '</div>'
                        );
                        
                        // حفظ معلومات التأمين للاستخدام في طلب الخدمة
                        window._detailPolicyId = pol.policy_id;
                        window._detailCompanyId = pol.company_id;
                        window._detailCoveragePct = parseFloat(pol.coverage_percentage);
                        
                    } else {
                        $('#detailInsuranceInfo').html('<p class="text-muted">لا يوجد تأمين نشط</p>');
                        $('#detailCoverageInfo').html('<p class="text-muted">-</p>');
                        window._detailPolicyId = 0;
                        window._detailCompanyId = 0;
                        window._detailCoveragePct = 0;
                    }
                    
                    // عرض الخدمات
                    renderServices(res.services, res.policy);
                    
                    // عرض المطالبات
                    renderClaims(res.claims || []);
                    
                    // عرض السجل
                    renderHistory(res.history || []);
                },
                error: function() {
                    alert('فشل تحميل بيانات المريض');
                }
            });
        };

        window.loadPatientServices = function() {
            if (window._detailPatientId) {
                loadPatientDetails(window._detailPatientId);
            }
        };

        function renderServices(services, policy) {
            if (!services || services.length === 0) {
                $('#servicesListContainer').html('<p class="text-muted text-center py-4">لا توجد خدمات متاحة</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>الخدمة</th><th>النوع</th><th>السعر الأساسي</th>';
            if (policy) html += '<th>سعر التأمين</th><th>تغطية %</th><th>حصة المريض</th>';
            html += '</tr></thead><tbody>';
            
            services.forEach(function(svc) {
                var price = parseFloat(svc.price) || 0;
                var insPrice = parseFloat(svc.insurance_price) || price;
                var covPct = parseInt(svc.coverage_pct) || 0;
                var patientShare = insPrice * (100 - covPct) / 100;
                
                var typeBadge = 'badge-secondary';
                if (svc.service_type === 'Laboratory') typeBadge = 'badge-info';
                else if (svc.service_type === 'Clinic') typeBadge = 'badge-primary';
                else if (svc.service_type === 'Medical') typeBadge = 'badge-success';
                
                html += '<tr>' +
                    '<td><strong>' + htmlEncode(svc.name) + '</strong></td>' +
                    '<td><span class="badge ' + typeBadge + ' badge-pill">' + svc.service_type + '</span></td>' +
                    '<td>' + price.toFixed(2) + '</td>';
                if (policy) {
                    html += '<td class="text-primary font-weight-bold">' + insPrice.toFixed(2) + '</td>' +
                        '<td>' + covPct + '%</td>' +
                        '<td class="text-danger font-weight-bold">' + patientShare.toFixed(2) + '</td>';
                }
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#servicesListContainer').html(html);
        }

        function renderClaims(claims) {
            if (!claims || claims.length === 0) {
                $('#claimsListContainer').html('<p class="text-muted text-center py-4">لا توجد مطالبات تأمينية</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>المرجع</th><th>النوع</th><th>التكلفة</th><th>تغطية التأمين</th><th>المدفوع</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>';
            
            claims.forEach(function(cl) {
                var badgeClass = 'badge-secondary';
                var statusText = cl.status;
                switch(cl.status) {
                    case 'Pending': badgeClass = 'badge-warning'; statusText = 'قيد الانتظار'; break;
                    case 'Approved': badgeClass = 'badge-info'; statusText = 'معتمد'; break;
                    case 'Paid': badgeClass = 'badge-success'; statusText = 'مدفوع'; break;
                    case 'Rejected': badgeClass = 'badge-danger'; statusText = 'مرفوض'; break;
                    case 'Partial_Paid': badgeClass = 'badge-primary'; statusText = 'مدفوع جزئياً'; break;
                }
                
                html += '<tr>' +
                    '<td><code>' + htmlEncode(cl.claim_reference) + '</code></td>' +
                    '<td>' + (cl.claim_type || '-') + '</td>' +
                    '<td>' + parseFloat(cl.total_cost).toFixed(2) + '</td>' +
                    '<td class="text-info font-weight-bold">' + parseFloat(cl.insurance_coverage).toFixed(2) + '</td>' +
                    '<td>' + (parseFloat(cl.amount_paid) || 0).toFixed(2) + '</td>' +
                    '<td><span class="badge ' + badgeClass + ' badge-pill">' + statusText + '</span></td>' +
                    '<td><small>' + (cl.created_at || cl.claim_date || '').substring(0, 10) + '</small></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#claimsListContainer').html(html);
        }

        function renderHistory(history) {
            if (!history || history.length === 0) {
                $('#historyListContainer').html('<p class="text-muted text-center py-4">لا توجد طلبات سابقة</p>');
                return;
            }
            
            var html = '<div class="table-responsive"><table class="table table-hover table-sm align-items-center">' +
                '<thead class="thead-light"><tr><th>الرمز</th><th>النوع</th><th>الإجمالي</th><th>المدفوع</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>';
            
            history.forEach(function(h) {
                var badgeClass = h.status === 'Paid' ? 'badge-success' : (h.status === 'Unpaid' ? 'badge-danger' : 'badge-warning');
                html += '<tr>' +
                    '<td><code>' + htmlEncode(h.code) + '</code></td>' +
                    '<td><span class="badge badge-info badge-pill">' + h.type + '</span></td>' +
                    '<td>' + parseFloat(h.total).toFixed(2) + '</td>' +
                    '<td>' + parseFloat(h.paid).toFixed(2) + '</td>' +
                    '<td><span class="badge ' + badgeClass + ' badge-pill">' + h.status + '</span></td>' +
                    '<td><small>' + (h.date || '').substring(0, 10) + '</small></td>' +
                    '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#historyListContainer').html(html);
        }

        // =====================================================================
        // دوال طلب الخدمة مع التأمين (Service Request Modal)
        // =====================================================================
        
        window.openServiceRequestFromDetail = function() {
            // إغلاق نافذة التفاصيل وفتح نافذة طلب الخدمة
            $('#patientDetailModal').modal('hide');
            
            setTimeout(function() {
                var patientId = window._detailPatientId || 0;
                var patientName = window._detailPatientName || '';
                var policyId = window._detailPolicyId || 0;
                var companyId = window._detailCompanyId || 0;
                var coveragePct = window._detailCoveragePct || 0;
                
                $('#sr_patient_id').val(patientId);
                $('#sr_patient_name').text(patientName);
                $('#sr_policy_id').val(policyId);
                $('#sr_company_id').val(companyId);
                
                // عرض معلومات التأمين
                if (policyId > 0) {
                    $('#srInsuranceBanner').show();
                    $('#sr_company_name').text(window._detailPolicy ? window._detailPolicy.company_name : '');
                    $('#sr_coverage_pct').text(coveragePct);
                    $('#sr_patient_pct').text(100 - coveragePct);
                } else {
                    $('#srInsuranceBanner').hide();
                }
                
                // إعادة تعيين الحقول
                $('#sr_service_type').val('');
                $('#srServiceItemsContainer').hide();
                $('#srCalculatorSection').hide();
                $('#srServiceItems').html('<p class="text-muted text-center">اختر نوع الخدمة أولاً</p>');
                $('#sr_total_cost').text('0.00');
                $('#sr_insurance_share').text('0.00');
                $('#sr_patient_share').text('0.00');
                $('#sr_amount_paid').val('');
                $('#sr_insurance_coverage').val('0');
                $('#sr_patient_responsibility').val('0');
                
                $('#serviceRequestModal').modal('show');
            }, 300);
        };

        window.loadServiceItems = function() {
            var type = $('#sr_service_type').val();
            if (!type) {
                $('#srServiceItemsContainer').hide();
                $('#srCalculatorSection').hide();
                return;
            }
            
            $('#srServiceItemsContainer').show();
            $('#srServiceItems').html('<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</p>');
            
            // تحميل الخدمات حسب النوع
            var items = [];
            
            if (type === 'Laboratory') {
                // مختبر - يتم تحميلها عبر AJAX
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Laboratory' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'test_id');
                    }
                });
            } else if (type === 'Medical') {
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Medical' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'service_id');
                    }
                });
            } else if (type === 'Clinic') {
                $.getJSON('patient.php', { action: 'get_patient_details', patient_id: window._detailPatientId, service_type: 'Clinic' }, function(res) {
                    if (res.success && res.services) {
                        renderServiceCheckboxes(res.services, 'clinic_id');
                    }
                });
            }
        };

        function renderServiceCheckboxes(services, idField) {
            var coveragePct = window._detailCoveragePct || 0;
            var html = '';
            
            services.forEach(function(svc) {
                var price = parseFloat(svc.price) || 0;
                var insPrice = parseFloat(svc.insurance_price) || price;
                var covPct = parseInt(svc.coverage_pct) || coveragePct;
                var patientShare = insPrice * (100 - covPct) / 100;
                
                html += '<div class="custom-control custom-checkbox mb-2">' +
                    '<input type="checkbox" class="custom-control-input sr-service-item" ' +
                    'id="sr_item_' + svc.id + '" ' +
                    'value="' + svc.id + '" ' +
                    'data-price="' + insPrice + '" ' +
                    'data-name="' + htmlEncode(svc.name) + '" ' +
                    'data-coverage="' + covPct + '" ' +
                    'onchange="calculateSRTotal()">' +
                    '<label class="custom-control-label" for="sr_item_' + svc.id + '">' +
                    '<strong>' + htmlEncode(svc.name) + '</strong> ' +
                    '<span class="text-muted">(' + insPrice.toFixed(2) + ' SDG)</span>' +
                    '<br><small class="text-info">تغطية: ' + covPct + '% | المريض: ' + patientShare.toFixed(2) + '</small>' +
                    '</label></div>';
            });
            
            $('#srServiceItems').html(html);
            if (services.length === 0) {
                $('#srServiceItems').html('<p class="text-muted text-center">لا توجد خدمات متاحة من هذا النوع</p>');
            }
        }

        window.calculateSRTotal = function() {
            var total = 0;
            var checkedItems = [];
            var checkedNames = [];
            
            $('.sr-service-item:checked').each(function() {
                var price = parseFloat($(this).data('price')) || 0;
                total += price;
                checkedItems.push($(this).val());
                checkedNames.push($(this).data('name'));
            });
            
            if (checkedItems.length === 0) {
                $('#srCalculatorSection').hide();
                return;
            }
            
            $('#srCalculatorSection').show();
            
            var coveragePct = window._detailCoveragePct || 0;
            var insuranceShare = total * coveragePct / 100;
            var patientShare = total - insuranceShare;
            
            $('#sr_total_cost').text(total.toFixed(2));
            $('#sr_insurance_share').text(insuranceShare.toFixed(2));
            $('#sr_patient_share').text(patientShare.toFixed(2));
            $('#sr_amount_paid').val(patientShare.toFixed(2));
            $('#sr_insurance_coverage').val(insuranceShare.toFixed(2));
            $('#sr_patient_responsibility').val(patientShare.toFixed(2));
            
            // إضافة الحقول المخفية للإرسال
            // نستخدم hidden inputs بدلاً من serialize
            $('.sr-hidden-items').remove();
            checkedItems.forEach(function(val) {
                $('#serviceRequestForm').append('<input type="hidden" class="sr-hidden-items" name="sr_items[]" value="' + val + '">');
            });
            checkedNames.forEach(function(name) {
                $('#serviceRequestForm').append('<input type="hidden" class="sr-hidden-items" name="sr_item_names[]" value="' + htmlEncode(name) + '">');
            });
        };

        // إرسال طلب الخدمة مع التأمين
        $('#serviceRequestForm').on('submit', function(e) {
            e.preventDefault();
            
            var selectedItems = $('.sr-service-item:checked').length;
            if (selectedItems === 0) {
                alert('الرجاء اختيار خدمة واحدة على الأقل');
                return;
            }
            
            var amountPaid = parseFloat($('#sr_amount_paid').val());
            if (isNaN(amountPaid) || amountPaid < 0) {
                alert('الرجاء إدخال مبلغ صحيح');
                return;
            }
            
            this.submit();
        });

        // أداة مساعدة لترميز HTML
        function htmlEncode(str) {
            if (!str) return '';
            return $('<span>').text(str).html();
        }
    });