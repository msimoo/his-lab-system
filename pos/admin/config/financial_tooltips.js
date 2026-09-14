/**
 * نظام Tooltips والـ Alerts ذاتي الإخفاء بعد 10 دقائق
 * Auto-Hide System for Financial Explanatory Text
 */

(function($) {
    if (!$) {
        console.warn('financial_tooltips.js: jQuery not found. Tooltips and alerts are disabled.');
        return;
    }

    $(function() {
        // 1. تفعيل جميع tooltips في Bootstrap
        if ($.fn.tooltip) {
            $('[data-toggle="tooltip"]').tooltip({
                trigger: 'hover',
                delay: { show: 200, hide: 100 },
                placement: 'auto',
                html: true
            });
        }
        
        // 2. معالجة Alerts ذاتية الإخفاء
        // جميع الـ alerts مع class alert-auto-hide ستختفي بعد 10 دقائق
        $('[data-auto-hide]').each(function() {
            const element = $(this);
            const ms = parseInt(element.data('auto-hide')) || 600000; // 10 دقائق افتراضياً
            
            setTimeout(function() {
                element.fadeOut(300, function() {
                    $(this).remove();
                });
            }, ms);
        });
        
        // 3. معالجة الـ Info Tooltips مع Auto-Hide
        // tooltips بـ class help-tooltip ستختفي بعد 10 دقائق تلقائياً
        $('.help-tooltip').each(function() {
            const tooltip = $(this);
            
            // تفعيل tooltip عند التمرير
            tooltip.tooltip();
            
            // إغلاق tooltip بعد 10 دقائق
            setTimeout(function() {
                try {
                    tooltip.tooltip('dispose');
                    tooltip.fadeOut(300, function() {
                        $(this).remove();
                    });
                } catch(e) {
                    console.warn('Could not dispose tooltip:', e);
                }
            }, 10 * 60 * 1000); // 10 دقائق
        });
        
        // 4. معالجة الـ Alert Box ذاتية الإخفاء
        // متوافق مع alerts البوتستراب
        $('.alert.alert-dismissible').each(function() {
            const alert = $(this);
            
            // إذا كان لديه data-auto-hide، استخدم القيمة
            if (alert.data('auto-hide')) {
                const ms = parseInt(alert.data('auto-hide'));
                setTimeout(function() {
                    alert.fadeOut(500, function() {
                        $(this).remove();
                    });
                }, ms);
            } else if (alert.data('auto-hide') !== false) {
                // إغلاء تلقائي بعد 10 دقائق لأي alert
                setTimeout(function() {
                    alert.fadeOut(500, function() {
                        $(this).remove();
                    });
                }, 10 * 60 * 1000);
            }
        });
        
        // 5. نظام Explanatory Messages (شرح العمليات)
        // التعامل مع الرسائل التوضيحية في النماذج المختلفة
        $('[data-explain]').each(function() {
            const elem = $(this);
            const message = elem.data('explain');
            
            // إنشاء popover مع الرسالة التوضيحية
            elem.attr({
                'data-toggle': 'popover',
                'data-content': message,
                'data-trigger': 'hover',
                'data-placement': 'top',
                'data-html': 'true'
            });
            
            if ($.fn.popover) {
                elem.popover();
            }
        });
        
        // 6. Toast Notifications - رسائل إشعار سريعة
        window.showToast = function(message, type = 'info', duration = 5000) {
            const toastId = 'toast_' + Date.now();
            const className = `alert-${type}`;
            
            const toast = $(`
                <div id="${toastId}" class="alert ${className} alert-dismissible fade show" role="alert" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                    ${message}
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            `);
            
            $('body').append(toast);
            
            setTimeout(function() {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
            
            return toastId;
        };
        
        // 7. نظام الرسائل المرتبطة بالعمليات المالية
        // عند ظهور رسالة تتعلق بعملية مالية، يتم عرضها مع شرح صغير
        window.showFinancialMessage = function(message, explanation, duration = 10 * 60 * 1000) {
            const msgId = 'fin_msg_' + Date.now();
            const fullMessage = `
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>${message}</div>
                    <small style="margin-right: 10px; opacity: 0.7;" title="${explanation}">
                        <i class="fas fa-question-circle" style="cursor: help;"></i>
                    </small>
                </div>
            `;
            
            const alert = $(`
                <div id="${msgId}" class="alert alert-info alert-dismissible fade show" role="alert" data-auto-hide="${duration}">
                    ${fullMessage}
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            `);
            
            $('body').prepend(alert);
            
            // تطبيق auto-hide
            setTimeout(function() {
                alert.fadeOut(500, function() {
                    $(this).remove();
                });
            }, duration);
        };
        
        // 8. رسائل تفاعلية للعمليات الطويلة
        window.ProgressMessage = {
            show: function(title, subtitle) {
                const id = 'progress_' + Date.now();
                const msg = $(`
                    <div id="${id}" class="alert alert-warning alert-dismissible" role="alert" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong>${title}</strong>
                            <small class="d-block" style="margin-top: 5px;">${subtitle}</small>
                            <div class="progress" style="height: 3px; margin-top: 8px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%;"></div>
                            </div>
                        </div>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span>&times;</span>
                        </button>
                    </div>
                `);
                
                $('body').prepend(msg);
                return id;
            },
            hide: function(id) {
                $(`#${id}`).fadeOut(300, function() {
                    $(this).remove();
                });
            }
        };
    });

    // نظام التنبيهات المالية عند حدوث عمليات
    window.notifyFinancialTransaction = function(type, amount, description) {
        /**
         * type: 'clinic', 'lab', 'expense', 'shift_open', 'shift_close'
         * amount: المبلغ المالي
         * description: وصف العملية
         */
        
        const icons = {
            'clinic': '<i class="fas fa-hospital-user text-info"></i>',
            'lab': '<i class="fas fa-flask text-warning"></i>',
            'expense': '<i class="fas fa-money-bill-wave text-danger"></i>',
            'shift_open': '<i class="fas fa-cash-register text-success"></i>',
            'shift_close': '<i class="fas fa-money-check text-primary"></i>'
        };
        
        const message = `
            ${icons[type] || '<i class="fas fa-receipt"></i>'} 
            ${description} - ${amount} ر.س
        `;
        
        showToast(message, 'info', 5000);
    };
})(window.jQuery);

