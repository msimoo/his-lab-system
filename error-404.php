<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>خطأ 404 — الصفحة غير موجودة</title>
    <link href="admin/assets/fonts/Tajawal-Bold.ttf" rel="stylesheet">
     <style>
    @font-face {
        font-family: 'Tajawal';
        src: url('admin/assets/fonts/Tajawal-Regular.ttf') format('truetype');
        font-weight: 400;
        font-style: normal;
      }
    @font-face {
        font-family: 'Tajawal';
        src: url('admin/assets/fonts/Tajawal-Medium.ttf') format('truetype');
        font-weight: 500;
        font-style: normal;
      }
    @font-face {
        font-family: 'Tajawal';
        src: url('admin/assets/fonts/Tajawal-Bold.ttf') format('truetype');
        font-weight: 700;
        font-style: normal;
      }
      
        :root{--bg1:#f5f7ff;--accent:#6c63ff;--muted:#7b8aa3}
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;font-family:'Tajawal',Poppins,system-ui,Arial;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#eef2ff 0%,#f8fbff 100%);padding:24px}
        .card{max-width:920px;width:100%;display:grid;grid-template-columns:1fr 420px;gap:28px;align-items:center}
        .visual{background:linear-gradient(180deg,rgba(108,99,255,0.12),rgba(108,99,255,0.04));border-radius:16px;padding:36px;color:#17223b;display:flex;flex-direction:column;align-items:flex-start;gap:18px;min-height:280px}
        .visual .big{font-size:72px;font-weight:800;color:var(--accent);letter-spacing:2px}
        .visual p{margin:0;color:var(--muted);font-size:16px}
        .art{margin-left:auto;display:flex;align-items:center;justify-content:center;width:100%}
        .art svg{max-width:260px;width:100%;height:auto;opacity:0.95}

        .panel{background:#fff;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(16,24,40,0.08)}
        .panel h1{margin:0;font-size:22px;color:#122235}
        .panel p{margin:10px 0 18px;color:var(--muted)}

        .btn-row{display:flex;justify-content:center;gap:12px;margin-top:8px}
        .btn{display:inline-flex;align-items:center;gap:10px;padding:12px 18px;border-radius:10px;border:none;cursor:pointer;font-weight:600}
        .btn.home{background:linear-gradient(90deg,var(--accent),#3b8dff);color:#fff;box-shadow:0 10px 28px rgba(108,99,255,0.18)}
        .btn.refresh{background:transparent;color:var(--accent);border:1px solid rgba(108,99,255,0.12)}

        .links{display:flex;justify-content:center;margin-top:14px;gap:18px;color:#8b96a8;font-size:14px}

        @media (max-width:920px){.card{grid-template-columns:1fr;max-width:420px}.visual{order:2}.art{justify-content:flex-start}}
    </style>
</head>
<body>
    <main class="card" role="main">
        <section class="visual" aria-hidden="true">
            <div class="big">404</div>
            <p>عذراً، الصفحة التي تبحث عنها غير موجودة أو قد تم نقلها.</p>
            <div class="art">
                <!-- Simple friendly illustration -->
                <svg viewBox="0 0 300 200" xmlns="http://www.w3.org/2000/svg" role="img">
                    <defs>
                        <linearGradient id="g1" x1="0" x2="1">
                            <stop offset="0" stop-color="#6c63ff" stop-opacity="0.9"/>
                            <stop offset="1" stop-color="#3b8dff" stop-opacity="0.9"/>
                        </linearGradient>
                    </defs>
                    <rect x="10" y="40" width="220" height="120" rx="12" fill="#fff" opacity="0.12"/>
                    <path d="M40 120c20-22 60-22 80 0" stroke="#cfe1ff" stroke-width="6" fill="none" stroke-linecap="round"/>
                    <circle cx="210" cy="70" r="36" fill="url(#g1)" opacity="0.95"/>
                    <g transform="translate(196,56)" fill="#fff" font-weight="700">
                        <text x="0" y="6" font-size="24">404</text>
                    </g>
                </svg>
            </div>
        </section>

        <aside class="panel" aria-labelledby="title-404">
            <h1 id="title-404">الصفحة غير موجودة</h1>
            <p>ربما تم حذف الرابط أو تغير عنوانه. يمكنك العودة إلى الصفحة الرئيسية أو تحديث هذه الصفحة.</p>

            <div class="btn-row">
                <a class="btn home" href="../../index.php" title="العودة إلى الصفحة الرئيسية">الرئيسية</a>
                <button class="btn refresh" onclick="location.reload();" title="إعادة تحميل الصفحة">تحديث</button>
            </div>
                
        </aside>
    </main>
</body>
</html>
