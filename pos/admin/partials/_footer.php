<style>
/* ===== Enhanced Footer Design ===== */
.footer-modern {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    padding: 0;
    position: relative;
    overflow: hidden;
    margin-top: 30px;
}
.footer-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #e94560, #0f3460, #533483, #e94560);
    background-size: 300% 100%;
    animation: footerGradient 4s ease infinite;
}
@keyframes footerGradient {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
.footer-modern::after {
    content: '';
    position: absolute;
    bottom: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(233, 69, 96, 0.06), transparent 70%);
    pointer-events: none;
}
.footer-modern .footer-inner {
    padding: 20px 30px;
    position: relative;
    z-index: 1;
}
.footer-modern .footer-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}
.footer-modern .footer-brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #e94560;
}
.footer-modern .footer-brand-text {
    font-weight: 700;
    font-size: 14px;
    color: #fff;
    letter-spacing: 0.3px;
}
.footer-modern .footer-copyright {
    font-size: 13px;
    color: rgba(255,255,255,0.6);
}
.footer-modern .footer-copyright a {
    color: #e94560;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}
.footer-modern .footer-copyright a:hover {
    color: #fff;
    text-shadow: 0 0 20px rgba(233, 69, 96, 0.4);
}
.footer-modern .footer-dev {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 14px;
    background: rgba(255,255,255,0.06);
    border-radius: 20px;
    font-size: 12px;
    color: rgba(255,255,255,0.7);
    transition: all 0.3s ease;
}
.footer-modern .footer-dev:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}
.footer-modern .footer-dev i {
    color: #e94560;
    font-size: 14px;
}
.footer-modern .footer-address {
    font-size: 13px;
    color: rgba(255,255,255,0.5);
    display: flex;
    align-items: center;
    gap: 8px;
}
.footer-modern .footer-address i {
    color: #e94560;
    font-size: 14px;
}
.footer-modern .footer-divider {
    border-color: rgba(255,255,255,0.06);
    margin: 10px 0;
}
.footer-modern .footer-bottom {
    font-size: 11px;
    color: rgba(255,255,255,0.3);
    text-align: center;
    padding: 8px 0;
}
.footer-modern .footer-bottom i {
    color: #e94560;
    font-size: 10px;
    animation: heartBeat 1.5s ease infinite;
}
@keyframes heartBeat {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}

@media (max-width: 768px) {
    .footer-modern .footer-inner {
        padding: 15px;
        text-align: center;
    }
    .footer-modern .footer-copyright {
        margin-bottom: 10px;
    }
}
</style>

<footer class="footer-modern">
    <div class="footer-inner">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="footer-brand">
                        <div class="footer-brand-icon">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <span class="footer-brand-text">نظام الواحات الصحي</span>
                    </div>
                    <div class="footer-copyright">
                        &copy; 2025 - <?php echo date('Y'); ?> 
                        <a href="https://elsamani.rf.gd/?i=1" target="_blank">
                            <i class="fas fa-code"></i> Mohamed Omer Elsamani
                        </a>
                        <span class="mx-2" style="opacity: 0.3;">|</span>
                        <span class="footer-dev">
                            <i class="fas fa-phone-alt"></i> +249127941569
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-md-left mt-2 mt-md-0">
                <?php
                if (!isset($companyAddress)) {
                    if (function_exists('getSetting')) {
                        $companyAddress = getSetting('company_address', 'الخرطوم، السودان');
                    } else {
                        $companyAddress = 'الخرطوم، السودان';
                    }
                }
                ?>
                <div class="footer-address justify-content-md-end justify-content-center">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?php echo htmlspecialchars((string) $companyAddress); ?></span>
                </div>
            </div>
        </div>
        <hr class="footer-divider">
        <div class="footer-bottom">
            <i class="fas fa-heart"></i> 
            <span>جميع الحقوق محفوظة &mdash; تصميم وتطوير محمد عمر السماني</span>
        </div>
    </div>
</footer>
