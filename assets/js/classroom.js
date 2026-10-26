/* Sepo LMS — course classroom: forums, chat, attendance and certificates. */
(function () {
  'use strict';
  var UI = window.SepoLmsUI;
  if (!UI) return;
  var api = UI.api, esc = UI.esc, t = UI.t, $ = UI.$, $$ = UI.$$, modal = UI.modal, confirmModal = UI.confirmModal;

  function q(params) {
    return Object.keys(params).filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
  }

  function onOpen(panel, fn) {
    var started = false;
    function go() { if (!started) { started = true; fn(); } }
    panel.addEventListener('slms:open', go);
    if (!panel.hidden) go();
  }

  function author(a) {
    return '<span class="slms-author">' + (a.avatar ? '<img src="' + esc(a.avatar) + '" alt="" width="28" height="28" loading="lazy">' : '<span class="slms-avatar-dot" aria-hidden="true"></span>')
      + '<strong>' + esc(a.name) + '</strong>' + (a.staff ? '<span class="slms-badge slms-badge--staff">' + esc(t('fStaff')) + '</span>' : '') + '</span>';
  }

  // ================================================================ forums
  function Forums(panel) {
    var course = parseInt(panel.getAttribute('data-course'), 10);
    var forums = JSON.parse(panel.getAttribute('data-slms-forums') || '[]');
    var params = new URLSearchParams(window.location.search);
    var state = { forum: params.get('slms_forum') || (forums[0] ? forums[0].key : ''), page: 1, search: '' };
    var startTopic = parseInt(params.get('slms_topic') || '0', 10);
    if (!forums.some(function (f) { return f.key === state.forum; })) state.forum = forums[0] ? forums[0].key : '';

    function typeLabel(type) {
      return type === 'announcements' ? t('fTypeAnn') : (type === 'post_first' ? t('fTypeFirst') : t('fTypeOpen'));
    }

    function shell() {
      panel.innerHTML = '<div class="slms-forum">'
        + (forums.length > 1 ? '<div class="slms-chips" role="tablist">' + forums.map(function (f) {
          return '<button type="button" class="slms-chip' + (f.key === state.forum ? ' is-on' : '') + '" data-forum="' + esc(f.key) + '" role="tab" aria-selected="' + (f.key === state.forum) + '">' + esc(f.title) + '</button>';
        }).join('') + '</div>' : '')
        + '<div data-forum-body></div></div>';
    }

    function forum() { return forums.filter(function (f) { return f.key === state.forum; })[0] || forums[0]; }

    function list() {
      var body = $('[data-forum-body]', panel);
      body.innerHTML = '<div class="slms-spinner" aria-hidden="true"></div>';
      api('/forum/topics?' + q({ course: course, forum: state.forum, q: state.search, page: state.page })).then(function (d) {
        var f = d.forum;
        var h = '<div class="slms-forum-head"><div><h2 class="slms-h3">' + esc(f.title) + ' <span class="slms-pill">' + esc(typeLabel(f.type)) + '</span></h2>'
          + (f.description ? '<p class="slms-muted slms-small">' + esc(f.description) + '</p>' : '') + '</div>'
          + (d.can_start ? '<button type="button" class="slms-btn slms-btn--primary" data-new>' + esc(f.type === 'announcements' ? t('fNewAnn') : t('fNewTopic')) + '</button>' : '') + '</div>';
        if (f.type === 'post_first' && !d.staff) h += '<p class="slms-note">' + esc(t('fFirstNote')) + '</p>';
        if (f.type === 'announcements' && !d.staff) h += '<p class="slms-small slms-muted">' + esc(t('fAnnNote')) + '</p>';
        h += '<label class="slms-search slms-search--full"><span class="screen-reader-text">' + esc(t('fSearch')) + '</span><input type="search" data-search value="' + esc(state.search) + '" placeholder="' + esc(t('fSearch')) + '"></label>';
        if (!d.topics.length) {
          h += '<p class="slms-empty">' + esc(state.search ? t('noMatch') : t('fEmpty')) + '</p>';
        } else {
          h += '<ul class="slms-topics">' + d.topics.map(function (tp) {
            return '<li class="slms-topic' + (tp.pinned ? ' is-pinned' : '') + '"><button type="button" class="slms-topic-link" data-topic="' + tp.id + '">'
              + '<span class="slms-topic-title">' + (tp.pinned ? '<span class="slms-pin" title="' + esc(t('fPinned')) + '">📌</span> ' : '') + esc(tp.title) + '</span>'
              + '<span class="slms-topic-excerpt">' + esc(tp.excerpt) + '</span>'
              + '<span class="slms-topic-meta">' + esc(tp.author.name) + (tp.author.staff ? ' · ' + esc(t('fStaff')) : '') + ' · ' + esc(tp.last)
              + (tp.answered === false ? ' · <span class="slms-bad">' + esc(t('fReplyToSee')) + '</span>' : '') + '</span>'
              + '</button><span class="slms-topic-count" title="' + esc(t('fReplies')) + '">' + tp.replies + '</span></li>';
          }).join('') + '</ul>';
          if (d.pages > 1) {
            h += '<nav class="slms-pager">' + '<button type="button" class="slms-page" data-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>‹</button>'
              + '<span class="slms-small slms-muted">' + esc(t('page', d.page, d.pages)) + '</span>'
              + '<button type="button" class="slms-page" data-page="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>›</button></nav>';
          }
        }
        body.innerHTML = h;
        var s = $('[data-search]', body);
        var timer = null;
        s.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () { state.search = s.value.trim(); state.page = 1; list(); }, 350);
        });
      }).catch(function (e) { body.innerHTML = '<p class="slms-error">' + esc(e.message) + '</p>'; });
    }

    function topic(id) {
      var body = $('[data-forum-body]', panel);
      body.innerHTML = '<div class="slms-spinner" aria-hidden="true"></div>';
      api('/forum/topic?' + q({ id: id })).then(function (d) {
        var tp = d.topic;
        var h = '<button type="button" class="slms-link-btn" data-back>← ' + esc(t('fBack')) + '</button>'
          + '<article class="slms-post slms-post--topic">'
          + '<h2 class="slms-h3">' + esc(tp.title) + '</h2>'
          + '<div class="slms-post-meta">' + author(tp.author) + '<span class="slms-muted slms-small">' + esc(tp.created) + (tp.edited ? ' · ' + esc(t('fEdited')) : '') + '</span></div>'
          + '<div class="slms-post-body">' + tp.html + '</div>'
          + actions(tp, true, d.staff) + '</article>';
        h += '<h3 class="slms-h3 slms-replies-h">' + esc(t('fRepliesN', d.replies.length + d.hidden)) + '</h3>';
        if (d.hidden > 0) h += '<p class="slms-note">' + esc(d.hidden === 1 ? t('fHiddenOne') : t('fHidden', d.hidden)) + '</p>';
        h += '<div class="slms-replies">' + d.replies.map(function (r) {
          return '<article class="slms-post" data-post="' + r.id + '"><div class="slms-post-meta">' + author(r.author) + '<span class="slms-muted slms-small">' + esc(r.created) + (r.edited ? ' · ' + esc(t('fEdited')) : '') + '</span></div>'
            + '<div class="slms-post-body">' + r.html + '</div>' + actions(r, false, d.staff) + '</article>';
        }).join('') + '</div>';
        if (d.can_reply) {
          h += '<form class="slms-reply-form" data-reply><label class="slms-field"><span>' + esc(t('fYourReply')) + '</span><textarea class="slms-input" rows="4" required></textarea></label>'
            + '<p class="slms-error" data-err role="alert"></p><div class="slms-row"><button type="submit" class="slms-btn slms-btn--primary">' + esc(t('fPostReply')) + '</button></div></form>';
        } else if (d.forum.type === 'announcements') {
          h += '<p class="slms-small slms-muted">' + esc(t('fAnnNote')) + '</p>';
        }
        body.innerHTML = h;
        body.setAttribute('data-topic-id', String(tp.id));
        body.slmsTopic = d;
        var form = $('[data-reply]', body);
        if (form) form.addEventListener('submit', function (e) {
          e.preventDefault();
          var ta = $('textarea', form), btn = $('button[type=submit]', form);
          if (!ta.value.trim()) return;
          btn.disabled = true;
          api('/forum/post', 'POST', { course: course, forum: state.forum, parent: tp.id, body: ta.value })
            .then(function () { topic(tp.id); })
            .catch(function (er) { $('[data-err]', form).textContent = er.message; btn.disabled = false; });
        });
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }).catch(function (e) { body.innerHTML = '<p class="slms-error">' + esc(e.message) + '</p>'; });
    }

    function actions(p, isTopic, staff) {
      var a = [];
      if (isTopic && staff) a.push('<button type="button" class="slms-link-btn" data-pin="' + p.id + '" data-on="' + (p.pinned ? '0' : '1') + '">' + esc(p.pinned ? t('fUnpin') : t('fPin')) + '</button>');
      if (p.can_edit) a.push('<button type="button" class="slms-link-btn" data-edit="' + p.id + '" data-topic-edit="' + (isTopic ? 1 : 0) + '">' + esc(t('fEdit')) + '</button>');
      if (p.can_delete) a.push('<button type="button" class="slms-link-btn slms-bad" data-del="' + p.id + '" data-topic-del="' + (isTopic ? 1 : 0) + '">' + esc(t('delete')) + '</button>');
      return a.length ? '<div class="slms-post-actions">' + a.join('<span aria-hidden="true">·</span>') + '</div>' : '';
    }

    function compose(existing, isTopic) {
      var f = forum();
      var h = '<h3 class="slms-modal-title">' + esc(existing ? t('fEdit') : (f.type === 'announcements' ? t('fNewAnn') : t('fNewTopic'))) + '</h3>';
      if (isTopic) h += '<label class="slms-field"><span>' + esc(t('fTitle')) + '</span><input class="slms-input" data-title maxlength="200" value="' + esc(existing ? existing.title : '') + '"></label>';
      h += '<label class="slms-field"><span>' + esc(t('fMessage')) + '</span><textarea class="slms-input" rows="7" data-body>' + esc(existing ? existing.body : '') + '</textarea></label>'
        + (f.type === 'announcements' && !existing ? '<p class="slms-small slms-muted">' + esc(t('fAnnEmailNote')) + '</p>' : '')
        + '<p class="slms-error" data-err role="alert"></p><div class="slms-modal-actions"><button type="button" class="slms-btn" data-close>' + esc(t('cancel')) + '</button>'
        + '<button type="button" class="slms-btn slms-btn--primary" data-ok>' + esc(existing ? t('fSave') : t('fPost')) + '</button></div>';
      var m = modal(h, { wide: true });
      $('[data-ok]', m.card).addEventListener('click', function () {
        var title = $('[data-title]', m.card), bodyEl = $('[data-body]', m.card), btn = this;
        btn.disabled = true;
        var req = existing
          ? api('/forum/edit', 'POST', { id: existing.id, title: title ? title.value : '', body: bodyEl.value })
          : api('/forum/post', 'POST', { course: course, forum: state.forum, parent: 0, title: title ? title.value : '', body: bodyEl.value });
        req.then(function (d) {
          m.close();
          if (existing) {
            var open = parseInt($('[data-forum-body]', panel).getAttribute('data-topic-id') || '0', 10);
            if (open) topic(open); else list();
          } else {
            topic(d.post.id);
          }
        }).catch(function (e) { $('[data-err]', m.card).textContent = e.message; btn.disabled = false; });
      });
    }

    panel.addEventListener('click', function (e) {
      var el;
      if ((el = e.target.closest('[data-forum]'))) {
        state.forum = el.getAttribute('data-forum'); state.page = 1; state.search = '';
        $$('[data-forum]', panel).forEach(function (b) { var on = b === el; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', String(on)); });
        list();
      } else if ((el = e.target.closest('[data-topic]'))) {
        topic(parseInt(el.getAttribute('data-topic'), 10));
      } else if (e.target.closest('[data-back]')) {
        $('[data-forum-body]', panel).removeAttribute('data-topic-id');
        list();
      } else if (e.target.closest('[data-new]')) {
        compose(null, true);
      } else if ((el = e.target.closest('[data-page]'))) {
        state.page = parseInt(el.getAttribute('data-page'), 10) || 1; list();
      } else if ((el = e.target.closest('[data-edit]'))) {
        var d = $('[data-forum-body]', panel).slmsTopic;
        var pid = parseInt(el.getAttribute('data-edit'), 10);
        var post = d.topic.id === pid ? d.topic : d.replies.filter(function (r) { return r.id === pid; })[0];
        if (post) compose(post, el.getAttribute('data-topic-edit') === '1');
      } else if ((el = e.target.closest('[data-del]'))) {
        var isTopic = el.getAttribute('data-topic-del') === '1';
        var id = parseInt(el.getAttribute('data-del'), 10);
        confirmModal(t('fDeleteTitle'), isTopic ? t('fDeleteTopic') : t('fDeleteReply'), t('delete')).then(function (yes) {
          if (!yes) return;
          api('/forum/delete', 'POST', { id: id }).then(function () {
            if (isTopic) { $('[data-forum-body]', panel).removeAttribute('data-topic-id'); list(); }
            else topic(parseInt($('[data-forum-body]', panel).getAttribute('data-topic-id'), 10));
          });
        });
      } else if ((el = e.target.closest('[data-pin]'))) {
        api('/forum/pin', 'POST', { id: parseInt(el.getAttribute('data-pin'), 10), pinned: el.getAttribute('data-on') === '1' })
          .then(function () { topic(parseInt($('[data-forum-body]', panel).getAttribute('data-topic-id'), 10)); });
      }
    });

    onOpen(panel, function () {
      shell();
      if (startTopic) topic(startTopic); else list();
    });
  }

  // ================================================================== chat
  function Chat(panel) {
    var course = parseInt(panel.getAttribute('data-course'), 10);
    var pollSecs = Math.max(2, parseInt(panel.getAttribute('data-poll'), 10) || 5);
    var badge = document.querySelector('[data-slms-chat-badge]');
    var room = 'c:' + course, lastId = 0, firstId = 0, timer = null, ticks = 0, rooms = [], contacts = [];

    function shell() {
      panel.innerHTML = '<div class="slms-chat">'
        + '<aside class="slms-chat-rooms"><div data-rooms></div><label class="slms-field slms-chat-new"><span>' + esc(t('cNew')) + '</span><select class="slms-input" data-contact><option value="">' + esc(t('cChoose')) + '</option></select></label></aside>'
        + '<section class="slms-chat-main"><header class="slms-chat-head"><strong data-title></strong></header>'
        + '<div class="slms-chat-log" data-log aria-live="polite"><button type="button" class="slms-link-btn slms-chat-more" data-more hidden>' + esc(t('cEarlier')) + '</button><ol data-msgs></ol></div>'
        + '<form class="slms-chat-compose" data-compose><label class="screen-reader-text" for="slms-chat-input">' + esc(t('cMessage')) + '</label>'
        + '<textarea id="slms-chat-input" class="slms-input" rows="2" maxlength="2000" placeholder="' + esc(t('cPlaceholder')) + '"></textarea>'
        + '<button type="submit" class="slms-btn slms-btn--primary">' + esc(t('cSend')) + '</button></form><p class="slms-error" data-err role="alert"></p></section></div>';
      $('[data-contact]', panel).addEventListener('change', function () {
        if (this.value) { open(this.value); this.value = ''; }
      });
      $('[data-more]', panel).addEventListener('click', earlier);
      var form = $('[data-compose]', panel), ta = $('textarea', form);
      ta.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = ta.value.trim();
        if (!body) return;
        ta.disabled = true;
        api('/chat/send', 'POST', { room: room, body: body }).then(function (d) {
          ta.value = '';
          $('[data-err]', panel).textContent = '';
          append([d.message]);
        }).catch(function (er) { $('[data-err]', panel).textContent = er.message; })
          .then(function () { ta.disabled = false; ta.focus(); });
      });
      $('[data-msgs]', panel).addEventListener('click', function (e) {
        var b = e.target.closest('[data-del]');
        if (!b) return;
        api('/chat/delete', 'POST', { id: parseInt(b.getAttribute('data-del'), 10) }).then(function () {
          var li = b.closest('li');
          li.classList.add('is-deleted');
          li.querySelector('.slms-msg-body').textContent = t('cDeleted');
          b.remove();
        });
      });
    }

    function loadRooms() {
      return api('/chat/rooms?' + q({ course: course })).then(function (d) {
        rooms = d.rooms; contacts = d.contacts;
        var total = 0;
        $('[data-rooms]', panel).innerHTML = '<ul class="slms-room-list">' + rooms.map(function (r) {
          if (r.room !== room) total += r.unread;
          return '<li><button type="button" class="slms-room' + (r.room === room ? ' is-on' : '') + '" data-room="' + esc(r.room) + '">'
            + '<span>' + esc(r.title) + (r.staff ? ' <span class="slms-badge slms-badge--staff">' + esc(t('fStaff')) + '</span>' : '') + '</span>'
            + (r.unread && r.room !== room ? '<span class="slms-count slms-count--alert">' + r.unread + '</span>' : '') + '</button></li>';
        }).join('') + '</ul>';
        var sel = $('[data-contact]', panel);
        sel.innerHTML = '<option value="">' + esc(t('cChoose')) + '</option>' + contacts.map(function (c) {
          return '<option value="' + esc(c.room) + '">' + esc(c.name) + (c.staff ? ' (' + esc(t('fStaff')) + ')' : '') + '</option>';
        }).join('');
        sel.closest('.slms-chat-new').hidden = !contacts.length;
        setBadge(total);
      });
    }

    function setBadge(n) {
      if (!badge) return;
      badge.textContent = String(n);
      badge.hidden = n <= 0;
    }

    function title() {
      var r = rooms.filter(function (x) { return x.room === room; })[0];
      var c = contacts.filter(function (x) { return x.room === room; })[0];
      $('[data-title]', panel).textContent = r ? r.title : (c ? c.name : t('cRoom'));
    }

    function render(m) {
      var time = new Date(m.time * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      var day = new Date(m.time * 1000).toLocaleDateString();
      return '<li class="slms-msg' + (m.mine ? ' is-mine' : '') + (m.deleted ? ' is-deleted' : '') + '" data-id="' + m.id + '">'
        + '<div class="slms-msg-meta"><strong>' + esc(m.name) + '</strong>' + (m.staff ? ' <span class="slms-badge slms-badge--staff">' + esc(t('fStaff')) + '</span>' : '')
        + ' <time title="' + esc(day) + '">' + esc(time) + '</time>'
        + (m.can_delete ? ' <button type="button" class="slms-msg-del" data-del="' + m.id + '" aria-label="' + esc(t('delete')) + '">✕</button>' : '') + '</div>'
        + '<div class="slms-msg-body">' + (m.deleted ? esc(t('cDeleted')) : m.html) + '</div></li>';
    }

    function append(msgs) {
      if (!msgs.length) return;
      var log = $('[data-log]', panel), list = $('[data-msgs]', panel);
      var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
      msgs.forEach(function (m) {
        if (m.id <= lastId) return;
        list.insertAdjacentHTML('beforeend', render(m));
        lastId = m.id;
        if (!firstId) firstId = m.id;
      });
      if (atBottom || msgs.some(function (m) { return m.mine; })) log.scrollTop = log.scrollHeight;
    }

    function open(newRoom) {
      room = newRoom; lastId = 0; firstId = 0;
      $('[data-msgs]', panel).innerHTML = '';
      $('[data-err]', panel).textContent = '';
      api('/chat/messages?' + q({ room: room })).then(function (d) {
        append(d.messages);
        $('[data-more]', panel).hidden = d.messages.length < 50;
        var log = $('[data-log]', panel);
        log.scrollTop = log.scrollHeight;
        return loadRooms();
      }).then(title).catch(function (e) { $('[data-err]', panel).textContent = e.message; });
      $('textarea', panel).focus();
    }

    function earlier() {
      api('/chat/messages?' + q({ room: room, before: firstId })).then(function (d) {
        var list = $('[data-msgs]', panel), log = $('[data-log]', panel);
        var h = log.scrollHeight;
        list.insertAdjacentHTML('afterbegin', d.messages.map(render).join(''));
        if (d.messages.length) firstId = d.messages[0].id;
        log.scrollTop = log.scrollHeight - h;
        $('[data-more]', panel).hidden = d.messages.length < 50;
      });
    }

    function tick() {
      if (panel.hidden || document.hidden) return;
      api('/chat/messages?' + q({ room: room, after: lastId })).then(function (d) { append(d.messages); }).catch(function () {});
      if (++ticks % 4 === 0) loadRooms().catch(function () {});
    }

    panel.addEventListener('click', function (e) {
      var b = e.target.closest('[data-room]');
      if (b) open(b.getAttribute('data-room'));
    });

    onOpen(panel, function () {
      shell();
      loadRooms().then(function () { open(room); });
      timer = setInterval(tick, pollSecs * 1000);
    });
    void timer;
  }

  // ============================================================ attendance
  function Attendance(panel) {
    var course = parseInt(panel.getAttribute('data-course'), 10);
    var adminUrl = panel.getAttribute('data-admin') || '';

    function load() {
      panel.innerHTML = '<div class="slms-spinner" aria-hidden="true"></div>';
      api('/attendance?' + q({ course: course })).then(function (d) {
        var c = d.counts, L = d.labels;
        var h = '';
        if (adminUrl) h += '<p class="slms-note">' + esc(t('atStaff')) + ' <a href="' + esc(adminUrl) + '">' + esc(t('atOpenAdmin')) + '</a></p>';
        h += '<div class="slms-stats">'
          + '<div><strong>' + (d.rate === null ? '—' : esc(String(d.rate)) + '%') + '</strong><span>' + esc(t('atRate')) + '</span></div>'
          + ['present', 'late', 'absent', 'excused'].map(function (k) { return '<div><strong>' + (c[k] || 0) + '</strong><span>' + esc(L[k]) + '</span></div>'; }).join('')
          + '</div>';
        if (!d.sessions.length) {
          h += '<p class="slms-empty">' + esc(t('atNone')) + '</p>';
        } else {
          h += '<ul class="slms-sessions">' + d.sessions.map(function (s) {
            var right;
            if (s.open && !s.my_status) {
              right = '<form class="slms-checkin" data-checkin="' + s.id + '">'
                + (s.needs_code ? '<label class="screen-reader-text" for="slms-code-' + s.id + '">' + esc(t('atCode')) + '</label><input id="slms-code-' + s.id + '" class="slms-input slms-input--code" maxlength="12" autocomplete="off" placeholder="' + esc(t('atCode')) + '" required>' : '')
                + '<button type="submit" class="slms-btn slms-btn--primary slms-btn--sm">' + esc(t('atCheckIn')) + '</button><span class="slms-error" data-err role="alert"></span></form>';
            } else if (s.my_status) {
              right = '<span class="slms-att slms-att--' + esc(s.my_status) + '">' + esc(L[s.my_status] || s.my_status) + '</span>';
            } else {
              right = '<span class="slms-muted slms-small">' + esc(s.self_checkin ? t('atNotOpen') : t('atTeacherMarks')) + '</span>';
            }
            return '<li class="slms-session' + (s.open ? ' is-open' : '') + '"><div><strong>' + esc(s.title) + '</strong><div class="slms-small slms-muted">' + esc(s.when) + '</div></div>' + right + '</li>';
          }).join('') + '</ul>';
        }
        panel.innerHTML = h;
      }).catch(function (e) { panel.innerHTML = '<p class="slms-error">' + esc(e.message) + '</p>'; });
    }

    panel.addEventListener('submit', function (e) {
      var form = e.target.closest('[data-checkin]');
      if (!form) return;
      e.preventDefault();
      var code = $('input', form);
      var btn = $('button', form);
      btn.disabled = true;
      api('/attendance/checkin', 'POST', { session: parseInt(form.getAttribute('data-checkin'), 10), code: code ? code.value : '' })
        .then(function () { load(); })
        .catch(function (er) { $('[data-err]', form).textContent = er.message; btn.disabled = false; });
    });

    onOpen(panel, load);
  }

  // ========================================================= certificates
  function Certificate(box) {
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-slms-cert-claim]');
      if (!b) return;
      b.disabled = true;
      b.textContent = t('certIssuing');
      api('/certificate/claim', 'POST', { course: parseInt(box.getAttribute('data-course'), 10) }).then(function (d) {
        b.outerHTML = '<a class="slms-btn slms-btn--primary slms-btn--block" href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(t('certDownload')) + '</a>'
          + '<p class="slms-small slms-muted">' + esc(t('certCode', d.code)) + '</p>';
      }).catch(function (er) {
        b.disabled = false;
        b.textContent = t('certGet');
        box.insertAdjacentHTML('beforeend', '<p class="slms-error" role="alert">' + esc(er.message) + '</p>');
      });
    });
  }

  function boot() {
    $$('[data-slms-forums]').forEach(Forums);
    $$('[data-slms-chat]').forEach(Chat);
    $$('[data-slms-attendance]').forEach(Attendance);
    $$('[data-slms-cert]').forEach(Certificate);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
