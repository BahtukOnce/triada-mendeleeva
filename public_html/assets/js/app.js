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
})();
