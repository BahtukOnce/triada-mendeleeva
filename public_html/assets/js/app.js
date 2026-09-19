(function () {
  'use strict';

  var burger = document.getElementById('nav-burger');
  var nav = document.getElementById('site-nav');
  var scrim = document.getElementById('nav-scrim');
  var navClose = document.getElementById('nav-close');
  if (burger && nav) {
    var openNav = function () {
      nav.classList.add('open');
      burger.classList.add('open');
      burger.setAttribute('aria-expanded', 'true');
      document.body.classList.add('nav-lock');
      if (scrim) {
        scrim.hidden = false;
        requestAnimationFrame(function () { scrim.classList.add('open'); });
      }
    };
    var closeNav = function () {
      nav.classList.remove('open');
      burger.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('nav-lock');
      if (scrim) {
        scrim.classList.remove('open');
        setTimeout(function () { if (!nav.classList.contains('open')) scrim.hidden = true; }, 300);
      }
    };
    var toggleNav = function () { nav.classList.contains('open') ? closeNav() : openNav(); };

    burger.addEventListener('click', toggleNav);
    if (navClose) navClose.addEventListener('click', closeNav);
    if (scrim) scrim.addEventListener('click', closeNav);
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) closeNav(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('open')) closeNav();
    });

    // Свайп от левого края вправо — открыть меню
    var tsx = 0, tsy = 0, tracking = false, swiping = false;
    window.addEventListener('touchstart', function (e) {
      if (nav.classList.contains('open')) return;
      var t = e.touches[0];
      if (t.clientX <= 24) { tsx = t.clientX; tsy = t.clientY; tracking = true; swiping = false; }
    }, { passive: true });
    window.addEventListener('touchmove', function (e) {
      if (!tracking) return;
      var t = e.touches[0], dx = t.clientX - tsx, dy = t.clientY - tsy;
      if (!swiping && Math.abs(dx) > Math.abs(dy) && dx > 12) swiping = true;
      if (swiping && dx > 55) { openNav(); tracking = false; }
    }, { passive: true });
    window.addEventListener('touchend', function () { tracking = false; }, { passive: true });

    // Свайп влево по открытому меню — закрыть
    var nsx = null, nsy = null;
    nav.addEventListener('touchstart', function (e) { nsx = e.touches[0].clientX; nsy = e.touches[0].clientY; }, { passive: true });
    nav.addEventListener('touchmove', function (e) {
      if (nsx === null || !nav.classList.contains('open')) return;
      var t = e.touches[0], dx = t.clientX - nsx, dy = t.clientY - nsy;
      if (dx < -50 && Math.abs(dx) > Math.abs(dy)) { closeNav(); nsx = null; }
    }, { passive: true });
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

  // Просмотр фото (лайтбокс) переехал в общий блок ниже: здесь он жил после раннего return и
  // на неновостных страницах не работал вовсе (в т.ч. увеличение аватарки в профиле).
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
    closeModal();   // открытое фото закрывается раньше — его обработчик останавливает Escape
  });

})();

// ── Просмотр фото поверх страницы (лайтбокс) — на всех страницах ──
// Фото новостей (.post-imgs img), аватар профиля (.pf-ava-zoom[data-full]) и любые ссылки на фото
// с data-lb (скриншоты предложений, альбомы): открываются внутри сайта, а не в новой вкладке.
// Несколько фото в одной группе ([data-lb-group], иначе родитель ссылки) листаются стрелками,
// клавишами ← → и свайпом. Ctrl/⌘-клик по ссылке по-прежнему открывает файл в новой вкладке.
(function () {
  var lb = null, lbImg, lbPrev, lbNext, lbCount, lbT = null, list = [], idx = 0;
  function ensure() {
    if (lb) return;
    lb = document.createElement('div');
    lb.className = 'img-lightbox';
    lb.hidden = true;
    lb.innerHTML = '<img alt="">'
      + '<button type="button" class="lb-btn lb-x" aria-label="Закрыть">✕</button>'
      + '<button type="button" class="lb-btn lb-prev" aria-label="Предыдущее фото">‹</button>'
      + '<button type="button" class="lb-btn lb-next" aria-label="Следующее фото">›</button>'
      + '<span class="lb-count"></span>';
    document.body.appendChild(lb);
    lbImg = lb.querySelector('img');
    lbPrev = lb.querySelector('.lb-prev');
    lbNext = lb.querySelector('.lb-next');
    lbCount = lb.querySelector('.lb-count');
    lb.addEventListener('click', function (e) {
      if (e.target === lbPrev) { step(-1); return; }
      if (e.target === lbNext) { step(1); return; }
      hide();                                   // фон, само фото или ✕ — закрыть
    });
    var x0 = null;                              // свайп на телефоне
    lb.addEventListener('touchstart', function (e) {
      x0 = e.touches.length === 1 ? e.touches[0].clientX : null;
    }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (x0 === null || list.length < 2) return;
      var dx = e.changedTouches[0].clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 45) { e.preventDefault(); step(dx < 0 ? 1 : -1); }
    });
  }
  function render() {
    lbImg.src = list[idx];
    var many = list.length > 1;
    lbPrev.hidden = !many;
    lbNext.hidden = !many;
    lbCount.hidden = !many;
    lbCount.textContent = (idx + 1) + ' / ' + list.length;
  }
  function step(d) {
    if (list.length < 2) return;
    idx = (idx + d + list.length) % list.length;
    render();
  }
  function show(src, group) {
    ensure();
    if (lbT) { clearTimeout(lbT); lbT = null; }
    list = group && group.length ? group : [src];
    idx = Math.max(0, list.indexOf(src));
    render();
    lb.hidden = false;
    // два кадра — чтобы сработал CSS-переход (плавное появление + зум)
    requestAnimationFrame(function () { requestAnimationFrame(function () { lb.classList.add('show'); }); });
  }
  function hide() {
    if (!lb) return;
    lb.classList.remove('show');
    lbT = setTimeout(function () { lb.hidden = true; }, 220);
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var im = t.closest('.post-imgs img');
    if (im) {
      e.preventDefault();
      e.stopPropagation();
      var imgs = [].map.call(im.closest('.post-imgs').querySelectorAll('img'), function (x) { return x.currentSrc || x.src; });
      show(im.currentSrc || im.src, imgs);
      return;
    }
    var z = t.closest('.pf-ava-zoom');
    if (z && z.getAttribute('data-full')) {
      e.preventDefault();
      e.stopPropagation();
      show(z.getAttribute('data-full'));
      return;
    }
    var a = t.closest('a[data-lb]');
    if (a && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
      e.preventDefault();
      e.stopPropagation();
      var box = a.closest('[data-lb-group]') || a.parentNode;
      show(a.href, [].map.call(box.querySelectorAll('a[data-lb]'), function (x) { return x.href; }));
    }
  });
  // Escape и стрелки; в фазе погружения — чтобы Escape закрыл фото, а не модалку новости под ним
  // (кнопка «Назад» в приложении тоже шлёт Escape).
  document.addEventListener('keydown', function (e) {
    if (!lb || lb.hidden || !lb.classList.contains('show')) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); hide(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); step(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); step(-1); }
  }, true);
})();

// ── Выпадашка с поиском: <select data-search> ──
// Жило внутри блока новостей, который на неновостных страницах выходит раньше
// (early return выше), поэтому поиск по игрокам не работал вообще нигде: ни в
// «Дуэли», ни в кабинете, ни в админке турниров. Вынесено в отдельный блок.
(function () {
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

// ── Кнопки −/+ вместо выпадающего списка у маленьких числовых полей (протоколы: фолы, техи,
// удаление, порядок выбывания). Сам <select> остаётся, только скрыт: форма, пересчёт итога и
// восстановление введённого после ошибки работают как раньше — кнопки лишь листают варианты.
// data-stepper-warn — подсветить крайнее значение (4 фола, удаление на крит. круг).
(function () {
  function btn(txt, label) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'stp-b'; b.textContent = txt; b.setAttribute('aria-label', label);
    return b;
  }
  // Числовое поле (input[type=number][data-stepper]): те же −/+, уважаем min/max/step, число
  // можно и вписать руками. Событие input отдаём наружу — фильтры страницы срабатывают как раньше.
  function enhanceNumber(inp) {
    if (inp.getAttribute('data-stepper-ready')) return;
    inp.setAttribute('data-stepper-ready', '1');
    var wrap = document.createElement('span');
    wrap.className = 'stp stp-num';
    var dec = btn('−', 'меньше'), inc = btn('+', 'больше');
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(dec); wrap.appendChild(inp); wrap.appendChild(inc);
    function num(v, d) { var n = parseFloat(v); return isNaN(n) ? d : n; }
    function sync() {
      var v = num(inp.value, 0);
      dec.disabled = v <= num(inp.min, -Infinity);
      inc.disabled = v >= num(inp.max, Infinity);
    }
    function step(dir) {
      var min = num(inp.min, -Infinity), max = num(inp.max, Infinity), st = num(inp.step, 1) || 1;
      var v = num(inp.value, min > -Infinity ? min : 0) + dir * st;
      inp.value = String(Math.min(max, Math.max(min, v)));
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      inp.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dec.addEventListener('click', function () { step(-1); });
    inc.addEventListener('click', function () { step(1); });
    inp.addEventListener('input', sync);
    inp.addEventListener('change', sync);
    sync();
  }
  document.querySelectorAll('input[type="number"][data-stepper]').forEach(enhanceNumber);

  function enhanceStepper(sel) {
    if (sel.getAttribute('data-stepper-ready')) return;
    sel.setAttribute('data-stepper-ready', '1');
    var wrap = document.createElement('span');
    wrap.className = 'stp';
    if (sel.title) wrap.title = sel.title;
    var dec = btn('−', 'меньше'), inc = btn('+', 'больше');
    var val = document.createElement('span');
    val.className = 'stp-v';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(dec); wrap.appendChild(val); wrap.appendChild(inc); wrap.appendChild(sel);
    sel.style.display = 'none';
    var last = sel.options.length - 1;
    function sync() {
      var i = sel.selectedIndex, o = sel.options[i];
      val.textContent = o ? o.text : '';
      dec.disabled = i <= 0;
      inc.disabled = i >= last;
      wrap.classList.toggle('stp-zero', i <= 0);
      wrap.classList.toggle('stp-max', sel.hasAttribute('data-stepper-warn') && i === last && last > 0);
    }
    function step(d) {
      var i = Math.max(0, Math.min(last, sel.selectedIndex + d));
      if (i === sel.selectedIndex) return;
      sel.selectedIndex = i;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dec.addEventListener('click', function () { step(-1); });
    inc.addEventListener('click', function () { step(1); });
    sel.addEventListener('change', sync);
    sync();
  }
  document.querySelectorAll('select[data-stepper]').forEach(enhanceStepper);
})();

// ── «!» в рейтинге (игрока нет в системе): на телефоне наведения нет — подсказка открывается
// нажатием. Знак стоит внутри ссылки на профиль, поэтому нажатие по нему не уводит со страницы.
(function () {
  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('.rt-unreg') : null;
    document.querySelectorAll('.rt-unreg.show').forEach(function (x) {
      if (x !== t) x.classList.remove('show');
    });
    if (t) {
      e.preventDefault();
      t.classList.toggle('show');
    }
  });
})();

// ── Окно подтверждения в стиле сайта вместо белых системных confirm()/alert() ──
// triadaConfirm(текст, onOk, onCancel) и triadaAlert(текст, onDone) — для кода страниц.
// Старые onsubmit/onclick/onchange="…confirm('…')…" переписывать не нужно: перехватываем их
// заранее (в фазе погружения), текст берём, вызвав сам обработчик с подменённым confirm —
// так все экранирования и переносы разбирает движок JS. Разметка — .ach-modal: кнопка «Назад»
// в приложении шлёт Escape для открытых .ach-modal, а Escape здесь — это «Отмена».
(function () {
  var dlg = null, msgEl, okBtn, cancelBtn, onOk = null, onCancel = null;
  function build() {
    if (dlg) return;
    dlg = document.createElement('div');
    dlg.className = 'ach-modal tr-dlg';
    dlg.innerHTML = '<div class="ach-modal-box tr-dlg-box" role="alertdialog" aria-modal="true">'
      + '<div class="tr-dlg-msg"></div>'
      + '<div class="tr-dlg-btns"><button type="button" class="btn btn-ghost tr-dlg-cancel">Отмена</button>'
      + '<button type="button" class="btn tr-dlg-ok">Да</button></div></div>';
    document.body.appendChild(dlg);
    msgEl = dlg.querySelector('.tr-dlg-msg');
    okBtn = dlg.querySelector('.tr-dlg-ok');
    cancelBtn = dlg.querySelector('.tr-dlg-cancel');
    okBtn.addEventListener('click', function () { finish(true); });
    cancelBtn.addEventListener('click', function () { finish(false); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) finish(false); });
    document.addEventListener('keydown', function (e) {
      if (!dlg.classList.contains('open')) return;
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      else if (e.key === 'Enter') { e.preventDefault(); finish(true); }
    });
  }
  function finish(ok) {
    if (!dlg.classList.contains('open')) return;
    dlg.classList.remove('open');
    var cb = ok ? onOk : onCancel;
    onOk = null; onCancel = null;
    if (cb) cb();
  }
  function show(msg, isAlert, ok, cancel) {
    build();
    msgEl.textContent = String(msg);
    cancelBtn.hidden = !!isAlert;
    okBtn.textContent = isAlert ? 'Понятно' : 'Да';
    onOk = ok || null;
    onCancel = cancel || null;
    dlg.classList.add('open');
    okBtn.focus();
  }
  window.triadaConfirm = function (msg, ok, cancel) { show(msg, false, ok, cancel); };
  window.triadaAlert = function (msg, done) { show(msg, true, done, done); };

  // Текст из старого обработчика: вызываем его с confirm, который запоминает текст и отвечает «нет».
  function grabConfirm(el, prop, ev) {
    var fn = el[prop];
    if (typeof fn !== 'function') return null;
    var msg = null, orig = window.confirm;
    window.confirm = function (m) { msg = m; return false; };
    try { fn.call(el, ev); } catch (err) {} finally { window.confirm = orig; }
    return msg;
  }
  // Отправка в обход onsubmit (иначе снова спросит); нажатая кнопка уходит в форму как обычно.
  function submitForm(form, submitter) {
    if (submitter && submitter.name) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
      form.appendChild(h);
    }
    HTMLFormElement.prototype.submit.call(form);
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || (f.getAttribute('onsubmit') || '').indexOf('confirm(') === -1) return;
    var msg = grabConfirm(f, 'onsubmit', e);
    if (msg === null) return;                    // до confirm дело не дошло — всё как было
    e.preventDefault();
    e.stopPropagation();
    var submitter = e.submitter || null;
    window.triadaConfirm(msg, function () { submitForm(f, submitter); });
  }, true);
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[onclick*="confirm("]') : null;
    if (!el) return;
    var msg = grabConfirm(el, 'onclick', e);
    if (msg === null) return;
    e.preventDefault();
    e.stopPropagation();
    window.triadaConfirm(msg, function () {
      if (el.form) submitForm(el.form, el);
      else if (el.href) location.href = el.href;
    });
  }, true);
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute || (el.getAttribute('onchange') || '').indexOf('confirm(') === -1) return;
    var msg = grabConfirm(el, 'onchange', e);
    if (msg === null) return;
    e.stopPropagation();
    window.triadaConfirm(msg, function () {
      var orig = window.confirm;
      window.confirm = function () { return true; };
      try { el.onchange.call(el, e); } finally { window.confirm = orig; }
    }, function () {
      // Отмена — возвращаем список к исходному значению (например, роль в «Пользователях»).
      if (el.tagName === 'SELECT') {
        [].forEach.call(el.options, function (o) { o.selected = o.defaultSelected; });
      }
    });
  }, true);
})();

// ── Роль и победа в протоколе — цветом: data-role на select.f-role (мирный, мафия, дон, шериф)
// и data-win на select.f-win (красные, чёрные, ничья), красит CSS ──
(function () {
  var SEL = 'select.f-role, select.f-win';
  function paint(s) { s.setAttribute(s.classList.contains('f-win') ? 'data-win' : 'data-role', s.value); }
  document.querySelectorAll(SEL).forEach(paint);
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches(SEL)) paint(t);
  });
})();
