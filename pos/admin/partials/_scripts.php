  <!-- Core -->
  <script src="assets/js/jquery.datatable.min.js"></script>
  <!-- DataTables Buttons -->
  <script src="assets/js/external/dataTables.buttons.min.js"></script>
  <script src="assets/js/external/buttons.html5.min.js"></script>
  <script src="assets/js/external/buttons.print.min.js"></script>
  <script src="assets/js/external/jszip.min.js"></script>
  <script src="assets/js/external/pdfmake.min.js"></script>
  <script src="assets/js/external/vfs_fonts.js"></script>
  <script src="assets/js/external/bootstrap-select.min.js"></script>
  <script src="assets/vendor/chart.js/dist/Chart.min.js"></script>
  <script src="assets/js/argon.js?v=1.0.0"></script>
  <!-- <script src="assets/vendor/chart.js/dist/Chart.extension.js"></script> -->
  <script>
    $(function() {
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      // Safety fallback: ensure all elements are visible
      function ensureElementsVisible() {
        document.querySelectorAll('.main-content .card, .motion-reveal, .card, .table-responsive, table thead th, .card-header, .modal-header, .page-header').forEach(function(el) {
          el.style.opacity = '1';
          el.style.transform = 'none';
        });
      }

      // Check if Anime.js loaded successfully
      if (typeof anime === 'undefined') {
        console.warn('Anime.js failed to load, using fallback styles');
        ensureElementsVisible();
        return;
      }

      function formatCounter(value, decimals) {
        return new Intl.NumberFormat('en-US', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        }).format(value);
      }

      function runCountUp() {
        document.querySelectorAll('.count-up').forEach(function(el) {
          var target = parseFloat(el.dataset.target) || 0;
          var decimals = parseInt(el.dataset.decimals || '0', 10);
          var suffix = el.dataset.suffix || '';
          if (reduceMotion || typeof anime === 'undefined') {
            el.textContent = formatCounter(target, decimals) + suffix;
            return;
          }
          anime({
            targets: el,
            innerHTML: [0, target],
            round: decimals,
            duration: 1400,
            easing: 'easeOutExpo',
            update: function(anim) {
              el.textContent = formatCounter(parseFloat(el.innerHTML), decimals) + suffix;
            }
          });
        });
      }

      function bindHoverEffects() {
        if (reduceMotion || typeof anime === 'undefined') return;

        document.querySelectorAll('.btn, .navbar-nav .nav-link, .dropdown-item').forEach(function(el) {
          el.addEventListener('mouseenter', function() {
            anime({
              targets: el,
              scale: 1.04,
              duration: 180,
              easing: 'easeOutExpo'
            });
          });
          el.addEventListener('mouseleave', function() {
            anime({
              targets: el,
              scale: 1,
              duration: 180,
              easing: 'easeOutExpo'
            });
          });
        });

        document.querySelectorAll('.card').forEach(function(card) {
          card.addEventListener('mouseenter', function() {
            anime({
              targets: card,
              translateY: -4,
              duration: 180,
              easing: 'easeOutExpo'
            });
          });
          card.addEventListener('mouseleave', function() {
            anime({
              targets: card,
              translateY: 0,
              duration: 180,
              easing: 'easeOutExpo'
            });
          });
        });

        // Enhanced button effects with Anime.js
        document.querySelectorAll('.btn').forEach(function(btn) {
          btn.addEventListener('mousedown', function() {
            anime({
              targets: btn,
              scale: 0.98,
              duration: 80,
              easing: 'easeOutExpo'
            });
          });
          btn.addEventListener('mouseup', function() {
            anime({
              targets: btn,
              scale: 1.04,
              duration: 120,
              easing: 'easeOutExpo'
            });
          });
        });

        // Icon effects
        document.querySelectorAll('.icon, .fas, .far, .fab').forEach(function(icon) {
          icon.addEventListener('mouseenter', function() {
            anime({
              targets: icon,
              rotate: '+=10',
              scale: 1.1,
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
          icon.addEventListener('mouseleave', function() {
            anime({
              targets: icon,
              rotate: 0,
              scale: 1,
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
        });
      }

      function entranceAnimations() {
        if (reduceMotion || typeof anime === 'undefined') {
          document.querySelectorAll('.main-content .card').forEach(function(el) {
            el.style.opacity = 1;
            el.style.transform = 'none';
          });
          return;
        }

        // Ensure elements are visible before animating
        document.querySelectorAll('.main-content .card').forEach(function(el) {
          el.style.opacity = '0';
          el.style.transform = 'translateY(24px)';
        });

        anime({
          targets: '.main-content .card',
          opacity: [0, 1],
          translateY: [24, 0],
          duration: 850,
          easing: 'easeOutExpo',
          delay: anime.stagger(80)
        });

        anime({
          targets: '#sidenav-main .nav-link',
          opacity: [0, 1],
          translateX: [-18, 0],
          duration: 700,
          easing: 'easeOutExpo',
          delay: anime.stagger(35, { start: 120 })
        });

        // Animate table headers
        document.querySelectorAll('table thead th').forEach(function(th) {
          th.style.opacity = '0';
          th.style.transform = 'translateY(-10px)';
        });
        anime({
          targets: 'table thead th',
          opacity: [0, 1],
          translateY: [-10, 0],
          duration: 600,
          easing: 'easeOutExpo',
          delay: anime.stagger(50, {start: 200})
        });

        // Animate div headers
        document.querySelectorAll('.card-header, .modal-header, .page-header').forEach(function(header) {
          header.style.opacity = '0';
          header.style.transform = 'translateX(-20px)';
        });
        anime({
          targets: '.card-header, .modal-header, .page-header',
          opacity: [0, 1],
          translateX: [-20, 0],
          duration: 700,
          easing: 'easeOutExpo',
          delay: anime.stagger(100, {start: 300})
        });
      }

      function setupScrollReveal() {
        if (reduceMotion || typeof anime === 'undefined') return;
        var revealElements = document.querySelectorAll('.motion-reveal, .card, .table-responsive');

        // Set initial state for reveal elements
        revealElements.forEach(function(el) {
          if (!el.hasAttribute('data-initialized')) {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.setAttribute('data-initialized', 'true');
          }
        });

        var observer = new IntersectionObserver(function(entries) {
          entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            anime({
              targets: entry.target,
              opacity: [0, 1],
              translateY: [20, 0],
              duration: 700,
              easing: 'easeOutExpo',
              delay: 0
            });
            observer.unobserve(entry.target);
          });
        }, { threshold: 0.18 });

        revealElements.forEach(function(el) {
          observer.observe(el);
        });
      }

      function advancedAnimeEffects() {
        if (reduceMotion || typeof anime === 'undefined') return;

        // Color transitions for buttons
        document.querySelectorAll('.btn-primary').forEach(function(btn) {
          btn.addEventListener('mouseenter', function() {
            anime({
              targets: btn,
              backgroundColor: ['#007bff', '#0056b3'],
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
          btn.addEventListener('mouseleave', function() {
            anime({
              targets: btn,
              backgroundColor: ['#0056b3', '#007bff'],
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
        });

        // Shadow effects for cards
        document.querySelectorAll('.card').forEach(function(card) {
          card.addEventListener('mouseenter', function() {
            anime({
              targets: card,
              boxShadow: ['0 4px 6px rgba(0,0,0,0.1)', '0 10px 25px rgba(0,0,0,0.15)'],
              duration: 300,
              easing: 'easeOutExpo'
            });
          });
          card.addEventListener('mouseleave', function() {
            anime({
              targets: card,
              boxShadow: ['0 10px 25px rgba(0,0,0,0.15)', '0 4px 6px rgba(0,0,0,0.1)'],
              duration: 300,
              easing: 'easeOutExpo'
            });
          });
        });

        // Table row hover effects
        document.querySelectorAll('tbody tr').forEach(function(row) {
          row.addEventListener('mouseenter', function() {
            anime({
              targets: row,
              backgroundColor: ['rgba(0,0,0,0)', 'rgba(14, 165, 233, 0.08)'],
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
          row.addEventListener('mouseleave', function() {
            anime({
              targets: row,
              backgroundColor: ['rgba(14, 165, 233, 0.08)', 'rgba(0,0,0,0)'],
              duration: 200,
              easing: 'easeOutExpo'
            });
          });
        });

        // Number animation effects
        document.querySelectorAll('.count-up, .stat-number').forEach(function(num) {
          anime({
            targets: num,
            scale: [0.8, 1],
            opacity: [0, 1],
            duration: 800,
            easing: 'easeOutExpo',
            delay: anime.stagger(100)
          });
        });

        // Loading animation for buttons
        document.querySelectorAll('.btn').forEach(function(btn) {
          btn.addEventListener('click', function() {
            if (btn.classList.contains('btn-loading')) return;
            btn.classList.add('btn-loading');
            anime({
              targets: btn,
              scale: [1, 0.95, 1],
              duration: 400,
              easing: 'easeInOutExpo',
              complete: function() {
                btn.classList.remove('btn-loading');
              }
            });
          });
        });
      }

      try {
        runCountUp();
        bindHoverEffects();
        entranceAnimations();
        setupScrollReveal();
        advancedAnimeEffects();
      } catch (error) {
        console.error('Animation error:', error);
        ensureElementsVisible();
      }

      // Additional safety: ensure elements are visible after a timeout
      setTimeout(function() {
        if (typeof anime === 'undefined' || reduceMotion) {
          ensureElementsVisible();
        }
      }, 2000);
    });
  </script>
  <!-- Backspace Navigation Script -->
  <script>
    document.addEventListener('keydown', function(event) {
      // Check if backspace key is pressed
      if (event.keyCode === 8) {
        // Check if the target is not an input, textarea, or contenteditable element
        var target = event.target;
        if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA' && !target.isContentEditable) {
          // Prevent default backspace behavior and go back
          event.preventDefault();
          window.history.back();
        }
      }
    });
  </script>

  <!-- Horizontal Scroll with Arrow Keys for DataTables -->
  <script>
    $(document).on('keydown', function(e) {
      if (e.keyCode === 37) { // left arrow
        $('.dataTables_scrollBody').each(function() {
          this.scrollLeft -= 50;
        });
        e.preventDefault();
      } else if (e.keyCode === 39) { // right arrow
        $('.dataTables_scrollBody').each(function() {
          this.scrollLeft += 50;
        });
        e.preventDefault();
      }
    });
  </script>
