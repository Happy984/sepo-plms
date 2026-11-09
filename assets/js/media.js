/* Sepo LMS — the audio player and the file preview for course resources.
 *
 * [data-slms-audio] holds a native <audio>; this script hides it and drives
 * the custom controls around it. Only one player plays at a time.
 *
 * [data-slms-preview] buttons open their file in a dialog on the page: PDFs in
 * the browser's own viewer, pictures, text files, audio and video. Previous
 * and Next move through the other previewable files in the same list.
 */
(function () {
  'use strict';
  var L = window.SepoLmsMedia || {};
  function t(k, d) { return L[k] || d; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function time(s) {
    if (!isFinite(s) || s < 0) { s = 0; }
    s = Math.floor(s);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (x < 10 ? '0' : '') + x;
  }

  // ------------------------------------------------------------- player
  var RATES = [1, 1.25, 1.5, 1.75, 2, 0.75];
  var current = null;

  function player(root) {
    if (root.slmsReady) { return; }
    root.slmsReady = true;
    var a = root.querySelector('audio');
    if (!a) { return; }
    function q(n) { return root.querySelector('[data-ap="' + n + '"]'); }
    var play = q('play'), bar = q('bar'), fill = q('fill'), knob = q('knob'), buffer = q('buffer'), cur = q('cur'), left = q('left'), vol = q('volume'), rate = q('rate');
    root.classList.add('is-ready');
    a.controls = false;

    function paint() {
      var d = a.duration, p = isFinite(d) && d > 0 ? a.currentTime / d : 0;
      fill.style.width = (p * 100) + '%';
      knob.style.left = (p * 100) + '%';
      cur.textContent = time(a.currentTime);
      left.textContent = isFinite(d) && d > 0 ? '-' + time(d - a.currentTime) : '--:--';
      bar.setAttribute('aria-valuenow', String(Math.round(p * 100)));
      bar.setAttribute('aria-valuetext', time(a.currentTime) + (isFinite(d) ? ' / ' + time(d) : ''));
      if (a.buffered.length && isFinite(d) && d > 0) { buffer.style.width = (a.buffered.end(a.buffered.length - 1) / d * 100) + '%'; }
    }
    function state() {
      var on = !a.paused;
      root.classList.toggle('is-playing', on);
      play.setAttribute('aria-label', on ? t('pause', 'Pause') : t('play', 'Play'));
    }
    function seekTo(x) {
      var r = bar.getBoundingClientRect();
      var p = Math.min(1, Math.max(0, (x - r.left) / r.width));
      if (isFinite(a.duration)) { a.currentTime = p * a.duration; paint(); }
    }

    play.addEventListener('click', function () {
      if (a.paused) {
        if (current && current !== a) { current.pause(); }
        current = a;
        root.classList.add('is-loading');
        var pr = a.play();
        if (pr && pr.catch) { pr.catch(function () { root.classList.remove('is-loading'); root.classList.add('is-error'); }); }
      } else { a.pause(); }
    });
    q('back').addEventListener('click', function () { a.currentTime = Math.max(0, a.currentTime - 15); paint(); });
    q('fwd').addEventListener('click', function () { if (isFinite(a.duration)) { a.currentTime = Math.min(a.duration, a.currentTime + 15); paint(); } });
    rate.addEventListener('click', function () {
      var i = (RATES.indexOf(a.playbackRate) + 1) % RATES.length;
      a.playbackRate = RATES[i];
      rate.textContent = String(RATES[i]).replace(/^0/, '') + '×';
    });
    q('mute').addEventListener('click', function () { a.muted = !a.muted; root.classList.toggle('is-muted', a.muted); });
    vol.addEventListener('input', function () { a.volume = parseFloat(vol.value); a.muted = a.volume === 0; root.classList.toggle('is-muted', a.muted); });

    var dragging = false;
    bar.addEventListener('pointerdown', function (e) { dragging = true; bar.setPointerCapture(e.pointerId); seekTo(e.clientX); });
    bar.addEventListener('pointermove', function (e) { if (dragging) { seekTo(e.clientX); } });
    bar.addEventListener('pointerup', function () { dragging = false; });
    bar.addEventListener('keydown', function (e) {
      var step = { ArrowLeft: -5, ArrowRight: 5, PageDown: -30, PageUp: 30 }[e.key];
      if (step) { e.preventDefault(); a.currentTime = Math.max(0, Math.min(a.duration || 0, a.currentTime + step)); paint(); }
      if (e.key === 'Home') { e.preventDefault(); a.currentTime = 0; paint(); }
      if (e.key === 'End' && isFinite(a.duration)) { e.preventDefault(); a.currentTime = a.duration; paint(); }
    });

    ['play', 'pause', 'ended'].forEach(function (ev) { a.addEventListener(ev, state); });
    a.addEventListener('playing', function () { root.classList.remove('is-loading'); });
    a.addEventListener('waiting', function () { root.classList.add('is-loading'); });
    a.addEventListener('timeupdate', paint);
    a.addEventListener('progress', paint);
    a.addEventListener('loadedmetadata', paint);
    a.addEventListener('error', function () { root.classList.remove('is-loading'); root.classList.add('is-error'); });
    paint();
  }

  function players(scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll('[data-slms-audio]'), player);
  }

  // ------------------------------------------------------------- preview
  var box = null, list = [], at = 0, back = null;

  function bodyHtml(it) {
    if (it.kind === 'image') { return '<img src="' + esc(it.url) + '" alt="' + esc(it.name) + '">'; }
    if (it.kind === 'video') { return '<video controls autoplay src="' + esc(it.url) + '"></video>'; }
    if (it.kind === 'audio') { return it.player || '<audio controls src="' + esc(it.url) + '"></audio>'; }
    if (it.kind === 'text') { return '<pre class="slms-pv__text" data-text>' + esc(t('loading', 'Loading…')) + '</pre>'; }
    if (navigator.pdfViewerEnabled === false) {
      return '<div class="slms-pv__nopdf"><svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/></svg>'
        + '<p>' + esc(t('noPdf', 'This browser cannot show PDFs inside the page. Open it or download it instead.')) + '</p>'
        + '<div><a class="slms-btn" href="' + esc(it.url) + '" target="_blank" rel="noopener">' + esc(t('newTab', 'Open in a new tab')) + '</a>'
        + '<a class="slms-btn slms-btn--primary" href="' + esc(it.download || it.url) + '" download>' + esc(t('download', 'Download')) + '</a></div></div>';
    }
    return '<iframe src="' + esc(it.url) + '#view=FitH" title="' + esc(it.name) + '"></iframe>';
  }

  function show(i) {
    at = (i + list.length) % list.length;
    var it = list[at];
    box.querySelector('[data-pv-name]').textContent = it.name;
    box.querySelector('[data-pv-meta]').textContent = it.meta || '';
    box.querySelector('[data-pv-dl]').setAttribute('href', it.download || it.url);
    box.querySelector('[data-pv-tab]').setAttribute('href', it.url);
    var stage = box.querySelector('[data-pv-stage]');
    stage.className = 'slms-pv__stage slms-pv__stage--' + it.kind;
    stage.innerHTML = bodyHtml(it);
    players(stage);
    if (it.kind === 'text') {
      fetch(it.url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (txt) {
        var pre = stage.querySelector('[data-text]');
        if (pre) { pre.textContent = txt.length > 400000 ? txt.slice(0, 400000) + '\n…' : txt; }
      }).catch(function () { var pre = stage.querySelector('[data-text]'); if (pre) { pre.textContent = t('noPreview', 'This file could not be shown.'); } });
    }
    var many = list.length > 1;
    box.querySelector('[data-pv="prev"]').hidden = !many;
    box.querySelector('[data-pv="next"]').hidden = !many;
    box.querySelector('[data-pv-count]').textContent = many ? (at + 1) + ' / ' + list.length : '';
  }

  function close() {
    if (!box) { return; }
    box.remove();
    box = null;
    document.body.classList.remove('slms-modal-open');
    document.removeEventListener('keydown', keys, true);
    if (back && back.focus) { back.focus(); }
  }

  function keys(e) {
    if (!box) { return; }
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if ((e.key === 'ArrowRight' || e.key === 'ArrowLeft') && list.length > 1 && !/^(INPUT|TEXTAREA|VIDEO|AUDIO)$/.test(document.activeElement.tagName) && !document.activeElement.closest('[data-ap="bar"]')) {
      e.preventDefault(); show(at + (e.key === 'ArrowRight' ? 1 : -1));
    }
    if (e.key === 'Tab') {
      var f = Array.prototype.filter.call(box.querySelectorAll('a[href], button:not([disabled]), input, video, audio[controls], iframe, [tabindex="0"]'), function (x) { return x.offsetParent !== null; });
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function chev(d) { return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + d + '"/></svg>'; }

  function open(btn) {
    var scope = btn.closest('[data-slms-preview-group]') || document;
    list = Array.prototype.map.call(scope.querySelectorAll('[data-slms-preview]'), function (b) {
      var tpl = b.getAttribute('data-player-id') ? document.getElementById(b.getAttribute('data-player-id')) : null;
      return { btn: b, url: b.getAttribute('data-slms-preview'), kind: b.getAttribute('data-kind') || 'pdf', name: b.getAttribute('data-name') || '', meta: b.getAttribute('data-meta') || '', download: b.getAttribute('data-download') || '', player: tpl ? tpl.innerHTML : '' };
    });
    var i = 0;
    list.forEach(function (it, n) { if (it.btn === btn) { i = n; } });
    back = btn;
    close();
    back = btn;
    box = document.createElement('div');
    box.className = 'slms-modal slms-pv';
    box.innerHTML = '<div class="slms-pv__card" role="dialog" aria-modal="true" aria-labelledby="slms-pv-name" tabindex="-1">'
      + '<div class="slms-pv__bar"><div class="slms-pv__title"><strong id="slms-pv-name" data-pv-name></strong><small data-pv-meta></small></div>'
      + '<span class="slms-pv__count" data-pv-count></span>'
      + '<a class="slms-btn slms-btn--sm" data-pv-tab target="_blank" rel="noopener">' + esc(t('newTab', 'Open in a new tab')) + '</a>'
      + '<a class="slms-btn slms-btn--sm slms-btn--primary" data-pv-dl download>' + esc(t('download', 'Download')) + '</a>'
      + '<button type="button" class="slms-pv__close" data-pv="close" aria-label="' + esc(t('close', 'Close')) + '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>'
      + '<div class="slms-pv__main"><button type="button" class="slms-pv__nav is-prev" data-pv="prev" aria-label="' + esc(t('previous', 'Previous')) + '">' + chev('M15 6l-6 6 6 6') + '</button>'
      + '<div class="slms-pv__stage" data-pv-stage></div>'
      + '<button type="button" class="slms-pv__nav is-next" data-pv="next" aria-label="' + esc(t('next', 'Next')) + '">' + chev('M9 6l6 6-6 6') + '</button></div></div>';
    document.body.appendChild(box);
    document.body.classList.add('slms-modal-open');
    box.addEventListener('click', function (e) {
      if (e.target === box) { close(); return; }
      var b = e.target.closest('[data-pv]');
      if (!b) { return; }
      var act = b.getAttribute('data-pv');
      if (act === 'close') { close(); }
      if (act === 'prev') { show(at - 1); }
      if (act === 'next') { show(at + 1); }
    });
    document.addEventListener('keydown', keys, true);
    show(i);
    box.querySelector('[data-pv="close"]').focus();
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-slms-preview]') : null;
    if (!b) { return; }
    e.preventDefault();
    open(b);
  });

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { players(); }); } else { players(); }
  window.SepoLmsPlayers = players;
})();
