/* Sepo LMS — front end. No dependencies. */
(function () {
  'use strict';

  var CFG = window.SepoLms || { api: '', nonce: '', i18n: {} };
  var T = CFG.i18n || {};

  function t(key, a, b, c) {
    var s = T[key] || key;
    if (a !== undefined) s = s.replace('%1$s', a).replace('%s', a);
    if (b !== undefined) s = s.replace('%2$s', b);
    if (c !== undefined) s = s.replace('%3$s', c);
    return s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function api(path, method, body) {
    var opts = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce, 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(CFG.api + path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok) throw new Error((d && d.message) || t('failed'));
        return d;
      });
    });
  }

  // ------------------------------------------------------------------ modal
  function modal(html, opts) {
    opts = opts || {};
    var last = document.activeElement;
    var wrap = document.createElement('div');
    wrap.className = 'slms-modal';
    wrap.innerHTML = '<div class="slms-modal-card' + (opts.wide ? ' slms-modal-card--wide' : '') + '" role="dialog" aria-modal="true" tabindex="-1">' + html + '</div>';
    document.body.appendChild(wrap);
    document.body.classList.add('slms-modal-open');
    var card = wrap.firstChild;
    function close() {
      wrap.remove();
      document.body.classList.remove('slms-modal-open');
      document.removeEventListener('keydown', onKey);
      if (last && last.focus) last.focus();
      if (opts.onClose) opts.onClose();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    wrap.addEventListener('click', function (e) { if (e.target === wrap) close(); });
    $$('[data-close]', card).forEach(function (b) { b.addEventListener('click', close); });
    setTimeout(function () { var f = card.querySelector('input,select,textarea,button'); (f || card).focus(); }, 30);
    return { card: card, close: close };
  }

  function confirmModal(title, message, okLabel) {
    return new Promise(function (resolve) {
      var done = false;
      var m = modal('<h3 class="slms-modal-title">' + esc(title) + '</h3><p>' + esc(message) + '</p>'
        + '<div class="slms-modal-actions"><button type="button" class="slms-btn" data-close>' + esc(t('cancel')) + '</button>'
        + '<button type="button" class="slms-btn slms-btn--danger" data-ok>' + esc(okLabel) + '</button></div>',
        { onClose: function () { if (!done) resolve(false); } });
      m.card.querySelector('[data-ok]').addEventListener('click', function () { done = true; m.close(); resolve(true); });
    });
  }

  // ------------------------------------------------------------------ index
  function initIndex(root) {
    var items = $$('[data-slms-item]', root);
    var grid = $('[data-slms-items]', root);
    var q = $('[data-slms-q]', root);
    var filters = $$('[data-slms-filter]', root);
    var pager = $('[data-slms-pager]', root);
    var none = $('[data-slms-none]', root);
    var per = parseInt(root.getAttribute('data-per-page'), 10) || 12;
    var page = 1;

    try {
      var saved = localStorage.getItem('slms-view');
      if (saved === 'list' && grid) setView('list');
    } catch (e) { /* storage unavailable */ }

    function setView(v) {
      if (!grid) return;
      grid.classList.toggle('is-list', v === 'list');
      $$('[data-slms-view]', root).forEach(function (b) {
        var on = b.getAttribute('data-slms-view') === v;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      try { localStorage.setItem('slms-view', v); } catch (e) { /* ignore */ }
    }
    $$('[data-slms-view]', root).forEach(function (b) {
      b.addEventListener('click', function () { setView(b.getAttribute('data-slms-view')); });
    });

    function matches(el) {
      var term = q ? q.value.trim().toLowerCase() : '';
      if (term && (el.getAttribute('data-search') || '').indexOf(term) === -1) return false;
      for (var i = 0; i < filters.length; i++) {
        var f = filters[i], v = f.value;
        if (v && el.getAttribute('data-' + f.getAttribute('data-slms-filter')) !== v) return false;
      }
      return true;
    }

    function render() {
      var shown = items.filter(matches);
      var pages = Math.max(1, Math.ceil(shown.length / per));
      if (page > pages) page = pages;
      var from = (page - 1) * per;
      items.forEach(function (el) { el.hidden = true; });
      shown.slice(from, from + per).forEach(function (el) { el.hidden = false; });
      if (none) none.hidden = shown.length > 0;
      if (!pager) return;
      if (pages <= 1) { pager.innerHTML = ''; return; }
      var h = '<span class="slms-pager-info">' + esc(t('showing', from + 1, Math.min(from + per, shown.length), shown.length)) + '</span><span class="slms-pager-btns">';
      h += '<button type="button" class="slms-page" data-p="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="' + esc(t('prev')) + '">‹</button>';
      for (var p = 1; p <= pages; p++) {
        if (pages > 7 && p !== 1 && p !== pages && Math.abs(p - page) > 1) {
          if (p === 2 || p === pages - 1) h += '<span class="slms-page-gap">…</span>';
          continue;
        }
        h += '<button type="button" class="slms-page' + (p === page ? ' is-on' : '') + '" data-p="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
      }
      h += '<button type="button" class="slms-page" data-p="' + (page + 1) + '"' + (page === pages ? ' disabled' : '') + ' aria-label="' + esc(t('next')) + '">›</button></span>';
      pager.innerHTML = h;
    }

    if (pager) pager.addEventListener('click', function (e) {
      var b = e.target.closest('[data-p]');
      if (!b || b.disabled) return;
      page = parseInt(b.getAttribute('data-p'), 10) || 1;
      render();
      if (root.getBoundingClientRect().top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    if (q) q.addEventListener('input', function () { page = 1; render(); });
    filters.forEach(function (f) { f.addEventListener('change', function () { page = 1; render(); }); });
    render();
  }

  // ----------------------------------------------------------------- course
  function initCourse(root) {
    var courseId = parseInt(root.getAttribute('data-slms-course'), 10);
    var free = root.getAttribute('data-free') === '1';
    var methods = JSON.parse(root.getAttribute('data-methods') || '[]');
    var fields = JSON.parse(root.getAttribute('data-fields') || '{}');
    var prefill = JSON.parse(root.getAttribute('data-prefill') || '{}');
    var total = root.getAttribute('data-total') || '';

    // tabs
    var tabs = $$('[data-slms-tab]', root);
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-slms-tab');
        tabs.forEach(function (x) { var on = x === tab; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
        $$('[data-slms-panel]', root).forEach(function (p) {
          var on = p.getAttribute('data-slms-panel') === name;
          p.hidden = !on;
          p.classList.toggle('is-on', on);
        });
      });
    });

    // enrol
    $$('[data-slms-enroll]', root).forEach(function (btn) {
      btn.addEventListener('click', function () { openEnroll(btn.getAttribute('data-retry') === '1'); });
    });

    function field(name, label, type, extra) {
      return '<label class="slms-field"><span>' + esc(label) + '</span><input class="slms-input" data-f="' + name + '" type="' + type + '"' + (extra || '') + '></label>';
    }

    function openEnroll(retry) {
      var h = '<h3 class="slms-modal-title">' + esc(retry ? t('payNow') : (free ? t('enroll') : t('enrollPay'))) + '</h3>';
      if (!free) h += '<div class="slms-modal-total">' + esc(total) + '</div>';
      h += field('name', t('fullName'), 'text', ' autocomplete="name" required value="' + esc(prefill.name || '') + '"');
      h += field('email', t('email'), 'email', ' autocomplete="email" required value="' + esc(prefill.email || '') + '"');
      if (!retry) {
        if (fields.national_id) h += field('national_id', t('nationalId'), 'text');
        if (fields.gender) {
          h += '<label class="slms-field"><span>' + esc(t('gender')) + '</span><select class="slms-input" data-f="gender"><option value=""></option>'
            + '<option>' + esc(t('female')) + '</option><option>' + esc(t('male')) + '</option></select></label>';
        }
        if (fields.location) h += field('location', t('location'), 'text', ' placeholder="' + esc(t('cityCountry')) + '"');
        if (fields.dob) h += field('dob', t('dob'), 'date');
      }
      if (!free && methods.length) {
        h += '<label class="slms-field"><span>' + esc(t('method')) + '</span><select class="slms-input" data-f="method">'
          + methods.map(function (m) { return '<option value="' + esc(m.id) + '">' + esc(m.label) + '</option>'; }).join('') + '</select></label>';
        h += '<label class="slms-field" data-phone hidden><span>' + esc(t('phone')) + '</span><input class="slms-input" data-f="phone" type="tel" autocomplete="tel" placeholder="097…"></label>';
      }
      h += '<p class="slms-error" data-err role="alert"></p><div class="slms-modal-actions"><button type="button" class="slms-btn" data-close>' + esc(t('cancel')) + '</button>'
        + '<button type="button" class="slms-btn slms-btn--primary" data-ok>' + esc(free ? t('enroll') : t('enrollPay')) + '</button></div>';

      var m = modal(h);
      var card = m.card;
      function val(n) { var el = card.querySelector('[data-f="' + n + '"]'); return el ? el.value.trim() : ''; }
      var methodEl = card.querySelector('[data-f="method"]');
      var phoneWrap = card.querySelector('[data-phone]');
      function current() { var id = methodEl ? methodEl.value : ''; for (var i = 0; i < methods.length; i++) if (methods[i].id === id) return methods[i]; return null; }
      function togglePhone() { if (phoneWrap) { var mm = current(); phoneWrap.hidden = !(mm && mm.phone); } }
      if (methodEl) { methodEl.addEventListener('change', togglePhone); togglePhone(); }

      card.querySelector('[data-ok]').addEventListener('click', function () {
        var err = card.querySelector('[data-err]');
        var ok = this;
        if (!val('name') || !val('email')) { err.textContent = t('required'); return; }
        var mm = current();
        if (mm && mm.phone && !val('phone')) { err.textContent = t('phoneRequired'); return; }
        ok.disabled = true;
        err.textContent = '';
        api('/enroll', 'POST', {
          course: courseId, name: val('name'), email: val('email'), national_id: val('national_id'),
          gender: val('gender'), location: val('location'), dob: val('dob'), method: val('method'), phone: val('phone')
        }).then(function (d) {
          if (d.checkout_url) {
            card.innerHTML = '<h3 class="slms-modal-title">' + esc(t('redirecting')) + '</h3><p><a class="slms-btn slms-btn--primary" href="' + esc(d.checkout_url) + '">' + esc(t('clickHere')) + '</a></p>';
            window.location.assign(d.checkout_url);
            return;
          }
          if (d.poll) {
            card.innerHTML = '<h3 class="slms-modal-title">' + esc(t('waiting')) + '</h3><p data-poll-msg>' + esc(d.instructions || '') + '</p><div class="slms-spinner" aria-hidden="true"></div>'
              + '<div class="slms-modal-actions"><button type="button" class="slms-btn" data-close-reload>' + esc(t('close')) + '</button></div>';
            card.querySelector('[data-close-reload]').addEventListener('click', function () { window.location.reload(); });
            poll(d.reference, card.querySelector('[data-poll-msg]'), 40, function () { window.location.reload(); });
            return;
          }
          var body = '<h3 class="slms-modal-title">' + esc(t('enrolled')) + '</h3>';
          if (d.instructions) body += '<div class="slms-note">' + esc(d.instructions).replace(/\n/g, '<br>') + '</div>';
          if (d.link) body += '<p><a class="slms-btn slms-btn--primary" target="_blank" rel="noopener" href="' + esc(d.link) + '">' + esc(t('payNow')) + '</a></p>';
          body += '<div class="slms-modal-actions"><button type="button" class="slms-btn slms-btn--primary" data-done>' + esc(t('done')) + '</button></div>';
          card.innerHTML = body;
          card.querySelector('[data-done]').addEventListener('click', function () { window.location.reload(); });
        }).catch(function (e) { err.textContent = e.message; ok.disabled = false; });
      });
    }

    // payment polling for an enrolment already waiting on a gateway
    var ref = root.getAttribute('data-slms-poll');
    if (ref) {
      var long = root.getAttribute('data-poll-long') === '1';
      poll(ref, $('[data-slms-poll-msg]', root), long ? 30 : 1, function () {
        var url = new URL(window.location.href);
        url.searchParams.set('slms_payment', 'paid');
        window.location.replace(url.toString());
      });
    }

    // lesson checkboxes
    $$('[data-slms-check]', root).forEach(function (cb) {
      cb.addEventListener('change', function () {
        cb.disabled = true;
        api('/progress', 'POST', { item: parseInt(cb.getAttribute('data-slms-check'), 10), done: cb.checked })
          .then(function () { window.location.reload(); })
          .catch(function (e) { cb.checked = !cb.checked; cb.disabled = false; window.alert(e.message); });
      });
    });

    // reviews
    var form = $('[data-slms-review-form]', root);
    if (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var status = $('[data-slms-status]', form);
        var body = $('textarea', form).value.trim();
        var rating = $('input[name="slms_rating"]:checked', form);
        if (!body) return;
        status.textContent = t('posting');
        api('/reviews', 'POST', { course: courseId, rating: rating ? parseInt(rating.value, 10) : 5, body: body })
          .then(function () { window.location.reload(); })
          .catch(function (e) { status.textContent = e.message; });
      });
    }
    $$('[data-slms-review-delete]', root).forEach(function (btn) {
      btn.addEventListener('click', function () {
        confirmModal(t('deleteTitle'), t('deleteMsg'), t('delete')).then(function (yes) {
          if (!yes) return;
          btn.disabled = true;
          api('/reviews/delete', 'POST', { id: parseInt(btn.getAttribute('data-slms-review-delete'), 10) })
            .then(function () { window.location.reload(); })
            .catch(function (e) { btn.disabled = false; window.alert(e.message); });
        });
      });
    });

    // quiz results
    $$('[data-slms-review]', root).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var r;
        try { r = JSON.parse(btn.getAttribute('data-slms-review')); } catch (e) { return; }
        var h = '<div class="slms-modal-head"><h3 class="slms-modal-title">' + esc(r.title) + '</h3><span class="slms-score ' + (r.passed ? 'is-pass' : 'is-fail') + '">' + r.score + '%</span></div>'
          + '<div class="slms-modal-stats"><span>' + esc(t('ofCorrect', r.correct, r.total)) + '</span><span>' + esc(t('answered', r.answered)) + '</span><span>' + esc(t('attempt', r.attempt)) + '</span><span>' + esc(r.passed ? t('passed') : t('notPassed')) + '</span></div>';
        h += reviewHtml(r.questions || []);
        h += '<div class="slms-modal-actions">' + (r.retake ? '<a class="slms-btn" href="' + esc(r.retake) + '">' + esc(t('retake')) + '</a>' : '')
          + '<button type="button" class="slms-btn slms-btn--primary" data-close>' + esc(t('close')) + '</button></div>';
        modal(h, { wide: true });
      });
    });
  }

  function reviewHtml(qs) {
    if (!qs.length) return '';
    var h = '<div class="slms-review-list">';
    qs.forEach(function (q) {
      var open = q.graded === false;
      h += '<div class="slms-rq ' + (open ? 'is-open' : (q.is_correct ? 'is-correct' : 'is-wrong')) + '"><div class="slms-rq-head"><strong>Q' + q.n + '. ' + esc(q.question) + '</strong>'
        + '<span class="slms-pill">' + esc(open ? t('notGraded') : (q.is_correct ? t('correct') : (q.answered ? t('incorrect') : t('unanswered')))) + '</span></div>'
        + '<div><span class="slms-muted">' + esc(t('yourAnswer')) + ':</span> ' + esc(q.answered ? q.user : t('notAnswered')) + '</div>';
      if (!q.is_correct && q.correct) h += '<div><span class="slms-muted">' + esc(t('correctAnswer')) + ':</span> <strong>' + esc(q.correct) + '</strong></div>';
      if (q.explanation) h += '<div class="slms-rq-exp">' + esc(q.explanation) + '</div>';
      h += '</div>';
    });
    return h + '</div>';
  }

  /** Ask the server (which asks the gateway) whether a payment went through. */
  function poll(ref, msgEl, tries, onPaid) {
    var n = 0;
    function tick() {
      n++;
      api('/enrollment?ref=' + encodeURIComponent(ref)).then(function (d) {
        if (d.paid) { if (msgEl) msgEl.textContent = t('paidNow'); setTimeout(onPaid, 900); return; }
        if (d.state === 'failed') { if (msgEl) msgEl.textContent = t('payFailed'); return; }
        if (n < tries) setTimeout(tick, 5000);
        else if (msgEl && tries > 1) msgEl.textContent = t('stillWaiting');
      }).catch(function () { if (n < tries) setTimeout(tick, 8000); });
    }
    tick();
  }

  // ----------------------------------------------------------------- lesson
  function initComplete(btn) {
    btn.addEventListener('click', function () {
      var done = btn.getAttribute('data-done') === '1';
      btn.disabled = true;
      api('/progress', 'POST', { item: parseInt(btn.getAttribute('data-slms-complete'), 10), done: !done })
        .then(function () { window.location.reload(); })
        .catch(function (e) { btn.disabled = false; window.alert(e.message); });
    });
  }

  // ------------------------------------------------------------------- quiz
  function initQuiz(root) {
    var form = $('[data-slms-quiz-form]', root);
    if (!form) return;
    var quizId = parseInt(root.getAttribute('data-slms-quiz'), 10);
    var pages = $$('[data-slms-qpage]', root);
    var total = pages.length || 1;
    var prev = $('[data-slms-prev]', root), next = $('[data-slms-next]', root), submit = $('[data-slms-submit]', root);
    var errEl = $('[data-slms-error]', root);
    var results = $('[data-slms-results]', root);
    var courseUrl = root.getAttribute('data-course-url') || '';
    var current = 1, sent = false, dirty = false;

    function show(n) {
      current = Math.min(Math.max(n, 1), total);
      pages.forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-slms-qpage'), 10) !== current; });
      if (prev) prev.hidden = current === 1;
      if (next) next.hidden = current === total;
      if (submit) submit.hidden = current !== total || sent;
      var pct = Math.round(current / total * 100);
      var bar = $('[data-slms-page-bar]', root); if (bar) bar.style.width = pct + '%';
      var lab = $('[data-slms-page-label]', root); if (lab) lab.textContent = t('page', current, total);
      var pc = $('[data-slms-page-pct]', root); if (pc) pc.textContent = pct + '%';
      var info = $('[data-slms-page-info]', root); if (info) info.textContent = total > 1 ? t('page', current, total) : '';
    }
    if (prev) prev.addEventListener('click', function () { show(current - 1); root.scrollIntoView({ behavior: 'smooth' }); });
    if (next) next.addEventListener('click', function () { show(current + 1); root.scrollIntoView({ behavior: 'smooth' }); });
    form.addEventListener('change', function () { dirty = true; });
    window.addEventListener('beforeunload', function (e) { if (dirty && !sent) { e.preventDefault(); e.returnValue = t('leave'); } });

    function answers() {
      var out = {};
      $$('[data-slms-q]', root).forEach(function (fs) {
        var id = fs.getAttribute('data-slms-q');
        var type = fs.getAttribute('data-type');
        if (type === 'multipleselect') {
          var v = $$('input:checked', fs).map(function (i) { return i.value; });
          if (v.length) out[id] = v;
        } else if (type === 'multiplechoice') {
          var c = $('input:checked', fs); if (c) out[id] = c.value;
        } else {
          var el = $('input,textarea', fs); if (el && el.value.trim() !== '') out[id] = el.value.trim();
        }
      });
      return out;
    }

    function firstMissing(a) {
      var bad = 0;
      $$('[data-slms-q]', root).forEach(function (fs) {
        var missing = fs.getAttribute('data-required') === '1' && a[fs.getAttribute('data-slms-q')] === undefined;
        fs.classList.toggle('is-missing', missing);
        if (missing && !bad) { var pg = fs.closest('[data-slms-qpage]'); bad = pg ? parseInt(pg.getAttribute('data-slms-qpage'), 10) : 1; }
      });
      return bad;
    }

    function send(force) {
      if (sent) return;
      var a = answers();
      if (!force) {
        var miss = firstMissing(a);
        if (miss) { show(miss); errEl.textContent = t('answerAll'); return; }
        if (!window.confirm(t('confirmSubmit'))) return;
      }
      var emailEl = form.querySelector('[name="slms_email"]');
      sent = true;
      errEl.textContent = '';
      if (submit) { submit.disabled = true; submit.textContent = t('submitting'); }
      api('/quiz/submit', 'POST', { quiz: quizId, answers: a, email: emailEl ? emailEl.value.trim() : '' })
        .then(function (d) {
          $$('input,textarea,button', form).forEach(function (el) { el.disabled = true; });
          [prev, next, submit].forEach(function (b) { if (b) b.hidden = true; });
          stopTimer();
          renderResults(d);
        })
        .catch(function (e) {
          sent = false;
          if (submit) { submit.disabled = false; submit.textContent = t('submit'); }
          errEl.textContent = e.message;
        });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); send(false); });

    function renderResults(d) {
      var h = '';
      if (!d.show_results) {
        h = '<div class="slms-note">' + esc(t('submitted')) + '</div>';
      } else {
        var wrong = (d.answered || 0) - (d.correct || 0);
        h += '<div class="slms-result-score ' + (d.passed ? 'is-pass' : 'is-fail') + '"><strong>' + (d.score || 0) + '%</strong><span>' + esc(d.passed ? t('passed') : t('notPassed')) + '</span>'
          + (d.points ? '<small>' + esc(d.points) + '</small>' : '') + '</div>';
        h += '<div class="slms-stats"><div><strong>' + (d.correct || 0) + '</strong><span>' + esc(t('correct')) + '</span></div>'
          + '<div><strong>' + wrong + '</strong><span>' + esc(t('incorrect')) + '</span></div>'
          + '<div><strong>' + ((d.total || 0) - (d.answered || 0)) + '</strong><span>' + esc(t('unanswered')) + '</span></div>'
          + '<div><strong>' + (d.total || 0) + '</strong><span>' + esc(t('total')) + '</span></div></div>';
        if (d.review && d.review.length) {
          h += '<h2 class="slms-h2">' + esc(t('review')) + '</h2>' + reviewHtml(d.review);
          markQuestions(d.review);
        }
      }
      h += '<div class="slms-row">';
      if (d.can_retake) h += '<a class="slms-btn" href="' + esc(window.location.href.split('#')[0]) + '">' + esc(t('retake')) + '</a>';
      if (courseUrl) h += '<a class="slms-btn slms-btn--primary" href="' + esc(courseUrl) + '">← ' + esc(t('backToCourse')) + '</a>';
      h += '</div>';
      results.innerHTML = h;
      results.hidden = false;
      results.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function markQuestions(review) {
      var sets = $$('[data-slms-q]', root);
      review.forEach(function (r, i) {
        var fs = sets[i];
        if (!fs || r.graded === false) return;
        fs.classList.add(r.is_correct ? 'is-correct' : 'is-wrong');
      });
    }

    // timer
    var timerEl = $('[data-slms-timer]', root);
    var iv = null;
    function stopTimer() { if (iv) clearInterval(iv); }
    if (timerEl) {
      var left = parseInt(root.getAttribute('data-remaining'), 10) || 0;
      var out = $('[data-slms-timer-v]', root);
      var fmt = function (s) { return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
      iv = setInterval(function () {
        left--;
        if (left <= 0) {
          stopTimer();
          out.textContent = '00:00';
          errEl.textContent = t('timeUp');
          send(true);
          return;
        }
        out.textContent = fmt(left);
        if (left < 60) timerEl.classList.add('is-low');
      }, 1000);
    }

    document.addEventListener('keydown', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
      if (e.key === 'ArrowRight' && next && !next.hidden) next.click();
      if (e.key === 'ArrowLeft' && prev && !prev.hidden) prev.click();
    });

    show(1);
  }

  // ------------------------------------------------------------- my courses
  /** My courses: Courses / Quiz results tabs. The open tab follows #quizzes in the address. */
  function initMine(root) {
    var tabs = $$('[data-slms-mine-tab]', root);
    var panels = $$('[data-slms-mine-panel]', root);
    if (!tabs.length) return;
    function open(name, focus, push) {
      if (!panels.some(function (p) { return p.getAttribute('data-slms-mine-panel') === name; })) name = 'courses';
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-slms-mine-tab') === name;
        t.classList.toggle('is-on', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (on && focus) t.focus();
      });
      panels.forEach(function (p) {
        var on = p.getAttribute('data-slms-mine-panel') === name;
        p.hidden = !on;
        p.classList.toggle('is-on', on);
      });
      if (push && window.history && history.replaceState) {
        history.replaceState(null, '', name === 'courses' ? window.location.pathname + window.location.search : '#' + name);
      }
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { open(t.getAttribute('data-slms-mine-tab'), false, true); });
      t.addEventListener('keydown', function (e) {
        var k = e.key, n = null;
        if (k === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
        else if (k === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (k === 'Home') n = tabs[0];
        else if (k === 'End') n = tabs[tabs.length - 1];
        if (n) { e.preventDefault(); open(n.getAttribute('data-slms-mine-tab'), true, true); }
      });
    });
    open((window.location.hash || '').replace('#', '') || 'courses', false, false);
  }

  /**
   * Quiz results grouped by course. Each course opens and closes; search
   * matches a course or quiz title, the result filter narrows the quizzes,
   * and courses are paged so a long list stays manageable.
   */
  function initGroups(root) {
    var groups = $$('[data-slms-group]', root);
    var q = $('[data-slms-gq]', root), res = $('[data-slms-gresult]', root);
    var pager = $('[data-slms-gpager]', root), none = $('[data-slms-gnone]', root);
    var per = parseInt(root.getAttribute('data-per-page'), 10) || 10;
    var page = 1;
    var chosen = {}; // groups the learner opened themselves

    function setOpen(g, open) {
      var btn = $('[data-slms-group-toggle]', g), body = $('#' + btn.getAttribute('aria-controls'), g);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      g.classList.toggle('is-open', open);
      if (body) body.hidden = !open;
    }

    groups.forEach(function (g, i) {
      $('[data-slms-group-toggle]', g).addEventListener('click', function () {
        var open = this.getAttribute('aria-expanded') !== 'true';
        chosen[i] = open;
        setOpen(g, open);
      });
    });

    function render() {
      var term = q ? q.value.trim().toLowerCase() : '';
      var want = res ? res.value : '';
      var filtering = term !== '' || want !== '';
      var shown = [];
      groups.forEach(function (g, i) {
        var titleHit = term !== '' && (g.getAttribute('data-search') || '').indexOf(term) !== -1;
        var any = 0;
        $$('[data-slms-gitem]', g).forEach(function (row) {
          var ok = (!term || titleHit || (row.getAttribute('data-search') || '').indexOf(term) !== -1)
            && (!want || row.getAttribute('data-result') === want);
          row.hidden = !ok;
          if (ok) any++;
        });
        g.hidden = true;
        if (any) shown.push(g);
        // While filtering, open the courses that matched by a quiz so the
        // match is visible; otherwise keep whatever the learner chose.
        setOpen(g, filtering && !titleHit ? any > 0 : !!chosen[i] || (filtering && titleHit && want !== ''));
      });
      var pages = Math.max(1, Math.ceil(shown.length / per));
      if (page > pages) page = pages;
      var from = (page - 1) * per;
      shown.slice(from, from + per).forEach(function (g) { g.hidden = false; });
      if (none) none.hidden = shown.length > 0;
      if (!pager) return;
      if (pages <= 1) { pager.innerHTML = ''; return; }
      var h = '<span class="slms-pager-info">' + esc(t('showing', from + 1, Math.min(from + per, shown.length), shown.length)) + '</span><span class="slms-pager-btns">';
      h += '<button type="button" class="slms-page" data-p="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + ' aria-label="' + esc(t('prev')) + '">‹</button>';
      for (var p = 1; p <= pages; p++) {
        if (pages > 7 && p !== 1 && p !== pages && Math.abs(p - page) > 1) {
          if (p === 2 || p === pages - 1) h += '<span class="slms-page-gap">…</span>';
          continue;
        }
        h += '<button type="button" class="slms-page' + (p === page ? ' is-on' : '') + '" data-p="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
      }
      h += '<button type="button" class="slms-page" data-p="' + (page + 1) + '"' + (page === pages ? ' disabled' : '') + ' aria-label="' + esc(t('next')) + '">›</button></span>';
      pager.innerHTML = h;
    }

    if (pager) pager.addEventListener('click', function (e) {
      var b = e.target.closest('[data-p]');
      if (!b || b.disabled) return;
      page = parseInt(b.getAttribute('data-p'), 10) || 1;
      render();
      if (root.getBoundingClientRect().top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    if (q) q.addEventListener('input', function () { page = 1; render(); });
    if (res) res.addEventListener('change', function () { page = 1; render(); });
    $$('[data-slms-gall]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        var open = b.getAttribute('data-slms-gall') === 'open';
        groups.forEach(function (g, i) { if (!g.hidden) { chosen[i] = open; setOpen(g, open); } });
      });
    });
    render();
  }

  function boot() {
    $$('[data-slms-index]').forEach(initIndex);
    $$('[data-slms-course]').forEach(initCourse);
    $$('[data-slms-complete]').forEach(initComplete);
    $$('[data-slms-quiz]').forEach(initQuiz);
    $$('[data-slms-mine]').forEach(initMine);
    $$('[data-slms-groups]').forEach(initGroups);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
