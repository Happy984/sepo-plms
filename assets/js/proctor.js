/* Sepo LMS — proctoring for assignments and quizzes.
 *
 * An element with data-proctor='{"item":…, "record":bool, "lockdown":bool, "leaves":n, "note":""}'
 * stays hidden behind a consent screen until the learner agrees and the
 * session is running. Then it receives "slms:proctor-ready" and carries the
 * session token in data-proctor-token. When the leave limit is exceeded it
 * receives "slms:proctor-submit". It sends "slms:submitted" once the work is
 * in, which ends the session.
 *
 * Before handing in, callers run element.slmsProctorFlush() so the last
 * seconds of the recording reach the server first.
 *
 * Full screen:
 *  - Pressing Esc asks before leaving full screen (Chrome and Edge, through
 *    the Keyboard Lock API); choosing to leave is recorded.
 *  - When full screen closes without being asked (the browser, holding Esc,
 *    F11), the learner has GRACE_S seconds to return before it counts.
 *  - The browser's own windows (the file chooser, a download) can close full
 *    screen or take focus. While one is open nothing is counted, and the
 *    learner is asked to return to full screen afterwards.
 * Following a link off the page asks first; leaving ends the session.
 */
(function () {
  'use strict';
  var UI = window.SepoLmsUI;
  var CFG = window.SepoLms || {};
  if (!UI) return;
  var esc = UI.esc, t = UI.t, api = UI.api;

  var CHUNK_MS = 4000;
  var DEDUPE_MS = 1500;
  var GRACE_S = 15;
  var PICKER_MAX_MS = 5 * 60 * 1000;
  var DOWNLOAD_MS = 4000;

  function supported(rules) {
    var canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && window.MediaRecorder);
    var canFull = !!(document.documentElement.requestFullscreen);
    if (rules.record && !canRecord) return false;
    if (rules.lockdown && !canFull) return false;
    return true;
  }

  function pickMime() {
    var types = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function Proctor(el) {
    var rules = {};
    try { rules = JSON.parse(el.getAttribute('data-proctor') || '{}'); } catch (e) { return; }
    var token = '', active = false, finished = false;
    var stream = null, recorder = null, mime = pickMime(), part = 0, seq = 0;
    var queue = [], uploading = false, failures = 0;
    var violations = 0, lastViolation = 0, submitting = false;
    var bar = null, gate = null, cover = null;
    var interrupt = null, interruptTimer = null, fullLostDuring = false;
    var leavingByChoice = false, graceTimer = null, graceTick = null, lostAt = 0, allowLeave = false;

    el.classList.add('slms-proctor-locked');

    // ---------------------------------------------------------------- consent
    gate = document.createElement('div');
    gate.className = 'slms-proctor-gate';
    var rulesList = '';
    if (rules.record) rulesList += '<li>' + esc(t('pRuleRecord')) + '</li>';
    if (rules.lockdown) rulesList += '<li>' + esc(t('pRuleFull')) + '</li>';
    rulesList += '<li>' + esc(t('pRuleLeave')) + '</li>';
    if (rules.leaves > 0) rulesList += '<li><strong>' + esc(t('pRuleLimit', rules.leaves)) + '</strong></li>';
    var ok = supported(rules);
    gate.innerHTML = '<h2 class="slms-h3">' + esc(t('pTitle')) + '</h2>'
      + '<ul class="slms-proctor-rules">' + rulesList + '</ul>'
      + (rules.note ? '<p class="slms-note">' + esc(rules.note) + '</p>' : '')
      + (ok
        ? '<label class="slms-check-row"><input type="checkbox" data-agree> <span>' + esc(t('pAgree')) + '</span></label>'
          + '<p class="slms-error" data-err role="alert"></p>'
          + '<div class="slms-row slms-row--start"><button type="button" class="slms-btn slms-btn--primary" data-start disabled>' + esc(rules.record ? t('pStartShare') : t('pStart')) + '</button></div>'
          + (rules.record ? '<p class="slms-small slms-muted">' + esc(t('pShareHint')) + '</p>' : '')
        : '<p class="slms-note slms-note--bad">' + esc(t('pUnsupported')) + '</p>');
    el.insertBefore(gate, el.firstChild);

    var agree = gate.querySelector('[data-agree]');
    var startBtn = gate.querySelector('[data-start]');
    var gateErr = gate.querySelector('[data-err]');
    if (agree) agree.addEventListener('change', function () { startBtn.disabled = !agree.checked; });
    if (startBtn) startBtn.addEventListener('click', start);

    function fail(msg) {
      if (gateErr) gateErr.textContent = msg;
      if (startBtn) { startBtn.disabled = !(agree && agree.checked); startBtn.textContent = rules.record ? t('pStartShare') : t('pStart'); }
    }

    function share() {
      return navigator.mediaDevices.getDisplayMedia({
        video: { displaySurface: 'monitor', frameRate: { ideal: 5, max: 10 }, width: { max: 1920 }, height: { max: 1080 } },
        audio: false,
        monitorTypeSurfaces: 'include',
        surfaceSwitching: 'exclude',
        selfBrowserSurface: 'include'
      }).then(function (s) {
        var track = s.getVideoTracks()[0];
        var surface = track && track.getSettings ? (track.getSettings().displaySurface || '') : '';
        if (surface && surface !== 'monitor') {
          s.getTracks().forEach(function (tr) { tr.stop(); });
          throw new Error(t('pNeedScreen'));
        }
        return { stream: s, surface: surface || 'monitor' };
      }, function (err) {
        throw new Error(err && err.name === 'NotAllowedError' ? t('pShareDenied') : t('pShareFailed'));
      });
    }

    function start() {
      startBtn.disabled = true;
      startBtn.textContent = t('pStarting');
      gateErr.textContent = '';
      var shared = rules.record ? share() : Promise.resolve({ stream: null, surface: '' });
      shared.then(function (s) {
        stream = s.stream;
        return api('/proctor/start', 'POST', { item: rules.item, recording: !!rules.record, surface: s.surface }).then(function (d) {
          token = d.token;
          el.setAttribute('data-proctor-token', token);
          if (stream) beginRecording(stream);
          if (rules.lockdown) askFullscreen(true);
          else ready();
        });
      }).catch(function (e) {
        if (stream) { stream.getTracks().forEach(function (tr) { tr.stop(); }); stream = null; }
        fail(e.message);
      });
    }

    // Browsers only enter full screen from a click, so each entry goes
    // through a button on the cover.
    function enterFullscreen() {
      return document.documentElement.requestFullscreen({ navigationUI: 'hide' }).then(function () {
        // With Escape locked, a short press reaches the page (which asks
        // first) and only a long press leaves full screen.
        if (navigator.keyboard && navigator.keyboard.lock) navigator.keyboard.lock(['Escape']).catch(function () {});
      });
    }

    function askFullscreen(first) {
      showCover(first ? t('pFullTitle') : t('pBackTitle'), first ? t('pFullBody') : t('pBackBody'), t('pEnterFull'), function () {
        enterFullscreen().then(function () {
          hideCover();
          if (first) ready(); else log('fullscreen_enter', '');
        }).catch(function () { coverError(t('pFullFailed')); });
      });
      if (first && gate) { gate.remove(); gate = null; }
    }

    /** Full screen closed without being asked: a short grace period before it counts. */
    function fullscreenLost() {
      lostAt = Date.now();
      log('fullscreen_lost', '');
      var left = GRACE_S;
      showCover(t('pLostTitle'), t('pLostBody', left), t('pEnterFull'), function () {
        enterFullscreen().then(function () {
          var counted = !graceTimer;
          stopGrace();
          hideCover();
          log(counted ? 'fullscreen_enter' : 'fullscreen_back', counted ? '' : t('pBackAfter', Math.round((Date.now() - lostAt) / 1000)));
        }).catch(function () { coverError(t('pFullFailed')); });
      });
      graceTick = setInterval(function () {
        left--;
        if (cover && left > 0) cover.querySelector('[data-cbody]').textContent = t('pLostBody', left);
      }, 1000);
      graceTimer = setTimeout(function () {
        stopGrace();
        violation('fullscreen_exit', t('pNotBack', GRACE_S));
        if (cover) cover.querySelector('[data-cbody]').textContent = t('pBackBody');
      }, GRACE_S * 1000);
    }

    function stopGrace() {
      clearTimeout(graceTimer); graceTimer = null;
      clearInterval(graceTick); graceTick = null;
    }

    /** After the browser's own window closed: back to full screen, nothing counted. */
    function resumeAfterInterrupt() {
      showCover(t('pPickerTitle'), t('pPickerBody'), t('pEnterFull'), function () {
        enterFullscreen().then(function () {
          hideCover();
          log('fullscreen_back', '');
        }).catch(function () { coverError(t('pFullFailed')); });
      });
    }

    // ------------------------------------------- the browser's own windows
    function startInterrupt(kind) {
      if (!active || finished) return;
      clearTimeout(interruptTimer);
      if (!interrupt) {
        interrupt = { kind: kind, since: Date.now() };
        fullLostDuring = false;
        if (kind === 'file') log('file_picker_open', '');
      }
      interruptTimer = setTimeout(endInterrupt, kind === 'file' ? PICKER_MAX_MS : DOWNLOAD_MS);
    }

    function endInterrupt() {
      if (!interrupt) return;
      clearTimeout(interruptTimer);
      var it = interrupt;
      interrupt = null;
      if (it.kind === 'file') log('file_picker_closed', t('pBackAfter', Math.round((Date.now() - it.since) / 1000)));
      if (rules.lockdown && active && !finished && !submitting && (fullLostDuring || !document.fullscreenElement)) resumeAfterInterrupt();
      fullLostDuring = false;
    }

    // ------------------------------------------------------- leaving, asked
    function askLeaveFullscreen() {
      UI.confirmModal(t('pLeaveFullTitle'), t('pLeaveFullBody'), t('pLeaveFullOk'), { cancel: t('pStay') }).then(function (yes) {
        if (!yes) { log('leave_asked', 'full screen'); return; }
        leavingByChoice = true;
        violation('fullscreen_exit', t('pByChoice'));
        document.exitFullscreen().catch(function () { leavingByChoice = false; });
      });
    }

    function askLeavePage(href) {
      UI.confirmModal(t('pLeavePageTitle'), t('pLeavePageBody'), t('pLeavePageOk'), { cancel: t('pStay') }).then(function (yes) {
        if (!yes) { log('leave_asked', 'link'); return; }
        allowLeave = true;
        // Other unsaved-work prompts on the page stand down: the learner has just confirmed.
        window.SepoLmsLeaving = true;
        log('left_page', href.slice(0, 100)).then(function () {
          endSession('left_page');
          window.location.href = href;
        });
      });
    }

    function endSession(reason) {
      if (!token || finished) return;
      try {
        fetch(CFG.api + '/proctor/end', {
          method: 'POST', credentials: 'same-origin', keepalive: true,
          headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: token, reason: reason })
        });
      } catch (e) { /* the server closes stale sessions */ }
    }

    function ready() {
      if (gate) { gate.remove(); gate = null; }
      active = true;
      el.classList.remove('slms-proctor-locked');
      document.body.classList.add('slms-proctoring');
      showBar();
      watch();
      if (rules.lockdown) log('fullscreen_enter', '');
      el.dispatchEvent(new CustomEvent('slms:proctor-ready'));
    }

    // -------------------------------------------------------------- recording
    function beginRecording(s) {
      part++;
      seq = 0;
      try {
        recorder = new MediaRecorder(s, mime ? { mimeType: mime, videoBitsPerSecond: 600000 } : { videoBitsPerSecond: 600000 });
      } catch (e) {
        recorder = new MediaRecorder(s);
      }
      var myPart = part;
      recorder.ondataavailable = function (ev) {
        if (ev.data && ev.data.size > 0) {
          queue.push({ part: myPart, seq: seq++, blob: ev.data });
          pump();
        }
      };
      recorder.start(CHUNK_MS);
      log('share_started', 'part ' + part);
      var track = s.getVideoTracks()[0];
      if (track) track.addEventListener('ended', function () { if (active && !finished) shareStopped(); });
    }

    function pump() {
      if (uploading || !queue.length || !token) return;
      uploading = true;
      var c = queue[0];
      var url = CFG.api + '/proctor/chunk?token=' + encodeURIComponent(token) + '&part=' + c.part + '&seq=' + c.seq;
      fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': (mime || 'video/webm').split(';')[0] },
        body: c.blob
      }).then(function (r) {
        if (!r.ok && r.status !== 409) throw new Error('upload ' + r.status);
        queue.shift();
        failures = 0;
      }).catch(function () {
        failures++;
      }).then(function () {
        uploading = false;
        if (queue.length) setTimeout(pump, failures ? Math.min(15000, 1000 * failures) : 0);
        else resolveFlush();
      });
    }

    var flushWaiters = [];
    function resolveFlush() {
      var w = flushWaiters; flushWaiters = [];
      w.forEach(function (fn) { fn(); });
    }
    el.slmsProctorFlush = function () {
      if (!recorder || recorder.state !== 'recording') return Promise.resolve();
      return new Promise(function (resolve) {
        var done = false;
        function finish() { if (!done) { done = true; resolve(); } }
        flushWaiters.push(finish);
        try { recorder.requestData(); } catch (e) { finish(); }
        setTimeout(function () { if (!queue.length && !uploading) finish(); }, 400);
        setTimeout(finish, 20000);
      });
    };

    function shareStopped() {
      violation('share_stopped', '');
      if (recorder && recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) { /* already stopped */ } }
      showCover(t('pShareStoppedTitle'), t('pShareStoppedBody'), t('pShareAgain'), function () {
        share().then(function (s) {
          stream = s.stream;
          beginRecording(stream);
          hideCover();
          if (rules.lockdown && !document.fullscreenElement) askFullscreen(false);
        }).catch(function (e) { coverError(e.message); });
      });
    }

    // ---------------------------------------------------------------- watch
    function watch() {
      document.addEventListener('visibilitychange', function () {
        if (!active || finished) return;
        if (document.hidden) violation('leave', 'tab');
        else log('return', '');
      });
      window.addEventListener('blur', function () {
        if (!active || finished || interrupt) return;
        setTimeout(function () {
          if (interrupt || document.hidden || document.hasFocus()) return;
          var a = document.activeElement;
          if (a && a.tagName === 'IFRAME') return;
          violation('leave', 'window');
        }, 300);
      });
      window.addEventListener('focus', function () {
        // The file chooser hands focus back when it closes; give its change
        // or cancel event a moment to arrive first.
        if (interrupt && interrupt.kind === 'file') setTimeout(endInterrupt, 400);
      });

      // File inputs open the browser's file chooser.
      el.addEventListener('click', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[type="file"]')) startInterrupt('file');
      }, true);
      ['change', 'cancel'].forEach(function (type) {
        el.addEventListener(type, function (e) {
          if (e.target && e.target.matches && e.target.matches('input[type="file"]')) setTimeout(endInterrupt, 200);
        }, true);
      });

      if (rules.lockdown) {
        document.addEventListener('fullscreenchange', function () {
          if (!active || finished || submitting || document.fullscreenElement) return;
          if (leavingByChoice) { leavingByChoice = false; askFullscreen(false); return; }
          if (interrupt) { fullLostDuring = true; log('fullscreen_lost', interrupt.kind === 'file' ? 'file chooser' : 'download'); return; }
          fullscreenLost();
        });
        document.addEventListener('keydown', function (e) {
          if (e.key !== 'Escape' || !active || finished || !document.fullscreenElement) return;
          if (cover || document.querySelector('.slms-modal')) return;
          e.preventDefault();
          e.stopPropagation();
          askLeaveFullscreen();
        }, true);
      }

      // Links that leave the page ask first; downloads stay on the page.
      document.addEventListener('click', function (e) {
        if (!active || finished || allowLeave || e.defaultPrevented || e.button !== 0) return;
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
        if (a.hasAttribute('download') || /[?&]slms_dl=/.test(href)) { startInterrupt('download'); return; }
        if (a.target && a.target !== '_self') return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        askLeavePage(a.href);
      }, true);

      ['copy', 'paste'].forEach(function (type) {
        document.addEventListener(type, function () { if (active && !finished) log(type, ''); });
      });
      document.addEventListener('keyup', function (e) {
        if (active && !finished && e.key === 'PrintScreen') log('print_screen', '');
      });
      // Closing the tab, reloading or typing a new address: the browser asks.
      window.addEventListener('beforeunload', function (e) {
        if (!active || finished || allowLeave || !token) return;
        e.preventDefault();
        e.returnValue = '';
      });
      // The session ends only once the page is really going.
      window.addEventListener('pagehide', function () {
        if (active && !finished) endSession('left_page');
      });
    }

    function log(type, detail) {
      if (!token) return Promise.resolve({});
      return api('/proctor/event', 'POST', { token: token, type: type, detail: detail || '' }).catch(function () { return {}; });
    }

    function violation(type, detail) {
      var now = Date.now();
      if (now - lastViolation < DEDUPE_MS) return;
      lastViolation = now;
      log(type, detail).then(function (d) {
        if (typeof d.violations === 'number') violations = d.violations;
        updateBar();
        if (d.action === 'submit') autoSubmit();
        else warn();
      });
    }

    function warn() {
      if (finished) return;
      var msg = rules.leaves > 0 ? t('pWarnLimit', violations, rules.leaves) : t('pWarn', violations);
      toast(msg);
    }

    function autoSubmit() {
      if (submitting || finished) return;
      submitting = true;
      stopGrace();
      log('auto_submit', '');
      hideCover();
      toast(t('pAuto'), true);
      el.dispatchEvent(new CustomEvent('slms:proctor-submit'));
    }

    el.addEventListener('slms:submitted', function () {
      finished = true;
      active = false;
      stopGrace();
      clearTimeout(interruptTimer);
      interrupt = null;
      document.body.classList.remove('slms-proctoring');
      if (navigator.keyboard && navigator.keyboard.unlock) navigator.keyboard.unlock();
      // The recording was flushed before the work was sent and the server has
      // closed the session, so the recorder stops without uploading more.
      queue.length = 0;
      if (recorder) {
        recorder.ondataavailable = null;
        if (recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) { /* stopped */ } }
      }
      if (stream) stream.getTracks().forEach(function (tr) { tr.stop(); });
      if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
      if (bar) { bar.classList.add('is-done'); bar.querySelector('[data-state]').textContent = t('pDone'); setTimeout(function () { bar.remove(); }, 4000); }
    });

    // --------------------------------------------------------------- ui bits
    function showBar() {
      bar = document.createElement('div');
      bar.className = 'slms-proctor-bar';
      bar.setAttribute('role', 'status');
      bar.innerHTML = '<span class="slms-rec-dot" aria-hidden="true"></span><span data-state>' + esc(rules.record ? t('pRecording') : t('pProctored')) + '</span>'
        + '<span class="slms-proctor-leaves" data-leaves></span>';
      document.body.appendChild(bar);
      updateBar();
    }

    function updateBar() {
      if (!bar) return;
      var l = bar.querySelector('[data-leaves]');
      l.textContent = rules.leaves > 0 ? t('pLeavesOf', violations, rules.leaves) : t('pLeaves', violations);
      bar.classList.toggle('is-warn', violations > 0);
    }

    var toastTimer = null;
    function toast(msg, sticky) {
      var box = document.querySelector('.slms-proctor-toast');
      if (!box) {
        box = document.createElement('div');
        box.className = 'slms-proctor-toast';
        box.setAttribute('role', 'alert');
        document.body.appendChild(box);
      }
      box.textContent = msg;
      box.hidden = false;
      clearTimeout(toastTimer);
      if (!sticky) toastTimer = setTimeout(function () { box.hidden = true; }, 6000);
    }

    function showCover(title, body, button, onClick) {
      hideCover();
      el.classList.add('slms-proctor-locked');
      cover = document.createElement('div');
      cover.className = 'slms-proctor-cover';
      cover.innerHTML = '<div class="slms-proctor-cover-card"><h2 class="slms-h3">' + esc(title) + '</h2><p data-cbody>' + esc(body) + '</p>'
        + '<p class="slms-error" data-cerr role="alert"></p><button type="button" class="slms-btn slms-btn--primary">' + esc(button) + '</button></div>';
      document.body.appendChild(cover);
      var b = cover.querySelector('button');
      b.addEventListener('click', onClick);
      b.focus();
    }
    function coverError(msg) { if (cover) cover.querySelector('[data-cerr]').textContent = msg; }
    function hideCover() {
      if (cover) { cover.remove(); cover = null; }
      if (active) el.classList.remove('slms-proctor-locked');
    }
  }

  function boot() {
    UI.$$('[data-proctor]').forEach(function (el) { if (!el.slmsProctor) { el.slmsProctor = true; Proctor(el); } });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
