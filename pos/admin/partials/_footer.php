<style>
/* ============================================================
   MODERN FOOTER — Glassmorphism + Colored Icons
   ============================================================ */
.footer-modern{
    position: relative;
    overflow: hidden;
    margin-top: 40px;
    padding: 0;
    background:
        radial-gradient(circle at 12% 20%, rgba(139,92,246,.25), transparent 45%),
        radial-gradient(circle at 88% 80%, rgba(6,182,212,.22), transparent 45%),
        radial-gradient(circle at 50% 0%, rgba(236,72,153,.12), transparent 55%),
        linear-gradient(135deg, #0b1220 0%, #111a2e 45%, #1a1a2e 100%);
    color: #fff;
    isolation: isolate;
    border-radius: 32px 32px 0 0;
}

/* Animated gradient ribbon on top */
.footer-modern::before{
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg,
        #06b6d4, #8b5cf6, #ec4899, #f59e0b, #10b981, #06b6d4);
    background-size: 300% 100%;
    animation: footerRibbon 6s linear infinite;
    z-index: 2;
}
@keyframes footerRibbon{
    0%   { background-position: 0% 50%; }
    100% { background-position: 300% 50%; }
}

/* Floating decorative orbs */
.footer-orb{
    position: absolute;
    border-radius: 50%;
    filter: blur(50px);
    opacity: .35;
    pointer-events: none;
    z-index: 0;
    animation: footerOrb 18s ease-in-out infinite;
}
.footer-orb.o1{
    width: 280px; height: 280px;
    top: -120px; left: -80px;
    background: radial-gradient(circle, #8b5cf6, transparent 70%);
}
.footer-orb.o2{
    width: 240px; height: 240px;
    bottom: -110px; right: -60px;
    background: radial-gradient(circle, #06b6d4, transparent 70%);
    animation-delay: -6s;
}
.footer-orb.o3{
    width: 180px; height: 180px;
    top: 30%; right: 35%;
    background: radial-gradient(circle, #ec4899, transparent 70%);
    opacity: .18;
    animation-delay: -12s;
}
@keyframes footerOrb{
    0%,100%{ transform: translate3d(0,0,0) scale(1); }
    50%    { transform: translate3d(20px,-24px,0) scale(1.1); }
}

/* Container */
.footer-inner{
    position: relative;
    z-index: 1;
    padding: 20px 34px 20px;
}

/* ============================================================
   BRAND BLOCK
   ============================================================ */
.footer-brand-wrap{
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 18px;
}
.footer-brand-icon{
    position: relative;
    width: 56px; height: 56px;
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
    color: #fff;
    background: linear-gradient(135deg, #ec4899, #f43f5e 55%, #f59e0b);
    box-shadow:
        0 12px 28px rgba(236,72,153,.4),
        inset 0 0 0 1px rgba(255,255,255,.15);
    flex: 0 0 auto;
    overflow: hidden;
}
.footer-brand-icon::after{
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.28), transparent);
    transform: translateX(-100%);
    animation: brandShine 4s ease-in-out infinite;
}
@keyframes brandShine{
    0%, 60% { transform: translateX(-100%); }
    100%    { transform: translateX(100%); }
}
.footer-brand-icon .pulse-ring{
    position: absolute;
    inset: -6px;
    border-radius: 22px;
    border: 2px solid rgba(236,72,153,.5);
    animation: brandPulse 2s ease-in-out infinite;
    pointer-events: none;
}
@keyframes brandPulse{
    0%   { transform: scale(1);    opacity: .8; }
    100% { transform: scale(1.35); opacity: 0; }
}

.footer-brand-text-wrap{ display: flex; flex-direction: column; gap: 3px; }
.footer-brand-text{
    font-weight: 900;
    font-size: 1.05rem;
    color: #fff;
    letter-spacing: .3px;
    line-height: 1.2;
}
.footer-brand-tagline{
    font-size: .74rem;
    font-weight: 700;
    color: rgba(255,255,255,.55);
    letter-spacing: .4px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.footer-brand-tagline i{
    color: #10b981;
    font-size: .6rem;
    animation: livePulse 2s ease-in-out infinite;
}
@keyframes livePulse{
    0%,100%{ opacity: 1; }
    50%    { opacity: .35; }
}

/* ============================================================
   CONTACT CHIPS
   ============================================================ */
.footer-chips{
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}
.footer-chip{
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.10);
    border-radius: 999px;
    padding: 7px 14px 7px 7px;
    font-size: .8rem;
    font-weight: 700;
    color: rgba(255,255,255,.85);
    text-decoration: none;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}
.footer-chip::before{
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.12), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.footer-chip:hover::before{ transform: translateX(100%); }
.footer-chip:hover{
    transform: translateY(-3px);
    color: #fff;
    text-decoration: none;
    border-color: rgba(255,255,255,.22);
    box-shadow: 0 12px 26px rgba(0,0,0,.35);
    background: rgba(255,255,255,.11);
}
.footer-chip .fc-ico{
    width: 28px; height: 28px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .78rem;
    color: #fff;
    transition: transform .3s cubic-bezier(.34,1.56,.64,1);
    flex: 0 0 auto;
}
.footer-chip:hover .fc-ico{ transform: scale(1.12) rotate(-6deg); }

/* Colored chip icons */
.fc-ico.fc-dev  { background: linear-gradient(135deg, #8b5cf6, #6366f1); box-shadow: 0 6px 14px rgba(139,92,246,.35); }
.fc-ico.fc-phone{ background: linear-gradient(135deg, #10b981, #06b6d4); box-shadow: 0 6px 14px rgba(16,185,129,.35); }
.fc-ico.fc-mail { background: linear-gradient(135deg, #f59e0b, #f97316); box-shadow: 0 6px 14px rgba(245,158,11,.35); }

/* ============================================================
   LOCATION CARD
   ============================================================ */
.footer-location{
    display: inline-flex;
    align-items: center;
    gap: 12px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.10);
    border-radius: 16px;
    padding: 14px 18px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all .3s ease;
    max-width: 100%;
}
.footer-location:hover{
    background: rgba(255,255,255,.09);
    border-color: rgba(255,255,255,.22);
    transform: translateY(-2px);
}
.footer-location .loc-ico{
    width: 42px; height: 42px;
    border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #f43f5e, #ec4899);
    color: #fff;
    font-size: 1rem;
    box-shadow: 0 10px 22px rgba(244,63,94,.35);
    flex: 0 0 auto;
}
.footer-location .loc-text{ display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.footer-location .loc-label{
    font-size: .68rem;
    font-weight: 800;
    color: rgba(255,255,255,.5);
    text-transform: uppercase;
    letter-spacing: .8px;
}
.footer-location .loc-value{
    font-size: .88rem;
    font-weight: 800;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ============================================================
   DIVIDER
   ============================================================ */
.footer-divider{
    position: relative;
    height: 1px;
    margin: 26px 0 18px;
    background: linear-gradient(90deg,
        transparent, rgba(255,255,255,.14), rgba(255,255,255,.05), transparent);
    border: none;
}
.footer-divider::before{
    content: '';
    position: absolute;
    top: -1px; left: 50%;
    transform: translateX(-50%);
    width: 80px; height: 3px;
    border-radius: 3px;
    background: linear-gradient(90deg, #06b6d4, #8b5cf6, #ec4899);
    box-shadow: 0 0 20px rgba(139,92,246,.5);
}

/* ============================================================
   BOTTOM BAR
   ============================================================ */
.footer-bottom{
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    padding: 4px 0 2px;
}
.footer-copyright{
    font-size: .78rem;
    font-weight: 700;
    color: rgba(255,255,255,.55);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.footer-copyright a{
    color: #f43f5e;
    font-weight: 800;
    text-decoration: none;
    transition: all .3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 4px;
    border-radius: 6px;
}
.footer-copyright a:hover{
    color: #fff;
    text-shadow: 0 0 20px rgba(244,63,94,.6);
    background: rgba(244,63,94,.12);
    text-decoration: none;
}
.footer-copyright .heart{
    color: #f43f5e;
    animation: heartBeat 1.5s ease-in-out infinite;
}
@keyframes heartBeat{
    0%,100%{ transform: scale(1); }
    15%    { transform: scale(1.25); }
    30%    { transform: scale(1); }
    45%    { transform: scale(1.18); }
    60%    { transform: scale(1); }
}

/* ============================================================
   SCROLL TO TOP BUTTON
   ============================================================ */
.footer-top-btn{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: auto;
    height: 42px;
    padding: 0 18px;
    border-radius: 999px;
    background: linear-gradient(135deg, #06b6d4, #8b5cf6);
    color: #fff;
    font-weight: 800;
    font-size: .78rem;
    text-decoration: none;
    cursor: pointer;
    border: none;
    box-shadow: 0 10px 24px rgba(6,182,212,.4);
    transition: all .3s cubic-bezier(.4,0,.2,1);
    font-family: inherit;
    position: relative;
    overflow: hidden;
}
.footer-top-btn::before{
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent, rgba(255,255,255,.25), transparent);
    transform: translateX(-100%);
    transition: transform .7s ease;
}
.footer-top-btn:hover::before{ transform: translateX(100%); }
.footer-top-btn:hover{
    transform: translateY(-3px);
    box-shadow: 0 16px 32px rgba(6,182,212,.55);
    color: #fff;
    text-decoration: none;
}
.footer-top-btn i{ font-size: .8rem; }
.footer-top-btn .arrow{
    display: inline-block;
    transition: transform .3s ease;
}
.footer-top-btn:hover .arrow{
    transform: translateY(-3px);
    animation: arrowBounce 1s ease-in-out infinite;
}
@keyframes arrowBounce{
    0%,100%{ transform: translateY(-3px); }
    50%    { transform: translateY(-7px); }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 991px){
    .footer-inner{ padding: 34px 24px 18px; }
    .footer-brand-text{ font-size: .98rem; }
}
@media (max-width: 768px){
    .footer-modern{ border-radius: 24px 24px 0 0; }
    .footer-inner{ padding: 28px 18px 16px; text-align: center; }
    .footer-brand-wrap{ justify-content: center; }
    .footer-chips{ justify-content: center; }
    .footer-location{ width: 100%; justify-content: center; }
    .footer-bottom{
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .footer-copyright{ justify-content: center; }
}
@media (max-width: 480px){
    .footer-brand-wrap{ flex-direction: column; gap: 10px; }
    .footer-brand-icon{ width: 48px; height: 48px; font-size: 1.3rem; }
    .footer-brand-text{ font-size: .92rem; }
    .footer-chip{ font-size: .74rem; padding: 6px 12px 6px 6px; }
    .footer-chip .fc-ico{ width: 24px; height: 24px; font-size: .7rem; }
    .footer-copyright{ font-size: .72rem; }
    .footer-top-btn{ width: 100%; justify-content: center; }
}
</style>

<footer class="footer-modern">
    <!-- Decorative floating orbs -->
    <span class="footer-orb o1"></span>
    <span class="footer-orb o2"></span>
    <span class="footer-orb o3"></span>

    <div class="footer-inner">
        <div class="row align-items-center g-3">

            <!-- LEFT: Brand + Contact chips -->
            <div class="col-lg-12 col-md-12" dir="rtl">
                <div class="footer-brand-wrap">
                    <div class="footer-brand-icon">
                        <i class="fas fa-heartbeat"></i>
                        <span class="pulse-ring"></span>
                    </div>
                    <div class="footer-brand-text-wrap">
                        <span class="footer-brand-text">نظام الواحات الصحي</span>
                        <span class="footer-brand-tagline">
                            <i class="fas fa-circle"></i> نظام طبي متكامل يعمل 24/7
                        </span>
                    </div>
                <?php
                if (!isset($companyAddress)) {
                    if (function_exists('getSetting')) {
                        $companyAddress = getSetting('company_address', 'الخرطوم، السودان');
                    } else {
                        $companyAddress = 'الخرطوم، السودان';
                    }
                }
                ?>
                    <div class="footer-location">
                        <div class="loc-ico"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="loc-text">
                            <span class="loc-label">مقر العمل</span>
                            <span class="loc-value"><?php echo htmlspecialchars((string) $companyAddress); ?></span>
                        </div>
                    </div>
                </div>

                <div class="footer-chips">
                    <a href="https://elsamani.rf.gd/?i=1" target="_blank" class="footer-chip" title="زيارة الموقع">
                        <span class="fc-ico fc-dev"><i class="fas fa-code"></i></span>
                        <span>Mohamed Omer Elsamani</span>
                    </a>
                    <a href="tel:+249127941569" class="footer-chip" title="اتصل بنا">
                        <span class="fc-ico fc-phone"><i class="fas fa-phone-alt"></i></span>
                        <span dir="ltr">+249 127 941 569</span>
                    </a>
                <button type="button" class="footer-top-btn" onclick="window.scrollTo({top:0,behavior:'smooth'});" aria-label="Back to top">
                        <i class="fas fa-arrow-up arrow"></i>
                        <span>أعلى الصفحة</span>
                </button>
                </div>
            </div>

            <!-- RIGHT: Location + Scroll to top 
            <div class="col-lg-6 col-md-12" dir="rtl">
               
                <div class="d-flex justify-content-lg-end justify-content-center align-items-center gap-3 flex-wrap">
                    

                    
                </div>
            </div>-->
        </div>

        <!-- Divider -->
        <div class="footer-divider"></div>

        <!-- Bottom bar -->
        <div class="footer-bottom">
            <div class="footer-copyright" style="opacity:.65;">
                <i class="fas fa-shield-alt" style="color:#10b981;"></i>
                <span>نظام آمن ومُعتمد</span>
                <span style="opacity:.35;">•</span>
                <i class="fas fa-bolt" style="color:#f59e0b;"></i>
                <span>أداء عالي</span>
            </div>
            <div class="footer-copyright">
                <span>&copy; <?php echo date('Y'); ?> — جميع الحقوق محفوظة</span>
                <span style="opacity:.35;">|</span>
                <span>
                    صُنع بـ <i class="fas fa-heart heart"></i> بواسطة
                    <a href="https://elsamani.rf.gd/?i=1" target="_blank">
                        <i class="fas fa-code"></i> Mohamed Omer Elsamani
                    </a>
                </span>
            </div>
        </div>
    </div>
</footer>