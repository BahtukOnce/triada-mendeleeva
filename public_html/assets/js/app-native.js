/* Нативный опыт внутри мобильного приложения (Capacitor WebView).
 * Грузится ТОЛЬКО когда is_app() (layout.php добавляет body.app и подключает этот файл).
 * Плагины (StatusBar/App/Browser) уже вшиты в APK — вызовы идут через window.Capacitor,
 * всё в try/catch и с feature-detect, поэтому в обычном вебе файл безвреден.
 */
(function () {
  'use strict';
  if (!document.body || !document.body.classList.contains('app')) return;

  var Cap = window.Capacitor || {};
  var P = Cap.Plugins || {};

  function vibe(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

  // ── 1. Статус-бар: тёмный фон, светлые иконки (чистая полоса, без оверлея) ──
  function styleStatusBar() {
    try {
      if (!P.StatusBar) return;
      P.StatusBar.setOverlaysWebView({ overlay: false });
      P.StatusBar.setBackgroundColor({ color: '#0e0e11' });
      P.StatusBar.setStyle({ style: 'DARK' }); // DARK = тёмный фон → светлые иконки
    } catch (e) {}
  }
  styleStatusBar();

  // ── 2. Активная вкладка таб-бара по текущему пути ──
  (function () {
    var cur = location.pathname;
    document.querySelectorAll('.tabbar a[data-match]').forEach(function (a) {
      var arr = a.getAttribute('data-match').split(',');
      if (arr.indexOf(cur) >= 0) a.classList.add('active');
    });
    // Вкладка «Ещё» открывает боковое меню (переиспользуем бургер)
    var more = document.getElementById('tab-more');
    if (more) {
      more.addEventListener('click', function (e) {
        e.preventDefault();
        var b = document.getElementById('nav-burger');
        if (b) b.click();
      });
    }
    // Виброотклик на тап по любой вкладке
    document.querySelectorAll('.tabbar a, .tabbar button').forEach(function (el) {
      el.addEventListener('click', function () { vibe(8); }, { passive: true });
    });
  })();

  // ── 3. Аппаратная кнопка «Назад» ──
  function closeDrawer() {
    var c = document.getElementById('nav-close') || document.getElementById('nav-burger');
    if (c) c.click();
  }
  function closeTopOverlay() {
    var nav = document.getElementById('site-nav');
    if (nav && nav.classList.contains('open')) { closeDrawer(); return true; }
    var um = document.getElementById('user-menu');
    if (um && um.classList.contains('open')) { um.classList.remove('open'); return true; }
    if (document.querySelector('.post-modal:not([hidden]), .img-lightbox:not([hidden]), .ach-modal.open')) {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      return true;
    }
    return false;
  }
  if (P.App && P.App.addListener) {
    var lastBack = 0;
    P.App.addListener('backButton', function () {
      if (closeTopOverlay()) return;
      var onHome = location.pathname === '/' || location.pathname === '/index.php';
      if (!onHome && history.length > 1) { history.back(); return; }
      var now = Date.now();
      if (now - lastBack < 1800) { try { P.App.exitApp(); } catch (e) {} }
      else { lastBack = now; toast('Ещё раз «Назад» — выход'); }
    });
    // При возврате в приложение восстановить стиль статус-бара
    P.App.addListener('resume', styleStatusBar);
  }

  // ── 4. Внешние ссылки — в системный браузер, не в webview ──
  if (P.Browser) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href]');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (!/^https?:\/\//i.test(href)) return;               // только абсолютные http(s)
      if (!a.hostname || a.hostname === location.hostname) return; // свой домен — обычная навигация
      e.preventDefault();
      try { P.Browser.open({ url: a.href, presentationStyle: 'popover' }); }
      catch (_) { window.open(a.href, '_system'); }
    }, true);
  }

  // ── 5. Тонкий индикатор перехода сверху ──
  var bar = document.createElement('div');
  bar.className = 'nav-progress';
  document.body.appendChild(bar);
  var barTimer = 0;
  function resetBar() { bar.className = 'nav-progress'; if (barTimer) { clearTimeout(barTimer); barTimer = 0; } }
  function isInternalNav(a) {
    var href = a.getAttribute('href') || '';
    if (a.target === '_blank') return false;
    if (/^(#|javascript:|tel:|mailto:)/i.test(href)) return false;
    if (/^https?:\/\//i.test(href) && a.hostname && a.hostname !== location.hostname) return false;
    return true;
  }
  // Bubble-фаза (без capture): срабатывает ПОСЛЕ обработчиков app.js. Если ссылку
  // перехватили (модалка новости, зум аватара — там preventDefault и навигации нет),
  // e.defaultPrevented === true → полосу не показываем. Плюс страховочный сброс.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || !isInternalNav(a)) return;
    bar.className = 'nav-progress go';
    if (barTimer) clearTimeout(barTimer);
    barTimer = setTimeout(resetBar, 3000);
  });
  window.addEventListener('pageshow', resetBar);

  // ── 6. Pull-to-refresh ──
  (function () {
    var el = document.createElement('div');
    el.className = 'ptr';
    el.innerHTML = '<span class="ptr-spin"></span>';
    document.body.appendChild(el);
    var REST = 'translate(-50%,-64px)';
    el.style.transform = REST;
    var startY = 0, startX = 0, pulling = false, dist = 0, TRIG = 66;
    // Не тянем, когда открыт оверлей (в модалке scrollY тоже 0, иначе жест перезагрузил бы страницу).
    function overlayOpen() {
      return document.body.classList.contains('nav-lock')
        || !!document.querySelector('#site-nav.open, .post-modal:not([hidden]), .img-lightbox:not([hidden]), .ach-modal.open');
    }
    window.addEventListener('touchstart', function (e) {
      if (window.scrollY > 0 || e.touches.length !== 1 || overlayOpen()) { pulling = false; return; }
      startY = e.touches[0].clientY; startX = e.touches[0].clientX; pulling = true; dist = 0;
    }, { passive: true });
    window.addEventListener('touchmove', function (e) {
      if (!pulling) return;
      var t = e.touches[0];
      dist = t.clientY - startY;
      // Горизонталь доминирует (свайп меню/карусель) — это не pull-to-refresh.
      if (Math.abs(t.clientX - startX) > Math.abs(dist)) {
        pulling = false; el.style.transform = REST; el.classList.remove('ready'); return;
      }
      if (dist <= 0) { el.style.transform = REST; el.classList.remove('ready'); return; }
      var d = Math.min(dist * 0.55, 130);
      el.style.transform = 'translate(-50%,' + (d - 64) + 'px)';
      el.classList.toggle('ready', dist > TRIG);
    }, { passive: true });
    window.addEventListener('touchend', function () {
      if (!pulling) return;
      pulling = false;
      if (dist > TRIG) {
        el.classList.add('spin');
        el.style.transform = 'translate(-50%,26px)';
        vibe(12);
        setTimeout(function () { location.reload(); }, 180);
      } else {
        el.style.transform = REST;
        el.classList.remove('ready');
      }
    }, { passive: true });
  })();

  // ── 7. Мини-тост ──
  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'app-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () {
      t.classList.remove('show');
      setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 260);
    }, 1600);
  }
})();
