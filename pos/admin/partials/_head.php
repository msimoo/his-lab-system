<?php
if (session_status() === PHP_SESSION_NONE) {
    include __DIR__ . "/../../../session_init.php";
}
include_once dirname(__DIR__) . '/config/languages.php';
if (!headers_sent()) {
    ob_start();
}

// في doctor_appointments.php (بعد checklogin.php):
include_once('config/financial_helpers.php');

// في patient.php (بعد checklogin.php):
include_once('config/financial_helpers.php');

// في expenses.php (بعد checklogin.php):
include_once('config/financial_helpers.php');

// في _head.php (قبل </head>):
?>
<!DOCTYPE html>
<html data-theme="light">
<head>
    <script>
    // Prevent FOUC: apply saved theme instantly before any rendering
    (function(){var t=localStorage.getItem('his_theme');if(t==='dark'){document.documentElement.setAttribute('data-theme','dark')}})();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="Al-Wahat Medical Center — Healthcare Information & Laboratory Management System">
    <meta name="author" content="Mohamed Omer Elsamani">
    <meta name="theme-color" content="#0f172a">
    <title>مركز الواحات الطبي — HIS & LIMS</title>
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/icons/favicon-16x16.png">
    <link rel="manifest" href="assets/img/icons/site.webmanifest">
    <link rel="mask-icon" href="assets/img/icons/safari-pinned-tab.svg" color="#5bbad5">
    <meta name="msapplication-TileColor" content="#da532c">
    <meta name="theme-color" content="#ffffff">
    <!-- Fonts -->
   <style>
      @font-face {
        font-family: 'Tajawal';
        src: url('assets/fonts/Tajawal-Regular.ttf') format('truetype');
        font-weight: 400;
        font-style: normal;
      }
      @font-face {
        font-family: 'Tajawal';
        src: url('assets/fonts/Tajawal-Medium.ttf') format('truetype');
        font-weight: 500;
        font-style: normal;
      }
      @font-face {
        font-family: 'Tajawal';
        src: url('assets/fonts/Tajawal-Bold.ttf') format('truetype');
        font-weight: 700;
        font-style: normal;
      }</style>
    <link href="assets/fonts/Tajawal-Bold.ttf" rel="stylesheet">
     <style>
      body { font-family: 'Tajawal', 'Inter', 'Roboto', system-ui, Arial, sans-serif !important; }
      html { font-family: 'Tajawal', 'Inter', 'Roboto', sans-serif; }
    </style>
    <!-- Icons -->
    <link href="assets/vendor/nucleo/css/nucleo.css" rel="stylesheet">
    <link href="assets/vendor/@fortawesome/fontawesome-free/css/all.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="assets/css/external/select2.min.css" rel="stylesheet" />
    <link href="assets/css/external/bootstrap-select.min.css" rel="stylesheet" />  <!-- Argon CSS -->
    <link rel="stylesheet" type="text/css" href="assets/css/jquery.datatable.min.css">
    <link type="text/css" href="assets/css/argon.css?v=1.0.0" rel="stylesheet">
  <style>
    table.table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: #ffffff; 
      overflow: hidden;
      box-shadow: 0 24px 48px rgba(15, 23, 42, 0.08);
      margin-bottom: 1.5rem;
    }

    table.table thead {
      background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%) !important;
    }

    table.table thead th {
      background: transparent !important;
      color: #ffffff !important;
      font-weight: 700;
      border: none;
      padding: 1rem 1rem;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      font-size: 0.78rem;
    }

    table.table thead th:first-child {
      border-top-left-radius: 0;
    }

    table.table thead th:last-child {
      border-top-right-radius: 0;
    }

    table.table tbody tr:nth-child(odd) {
      background: #f8fafc;
    }

    table.table tbody tr:hover {
      background: #eef2ff;
    }

    table.table tbody td {
      padding: 0.95rem 1rem;
      border-top: 1px solid rgba(148, 163, 184, 0.2);
      vertical-align: middle;
      color: #2f3e53;
      font-size: 0.9rem;
    }

    table.table.table-sm tbody td,
    table.table-sm th,
    table.table-sm td {
      padding: 0.75rem 0.9rem;
    }

    table.table.table-bordered,
    table.table-bordered th,
    table.table-bordered td {
      border: 1px solid rgba(148, 163, 184, 0.2);
    }

    table.table thead th {
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .table-responsive { 
      display: block;
      width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
      background: white;
      margin-bottom: 1.5rem;
    }

    .table-responsive > .table {
      min-width: 100%;
    }

    /* Custom Scrollbar Styles for Professional Look */
    .custom-scrollbar {
      overflow: auto;
      max-height: 62vh;
      padding-right: 6px;
      border-radius: 18px;
      background: rgba(255,255,255,0.04);
      box-shadow: inset 0 0 18px rgba(0,0,0,.08);
    }
    .custom-scrollbar::-webkit-scrollbar {
      width: 10px;
      height: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
      background: rgba(255,255,255,0.08);
      border-radius: 10px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(255,255,255,0.35), rgba(255,255,255,0.15));
      border-radius: 999px;
      border: 2px solid rgba(255,255,255,0.05);
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(180deg, rgba(255,255,255,0.65), rgba(255,255,255,0.35));
    }
    .custom-scrollbar {
      scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,0.35) rgba(255,255,255,0.08);
    }
    .custom-scrollbar table {
      min-width: 100%;
    }
    .custom-scrollbar td,
    .custom-scrollbar th {
      white-space: nowrap;
    }

    :root, [data-theme="light"] {
      --bg-primary: #f0f4f8;
      --bg-secondary: #ffffff;
      --bg-tertiary: #f8fafc;
      --bg-card: #ffffff;
      --bg-card-header: linear-gradient(135deg, #0891b2, #6366f1);
      --bg-sidebar: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
      --bg-navbar: rgba(15, 23, 42, 0.97);
      --text-primary: #0f172a;
      --text-secondary: #475569;
      --text-muted: #94a3b8;
      --text-on-accent: #ffffff;
      --border-color: #e2e8f0;
      --border-light: rgba(0,0,0,0.06);
      --accent: #0891b2;
      --accent-hover: #0e7490;
      --accent-light: rgba(8,145,178,0.1);
      --accent-gradient: linear-gradient(135deg, #0891b2, #6366f1);
      --success: #059669;
      --danger: #dc2626;
      --warning: #d97706;
      --info: #0284c7;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
      --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
      --shadow-lg: 0 12px 40px rgba(0,0,0,0.1);
      --shadow-card: 0 1px 3px rgba(0,0,0,0.04), 0 4px 20px rgba(0,0,0,0.06);
      --radius-sm: 8px;
      --radius-md: 14px;
      --radius-lg: 20px;
      --radius-xl: 28px;
      --pos-bg: var(--bg-primary);
      --pos-surface: var(--bg-card);
      --pos-surface-strong: var(--bg-secondary);
      --pos-shadow: var(--shadow-md);
      --pos-shadow-hover: var(--shadow-lg);
      --pos-primary: var(--text-primary);
      --pos-accent: var(--accent);
      --pos-accent-soft: var(--accent-light);
      --pos-border: var(--border-color);
      --pos-text: var(--text-primary);
      --pos-radius: var(--radius-lg);
      --pos-transition: 0.25s ease;
      --table-stripe: rgba(248,250,252,0.65);
      --table-hover: rgba(8,145,178,0.05);
      --table-header-bg: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
      --modal-bg: var(--bg-card);
      --input-bg: #f9fafb;
      --input-border: #e2e8f0;
      --input-focus-border: #0891b2;
      --input-focus-shadow: 0 0 0 3px rgba(8,145,178,0.12);
      --scrollbar-bg: rgba(0,0,0,0.04);
      --scrollbar-thumb: rgba(0,0,0,0.15);
    }

    [data-theme="dark"] {
      --bg-primary: #0b1121;
      --bg-secondary: #111827;
      --bg-tertiary: #1e293b;
      --bg-card: #1e293b;
      --bg-card-header: linear-gradient(135deg, #0e7490, #4f46e5);
      --bg-sidebar: linear-gradient(180deg, #030712 0%, #0f172a 100%);
      --bg-navbar: rgba(3, 7, 18, 0.97);
      --text-primary: #f1f5f9;
      --text-secondary: #94a3b8;
      --text-muted: #64748b;
      --text-on-accent: #ffffff;
      --border-color: #334155;
      --border-light: rgba(255,255,255,0.06);
      --accent: #22d3ee;
      --accent-hover: #06b6d4;
      --accent-light: rgba(34,211,238,0.12);
      --accent-gradient: linear-gradient(135deg, #22d3ee, #818cf8);
      --success: #10b981;
      --danger: #ef4444;
      --warning: #f59e0b;
      --info: #38bdf8;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
      --shadow-md: 0 4px 16px rgba(0,0,0,0.35);
      --shadow-lg: 0 12px 40px rgba(0,0,0,0.45);
      --shadow-card: 0 1px 3px rgba(0,0,0,0.2), 0 4px 20px rgba(0,0,0,0.25);
      --pos-bg: var(--bg-primary);
      --pos-surface: var(--bg-card);
      --pos-surface-strong: var(--bg-secondary);
      --pos-shadow: var(--shadow-md);
      --pos-shadow-hover: var(--shadow-lg);
      --pos-primary: var(--text-primary);
      --pos-accent: var(--accent);
      --pos-accent-soft: var(--accent-light);
      --pos-border: var(--border-color);
      --pos-text: var(--text-primary);
      --table-stripe: rgba(30,41,59,0.5);
      --table-hover: rgba(34,211,238,0.06);
      --table-header-bg: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
      --modal-bg: var(--bg-card);
      --input-bg: #0f172a;
      --input-border: #334155;
      --input-focus-border: #22d3ee;
      --input-focus-shadow: 0 0 0 3px rgba(34,211,238,0.15);
      --scrollbar-bg: rgba(255,255,255,0.04);
      --scrollbar-thumb: rgba(255,255,255,0.15);
    }

    /* ── Global Theme Application ── */
    body {
      background: var(--bg-primary) !important;
      color: var(--text-primary);
      scroll-behavior: smooth;
      transition: background 0.3s ease, color 0.3s ease;
    }
    html {
      font-family: 'Tajawal', 'Inter', 'Roboto', sans-serif;
      background: var(--bg-primary);
    }
    #navbar-main {
      background: var(--bg-navbar);
      border-bottom: 1px solid var(--border-light);
      box-shadow: var(--shadow-md);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      transition: background 0.3s ease;
    }
    #navbar-main .navbar-brand {
      color: #ffffff;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    #navbar-main .nav-link,
    #navbar-main .navbar-text,
    #navbar-main .badge {
      color: rgba(255, 255, 255, 0.9) !important;
    }
    #navbar-main .nav-link:hover,
    #navbar-main .nav-link.active {
      color: #ffffff !important;
    }
    .card {
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      background: var(--bg-card);
      box-shadow: var(--shadow-card);
      transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.3s ease;
      overflow: hidden;
      color: var(--text-primary);
    }

    .page-hero {
      position: relative;
      padding: 2.3rem 2rem;
      border-radius: 2rem;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.12);
      box-shadow: 0 28px 68px rgba(15, 23, 42, 0.14);
      overflow: hidden;
      backdrop-filter: blur(18px);
      margin-bottom: 1.5rem;
    }

    .page-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at top right, rgba(236, 72, 153, 0.18), transparent 28%),
                  radial-gradient(circle at bottom left, rgba(14, 165, 233, 0.12), transparent 22%);
      pointer-events: none;
      z-index: 0;
    }

    .page-hero .page-hero-content {
      position: relative;
      z-index: 1;
    }

    .page-hero h1 {
      font-size: clamp(2rem, 2.3vw, 3.4rem);
      line-height: 1.05;
      letter-spacing: 0.01em;
      font-weight: 800;
      margin-bottom: 0.9rem;
    }

    .page-hero .hero-subtitle {
      font-size: 1rem;
      max-width: 860px;
      color: rgba(255, 255, 255, 0.87);
      margin-bottom: 1.1rem;
      line-height: 1.8;
    }

    .page-hero .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .page-hero .hero-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.65rem 1rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.16);
      color: #ffffff;
      font-size: 0.95rem;
      font-weight: 600;
      backdrop-filter: blur(12px);
    }

    .page-hero .hero-badge i {
      margin-right: 0.4rem;
      color: rgba(255, 255, 255, 0.8);
    

      will-change: transform, opacity;
    }
    .card:hover {
      transform: translateY(-3px);
      box-shadow: var(--pos-shadow-hover);
    }
    .card.card-stats {
      background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(248,251,255,0.98) 100%);
    }
    .card .card-header {
      background: transparent;
      border-bottom: none;
    }
    .card .card-body {
      position: relative;
      z-index: 1;
    }
    .card.card-stats .icon.icon-shape {
      animation: pulse-icon 4s ease-in-out infinite;
      box-shadow: 0 14px 30px rgba(59, 130, 246, 0.16);
      will-change: transform, box-shadow;
    }
    .card.card-stats:hover .icon.icon-shape {
      transform: translateY(-2px) scale(1.05);
    }
    .btn,
    .navbar-nav .nav-link,
    .dropdown-item,
    .form-control {
      will-change: transform, opacity;
    }
    .btn:hover,
    .navbar-nav .nav-link:hover,
    .dropdown-item:hover {
      cursor: pointer;
    }
    .count-up {
      display: inline-block;
      min-width: 4ch;
      color: var(--pos-text);
    }
    .motion-reveal {
      opacity: 0;
      transform: translateY(24px);
    }
    .motion-layout {
      transition: transform 0.35s ease, opacity 0.35s ease;
    }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
      }
      .card,
      .btn,
      .motion-layout,
      .motion-reveal {
        transition: none !important;
        animation: none !important;
      }
    }

    /* Anime.js enhanced styles */
    .btn {
      position: relative;
      overflow: hidden;
      transition: all 0.3s ease;
    }
    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }
    .btn:hover::before {
      left: 100%;
    }

    .card {
      position: relative;
      overflow: hidden;
    }
    .card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, #0ea5e9, #3b82f6, #0ea5e9);
      transform: translateX(-100%);
      transition: transform 0.6s ease;
    }
    .card:hover::before {
      transform: translateX(0);
    }

    .table thead th {
      position: relative;
    }
    .table thead th::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 0;
      height: 2px;
      background: linear-gradient(90deg, #0ea5e9, #3b82f6);
      transition: width 0.4s ease;
    }
    .table thead th:hover::after {
      width: 100%;
    }

    .icon, .fas, .far, .fab {
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .count-up, .stat-number {
      font-weight: 700;
      color: var(--pos-accent);
      text-shadow: 0 2px 4px rgba(14, 165, 233, 0.2);
    }

    /* Pulse animation for loading states */
    @keyframes anime-pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
    .btn-loading {
      animation: anime-pulse 1.5s ease-in-out infinite;
      pointer-events: none;
    }

    /* Safety: ensure elements are always visible */
    .main-content .card,
    .motion-reveal,
    .card,
    .table-responsive,
    table thead th,
    .card-header,
    .modal-header,
    .page-header {
      opacity: 1 !important;
      transform: none !important;
    }
  </style>
    <script src="assets/js/swal.js"></script>
    <script type="text/javascript" charset="utf8" src="assets/js/jquery.min.js"></script>
    <script type="text/javascript" src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="config/financial_tooltips.js"></script>

    <script type="text/javascript" src="assets/js/anime.min.js"></script>
    <script type="text/javascript" src="assets/js/motion.js"></script>
    <script type="text/javascript" charset="utf8" src="assets/js/jquery.datatable.min.js"></script>
    <script type="text/javascript" charset="utf8" src="assets/js/datatable.jquery.min.js"></script>
    <script type="text/javascript" src="assets/js/external/select2.min.js"></script>

 

<script>
    $(document).ready(function() {
  // Initialize DataTable once and store the instance
  var table;
  if ($.fn.dataTable.isDataTable('#productsTable')) {
    table = $('#productsTable').DataTable();
  } else {
    table = $('#productsTable').DataTable({
      pageLength: 10, // Show 10 records by default
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
      autoWidth: true,
      dom: 'ltip', // Remove default search box (f), keep length (l), table (t), info (i), pagination (p)
      scrollY: "400px", // Enable vertical scrolling with fixed header
      scrollCollapse: true, // Allow table to reduce height when fewer records
      language: {
        paginate: {
          previous: "<i class='fas fa-angle-left'></i>",
          next: "<i class='fas fa-angle-right'></i>"
        }
      }
    });
  }

  // Custom search functionality using the stored instance
  $('#searchInput').on('keyup', function() {
    table.search($(this).val()).draw();
  });
});
// Global DataTable enhancer and select2 bootstrap-select integration
setTimeout(function() {
    $(document).ready(function() {
        $('table.dataTable, table.datatable').each(function() {
            if ($.fn.DataTable.isDataTable(this)) {
                // Table already initialized, skip re-initialization
                return;
            }
            $(this).DataTable({
                pageLength: 25,
                responsive: true,
                scrollX: true,
                scrollY: true,
                autoWidth: false,
                dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: { search: "" , searchPlaceholder: "Search…" },
            });
        });

        try {
            $('.dataTables_length select').select2({ minimumResultsForSearch: Infinity, width: 'auto' });
            $('.dataTables_filter input[type=search]').attr('placeholder', 'Search…').addClass('form-control-sm');
        } catch (e) {
            console.warn('Select2 not available', e);
        }

        // Sidebar search helper
        $('#sidebarNavSearch').on('input', function() {
            const query = $(this).val().toLowerCase();
            $('#sidenav-collapse-main .navbar-nav > li.nav-item').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(query) !== -1);
            });
        });
        $('.navbar-nav .nav-item.active > .nav-link, .navbar-nav .nav-item > .nav-link.active').addClass('bg-primary text-white');

        // KPI pin-to-top buttons + persistence + drag reorder
        const pinnedStorageKey = 'pos_pinned_cards';
        const cardOrderKey = 'pos_draggable_card_order';

        // restore card order if saved
        function restoreCardOrder() {
            const order = JSON.parse(localStorage.getItem(cardOrderKey) || '[]');
            if (!Array.isArray(order) || order.length === 0) return;
            $('.row').each(function() {
                const row = $(this);
                order.forEach(function(id) {
                    const card = row.find('[data-card-id="' + id + '"]');
                    if (card.length) row.append(card);
                });
            });
        }

        function saveCardOrder() {
            const order = [];
            $('.row').first().find('[data-card-id]').each(function() {
                order.push($(this).attr('data-card-id'));
            });
            localStorage.setItem(cardOrderKey, JSON.stringify(order));
        }

        function loadPinnedCards() {
            return JSON.parse(localStorage.getItem(pinnedStorageKey) || '[]');
        }

        function setPinnedStorage(ids) {
            localStorage.setItem(pinnedStorageKey, JSON.stringify(ids));
        }

        const pinnedList = loadPinnedCards();

        $('.kpi-card, .card.card-stats').each(function(index) {
            const card = $(this);
            let cardId = card.attr('data-card-id');
            if (!cardId) {
                const title = card.find('.card-title').first().text().trim().replace(/\s+/g, '_').toLowerCase();
                cardId = title || 'kpi_' + index;
                card.attr('data-card-id', cardId);
            }

            card.prop('draggable', true);
            card.addClass('draggable-card');

            const btn = $('<button class="btn btn-sm btn-icon btn-white pin-kpi" title="Pin to top"><i class="fas fa-thumbtack"></i></button>');
            card.css('position', 'relative');
            btn.css({position: 'absolute', top: '10px', right: '10px', zIndex: 20});
            card.append(btn);

            if (pinnedList.includes(cardId)) {
                card.addClass('pinned');
                card.css({position: 'sticky', top: '85px', zIndex: 999});
                btn.addClass('btn-primary').removeClass('btn-white');
                card.prependTo(card.parent());
            }

            btn.on('click', function() {
                const isPinned = card.hasClass('pinned');
                const currentPinned = loadPinnedCards();
                if (isPinned) {
                    card.removeClass('pinned');
                    card.css({position: '', top: '', zIndex: ''});
                    btn.removeClass('btn-primary').addClass('btn-white');
                    const arr = currentPinned.filter(function(x) { return x !== cardId; });
                    setPinnedStorage(arr);
                } else {
                    card.addClass('pinned');
                    card.prependTo(card.parent());
                    card.css({position: 'sticky', top: '85px', zIndex: 999});
                    btn.addClass('btn-primary').removeClass('btn-white');
                    if (!currentPinned.includes(cardId)) currentPinned.push(cardId);
                    setPinnedStorage(currentPinned);
                }
                saveCardOrder();
            });

            card.on('dragstart', function(e) {
                e.originalEvent.dataTransfer.setData('text/plain', cardId);
                card.addClass('dragging');
            });
            card.on('dragend', function() {
                card.removeClass('dragging');
            });
        });

        restoreCardOrder();

        $('.row').on('dragover', '.card.card-stats, .kpi-card', function(e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        }).on('dragleave', '.card.card-stats, .kpi-card', function() {
            $(this).removeClass('drag-over');
        }).on('drop', '.card.card-stats, .kpi-card', function(e) {
            e.preventDefault();
            const draggedId = e.originalEvent.dataTransfer.getData('text/plain');
            const targetCard = $(this);
            const draggedCard = $('[data-card-id="' + draggedId + '"]');
            if (draggedCard.length && !targetCard.is(draggedCard)) {
                targetCard.removeClass('drag-over');
                if (targetCard.index() > draggedCard.index()) {
                    targetCard.after(draggedCard);
                } else {
                    targetCard.before(draggedCard);
                }
                saveCardOrder();
            }
        });

        // modal wizard init
        $('.modal-wizard').each(function() {
            const $modal = $(this);
            const $steps = $modal.find('.wizard-step');
            const total = $steps.length;
            let current = 0;

            function showStep(idx) {
                $steps.hide().eq(idx).show();
                $modal.find('.wizard-step-indicator').text('Step ' + (idx + 1) + ' of ' + total);
                $modal.find('[data-wizard="prev"]').toggle(idx > 0);
                $modal.find('[data-wizard="next"]').toggle(idx < total - 1);
                $modal.find('[data-wizard="finish"]').toggle(idx === total - 1);
            }

            $modal.on('click', '[data-wizard="next"]', function(e) {
                e.preventDefault();
                if (current < total - 1) current++;
                showStep(current);
            });
            $modal.on('click', '[data-wizard="prev"]', function(e) {
                e.preventDefault();
                if (current > 0) current--;
                showStep(current);
            });
            $modal.on('click', '[data-wizard="finish"]', function(e) {
                e.preventDefault();
                const form = $modal.find('form').first();
                if (form.length) {
                    form.submit();
                } else {
                    $modal.modal('hide');
                }
            });

            $modal.on('show.bs.modal', function() {
                current = 0;
                showStep(0);
            });

            $modal.find('.wizard-progress-fill').css('width', '0%');
            $modal.on('click', '[data-wizard="next"], [data-wizard="prev"]', function() {
                const percent = ((current + 1) / total) * 100;
                $modal.find('.wizard-progress-fill').css('width', percent + '%');
            });

            showStep(0);
        });

        // Modal placeholder auto from data-placeholder
        $('.modal input[data-placeholder], .modal textarea[data-placeholder]').each(function() {
            $(this).attr('placeholder', $(this).data('placeholder'));
        });
    });
}, 300);
</script>

<style>
    /* Global card header style and body spacing */
    .card-header {
        background: var(--bg-card-header) !important;
        color: var(--text-on-accent) !important;
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }
    .card-body { padding: 1.15rem !important; }
    h1, h2, h3, h4, h5, h6 { letter-spacing: .05em; color: var(--text-primary); }
    h1 { font-weight: 800; }
    h2, h3 { font-weight: 700; }
    .bg-gradient-primary { background-image: var(--accent-gradient) !important; }

    /* Dark mode: card backgrounds and text */
    [data-theme="dark"] .card { background: var(--bg-card); color: var(--text-primary); }
    [data-theme="dark"] .card-body { color: var(--text-primary); }
    [data-theme="dark"] .bg-secondary { background: var(--bg-tertiary) !important; }
    [data-theme="dark"] .bg-white { background: var(--bg-card) !important; }
    [data-theme="dark"] .text-dark { color: var(--text-primary) !important; }
    [data-theme="dark"] .text-muted { color: var(--text-muted) !important; }
    [data-theme="dark"] .border { border-color: var(--border-color) !important; }

    /* Dark mode: forms and inputs */
    [data-theme="dark"] .form-control,
    [data-theme="dark"] .custom-select,
    [data-theme="dark"] select.form-control {
        background: var(--input-bg) !important;
        border-color: var(--input-border) !important;
        color: var(--text-primary) !important;
    }
    [data-theme="dark"] .form-control:focus {
        border-color: var(--input-focus-border) !important;
        box-shadow: var(--input-focus-shadow) !important;
    }
    [data-theme="dark"] .form-control::placeholder { color: var(--text-muted) !important; }
    [data-theme="dark"] .input-group-text {
        background: var(--bg-tertiary) !important;
        border-color: var(--input-border) !important;
        color: var(--text-secondary) !important;
    }
    [data-theme="dark"] label { color: var(--text-primary); }

    /* Dark mode: tables */
    [data-theme="dark"] table.table { background: var(--bg-card); }
    [data-theme="dark"] table.table tbody td { color: var(--text-primary); border-top-color: var(--border-color); }
    [data-theme="dark"] table.table tbody tr:nth-child(odd) { background: var(--table-stripe); }
    [data-theme="dark"] table.table tbody tr:hover { background: var(--table-hover); }
    [data-theme="dark"] .table-responsive { background: var(--bg-card); }

    /* Dark mode: modals */
    [data-theme="dark"] .modal-content { background: var(--modal-bg); color: var(--text-primary); border-color: var(--border-color); }
    [data-theme="dark"] .modal-body { background: var(--bg-card); color: var(--text-primary); }
    [data-theme="dark"] .modal-footer { background: var(--bg-tertiary); border-top-color: var(--border-color); }
    [data-theme="dark"] .modal-body.bg-secondary { background: var(--bg-tertiary) !important; }
    [data-theme="dark"] .close { color: var(--text-primary); }

    /* Dark mode: dropdowns */
    [data-theme="dark"] .dropdown-menu {
        background: var(--bg-card) !important;
        border-color: var(--border-color) !important;
        box-shadow: var(--shadow-lg);
    }
    [data-theme="dark"] .dropdown-item { color: var(--text-primary) !important; }
    [data-theme="dark"] .dropdown-item:hover { background: var(--accent-light) !important; }
    [data-theme="dark"] .dropdown-header { color: var(--text-muted) !important; }

    /* Dark mode: alerts */
    [data-theme="dark"] .alert-info { background: rgba(34,211,238,0.1); border-color: rgba(34,211,238,0.2); color: var(--info); }
    [data-theme="dark"] .alert-success { background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.2); color: var(--success); }
    [data-theme="dark"] .alert-warning { background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.2); color: var(--warning); }
    [data-theme="dark"] .alert-danger { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.2); color: var(--danger); }

    /* Dark mode: badges */
    [data-theme="dark"] .badge-primary { background: var(--accent) !important; }
    [data-theme="dark"] .badge-success { background: var(--success) !important; }
    [data-theme="dark"] .badge-danger { background: var(--danger) !important; }
    [data-theme="dark"] .badge-warning { background: var(--warning) !important; }
    [data-theme="dark"] .badge-info { background: var(--info) !important; }

    /* Dark mode: nav tabs & pills */
    [data-theme="dark"] .nav-tabs { border-bottom-color: var(--border-color); }
    [data-theme="dark"] .nav-tabs .nav-link { color: var(--text-secondary); }
    [data-theme="dark"] .nav-tabs .nav-link.active { color: var(--accent); border-color: var(--border-color) var(--border-color) var(--bg-card); background: var(--bg-card); }
    [data-theme="dark"] .nav-pills .nav-link { color: var(--text-secondary); }
    [data-theme="dark"] .nav-pills .nav-link.active { background: var(--accent); color: var(--text-on-accent); }

    /* Dark mode: pagination */
    [data-theme="dark"] .page-link { background: var(--bg-card); border-color: var(--border-color); color: var(--text-primary); }
    [data-theme="dark"] .page-item.active .page-link { background: var(--accent); border-color: var(--accent); }

    /* Dark mode: main content bg */
    [data-theme="dark"] .main-content { background: var(--bg-primary) !important; }
    [data-theme="dark"] .container-fluid { color: var(--text-primary); }

    /* Dark mode scrollbar */
    [data-theme="dark"] ::-webkit-scrollbar-track { background: var(--scrollbar-bg); }
    [data-theme="dark"] ::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); }

    /* Table row zebra coloring */
    table thead th { text-transform: uppercase; letter-spacing: .03em; }
    table tbody tr:nth-child(odd) { background-color: var(--table-stripe); }
    table tbody tr:hover { background-color: var(--table-hover); }
    .col-description { min-width: 240px; max-width: 440px; word-break: break-word; }

    /* Chart cards and KPI lift */
    .kpi-card, .card.card-stats { box-shadow: var(--shadow-card); transition: transform .2s ease, box-shadow .2s ease; }
    .kpi-card:hover, .card.card-stats:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); }
    .kpi-card.pinned { border: 1px solid rgba(45, 206, 137, 0.8); }

    /* Modal wizard */
    .wizard-step { display: none; }
    .modal-wizard .wizard-step:first-child { display: block; }
    .wizard-step-indicator { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 0.6rem; }
    .wizard-progress { width: 100%; height: 5px; background: var(--border-color); border-radius: 2px; overflow: hidden; margin-bottom: 1rem; }
    .wizard-progress-fill { height: 100%; background: var(--accent-gradient); width: 0%; transition: width .2s ease-in-out; }

    /* Sidebar active nav */
    .navbar-nav .nav-item.active > .nav-link, .navbar-nav .nav-link.active {
        background-color: #5e72e4 !important;
        color: #fff !important;
    }
    .navbar-nav .nav-item.active > .nav-link i, .navbar-nav .nav-link.active i {
        color: #fff !important;
    }

    /* Modal embedded styles */
    .modal-header { background: var(--bg-card-header) !important; color: white !important; }
    .modal-title { font-weight: 700; }

    /* Existing data table fix */
    #productsTable td, #productsTable th { white-space: normal !important; word-break: break-all; font-size: 12px; }
    .dataTables_wrapper { width: 100%; overflow-x: hidden; }
    </style>
    <!--Load Swal-->
    <?php if (!empty($success)) { ?>
        <!--This code for injecting success alert-->
        <script>
            setTimeout(function() {
                    swal("Success", "<?php echo $success; ?>", "success");
                },
                100);
        </script>

    <?php } ?>
    <?php if (!empty($err)) { ?>
        <!--This code for injecting error alert-->
        <script>
            setTimeout(function() {
                    swal("Failed", "<?php echo $err; ?>", "error");
                },
                100);
        </script>

    <?php } ?>
    <?php if (!empty($info)) { ?>
        <!--This code for injecting info alert-->
        <script>
            setTimeout(function() {
                    swal("Success", "<?php echo $info; ?>", "info");
                },
                100);
        </script>

    <?php } ?>
    <script>
        function getCustomer(val) {
            $.ajax({

                type: "POST",
                url: "customer_ajax.php",
                data: 'custName=' + val,
                success: function(data) {
                    // fill all ID fields (nav, hidden, cart)
                    $('#customerID').val(data);
                    $('#navCustomerID').val(data);
                    $('#hiddenCustomerID').val(data);
                }
            });

        }
    </script>
    <style>
    /* ============================================================
       GLOBAL LAYOUT SPECIFICATION (Side-by-Side Fixed Architecture)
       ============================================================ */
    @media (min-width: 768px) {
        #sidenav-main {
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            width: 250px !important;
            max-width: 250px !important;
            height: 100vh !important;
            overflow-y: auto !important;
            z-index: 1040 !important;
            transform: translateX(0) !important;
            visibility: visible !important;
            opacity: 1 !important;
            margin: 0 !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25) !important;
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1), width 0.28s ease, opacity 0.28s ease !important;
        }

        .main-content {
            margin-left: 250px !important;
            min-height: 100vh !important;
            width: calc(100% - 250px) !important;
            position: relative !important;
            transition: margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1), width 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        body.sidebar-collapsed #sidenav-main {
            transform: translateX(-280px) !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        body.sidebar-collapsed .navbar-top {
            margin-left: 0 !important;
            padding-left: 1.25rem !important;
        }
    }

    @media (max-width: 767.98px) {
        #sidenav-main {
            position: fixed !important;
            top: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            width: 260px !important;
            max-width: 260px !important;
            height: 100vh !important;
            overflow-y: auto !important;
            z-index: 1050 !important;
            transform: translateX(-280px);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #sidenav-main.show,
        body.sidebar-open #sidenav-main {
            transform: translateX(0) !important;
            box-shadow: 0 0 50px rgba(0,0,0,0.5) !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            min-height: 100vh !important;
        }
    }
    </style>
</head>