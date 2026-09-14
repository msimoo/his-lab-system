/**
 * نظام إدارة التأمين الديناميكي
 * Dynamic Insurance Management System
 */

// تحميل الشركات المتعاقد معها مع المريض
function loadPatientCompanies(patientId, selectId) {
    if (!patientId) return;
    
    $.ajax({
        url: 'patient.php',
        method: 'GET',
        dataType: 'json',
        data: {
            action: 'get_patient_companies',
            patient_id: patientId
        },
        success: function(response) {
            var $select = $('#' + selectId);
            $select.html('<option value="">-- بدون تأمين (السعر الأساسي) --</option>');
            
            if (response.success && response.companies.length > 0) {
                response.companies.forEach(function(company) {
                    $select.append(`<option value="${company.company_id}">${company.company_name}</option>`);
                });
                $select.prop('disabled', false);
            } else {
                $select.prop('disabled', true);
            }
        },
        error: function() {
            console.log('خطأ في جلب الشركات');
        }
    });
}

// حساب السعر ديناميكياً
function calculateDynamicPrice(patientId, companyId, serviceType, serviceName, defaultPrice, priceDisplayId, breakdownId) {
    if (!patientId || !serviceName || defaultPrice <= 0) {
        $('#' + priceDisplayId).html(`
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i> السعر الافتراضي: ${defaultPrice.toFixed(2)}
            </div>
        `);
        return;
    }
    
    $.ajax({
        url: 'ajax_calculate_price.php',
        method: 'GET',
        dataType: 'json',
        data: {
            patient_id: patientId,
            company_id: companyId || 0,
            service_type: serviceType,
            service_name: serviceName,
            default_price: defaultPrice
        },
        success: function(response) {
            if (response.success) {
                var html = `
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-3">
                            <h6 class="font-weight-bold mb-3">تفاصيل السعر والتأمين</h6>
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td><strong>السعر النهائي:</strong></td>
                                    <td class="text-right"><span class="badge badge-primary">${response.final_price.toFixed(2)}</span></td>
                                </tr>`;
                
                if (response.has_insurance) {
                    html += `
                                <tr class="bg-light">
                                    <td><strong>حصة المريض:</strong></td>
                                    <td class="text-right"><span class="badge badge-info">${response.patient_responsibility.toFixed(2)}</span></td>
                                </tr>
                                <tr class="bg-light">
                                    <td><strong>حصة التأمين:</strong></td>
                                    <td class="text-right"><span class="badge badge-success">${response.insurance_responsibility.toFixed(2)}</span></td>
                                </tr>
                                <tr>
                                    <td><strong>نسبة التغطية:</strong></td>
                                    <td class="text-right">${response.coverage_percentage}%</td>
                                </tr>`;
                    
                    if (response.requires_approval) {
                        html += `
                                <tr class="bg-warning">
                                    <td colspan="2" class="text-center">
                                        <small><i class="fas fa-exclamation-triangle"></i> هذه الخدمة تحتاج موافقة مسبقة</small>
                                    </td>
                                </tr>`;
                    }
                } else {
                    html += `
                                <tr class="bg-light">
                                    <td colspan="2" class="text-center text-muted">
                                        <small>بدون تأمين - المريض يدفع الكل</small>
                                    </td>
                                </tr>`;
                }
                
                html += `
                            </table>
                        </div>
                    </div>
                `;
                
                $('#' + breakdownId).html(html);
            } else {
                $('#' + breakdownId).html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> ${response.error}
                    </div>
                `);
            }
        },
        error: function() {
            $('#' + breakdownId).html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> خطأ في حساب السعر
                </div>
            `);
        }
    });
}

// تحديث السعر عند تغيير الخدمة
function updateServicePrice(serviceId) {
    var $option = $('input[type="checkbox"][value="' + serviceId + '"]:checked');
    if ($option.length > 0) {
        var serviceName = $option.data('name');
        var defaultPrice = parseFloat($option.data('price')) || 0;
        var patientId = $('#modal_patient_id').val();
        var companyId = $('#modal_company_select').val() || 0;
        
        calculateDynamicPrice(
            patientId,
            companyId,
            'Laboratory',
            serviceName,
            defaultPrice,
            'price_display_' + serviceId,
            'price_breakdown_' + serviceId
        );
    }
}

// حساب الإجمالي ديناميكياً
function calculateTotalWithInsurance() {
    var patientId = $('#modal_patient_id').val();
    var companyId = $('#modal_company_select').val() || 0;
    var total = 0;
    var breakdown = '';
    
    $('.lab-test-checkbox:checked').each(function() {
        var price = parseFloat($(this).data('price')) || 0;
        total += price;
        breakdown += $(this).data('name') + ': ' + price.toFixed(2) + '\n';
    });
    
    // عرض الإجمالي
    $('#labRequestTotal').text(total.toFixed(2) + ' SDG');
    $('#amount_paid_input').val(total.toFixed(2));
    
    // حساب التأمين
    if (companyId > 0 && patientId) {
        $.ajax({
            url: 'ajax_calculate_price.php',
            method: 'GET',
            dataType: 'json',
            data: {
                patient_id: patientId,
                company_id: companyId,
                service_type: 'Laboratory',
                service_name: 'فحوصات مختبر',
                default_price: total,
                total_amount: total
            },
            success: function(response) {
                if (response.success) {
                    // عرض توزيع التكلفة
                    var html = `
                        <div class="card border-0 bg-light">
                            <div class="card-body p-3">
                                <h6>توزيع التكاليف:</h6>
                                <table class="table table-sm mb-0">
                                    <tr>
                                        <td>الإجمالي:</td>
                                        <td class="text-right"><strong>${total.toFixed(2)}</strong></td>
                                    </tr>
                                    <tr class="bg-info text-white">
                                        <td>المريض يدفع:</td>
                                        <td class="text-right"><strong>${response.patient_responsibility.toFixed(2)}</strong></td>
                                    </tr>
                                    <tr class="bg-success text-white">
                                        <td>التأمين يدفع:</td>
                                        <td class="text-right"><strong>${response.insurance_responsibility.toFixed(2)}</strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    `;
                    $('#insurance_breakdown').html(html);
                }
            }
        });
    } else {
        $('#insurance_breakdown').html(`
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i> اختر شركة تأمين لعرض توزيع التكاليف
            </div>
        `);
    }
}

// تحديث عند اختيار الشركة
$(document).ready(function() {
    // عند تغيير الشركة
    $(document).on('change', '#modal_company_select', function() {
        calculateTotalWithInsurance();
    });
    
    // عند تغيير الفحوصات
    $(document).on('change', '.lab-test-checkbox', function() {
        calculateTotalWithInsurance();
    });
});
