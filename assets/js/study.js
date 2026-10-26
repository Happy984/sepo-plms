/* Sepo LMS — study tools: the checklist and block-based notes.
 *
 * Notes are a list of blocks (paragraph, headings, lists, to-do, quote,
 * callout, code, divider). Type "/" in an empty block for the block menu,
 * or use Markdown shortcuts at the start of a block ("# ", "- ", "1. ",
 * "[] ", "> ", "``` ", "---"). Select text for bold, italic, underline,
 * strike, code, highlight and links. Changes save automatically.
 */
(function () {
  'use strict';
  var UI = window.SepoLmsUI;
  if (!UI) return;
  var api = UI.api, esc = UI.esc, t = UI.t, $ = UI.$, $$ = UI.$$, confirmModal = UI.confirmModal;

  function q(params) {
    return Object.keys(params).filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
  }
  function uid() { return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }
  /**
   * Drag-to-reorder with a drop line: the dragged item is shown as the drag
   * image and marked in place, and a line shows exactly where it will land.
   * Items move only on drop.
   *   list      the container of the items (position: relative)
   *   itemSel   selector for an item
   *   handleSel selector for the part that starts a drag, or null for the item itself
   *   canDrag   () => bool
   *   onMove    () => void, called after an item was moved
   */
  function sortable(list, itemSel, handleSel, canDrag, onMove) {
    var dragged = null, before = undefined;
    var line = document.createElement('div');
    line.className = 'slms-drop-line';
    line.setAttribute('aria-hidden', 'true');

    function items() { return $$(itemSel, list).filter(function (n) { return n.parentNode === list; }); }

    function place(y) {
      var rest = items().filter(function (n) { return n !== dragged; });
      var target = null;
      for (var i = 0; i < rest.length; i++) {
        var r = rest[i].getBoundingClientRect();
        if (y < r.top + r.height / 2) { target = rest[i]; break; }
      }
      before = target;
      // Dropping right where it already is moves nothing, so no line.
      var same = target === dragged.nextElementSibling || (!target && !dragged.nextElementSibling);
      if (same) { line.hidden = true; return; }
      if (!line.parentNode) list.appendChild(line);
      line.hidden = false;
      var top;
      if (target) top = target.offsetTop - 2;
      else { var last = rest[rest.length - 1]; top = last ? last.offsetTop + last.offsetHeight - 1 : 0; }
      line.style.top = top + 'px';
    }

    function cleanup() {
      if (dragged) dragged.classList.remove('is-dragging');
      list.classList.remove('is-sorting');
      document.body.classList.remove('slms-grabbing');
      if (line.parentNode) line.remove();
      dragged = null;
      before = undefined;
    }

    list.addEventListener('dragstart', function (e) {
      var from = handleSel ? e.target.closest(handleSel) : e.target;
      var item = from ? from.closest(itemSel) : null;
      if (!item || item.parentNode !== list || !canDrag()) return;
      dragged = item;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', item.getAttribute('data-id') || '');
      var r = item.getBoundingClientRect();
      if (e.dataTransfer.setDragImage) e.dataTransfer.setDragImage(item, Math.min(40, e.clientX - r.left), Math.max(0, e.clientY - r.top));
      // Mark the original after the drag image has been taken.
      setTimeout(function () {
        if (!dragged) return;
        dragged.classList.add('is-dragging');
        list.classList.add('is-sorting');
        document.body.classList.add('slms-grabbing');
      }, 0);
    });
    list.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      place(e.clientY);
    });
    list.addEventListener('dragleave', function (e) {
      if (dragged && !list.contains(e.relatedTarget)) line.hidden = true;
    });
    list.addEventListener('drop', function (e) {
      if (!dragged) return;
      e.preventDefault();
      if (!line.hidden && before !== undefined) {
        list.insertBefore(dragged, before);
        var moved = dragged;
        cleanup();
        moved.classList.add('is-dropped');
        setTimeout(function () { moved.classList.remove('is-dropped'); }, 700);
        onMove();
        return;
      }
      cleanup();
    });
    list.addEventListener('dragend', cleanup);
  }

  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  // ============================================================ checklist
  function Checklist(host, course, item, itemTitle) {
    var items = [], filter = 'open';

    host.innerHTML = '<form class="slms-todo-add" data-add>'
      + '<label class="screen-reader-text" for="slms-todo-new-' + course + '">' + esc(t('tdAdd')) + '</label>'
      + '<input id="slms-todo-new-' + course + '" class="slms-input" maxlength="500" placeholder="' + esc(t('tdAdd')) + '" data-text>'
      + '<input type="date" class="slms-input slms-input--date" data-due aria-label="' + esc(t('tdDue')) + '">'
      + '<button type="submit" class="slms-btn slms-btn--primary slms-btn--sm">' + esc(t('tdAddBtn')) + '</button></form>'
      + (item ? '<label class="slms-check-row slms-small"><input type="checkbox" data-link checked> <span>' + esc(t('tdLink', itemTitle)) + '</span></label>' : '')
      + '<div class="slms-todo-tools"><div class="slms-chips slms-chips--sm" role="group" aria-label="' + esc(t('tdShow')) + '">'
      + ['open', 'done', 'all'].map(function (f) { return '<button type="button" class="slms-chip' + (f === filter ? ' is-on' : '') + '" data-filter="' + f + '">' + esc(t('td_' + f)) + ' <span data-n="' + f + '"></span></button>'; }).join('')
      + '</div><button type="button" class="slms-link-btn" data-clear hidden>' + esc(t('tdClear')) + '</button></div>'
      + '<ul class="slms-todos" data-list></ul><p class="slms-error" data-err role="alert"></p>';

    var list = $('[data-list]', host), err = $('[data-err]', host);

    function load() {
      api('/study/todos?' + q({ course: course })).then(function (d) { items = d.items; render(); }).catch(function (e) { err.textContent = e.message; });
    }

    function render() {
      var counts = { open: 0, done: 0, all: items.length };
      items.forEach(function (i) { counts[i.done ? 'done' : 'open']++; });
      ['open', 'done', 'all'].forEach(function (f) { $('[data-n="' + f + '"]', host).textContent = counts[f] ? '(' + counts[f] + ')' : ''; });
      $('[data-clear]', host).hidden = counts.done === 0;
      var shown = items.filter(function (i) { return filter === 'all' || (filter === 'done' ? i.done : !i.done); });
      if (!shown.length) {
        list.innerHTML = '<li class="slms-todo-empty">' + esc(filter === 'done' ? t('tdNoneDone') : t('tdNone')) + '</li>';
      } else {
        var now = today();
        list.innerHTML = shown.map(function (i) {
          var overdue = i.due && !i.done && i.due < now;
          return '<li class="slms-todo' + (i.done ? ' is-done' : '') + '" data-id="' + i.id + '" draggable="' + (filter === 'open' ? 'true' : 'false') + '">'
            + (filter === 'open' ? '<span class="slms-todo-handle" aria-hidden="true">⋮⋮</span>' : '')
            + '<input type="checkbox" data-toggle aria-label="' + esc(t('tdDone', i.text)) + '"' + (i.done ? ' checked' : '') + '>'
            + '<span class="slms-todo-text" data-text tabindex="0" title="' + esc(t('tdEditHint')) + '">' + esc(i.text) + '</span>'
            + (i.due ? '<span class="slms-todo-due' + (overdue ? ' is-over' : '') + '">' + esc(new Date(i.due + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' })) + '</span>' : '')
            + '<button type="button" class="slms-icon-btn" data-del aria-label="' + esc(t('tdDelete', i.text)) + '">✕</button></li>';
        }).join('');
      }
      host.dispatchEvent(new CustomEvent('slms:todo-count', { bubbles: true, detail: counts.open }));
    }

    $('[data-add]', host).addEventListener('submit', function (e) {
      e.preventDefault();
      var text = $('[data-text]', host), due = $('[data-due]', host), link = $('[data-link]', host);
      if (!text.value.trim()) return;
      api('/study/todo', 'POST', { course: course, item: link && link.checked ? item : 0, text: text.value.trim(), due: due.value })
        .then(function (d) { items.push(d.item); text.value = ''; due.value = ''; err.textContent = ''; filter = filter === 'done' ? 'open' : filter; render(); text.focus(); })
        .catch(function (er) { err.textContent = er.message; });
    });

    host.addEventListener('click', function (e) {
      var b;
      if ((b = e.target.closest('[data-filter]'))) {
        filter = b.getAttribute('data-filter');
        $$('[data-filter]', host).forEach(function (x) { x.classList.toggle('is-on', x === b); });
        render();
      } else if (e.target.closest('[data-clear]')) {
        api('/study/todo/clear', 'POST', { course: course }).then(function () { items = items.filter(function (i) { return !i.done; }); render(); });
      } else if ((b = e.target.closest('[data-del]'))) {
        var id = parseInt(b.closest('[data-id]').getAttribute('data-id'), 10);
        api('/study/todo/delete', 'POST', { id: id }).then(function () { items = items.filter(function (i) { return i.id !== id; }); render(); });
      } else if ((b = e.target.closest('[data-text]')) && b.closest('[data-id]')) {
        edit(b);
      }
    });
    host.addEventListener('keydown', function (e) {
      var b = e.target.closest('.slms-todo-text');
      if (b && (e.key === 'Enter' || e.key === 'F2') && !b.isContentEditable) { e.preventDefault(); edit(b); }
    });
    host.addEventListener('change', function (e) {
      var c = e.target.closest('[data-toggle]');
      if (!c) return;
      var id = parseInt(c.closest('[data-id]').getAttribute('data-id'), 10);
      var it = items.filter(function (i) { return i.id === id; })[0];
      it.done = c.checked;
      setTimeout(render, 250);
      api('/study/todo/update', 'POST', { id: id, done: c.checked }).catch(function () { it.done = !c.checked; render(); });
    });

    function edit(span) {
      var id = parseInt(span.closest('[data-id]').getAttribute('data-id'), 10);
      var it = items.filter(function (i) { return i.id === id; })[0];
      span.contentEditable = 'true';
      span.focus();
      document.getSelection().selectAllChildren(span);
      function finish(save) {
        span.contentEditable = 'false';
        span.removeEventListener('blur', onBlur);
        span.removeEventListener('keydown', onKey);
        var text = span.textContent.trim();
        if (save && text && text !== it.text) {
          it.text = text;
          api('/study/todo/update', 'POST', { id: id, text: text });
        }
        render();
      }
      function onBlur() { finish(true); }
      function onKey(e) {
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      }
      span.addEventListener('blur', onBlur);
      span.addEventListener('keydown', onKey);
    }

    sortable(list, '.slms-todo', null, function () { return filter === 'open'; }, function () {
      var order = $$('.slms-todo', list).map(function (li) { return parseInt(li.getAttribute('data-id'), 10); });
      var done = items.filter(function (i) { return i.done; });
      var byId = {};
      items.forEach(function (i) { byId[i.id] = i; });
      items = order.map(function (id) { return byId[id]; }).concat(done);
      api('/study/todo/order', 'POST', { ids: order });
    });

    load();
    return { reload: load };
  }

  // =========================================================== note editor
  var BLOCKS = [
    { type: 'p', label: 'nbText', hint: '', icon: 'T' },
    { type: 'h1', label: 'nbH1', hint: '#', icon: 'H1' },
    { type: 'h2', label: 'nbH2', hint: '##', icon: 'H2' },
    { type: 'h3', label: 'nbH3', hint: '###', icon: 'H3' },
    { type: 'ul', label: 'nbBullet', hint: '-', icon: '•' },
    { type: 'ol', label: 'nbNumbered', hint: '1.', icon: '1.' },
    { type: 'todo', label: 'nbTodo', hint: '[]', icon: '☐' },
    { type: 'quote', label: 'nbQuote', hint: '>', icon: '❝' },
    { type: 'callout', label: 'nbCallout', hint: '!', icon: '💡' },
    { type: 'code', label: 'nbCode', hint: '```', icon: '</>' },
    { type: 'divider', label: 'nbDivider', hint: '---', icon: '—' }
  ];
  var SHORTCUTS = { '#': 'h1', '##': 'h2', '###': 'h3', '-': 'ul', '*': 'ul', '1.': 'ol', '[]': 'todo', '[ ]': 'todo', '>': 'quote', '!': 'callout', '```': 'code' };
  var ICONS = ['📝', '📘', '📗', '📙', '📕', '💡', '⭐', '✅', '📌', '🧠', '🔬', '📐', '🧮', '🗂️', '🎯', '📅'];
  var ALLOWED = { B: 1, STRONG: 1, I: 1, EM: 1, U: 1, S: 1, CODE: 1, MARK: 1, A: 1, BR: 1 };

  /** Keep only the inline tags notes support. */
  function cleanHtml(node) {
    var out = '';
    node.childNodes.forEach(function (n) {
      if (n.nodeType === 3) { out += esc(n.nodeValue).replace(/ /g, ' '); return; }
      if (n.nodeType !== 1) return;
      var tag = n.tagName;
      var inner = cleanHtml(n);
      if (tag === 'BR') { out += '<br>'; return; }
      if (tag === 'DIV' || tag === 'P') { out += (out ? '<br>' : '') + inner; return; }
      if (!ALLOWED[tag]) { out += inner; return; }
      var name = tag.toLowerCase();
      if (name === 'strong') name = 'b';
      if (name === 'em') name = 'i';
      if (name === 'a') {
        var href = n.getAttribute('href') || '';
        if (!/^(https?:|mailto:)/i.test(href)) { out += inner; return; }
        out += '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + inner + '</a>';
        return;
      }
      out += inner ? '<' + name + '>' + inner + '</' + name + '>' : '';
    });
    return out;
  }

  function placeCaret(el, atStart) {
    el.focus();
    var r = document.createRange();
    r.selectNodeContents(el);
    r.collapse(!!atStart);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }

  function caretAtStart(el) {
    var s = window.getSelection();
    if (!s.rangeCount || !s.isCollapsed) return false;
    var r = s.getRangeAt(0).cloneRange();
    r.selectNodeContents(el);
    r.setEnd(s.getRangeAt(0).startContainer, s.getRangeAt(0).startOffset);
    return r.toString().length === 0;
  }

  function caretAtEnd(el) {
    var s = window.getSelection();
    if (!s.rangeCount || !s.isCollapsed) return false;
    var r = s.getRangeAt(0).cloneRange();
    r.selectNodeContents(el);
    r.setStart(s.getRangeAt(0).endContainer, s.getRangeAt(0).endOffset);
    return r.toString().length === 0;
  }

  /** Split a block's content at the caret: returns the HTML after the caret and removes it. */
  function splitAtCaret(el) {
    var s = window.getSelection();
    if (!s.rangeCount) return '';
    var r = s.getRangeAt(0);
    r.deleteContents();
    var after = document.createRange();
    after.selectNodeContents(el);
    after.setStart(r.endContainer, r.endOffset);
    var frag = after.extractContents();
    var tmp = document.createElement('div');
    tmp.appendChild(frag);
    return cleanHtml(tmp);
  }

  function NoteEditor(host, noteId, opts) {
    opts = opts || {};
    var note = null, saveTimer = null, saving = false, pending = false, menu = null, bar = null;

    host.innerHTML = '<div class="slms-spinner" aria-hidden="true"></div>';
    api('/study/note?' + q({ id: noteId })).then(function (d) { note = d.note; build(); }).catch(function (e) { host.innerHTML = '<p class="slms-error">' + esc(e.message) + '</p>'; });

    function build() {
      host.innerHTML = '<div class="slms-note-page">'
        + '<div class="slms-note-top">' + (opts.onBack ? '<button type="button" class="slms-link-btn" data-back>← ' + esc(t('nAll')) + '</button>' : '<span></span>')
        + '<span class="slms-note-status slms-small slms-muted" data-status role="status">' + esc(t('nSaved')) + '</span>'
        + '<button type="button" class="slms-icon-btn" data-delete aria-label="' + esc(t('nDelete')) + '" title="' + esc(t('nDelete')) + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button></div>'
        + '<button type="button" class="slms-note-icon" data-icon aria-label="' + esc(t('nIcon')) + '">' + esc(note.icon || '📝') + '</button>'
        + '<div class="slms-note-title" contenteditable="true" role="textbox" aria-label="' + esc(t('nTitle')) + '" data-placeholder="' + esc(t('nUntitled')) + '" data-title>' + esc(note.title) + '</div>'
        + '<div class="slms-note-where slms-small slms-muted">' + esc(note.course || t('nNoCourse'))
        + (note.lesson ? ' · <a href="' + esc(note.lesson_url) + '">' + esc(note.lesson) + '</a>' : '') + '</div>'
        + '<div class="slms-note-blocks" data-blocks></div>'
        + '<p class="slms-small slms-muted slms-note-help">' + esc(t('nHelp')) + '</p></div>';
      var wrap = $('[data-blocks]', host);
      (note.blocks.length ? note.blocks : [{ id: uid(), type: 'p', html: '' }]).forEach(function (b) { wrap.appendChild(blockEl(b)); });
      renumber();
      var title = $('[data-title]', host);
      title.addEventListener('input', function () { queue(); });
      title.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); var first = $('.slms-nb-text', wrap); if (first) placeCaret(first, true); }
      });
      title.addEventListener('paste', pastePlain);
      if (!note.title) title.focus();
      wire(wrap);
    }

    /** Centre each block's handle on the first line of its text. */
    function alignHandles() {
      $$('.slms-nb', host).forEach(function (el) {
        var handle = el.querySelector('.slms-nb-handle');
        if (!handle) return;
        var hr = el.querySelector('.slms-nb-hr');
        var size = handle.offsetHeight || 22;
        var top;
        if (hr) {
          top = hr.offsetTop + hr.offsetHeight / 2 - size / 2;
        } else {
          var text = el.querySelector('.slms-nb-text');
          if (!text) return;
          var cs = window.getComputedStyle(text);
          var lh = parseFloat(cs.lineHeight);
          if (isNaN(lh)) lh = parseFloat(cs.fontSize) * 1.2;
          top = text.offsetTop + (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.paddingTop) || 0) + (lh - size) / 2;
        }
        handle.style.top = Math.round(top) + 'px';
      });
    }

    function blockEl(b) {
      var el = document.createElement('div');
      el.className = 'slms-nb slms-nb--' + b.type;
      el.setAttribute('data-id', b.id || uid());
      el.setAttribute('data-type', b.type);
      var handle = '<button type="button" class="slms-nb-handle" draggable="true" aria-label="' + esc(t('nBlockMenu')) + '" title="' + esc(t('nDragHint')) + '">⋮⋮</button>';
      if (b.type === 'divider') {
        el.innerHTML = handle + '<hr class="slms-nb-hr" tabindex="0">';
        return el;
      }
      var pre = '';
      if (b.type === 'todo') pre = '<input type="checkbox" class="slms-nb-check"' + (b.checked ? ' checked' : '') + ' aria-label="' + esc(t('nbTodo')) + '">';
      if (b.type === 'ul') pre = '<span class="slms-nb-bullet" aria-hidden="true">•</span>';
      if (b.type === 'ol') pre = '<span class="slms-nb-num" aria-hidden="true"></span>';
      if (b.type === 'callout') pre = '<span class="slms-nb-emoji" aria-hidden="true">💡</span>';
      el.innerHTML = handle + pre + '<div class="slms-nb-text" contenteditable="true" spellcheck="' + (b.type === 'code' ? 'false' : 'true') + '" data-placeholder="' + esc(b.type === 'p' ? t('nTypeSlash') : t(blockDef(b.type).label)) + '">' + (b.html || '') + '</div>';
      if (b.type === 'todo' && b.checked) el.classList.add('is-checked');
      return el;
    }

    function blockDef(type) { return BLOCKS.filter(function (x) { return x.type === type; })[0] || BLOCKS[0]; }

    function renumber() {
      var n = 0;
      $$('.slms-nb', host).forEach(function (el) {
        if (el.getAttribute('data-type') === 'ol') { n++; el.querySelector('.slms-nb-num').textContent = n + '.'; } else { n = 0; }
      });
    }

    function serialize() {
      return $$('.slms-nb', host).map(function (el) {
        var type = el.getAttribute('data-type');
        var b = { id: el.getAttribute('data-id'), type: type, html: '' };
        var text = el.querySelector('.slms-nb-text');
        if (text) b.html = type === 'code' ? esc(text.innerText.replace(/\n$/, '')) : cleanHtml(text);
        if (type === 'todo') b.checked = el.querySelector('.slms-nb-check').checked;
        return b;
      });
    }

    function queue() {
      pending = true;
      setStatus(t('nEditing'));
      clearTimeout(saveTimer);
      saveTimer = setTimeout(save, 700);
    }

    function save() {
      if (saving) { saveTimer = setTimeout(save, 400); return; }
      if (!pending) return;
      pending = false;
      saving = true;
      setStatus(t('nSaving'));
      var title = $('[data-title]', host).innerText.trim();
      var icon = $('[data-icon]', host).textContent;
      api('/study/note/save', 'POST', { id: noteId, title: title, icon: icon, blocks: serialize() })
        .then(function () {
          setStatus(t('nSaved'));
          if (opts.onSaved) opts.onSaved({ id: noteId, title: title, icon: icon });
        })
        .catch(function () { pending = true; setStatus(t('nSaveFailed')); saveTimer = setTimeout(save, 4000); })
        .then(function () { saving = false; });
    }
    host.slmsFlush = function () { if (pending) { clearTimeout(saveTimer); save(); } };
    window.addEventListener('beforeunload', function (e) { if (pending || saving) { host.slmsFlush(); if (!window.SepoLmsLeaving) { e.preventDefault(); e.returnValue = ''; } } });

    function setStatus(s) { var el = $('[data-status]', host); if (el) el.textContent = s; }

    // --------------------------------------------------------------- input
    function wire(wrap) {
      wrap.addEventListener('input', function (e) {
        var text = e.target.closest('.slms-nb-text');
        if (!text) return;
        var el = text.closest('.slms-nb');
        var raw = text.textContent;
        // Markdown shortcuts: the marker followed by a space at the start of a block.
        if (el.getAttribute('data-type') === 'p' || el.getAttribute('data-type') === 'ul' || el.getAttribute('data-type') === 'ol') {
          var m = raw.match(/^(#{1,3}|[-*]|1\.|\[ ?\]|>|!|```)[  ]/);
          if (m && SHORTCUTS[m[1]] && SHORTCUTS[m[1]] !== el.getAttribute('data-type')) {
            text.innerHTML = text.innerHTML.replace(/^(\s|&nbsp;)*(#{1,3}|[-*]|1\.|\[ ?\]|&gt;|!|```)(&nbsp;|\s)/, '');
            el = convert(el, SHORTCUTS[m[1]]);
            placeCaret(el.querySelector('.slms-nb-text'), false);
          } else if (raw === '---' && el.getAttribute('data-type') === 'p') {
            var div = convert(el, 'divider');
            var next = addAfter(div, 'p', '');
            placeCaret(next.querySelector('.slms-nb-text'), true);
          }
        }
        slashCheck(el.querySelector('.slms-nb-text') || text);
        queue();
      });

      wrap.addEventListener('keydown', function (e) {
        if (menu && handleMenuKey(e)) return;
        var text = e.target.closest('.slms-nb-text');
        if (!text) {
          var hr = e.target.closest('.slms-nb-hr');
          if (hr && (e.key === 'Backspace' || e.key === 'Delete')) { e.preventDefault(); removeBlock(hr.closest('.slms-nb'), true); }
          return;
        }
        var el = text.closest('.slms-nb');
        var type = el.getAttribute('data-type');
        var mod = e.metaKey || e.ctrlKey;

        if (mod && !e.shiftKey && ['b', 'i', 'u'].indexOf(e.key.toLowerCase()) !== -1) {
          e.preventDefault();
          format({ b: 'bold', i: 'italic', u: 'underline' }[e.key.toLowerCase()]);
          return;
        }
        if (mod && e.key.toLowerCase() === 'e') { e.preventDefault(); wrapInline('code'); return; }
        if (mod && e.shiftKey && e.key.toLowerCase() === 'h') { e.preventDefault(); wrapInline('mark'); return; }
        if (mod && e.key.toLowerCase() === 'k') { e.preventDefault(); link(); return; }

        if (e.key === 'Enter' && !e.shiftKey) {
          if (type === 'code' && !mod) return; // newline inside code; Ctrl+Enter leaves the block
          e.preventDefault();
          var empty = text.textContent.trim() === '';
          if (empty && (type === 'ul' || type === 'ol' || type === 'todo' || type === 'quote' || type === 'callout')) {
            var plain = convert(el, 'p');
            placeCaret(plain.querySelector('.slms-nb-text'), true);
            queue();
            return;
          }
          var rest = type === 'code' ? '' : splitAtCaret(text);
          var nextType = (type === 'ul' || type === 'ol' || type === 'todo') ? type : 'p';
          var nb = addAfter(el, nextType, rest);
          placeCaret(nb.querySelector('.slms-nb-text'), true);
          queue();
          return;
        }

        if (e.key === 'Backspace' && caretAtStart(text) && window.getSelection().isCollapsed) {
          if (type !== 'p') {
            e.preventDefault();
            var p = convert(el, 'p');
            placeCaret(p.querySelector('.slms-nb-text'), true);
            queue();
            return;
          }
          var prev = el.previousElementSibling;
          if (prev) {
            e.preventDefault();
            var prevText = prev.querySelector('.slms-nb-text');
            if (!prevText) { removeBlock(prev, false); queue(); return; }
            var joinAt = prevText.innerHTML;
            prevText.innerHTML = joinAt + text.innerHTML;
            el.remove();
            renumber();
            caretAfterHtml(prevText, joinAt);
            queue();
          }
          return;
        }

        if (e.key === 'ArrowUp' && caretAtStart(text)) {
          var up = el.previousElementSibling;
          if (up && up.querySelector('.slms-nb-text')) { e.preventDefault(); placeCaret(up.querySelector('.slms-nb-text'), false); }
          else if (!up) { e.preventDefault(); placeCaret($('[data-title]', host), false); }
        }
        if (e.key === 'ArrowDown' && caretAtEnd(text)) {
          var down = el.nextElementSibling;
          if (down && down.querySelector('.slms-nb-text')) { e.preventDefault(); placeCaret(down.querySelector('.slms-nb-text'), true); }
        }
      });

      wrap.addEventListener('change', function (e) {
        var c = e.target.closest('.slms-nb-check');
        if (!c) return;
        c.closest('.slms-nb').classList.toggle('is-checked', c.checked);
        queue();
      });

      wrap.addEventListener('paste', pastePlain);

      wrap.addEventListener('click', function (e) {
        var h = e.target.closest('.slms-nb-handle');
        if (h) blockMenu(h.closest('.slms-nb'), h);
        var a = e.target.closest('.slms-nb-text a');
        if (a && (e.metaKey || e.ctrlKey)) window.open(a.href, '_blank', 'noopener');
      });

      // Drag a block by its handle to reorder.
      sortable(wrap, '.slms-nb', '.slms-nb-handle', function () { return true; }, function () {
        renumber();
        queue();
      });

      // Keep each handle level with the first line of its block.
      new MutationObserver(function () { window.requestAnimationFrame(alignHandles); }).observe(wrap, { childList: true });
      window.addEventListener('resize', alignHandles);
      alignHandles();

      document.addEventListener('selectionchange', selectionBar);
      host.addEventListener('click', function (e) {
        if (e.target.closest('[data-back]')) { host.slmsFlush(); opts.onBack(); }
        if (e.target.closest('[data-delete]')) {
          confirmModal(t('nDeleteTitle'), t('nDeleteMsg'), t('delete')).then(function (yes) {
            if (!yes) return;
            pending = false;
            api('/study/note/delete', 'POST', { id: noteId }).then(function () { if (opts.onDeleted) opts.onDeleted(noteId); });
          });
        }
        if (e.target.closest('[data-icon]')) iconPicker(e.target.closest('[data-icon]'));
      });
    }

    function caretAfterHtml(el, html) {
      var probe = document.createElement('div');
      probe.innerHTML = html;
      var len = probe.textContent.length;
      el.focus();
      var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      var node, seen = 0;
      while ((node = walker.nextNode())) {
        if (seen + node.length >= len) {
          var r = document.createRange();
          r.setStart(node, len - seen);
          r.collapse(true);
          var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
          return;
        }
        seen += node.length;
      }
      placeCaret(el, false);
    }

    function pastePlain(e) {
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      if (text === undefined) return;
      e.preventDefault();
      var target = e.target.closest('.slms-nb-text') || e.target.closest('[data-title]');
      if (!target) return;
      var lines = text.replace(/\r\n?/g, '\n').split('\n');
      if (target.hasAttribute('data-title') || lines.length === 1 || target.closest('.slms-nb--code')) {
        document.execCommand('insertText', false, target.hasAttribute('data-title') ? lines.join(' ') : text);
        return;
      }
      document.execCommand('insertText', false, lines.shift());
      var el = target.closest('.slms-nb');
      lines.forEach(function (line) {
        var m = line.match(/^(#{1,3}|[-*]|1\.|\[ ?\]|>)\s+(.*)$/);
        el = addAfter(el, m ? SHORTCUTS[m[1]] : 'p', esc(m ? m[2] : line));
      });
      placeCaret(el.querySelector('.slms-nb-text') || el, false);
      queue();
    }

    function convert(el, type) {
      var text = el.querySelector('.slms-nb-text');
      var html = text ? (el.getAttribute('data-type') === 'code' ? esc(text.innerText) : cleanHtml(text)) : '';
      var nb = blockEl({ id: el.getAttribute('data-id'), type: type, html: type === 'code' ? esc(text ? text.innerText : '') : html });
      el.replaceWith(nb);
      renumber();
      queue();
      return nb;
    }

    function addAfter(el, type, html) {
      var nb = blockEl({ id: uid(), type: type, html: html || '' });
      el.after(nb);
      renumber();
      return nb;
    }

    function removeBlock(el, focusPrev) {
      var prev = el.previousElementSibling, next = el.nextElementSibling;
      var wrap = el.parentNode;
      el.remove();
      if (!wrap.children.length) wrap.appendChild(blockEl({ id: uid(), type: 'p', html: '' }));
      renumber();
      var target = (focusPrev ? prev : next) || prev || next || wrap.firstElementChild;
      var tx = target && target.querySelector('.slms-nb-text');
      if (tx) placeCaret(tx, !focusPrev);
      queue();
    }

    // --------------------------------------------------------- slash menu
    function slashCheck(text) {
      var raw = text.textContent;
      var m = raw.match(/^\/(\S*)$/);
      if (m) openMenu(text, m[1]);
      else closeMenu();
    }

    function openMenu(text, filter) {
      var items = BLOCKS.filter(function (b) {
        return !filter || t(b.label).toLowerCase().indexOf(filter.toLowerCase()) !== -1 || b.type.indexOf(filter.toLowerCase()) === 0;
      });
      if (!menu) {
        menu = document.createElement('div');
        menu.className = 'slms-slash';
        menu.setAttribute('role', 'listbox');
        document.body.appendChild(menu);
        menu.addEventListener('mousedown', function (e) {
          var it = e.target.closest('[data-type]');
          if (!it) return;
          e.preventDefault();
          choose(it.getAttribute('data-type'));
        });
      }
      menu.slmsText = text;
      menu.slmsIndex = 0;
      if (!items.length) { menu.innerHTML = '<div class="slms-slash-empty">' + esc(t('nNoBlock')) + '</div>'; }
      else menu.innerHTML = '<div class="slms-slash-h">' + esc(t('nBlocks')) + '</div>' + items.map(function (b, i) {
        return '<div class="slms-slash-item' + (i === 0 ? ' is-on' : '') + '" role="option" data-type="' + b.type + '"><span class="slms-slash-icon">' + esc(b.icon) + '</span><span>' + esc(t(b.label)) + '</span>'
          + (b.hint ? '<kbd>' + esc(b.hint) + '</kbd>' : '') + '</div>';
      }).join('');
      var r = text.getBoundingClientRect();
      menu.style.left = Math.max(8, Math.min(window.innerWidth - 260, r.left + window.scrollX)) + 'px';
      menu.style.top = (r.bottom + window.scrollY + 6) + 'px';
    }

    function closeMenu() { if (menu) { menu.remove(); menu = null; } }

    function handleMenuKey(e) {
      var opts2 = $$('.slms-slash-item', menu);
      if (e.key === 'Escape') { e.preventDefault(); closeMenu(); return true; }
      if (!opts2.length) return false;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        menu.slmsIndex = (menu.slmsIndex + (e.key === 'ArrowDown' ? 1 : -1) + opts2.length) % opts2.length;
        opts2.forEach(function (o, i) { o.classList.toggle('is-on', i === menu.slmsIndex); });
        opts2[menu.slmsIndex].scrollIntoView({ block: 'nearest' });
        return true;
      }
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        choose(opts2[menu.slmsIndex].getAttribute('data-type'));
        return true;
      }
      return false;
    }

    function choose(type) {
      var text = menu.slmsText;
      closeMenu();
      text.innerHTML = '';
      var el = text.closest('.slms-nb');
      var nb = convert(el, type);
      if (type === 'divider') {
        var after = addAfter(nb, 'p', '');
        placeCaret(after.querySelector('.slms-nb-text'), true);
      } else {
        placeCaret(nb.querySelector('.slms-nb-text'), true);
      }
      queue();
    }

    // ---------------------------------------------------------- block menu
    function blockMenu(el, anchor) {
      closeMenu();
      menu = document.createElement('div');
      menu.className = 'slms-slash slms-slash--block';
      menu.innerHTML = '<div class="slms-slash-h">' + esc(t('nTurnInto')) + '</div>'
        + BLOCKS.filter(function (b) { return b.type !== 'divider'; }).map(function (b) {
          return '<div class="slms-slash-item" data-turn="' + b.type + '"><span class="slms-slash-icon">' + esc(b.icon) + '</span><span>' + esc(t(b.label)) + '</span></div>';
        }).join('')
        + '<div class="slms-slash-sep"></div>'
        + '<div class="slms-slash-item" data-act="up"><span class="slms-slash-icon">↑</span><span>' + esc(t('nMoveUp')) + '</span></div>'
        + '<div class="slms-slash-item" data-act="down"><span class="slms-slash-icon">↓</span><span>' + esc(t('nMoveDown')) + '</span></div>'
        + '<div class="slms-slash-item" data-act="dup"><span class="slms-slash-icon">⧉</span><span>' + esc(t('nDuplicate')) + '</span></div>'
        + '<div class="slms-slash-item slms-bad" data-act="del"><span class="slms-slash-icon">✕</span><span>' + esc(t('delete')) + '</span></div>';
      document.body.appendChild(menu);
      var r = anchor.getBoundingClientRect();
      menu.style.left = Math.max(8, Math.min(window.innerWidth - 260, r.left + window.scrollX)) + 'px';
      menu.style.top = (r.bottom + window.scrollY + 4) + 'px';
      menu.addEventListener('mousedown', function (e) {
        e.preventDefault();
        var it = e.target.closest('[data-turn],[data-act]');
        if (!it) return;
        var turn = it.getAttribute('data-turn'), act = it.getAttribute('data-act');
        closeMenu();
        if (turn) { var nb = convert(el, turn); var tx = nb.querySelector('.slms-nb-text'); if (tx) placeCaret(tx, false); }
        if (act === 'up' && el.previousElementSibling) { el.previousElementSibling.before(el); renumber(); queue(); }
        if (act === 'down' && el.nextElementSibling) { el.nextElementSibling.after(el); renumber(); queue(); }
        if (act === 'dup') {
          var data = serialize().filter(function (b) { return b.id === el.getAttribute('data-id'); })[0];
          data.id = uid();
          el.after(blockEl(data)); renumber(); queue();
        }
        if (act === 'del') removeBlock(el, true);
      });
      setTimeout(function () {
        document.addEventListener('mousedown', function off(ev) {
          if (menu && !menu.contains(ev.target)) { closeMenu(); }
          document.removeEventListener('mousedown', off);
        });
      }, 0);
    }

    function iconPicker(btn) {
      closeMenu();
      menu = document.createElement('div');
      menu.className = 'slms-slash slms-emoji-grid';
      menu.innerHTML = ICONS.map(function (i) { return '<button type="button" data-emoji="' + esc(i) + '">' + esc(i) + '</button>'; }).join('');
      document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.left = (r.left + window.scrollX) + 'px';
      menu.style.top = (r.bottom + window.scrollY + 4) + 'px';
      menu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-emoji]');
        if (!b) return;
        btn.textContent = b.getAttribute('data-emoji');
        closeMenu();
        queue();
      });
      setTimeout(function () {
        document.addEventListener('mousedown', function off(ev) {
          if (menu && !menu.contains(ev.target)) closeMenu();
          document.removeEventListener('mousedown', off);
        });
      }, 0);
    }

    // ----------------------------------------------------- inline format
    function inEditor() {
      var s = window.getSelection();
      if (!s.rangeCount) return null;
      var n = s.getRangeAt(0).commonAncestorContainer;
      var el = n.nodeType === 1 ? n : n.parentNode;
      var text = el && el.closest ? el.closest('.slms-nb-text') : null;
      return text && host.contains(text) && !text.closest('.slms-nb--code') ? text : null;
    }

    function format(cmd) {
      if (!inEditor()) return;
      document.execCommand(cmd, false);
      queue();
    }

    function wrapInline(tag) {
      var text = inEditor();
      var s = window.getSelection();
      if (!text || s.isCollapsed) return;
      var r = s.getRangeAt(0);
      var parent = r.commonAncestorContainer.nodeType === 1 ? r.commonAncestorContainer : r.commonAncestorContainer.parentNode;
      var existing = parent.closest(tag);
      if (existing && text.contains(existing)) {
        existing.replaceWith.apply(existing, Array.prototype.slice.call(existing.childNodes));
      } else {
        var w = document.createElement(tag);
        w.appendChild(r.extractContents());
        r.insertNode(w);
        s.removeAllRanges();
        var nr = document.createRange();
        nr.selectNodeContents(w);
        s.addRange(nr);
      }
      queue();
    }

    function link() {
      var text = inEditor();
      if (!text || window.getSelection().isCollapsed) return;
      var saved = window.getSelection().getRangeAt(0).cloneRange();
      var url = window.prompt(t('nLinkPrompt'), 'https://');
      if (!url) return;
      if (!/^(https?:\/\/|mailto:)/i.test(url)) url = 'https://' + url;
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(saved);
      document.execCommand('createLink', false, url);
      queue();
    }

    function selectionBar() {
      var text = inEditor();
      var s = window.getSelection();
      if (!text || s.isCollapsed || !s.toString().trim()) { if (bar) { bar.remove(); bar = null; } return; }
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'slms-fmt-bar';
        bar.setAttribute('role', 'toolbar');
        bar.innerHTML = [['bold', '<b>B</b>', 'nBold'], ['italic', '<i>I</i>', 'nItalic'], ['underline', '<u>U</u>', 'nUnderline'], ['strikeThrough', '<s>S</s>', 'nStrike'],
          ['code', '&lt;/&gt;', 'nCodeInline'], ['mark', '<mark>H</mark>', 'nHighlight'], ['link', '🔗', 'nLink']].map(function (b) {
          return '<button type="button" data-fmt="' + b[0] + '" aria-label="' + esc(t(b[2])) + '" title="' + esc(t(b[2])) + '">' + b[1] + '</button>';
        }).join('');
        document.body.appendChild(bar);
        bar.addEventListener('mousedown', function (e) {
          e.preventDefault();
          var b = e.target.closest('[data-fmt]');
          if (!b) return;
          var f = b.getAttribute('data-fmt');
          if (f === 'code' || f === 'mark') wrapInline(f);
          else if (f === 'link') link();
          else format(f);
        });
      }
      var r = s.getRangeAt(0).getBoundingClientRect();
      bar.style.left = Math.max(8, Math.min(window.innerWidth - 260, r.left + window.scrollX + r.width / 2 - 120)) + 'px';
      bar.style.top = (r.top + window.scrollY - 44) + 'px';
    }

    return host;
  }

  // ======================================================= notes browser
  function NotesBrowser(host, opts) {
    var course = opts.course; // -1 = every course
    var item = opts.item || 0;
    var courses = opts.courses || [];
    var notes = [], search = '', courseFilter = course >= 0 ? course : -1, openId = 0;
    var wide = !!opts.wide;

    host.innerHTML = '<div class="slms-notes' + (wide ? ' slms-notes--wide' : '') + '">'
      + '<aside class="slms-notes-side" data-side>'
      + '<div class="slms-notes-tools"><label class="slms-search"><span class="screen-reader-text">' + esc(t('nSearch')) + '</span><input type="search" data-search placeholder="' + esc(t('nSearch')) + '"></label>'
      + (wide && courses.length ? '<select class="slms-input" data-course aria-label="' + esc(t('nCourse')) + '"><option value="-1">' + esc(t('nAllCourses')) + '</option>'
        + courses.map(function (c) { return '<option value="' + c.id + '">' + esc(c.title) + '</option>'; }).join('') + '<option value="0">' + esc(t('nNoCourse')) + '</option></select>' : '')
      + '<button type="button" class="slms-btn slms-btn--primary slms-btn--sm" data-new>+ ' + esc(t('nNew')) + '</button></div>'
      + '<ul class="slms-notes-list" data-list></ul></aside>'
      + '<section class="slms-notes-main" data-main>' + (wide ? '<p class="slms-empty">' + esc(t('nPick')) + '</p>' : '') + '</section></div>';

    var list = $('[data-list]', host), main = $('[data-main]', host), side = $('[data-side]', host);
    if (!wide) main.hidden = true;

    function load() {
      api('/study/notes?' + q({ course: courseFilter, q: search })).then(function (d) {
        notes = d.notes;
        render();
      });
    }

    function render() {
      if (!notes.length) {
        list.innerHTML = '<li class="slms-todo-empty">' + esc(search ? t('noMatch') : t('nNone')) + '</li>';
        return;
      }
      list.innerHTML = notes.map(function (n) {
        return '<li><button type="button" class="slms-note-item' + (n.id === openId ? ' is-on' : '') + (item && n.item_id === item ? ' is-here' : '') + '" data-open="' + n.id + '">'
          + '<span class="slms-note-item-icon">' + esc(n.icon || '📝') + '</span><span class="slms-note-item-main"><strong>' + esc(n.title || t('nUntitled')) + '</strong>'
          + '<span class="slms-small slms-muted">' + esc([wide ? n.course : '', n.lesson, n.when].filter(Boolean).join(' · ')) + '</span>'
          + (n.excerpt ? '<span class="slms-small slms-note-excerpt">' + esc(n.excerpt) + '</span>' : '') + '</span></button></li>';
      }).join('');
    }

    /** Close the open note and show the list (and, when wide, the empty pane). */
    function closeEditor() {
      openId = 0;
      main.innerHTML = wide ? '<p class="slms-empty">' + esc(t('nPick')) + '</p>' : '';
      if (!wide) { main.hidden = true; side.hidden = false; }
      host.querySelector('.slms-notes').classList.remove('is-editing');
      var first = side.querySelector('[data-search]');
      if (first && !wide) first.focus();
    }

    function open(id) {
      if (main.firstElementChild && main.firstElementChild.slmsFlush) main.firstElementChild.slmsFlush();
      openId = id;
      render();
      var holder = document.createElement('div');
      main.innerHTML = '';
      main.appendChild(holder);
      if (!wide) { side.hidden = true; main.hidden = false; }
      else host.querySelector('.slms-notes').classList.add('is-editing');
      NoteEditor(holder, id, {
        onBack: function () { closeEditor(); load(); },
        onSaved: function (n) {
          notes.forEach(function (x) { if (x.id === n.id) { x.title = n.title; x.icon = n.icon; } });
          render();
        },
        onDeleted: function () { closeEditor(); load(); }
      });
    }

    host.addEventListener('click', function (e) {
      var b;
      if ((b = e.target.closest('[data-open]'))) open(parseInt(b.getAttribute('data-open'), 10));
      if (e.target.closest('[data-new]')) {
        var target = courseFilter >= 0 ? courseFilter : (course >= 0 ? course : 0);
        api('/study/note', 'POST', { course: target, item: item, title: '' }).then(function (d) {
          notes.unshift({ id: d.note.id, title: '', icon: '', course: '', lesson: '', when: '', item_id: item });
          open(d.note.id);
        });
      }
    });
    var timer = null;
    $('[data-search]', host).addEventListener('input', function (e) {
      clearTimeout(timer);
      timer = setTimeout(function () { search = e.target.value.trim(); load(); }, 300);
    });
    var sel = $('[data-course]', host);
    if (sel) sel.addEventListener('change', function () { courseFilter = parseInt(sel.value, 10); load(); });

    load();
  }

  // ================================================================ drawer
  function Drawer(root) {
    var course = parseInt(root.getAttribute('data-course'), 10) || 0;
    var item = parseInt(root.getAttribute('data-item'), 10) || 0;
    var itemTitle = root.getAttribute('data-item-title') || '';
    var openBtn = $('[data-slms-study-open]', root);
    var panel = $('.slms-study-panel', root);
    var started = { todo: false, notes: false };

    function show(on) {
      panel.hidden = !on;
      openBtn.setAttribute('aria-expanded', String(on));
      root.classList.toggle('is-open', on);
      if (on) tab(root.getAttribute('data-tab') || 'todo');
    }

    function tab(name) {
      root.setAttribute('data-tab', name);
      $$('[data-slms-study-tab]', root).forEach(function (b) {
        var on = b.getAttribute('data-slms-study-tab') === name;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-selected', String(on));
      });
      $$('[data-slms-study-pane]', root).forEach(function (p) { p.hidden = p.getAttribute('data-slms-study-pane') !== name; });
      var pane = $('[data-slms-study-pane="' + name + '"]', root);
      if (!started[name]) {
        started[name] = true;
        if (name === 'todo') Checklist(pane, course, item, itemTitle);
        else {
          NotesBrowser(pane, { course: course, item: item, wide: false });
          pane.insertAdjacentHTML('beforeend', '<p class="slms-small slms-notes-all"><a href="' + esc(root.getAttribute('data-notes-url')) + '">' + esc(t('nOpenAll')) + ' →</a></p>');
        }
      }
    }

    openBtn.addEventListener('click', function () { show(panel.hidden); });
    $('[data-slms-study-close]', root).addEventListener('click', function () { show(false); openBtn.focus(); });
    $$('[data-slms-study-tab]', root).forEach(function (b) { b.addEventListener('click', function () { tab(b.getAttribute('data-slms-study-tab')); }); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden && !document.querySelector('.slms-modal') && !document.querySelector('.slms-slash')) { show(false); openBtn.focus(); }
    });
  }

  function boot() {
    $$('[data-slms-study]').forEach(Drawer);
    $$('[data-slms-notes-app]').forEach(function (el) {
      var courses = [];
      try { courses = JSON.parse(el.getAttribute('data-courses') || '[]'); } catch (e) { courses = []; }
      var panel = el.closest('[data-slms-mine-panel]');
      var started = false;
      function go() { if (!started) { started = true; NotesBrowser(el, { course: -1, courses: courses, wide: true }); } }
      if (panel && panel.hidden) {
        var tab = document.querySelector('[data-slms-mine-tab="notes"]');
        if (tab) tab.addEventListener('click', go);
        if (window.location.hash === '#notes') go();
      } else go();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
