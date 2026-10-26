/* Sepo LMS — light or dark, following the theme.
 *
 * Themes switch to dark in many ways: a class or data attribute on <html> or
 * <body>, a stylesheet for prefers-color-scheme, or new values for their own
 * colour tokens. A theme that marks its mode (data-theme="dark", a "dark"
 * class and the like) is taken at its word; otherwise this reads the colours
 * the page actually shows behind the LMS. Either way <html> gets slms-dark or
 * slms-light.
 * It also passes the page's background and text colours to the stylesheet, so
 * dropdowns, dialogs and panels match whatever theme is active.
 *
 * It runs again whenever <html> or <body> changes its class, style or data
 * attributes, and when the device switches between light and dark.
 */
(function () {
  'use strict';
  var forced = window.SepoLmsMode === 'light' || window.SepoLmsMode === 'dark' ? window.SepoLmsMode : '';
  var root = document.documentElement;
  var styleEl = null, lastCss = '', timer = 0;

  function rgba(str) {
    var m = String(str).match(/rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)/);
    if (!m) return null;
    var a = m[4] === undefined ? 1 : (m[4].slice(-1) === '%' ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
    return [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]), a];
  }

  function luminance(c) {
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  }

  /** The first solid background behind an element, or null if none is painted. */
  function backgroundBehind(el) {
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var c = rgba(getComputedStyle(n).backgroundColor);
      if (c && c[3] >= 0.5) return c;
    }
    return null;
  }

  function setClass(name, on) {
    if (root.classList.contains(name) !== on) root.classList.toggle(name, on);
  }

  function apply(dark, paint, bg, fg) {
    setClass('slms-dark', dark);
    setClass('slms-light', !dark);
    setClass('slms-paint', paint);
    var css = '';
    if (bg) css += '--slms-page-bg:rgb(' + bg.slice(0, 3).map(Math.round).join(',') + ');';
    if (fg) css += '--slms-page-fg:rgb(' + fg.slice(0, 3).map(Math.round).join(',') + ');';
    css = css ? ':root{' + css + '}' : '';
    if (css === lastCss) return;
    lastCss = css;
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'sepo-lms-mode';
      (document.head || root).appendChild(styleEl);
    }
    styleEl.textContent = css;
  }

  var DARK_ATTRS = ['data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'];
  var DARK_CLASSES = /(^|\s)(dark|dark-mode|theme-dark|is-dark-theme|wp-dark-mode-active)(\s|$)/;
  var LIGHT_CLASSES = /(^|\s)(light|light-mode|theme-light|is-light-theme)(\s|$)/;

  /** A theme's own statement of its mode, when it makes one: 'dark', 'light' or ''. */
  function declared() {
    var nodes = [root, document.body];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n) continue;
      for (var j = 0; j < DARK_ATTRS.length; j++) {
        var v = (n.getAttribute(DARK_ATTRS[j]) || '').toLowerCase();
        if (v === 'dark' || v === 'light') return v;
      }
      var cls = (n.className && typeof n.className === 'string' ? n.className : '').replace(/\bslms-(dark|light|paint)\b/g, '');
      if (DARK_CLASSES.test(cls)) return 'dark';
      if (LIGHT_CLASSES.test(cls)) return 'light';
    }
    return '';
  }

  function detect() {
    if (!document.body) return;
    var probe = document.querySelector('.slms-wrap') || document.body;
    var start = probe.parentElement || document.body;
    var bg = backgroundBehind(start);
    var fg = rgba(getComputedStyle(probe).color);
    var dark;
    var stated = declared();
    if (forced) {
      dark = forced === 'dark';
    } else if (stated) {
      // A theme that says which mode it is in is taken at its word.
      dark = stated === 'dark';
    } else if (bg) {
      dark = luminance(bg) < 0.3;
    } else if (fg) {
      // Nothing painted: the browser canvas shows through, so the text colour decides.
      dark = luminance(fg) > 0.55;
    } else {
      dark = false;
    }
    // Page colours are passed on only when they agree with the chosen mode,
    // so a forced mode is never handed the opposite theme's colours.
    // When a mode set in LMS → Settings differs from the theme's, the LMS
    // paints its own background (slms-paint).
    var bgOk = !!bg && (luminance(bg) < 0.3) === dark;
    apply(dark, !!forced && !!bg && !bgOk, bgOk ? bg : null, bgOk ? fg : null);
  }

  /** Themes often animate their colour change; read again once it settles. */
  function schedule() {
    window.requestAnimationFrame(detect);
    clearTimeout(timer);
    timer = setTimeout(detect, 400);
  }

  function watch() {
    detect();
    if (forced) return;
    var opts = { attributes: true, attributeFilter: ['class', 'style', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'] };
    var mo = new MutationObserver(function (records) {
      // Ignore the classes this script sets itself.
      for (var i = 0; i < records.length; i++) {
        var r = records[i];
        if (r.target === root && r.attributeName === 'class') {
          var before = (r.oldValue || '').replace(/\bslms-(dark|light|paint)\b/g, '').replace(/\s+/g, ' ').trim();
          var now = root.className.replace(/\bslms-(dark|light|paint)\b/g, '').replace(/\s+/g, ' ').trim();
          if (before === now) continue;
        }
        schedule();
        return;
      }
    });
    opts.attributeOldValue = true;
    mo.observe(root, opts);
    if (document.body) mo.observe(document.body, opts);
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', schedule);
      else if (mq.addListener) mq.addListener(schedule);
    }
    window.addEventListener('load', schedule);
  }

  if (forced) root.classList.add(forced === 'dark' ? 'slms-dark' : 'slms-light');
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch);
  else watch();
})();
