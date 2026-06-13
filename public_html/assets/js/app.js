(function () {
  'use strict';

  var burger = document.getElementById('nav-burger');
  var nav = document.getElementById('site-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      nav.classList.toggle('open');
    });
  }

  var pill = document.getElementById('user-pill');
  var menu = document.getElementById('user-menu');
  if (pill && menu) {
    pill.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (menu.classList.contains('open') && !menu.contains(e.target)) {
        menu.classList.remove('open');
      }
    });
  }

  // Кликабельные строки таблиц (tr[data-href])
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, form')) return;
      window.location.href = tr.dataset.href;
    });
  });

  // Сортировка таблиц по клику на заголовок
  document.querySelectorAll('table.sortable').forEach(function (table) {
    var heads = table.querySelectorAll('thead tr:last-child th');
    heads.forEach(function (th, idx) {
      th.addEventListener('click', function () {
        var tbody = table.querySelector('tbody');
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        // первый клик — по убыванию, повторный — по возрастанию
        var asc = th.classList.contains('sorted-desc');
        heads.forEach(function (h) { h.classList.remove('sorted-asc', 'sorted-desc'); });
        th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
        rows.sort(function (a, b) {
          var ca = a.children[idx], cb = b.children[idx];
          var va = ca.dataset.sort !== undefined ? ca.dataset.sort : ca.textContent.trim();
          var vb = cb.dataset.sort !== undefined ? cb.dataset.sort : cb.textContent.trim();
          var na = parseFloat(va), nb = parseFloat(vb);
          var cmp;
          if (!isNaN(na) && !isNaN(nb)) { cmp = na - nb; }
          else { cmp = String(va).localeCompare(String(vb), 'ru'); }
          return asc ? cmp : -cmp;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  });

  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window)) return;

  // Появление блоков при прокрутке
  var main = document.querySelector('main.container');
  if (main) {
    var blocks = [];
    Array.prototype.forEach.call(main.children, function (el) {
      if (['SCRIPT', 'STYLE', 'DATALIST'].indexOf(el.tagName) >= 0) return;
      blocks.push(el);
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.06, rootMargin: '0px 0px -30px 0px' });
    blocks.forEach(function (el, i) {
      el.classList.add('reveal');
      el.style.transitionDelay = (Math.min(i, 6) * 45) + 'ms';
      io.observe(el);
    });
  }

  // Счётчики цифр
  function parseNum(t) {
    t = t.trim().replace(/\s/g, '');
    if (t.indexOf('.') >= 0 && t.indexOf(',') >= 0) t = t.replace(/,/g, '');
    else t = t.replace(',', '.');
    return parseFloat(t);
  }
  document.querySelectorAll('.stat .val').forEach(function (el) {
    var raw = el.textContent;
    var num = parseNum(raw);
    if (isNaN(num)) return;
    var dec = (raw.replace(/\s/g, '').split('.')[1] || '').length;
    var cio = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      cio.disconnect();
      var dur = 850, start = null;
      el.textContent = (0).toFixed(dec);
      function step(ts) {
        if (!start) start = ts;
        var p = Math.min(1, (ts - start) / dur);
        var eased = num * (1 - Math.pow(1 - p, 3));
        el.textContent = eased.toFixed(dec);
        if (p < 1) requestAnimationFrame(step); else el.textContent = num.toFixed(dec);
      }
      requestAnimationFrame(step);
    }, { threshold: 0.4 });
    cio.observe(el);
  });
})();

// Достижения: клик по карточке -> список получивших со ссылками на профили
(function () {
  var cards = document.querySelectorAll('.ach[data-who]');
  if (!cards.length) return;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }

  var ov = document.createElement('div');
  ov.className = 'ach-modal';
  ov.innerHTML = '<div class="ach-modal-box" role="dialog" aria-modal="true">'
    + '<button class="ach-modal-x" aria-label="Закрыть">✕</button>'
    + '<h3 class="ach-modal-h"></h3><div class="ach-modal-list"></div></div>';
  document.body.appendChild(ov);
  var titleEl = ov.querySelector('.ach-modal-h');
  var listEl = ov.querySelector('.ach-modal-list');

  function close() { ov.classList.remove('open'); }
  ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  ov.querySelector('.ach-modal-x').addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  cards.forEach(function (c) {
    c.style.cursor = 'pointer';
    c.addEventListener('click', function () {
      var who = [];
      try { who = JSON.parse(c.getAttribute('data-who') || '[]'); } catch (e) {}
      var t = (c.querySelector('.ach-t') || {}).textContent || 'Достижение';
      titleEl.textContent = t + ' — получили: ' + who.length;
      if (!who.length) {
        listEl.innerHTML = '<p style="color:var(--tx2);margin:0;">Пока ни у кого</p>';
      } else {
        listEl.innerHTML = who.map(function (e) {
          return '<a class="ach-earner" href="/player.php?id=' + encodeURIComponent(e[0]) + '">'
            + escapeHtml(e[1]) + '</a>';
        }).join('');
      }
      ov.classList.add('open');
    });
  });
})();
