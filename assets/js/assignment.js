/* Sepo LMS — handing in an upload assignment. */
(function () {
  'use strict';
  var UI = window.SepoLmsUI;
  var CFG = window.SepoLms || {};
  if (!UI) return;
  var esc = UI.esc, t = UI.t, $ = UI.$, $$ = UI.$$;

  function sizeLabel(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return Math.round(n / 1024) + ' KB';
    return n + ' B';
  }

  function words(text) {
    var s = text.trim();
    return s ? s.split(/\s+/).length : 0;
  }

  function HandIn(form) {
    var id = parseInt(form.getAttribute('data-slms-assignment'), 10);
    var maxFiles = parseInt(form.getAttribute('data-max-files'), 10) || 0;
    var maxBytes = parseInt(form.getAttribute('data-max-bytes'), 10) || 0;
    var exts = (form.getAttribute('data-ext') || '').split(',').filter(Boolean);
    var textMode = form.getAttribute('data-text') || 'optional';
    var minWords = parseInt(form.getAttribute('data-min-words'), 10) || 0;
    var drop = $('[data-slms-drop]', form);
    var input = $('[data-slms-files]', form);
    var list = $('[data-slms-filelist]', form);
    var body = $('[data-slms-body]', form);
    var wordsEl = $('[data-slms-words]', form);
    var err = $('[data-slms-err]', form);
    var btn = $('[data-slms-handin]', form);
    var bar = $('[data-slms-upbar]', form);
    var files = [];
    var sending = false, dirty = false;

    if (form.hasAttribute('data-proctor')) {
      form.addEventListener('slms:proctor-submit', function () { send(true); });
      if (drop) drop.insertAdjacentHTML('afterend', '<p class="slms-small slms-muted">' + esc(t('aProctorPick')) + '</p>');
    }

    function check(f) {
      var ext = (f.name.split('.').pop() || '').toLowerCase();
      if (f.name.indexOf('.') === -1 || exts.indexOf(ext) === -1) return t('aBadType', f.name, exts.join(', '));
      if (maxBytes && f.size > maxBytes) return t('aTooBig', f.name, sizeLabel(maxBytes));
      if (f.size === 0) return t('aEmpty', f.name);
      return '';
    }

    function add(fileList) {
      err.textContent = '';
      Array.prototype.forEach.call(fileList, function (f) {
        var problem = check(f);
        if (problem) { err.textContent = problem; return; }
        if (files.some(function (x) { return x.name === f.name && x.size === f.size; })) return;
        if (files.length >= maxFiles) { err.textContent = t('aTooMany', maxFiles); return; }
        files.push(f);
        dirty = true;
      });
      render();
    }

    function render() {
      if (!list) return;
      list.innerHTML = files.map(function (f, i) {
        return '<li><span class="slms-file-name">' + esc(f.name) + '</span><span class="slms-muted slms-small">' + esc(sizeLabel(f.size)) + '</span>'
          + '<button type="button" class="slms-icon-btn" data-remove="' + i + '" aria-label="' + esc(t('aRemove', f.name)) + '">✕</button></li>';
      }).join('');
      if (drop) drop.classList.toggle('is-full', files.length >= maxFiles);
    }

    if (drop && input) {
      drop.addEventListener('click', function () { input.click(); });
      drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
      input.addEventListener('change', function () { add(input.files); input.value = ''; });
      ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
      });
      drop.addEventListener('drop', function (e) { if (e.dataTransfer) add(e.dataTransfer.files); });
    }
    if (list) list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-remove]');
      if (!b) return;
      files.splice(parseInt(b.getAttribute('data-remove'), 10), 1);
      render();
    });

    if (body && wordsEl) {
      var count = function () {
        var n = words(body.value);
        wordsEl.textContent = minWords ? t('aWordsMin', n, minWords) : t('aWords', n);
        wordsEl.classList.toggle('slms-bad', minWords > 0 && n > 0 && n < minWords);
      };
      body.addEventListener('input', function () { dirty = true; count(); });
      count();
    }

    window.addEventListener('beforeunload', function (e) {
      if (dirty && !sending && !window.SepoLmsLeaving) { e.preventDefault(); e.returnValue = ''; }
    });

    function validate() {
      var text = body ? body.value.trim() : '';
      if (textMode === 'required' && !text) return t('aNeedText');
      if (text && minWords && words(text) < minWords) return t('aNeedWords', minWords);
      if (!text && !files.length) return maxFiles > 0 && textMode !== 'off' ? t('aNeedSomething') : (maxFiles > 0 ? t('aNeedFile') : t('aNeedText'));
      var boxes = $$('input[name="confirm[]"]', form);
      if (boxes.length && boxes.some(function (b) { return !b.checked; })) return t('aConfirmAll');
      return '';
    }

    function send(auto) {
      if (sending) return;
      if (!auto) {
        var problem = validate();
        if (problem) { err.textContent = problem; return; }
      }
      sending = true;
      err.textContent = '';
      btn.disabled = true;
      btn.textContent = t('aSending');
      var fd = new FormData();
      fd.append('assignment', String(id));
      if (body) fd.append('body', body.value);
      $$('input[name="confirm[]"]:checked', form).forEach(function (b) { fd.append('confirm[]', b.value); });
      files.forEach(function (f) { fd.append('files[]', f, f.name); });
      fd.append('proctor', form.getAttribute('data-proctor-token') || '');
      if (auto) fd.append('auto', '1');

      var flush = form.slmsProctorFlush ? form.slmsProctorFlush() : Promise.resolve();
      flush.then(function () {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CFG.api + '/assignment/submit');
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
        xhr.setRequestHeader('Accept', 'application/json');
        if (bar && files.length) {
          bar.hidden = false;
          xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) bar.firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%';
          });
        }
        xhr.onload = function () {
          var d = {};
          try { d = JSON.parse(xhr.responseText); } catch (e) { d = {}; }
          if (xhr.status >= 200 && xhr.status < 300 && d.submission) {
            dirty = false;
            done(d.submission, auto);
            form.dispatchEvent(new CustomEvent('slms:submitted', { detail: d }));
          } else {
            failed(d.message || t('failed'));
          }
        };
        xhr.onerror = function () { failed(t('failed')); };
        xhr.send(fd);
      });
    }

    function failed(msg) {
      sending = false;
      btn.disabled = false;
      btn.textContent = t('aSubmit');
      if (bar) { bar.hidden = true; bar.firstElementChild.style.width = '0'; }
      err.textContent = msg;
    }

    function done(sub, auto) {
      var host = $('[data-slms-sub-status]');
      var h = '<section class="slms-card-box slms-sub slms-sub--submitted"><div class="slms-sub-head"><h2 class="slms-h3">' + esc(t('aYourHandIn')) + '</h2>'
        + '<span class="slms-status slms-status--pending">' + esc(sub.status_label) + '</span></div>'
        + '<p class="slms-small slms-muted">' + esc(t('aHandedIn', sub.submitted, sub.attempt)) + (sub.late ? ' · <strong class="slms-bad">' + esc(t('aLate')) + '</strong>' : '') + '</p>';
      if (sub.files && sub.files.length) {
        h += '<ul class="slms-filelist">' + sub.files.map(function (f) {
          return '<li><span class="slms-file-name">' + esc(f.name) + '</span><span class="slms-muted slms-small">' + esc(f.size) + '</span>'
            + (f.view ? '<button type="button" class="slms-btn slms-btn--sm" data-slms-view="' + esc(f.view) + '" data-kind="' + esc(f.kind) + '" data-name="' + esc(f.name) + '" aria-expanded="false">' + esc(t('aView')) + '</button>' : '')
            + '<a class="slms-btn slms-btn--sm" href="' + esc(f.url) + '">' + esc(t('aDownload')) + '</a></li>';
        }).join('') + '</ul>';
      }
      h += '</section>';
      if (auto) h = '<p class="slms-note slms-note--bad">' + esc(t('pAutoDone')) + '</p>' + h;
      if (host) host.innerHTML = h;
      form.innerHTML = '<p class="slms-note slms-note--ok" role="status">' + esc(t('aThanks')) + '</p>';
      if (host) host.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); send(false); });
  }

  function boot() {
    $$('[data-slms-assignment]').forEach(HandIn);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
