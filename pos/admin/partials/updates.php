<div class="card product-card shadow">
   </div>


Pos content


<button type="button" class="btn btn-sm btn-primary" onclick="addToCart('<?php echo $prod->prod_id; ?>')">
    <i class="fas fa-cart-plus"></i> إضافة للسلة
</button>








Pos
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. منطق البحث والفلترة اللحظية (Real-time Product Search)
    var searchInput = document.getElementById('productSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var query = this.value.toLowerCase().trim();
            // جلب جميع كروت المنتجات المعروضة في الصفحة
            var productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(function(card) {
                // جلب النص الكامل داخل الكارت (الاسم، الكود، السعر)
                var cardText = card.textContent.toLowerCase();
                
                // البحث عن العمود الأب المحتوي على الكارت للحفاظ على التصميم المتناسق (Grid Row)
                var columnWrapper = card.closest('[class*="col-"]');
                
                if (cardText.includes(query)) {
                    // إذا كان المنتج يطابق البحث أو إذا كان البحث فارغاً، يتم إظهاره فوراً
                    if (columnWrapper) columnWrapper.style.display = '';
                    card.style.display = '';
                } else {
                    // إذا كان لا يطابق، يتم إخفاؤه
                    if (columnWrapper) columnWrapper.style.display = 'none';
                    card.style.display = 'none';
                }
            });
        });
    }

    // تعيين وظيفة إضافة المنتج عالمياً للاستدعاء من الكروت
    window.addToCart = function(productId) {
        var url = 'cart_api.php?action=add&prod_id=' + encodeURIComponent(productId);
        
        fetch(url)
            .then(function(response) {
                return response.json();
            })
            .then(function(resp) {
                if (resp.success) {
                    // تحديث محتوى السلة الجانبية تلقائياً بالـ HTML الجديد
                    var cartArea = document.getElementById('cartArea');
                    if (cartArea) {
                        cartArea.innerHTML = resp.html;
                    }
                    
                    // تحديث شارة العداد العلوي لعدد العناصر
                    var badge = document.getElementById('cartBadge');
                    if (badge && resp.count !== undefined) {
                        badge.textContent = resp.count;
                    }
                    
                    // إعادة تفعيل مستمعي الأحداث داخل السلة (مثل زر الحذف من السلة)
                    rebindCartEvents();
                } else {
                    // عرض التنبيهات الآمنة المرجعة من الخلفية (مثل كمية غير كافية)
                    alert(resp.message);
                }
            })
            .catch(function(error) {
                console.error('خطأ أثناء إضافة المنتج للسلة:', error);
            });
    };

    // دالة لتحديث مستمعي الأحداث لأزرار الحذف داخل السلة المحدثة ديناميكياً
    function rebindCartEvents() {
        document.querySelectorAll('.cart-remove').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var id = this.getAttribute('data-id');
                fetch('cart_api.php?action=remove&prod_id=' + encodeURIComponent(id))
                    .then(r => r.json())
                    .then(function(resp) {
                        if (resp.success) {
                            var cartArea = document.getElementById('cartArea');
                            if (cartArea) cartArea.innerHTML = resp.html;
                            var badge = document.getElementById('cartBadge');
                            if (badge) badge.textContent = resp.count;
                            rebindCartEvents();
                        }
                    });
            });
        });
    }
});
</script>





Top nav


<form class="navbar-search navbar-search-dark form-inline mr-3 d-none d-md-flex ml-lg-auto" onsubmit="event.preventDefault();">
    <div class="form-group mb-0">
        <div class="input-group input-group-alternative">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
            </div>
            <input class="form-control" id="productSearchInput" placeholder="ابحث عن منتج بالاسم أو الكود..." type="text" autocomplete="off">
        </div>
    </div>
</form>

