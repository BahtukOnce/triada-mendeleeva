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

// Достижения: клик по карточке -> боковая панель (десктоп) либо модалка (телефон)
(function () {
  var cards = document.querySelectorAll('.ach[data-who]');
  if (!cards.length) return;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }
  function whoOf(c) {
    try { return JSON.parse(c.getAttribute('data-who') || '[]'); } catch (e) { return []; }
  }
  function titleOf(c) {
    return c.getAttribute('data-title') || (c.querySelector('.ach-t') || {}).textContent || 'Достижение';
  }
  function avaHtml(nick, ava) {
    if (ava) return '<img src="' + escapeHtml(ava) + '" alt="">';
    var letter = escapeHtml(String(nick || '?').trim().charAt(0).toUpperCase());
    return '<span class="avatar-circle">' + letter + '</span>';
  }

  // Модалка — фолбэк для телефона (боковой панели там нет)
  var ov = document.createElement('div');
  ov.className = 'ach-modal';
  ov.innerHTML = '<div class="ach-modal-box" role="dialog" aria-modal="true">'
    + '<button class="ach-modal-x" aria-label="Закрыть">✕</button>'
    + '<h3 class="ach-modal-h"></h3><div class="ach-modal-list"></div></div>';
  document.body.appendChild(ov);
  var titleEl = ov.querySelector('.ach-modal-h');
  var listEl = ov.querySelector('.ach-modal-list');
  function closeModal() { ov.classList.remove('open'); }
  ov.addEventListener('click', function (e) { if (e.target === ov) closeModal(); });
  ov.querySelector('.ach-modal-x').addEventListener('click', closeModal);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  function openModal(c) {
    var who = whoOf(c);
    titleEl.textContent = titleOf(c) + ' — получили: ' + who.length;
    if (!who.length) {
      listEl.innerHTML = '<p style="color:var(--tx2);margin:0;">Пока ни у кого</p>';
    } else {
      listEl.innerHTML = who.map(function (e) {
        return '<a class="ach-earner" href="/player.php?id=' + encodeURIComponent(e[0]) + '">'
          + escapeHtml(e[1]) + (e[3] ? ' ' + escapeHtml(e[3]) : '') + '</a>';
      }).join('');
    }
    if (window.twemojiParse) window.twemojiParse(listEl);
    ov.classList.add('open');
  }

  // Боковая панель (десктоп)
  var side = document.getElementById('ach-side');
  var sideInner = side ? side.querySelector('.ach-side-inner') : null;
  var sideEmpty = sideInner ? sideInner.innerHTML : '';
  var pinned = false;
  function renderSide(c) {
    if (!sideInner) return;
    var who = whoOf(c);
    var html = '<div class="ach-side-ttl">' + escapeHtml(titleOf(c)) + '</div>'
      + '<div class="ach-side-sub">получили: ' + who.length + '</div>';
    if (!who.length) {
      html += '<div class="ach-side-empty">Пока ни у кого</div>';
    } else {
      html += '<div class="ach-side-list">' + who.map(function (e) {
        return '<a class="ach-side-row" href="/player.php?id=' + encodeURIComponent(e[0]) + '">'
          + avaHtml(e[1], e[2]) + '<span class="nm">' + escapeHtml(e[1]) + (e[3] ? ' ' + escapeHtml(e[3]) : '') + '</span></a>';
      }).join('') + '</div>';
    }
    sideInner.innerHTML = html;
    if (window.twemojiParse) window.twemojiParse(sideInner);
  }
  function sideVisible() { return side && side.offsetParent !== null; }

  cards.forEach(function (c) {
    c.style.cursor = 'pointer';
    c.addEventListener('click', function () {
      if (sideVisible()) { pinned = true; renderSide(c); } else { openModal(c); }
    });
    c.addEventListener('mouseenter', function () { if (sideVisible()) renderSide(c); });
  });
  // Сброс панели, только когда курсор ушёл со всего блока и список не «закреплён» кликом
  var achWrap = document.querySelector('.ach-wrap');
  if (achWrap && sideInner) {
    achWrap.addEventListener('mouseleave', function () { if (!pinned) sideInner.innerHTML = sideEmpty; });
  }
})();

// Новости: «Показать полностью» -> модалка поверх страницы; фото -> лайтбокс
(function () {
  var feed = document.querySelector('.news-cards');
  var onPost = document.querySelector('.post-single, .post-card');
  if (!feed && !onPost) return;

  // ── модалка с полным постом ──
  var modal = null, content = null;
  function ensureModal() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'post-modal';
    modal.hidden = true;
    modal.innerHTML = '<div class="post-modal-backdrop"></div>'
      + '<button class="post-modal-x" aria-label="Закрыть">✕</button>'
      + '<div class="post-modal-panel"><div class="post-modal-content"></div></div>';
    document.body.appendChild(modal);
    content = modal.querySelector('.post-modal-content');
    modal.querySelector('.post-modal-backdrop').addEventListener('click', closeModal);
    modal.querySelector('.post-modal-x').addEventListener('click', closeModal);
  }
  function openModal(id, fallback) {
    ensureModal();
    content.innerHTML = '<div class="post-modal-loading">Загрузка…</div>';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    fetch('/news.php?id=' + encodeURIComponent(id) + '&partial=1')
      .then(function (r) { if (!r.ok) throw 0; return r.text(); })
      .then(function (html) { content.innerHTML = html; if (window.twemojiParse) window.twemojiParse(content); })
      .catch(function () { closeModal(); if (fallback) location.href = fallback; });
  }
  function closeModal() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    content.innerHTML = '';
    document.body.style.overflow = '';
  }
  if (feed) {
    feed.addEventListener('click', function (e) {
      var card = e.target.closest('.ncard');
      if (!card) return;
      e.preventDefault();
      openModal(card.getAttribute('data-id'), card.getAttribute('href'));
    });
  }

  // ── лайтбокс: фото поста открывается в полный размер (плавно) ──
  var lb = null, lbImg = null, lbT = null;
  function ensureLb() {
    if (lb) return;
    lb = document.createElement('div');
    lb.className = 'img-lightbox';
    lb.hidden = true;
    lb.innerHTML = '<img alt="">';
    document.body.appendChild(lb);
    lbImg = lb.querySelector('img');
    lb.addEventListener('click', hideLb);
  }
  function showLb(src) {
    ensureLb();
    if (lbT) { clearTimeout(lbT); lbT = null; }
    lbImg.src = src;
    lb.hidden = false;
    // два кадра — чтобы сработал CSS-переход (плавное появление + зум)
    requestAnimationFrame(function () { requestAnimationFrame(function () { lb.classList.add('show'); }); });
  }
  function hideLb() {
    if (!lb) return;
    lb.classList.remove('show');
    lbT = setTimeout(function () { lb.hidden = true; }, 220);
  }
  document.addEventListener('click', function (e) {
    var im = e.target.closest('.post-imgs img');
    if (!im) return;
    e.preventDefault();
    e.stopPropagation();
    showLb(im.currentSrc || im.src);
  });
  // эмодзи-картинки Telegram: если не загрузилась — вернуть системный символ (alt)
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && t.className && ('' + t.className).indexOf('tg-e') !== -1 && t.parentNode) {
      t.parentNode.replaceChild(document.createTextNode(t.getAttribute('alt') || ''), t);
    }
  }, true);

  // ── реакции (эмодзи) ──
  function paintReactions(bar, counts, mine) {
    bar.querySelectorAll('.react-btn').forEach(function (b) {
      var em = b.getAttribute('data-emoji');
      var c = counts[em] || 0;
      b.classList.toggle('active', mine === em);
      var rc = b.querySelector('.rc');
      if (rc) rc.textContent = c ? c : '';
    });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.react-btn');
    if (!btn) return;
    e.preventDefault();
    var bar = btn.closest('.post-reactions');
    if (!bar) return;
    if (bar.getAttribute('data-guest')) { location.href = '/login.php'; return; }
    var fd = new FormData();
    fd.append('news_id', bar.getAttribute('data-id'));
    fd.append('emoji', btn.getAttribute('data-emoji'));
    fd.append('csrf', bar.getAttribute('data-csrf'));
    btn.disabled = true;
    fetch('/news_react.php', { method: 'POST', body: fd })
      .then(function (r) { if (r.status === 403) { location.href = '/login.php'; throw 0; } return r.json(); })
      .then(function (d) { if (d && d.counts) paintReactions(bar, d.counts, d.mine); })
      .catch(function () {})
      .finally(function () { btn.disabled = false; });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (lb && !lb.hidden) { hideLb(); return; }
    closeModal();
  });

  // ── Выпадашка с поиском: <select data-search> ──
  function enhanceSearchSelect(sel) {
    if (sel.dataset.ssDone) return;
    sel.dataset.ssDone = '1';
    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'text'; input.className = 'ss-input'; input.autocomplete = 'off';
    input.placeholder = sel.getAttribute('data-search') || 'Поиск…';
    wrap.appendChild(input);
    var menu = document.createElement('div');
    menu.className = 'ss-menu'; menu.hidden = true;
    wrap.appendChild(menu);
    var opts = Array.prototype.map.call(sel.options, function (o) {
      return { value: o.value, text: o.text, low: o.text.toLowerCase() };
    });
    function isEmpty(v) { return v === '' || v === '0'; }
    function sync() {
      var o = sel.options[sel.selectedIndex];
      input.value = (o && !isEmpty(o.value)) ? o.text : '';
    }
    sync();
    function choose(o) {
      sel.value = o.value;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      input.value = isEmpty(o.value) ? '' : o.text;
      menu.hidden = true;
    }
    function render(f) {
      f = (f || '').toLowerCase().trim();
      menu.innerHTML = ''; var shown = 0;
      opts.forEach(function (o) {
        if (isEmpty(o.value) && f) return;
        if (f && o.low.indexOf(f) === -1) return;
        if (shown >= 80) return;
        shown++;
        var it = document.createElement('div');
        it.className = 'ss-item' + (o.value === sel.value ? ' sel' : '');
        it.textContent = o.text;
        it.addEventListener('mousedown', function (e) { e.preventDefault(); choose(o); });
        menu.appendChild(it);
      });
      if (!shown) {
        var n = document.createElement('div');
        n.className = 'ss-item ss-none'; n.textContent = 'Ничего не найдено';
        menu.appendChild(n);
      }
    }
    input.addEventListener('focus', function () { render(''); menu.hidden = false; input.select(); });
    input.addEventListener('input', function () { render(input.value); menu.hidden = false; });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !menu.hidden) { e.preventDefault(); }
      else if (e.key === 'Escape') { menu.hidden = true; sync(); input.blur(); }
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) { menu.hidden = true; sync(); }
    });
  }
  document.querySelectorAll('select[data-search]').forEach(enhanceSearchSelect);
})();
