/* Sepo LMS — admin screens. */
(function ($) {
  'use strict';

  var CFG = window.SepoLmsAdmin || { i18n: {} };
  var T = CFG.i18n || {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s).replace(/%(\d+\$)?s/g, function (m, pos) { return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]); });
  }

  // ------------------------------------------------------- confirm links
  $(document).on('click', '[data-slms-confirm]', function (e) {
    var k = $(this).data('slms-confirm');
    var msg = k === 'remove' ? T.confirmRemove : (k === 'paid' ? T.confirmPaid : T.confirmDelete);
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // -------------------------------------------------------- price preview
  $('[data-slms-pricing]').each(function () {
    var box = $(this);
    var out = $('[data-slms-preview]');
    var def = box.data('default-currency') || '';
    function num(sel) { return Math.max(0, parseFloat(box.find('[data-p="' + sel + '"]').val()) || 0); }
    function money(v, c) { return c + ' ' + v.toFixed(2); }
    function update() {
      var cur = (box.find('[data-p="currency"]').val() || def).toUpperCase();
      var price = num('price'), d = Math.min(100, num('discount')), tax = Math.min(100, num('tax'));
      var unit = Math.round((price - price * d / 100) * 100) / 100;
      var t = Math.round(unit * tax) / 100;
      var total = Math.round((unit + t) * 100) / 100;
      out.text(price <= 0 ? '' : (money(price, cur) + ' → ' + money(unit, cur) + ' + ' + money(t, cur) + ' = ' + money(total, cur)));
    }
    box.on('input change', 'input', update);
  });

  // ------------------------------------------------------ outline sorting
  $('[data-slms-sortable]').sortable({ handle: '.slms-handle', axis: 'y', placeholder: 'slms-sort-ph', forcePlaceholderSize: true });

  // --------------------------------------------------------- image fields
  $(document).on('click', '[data-slms-image-pick]', function (e) {
    e.preventDefault();
    if (!window.wp || !wp.media) return;
    var wrap = $(this).closest('[data-slms-image]');
    var frame = wp.media({ title: T.choose || 'Choose image', button: { text: T.use || 'Use this image' }, library: { type: 'image' }, multiple: false });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      var url = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
      wrap.find('[data-slms-image-id]').val(a.id);
      wrap.find('[data-slms-image-prev]').html('<img src="' + esc(url) + '" alt="">');
      wrap.find('[data-slms-image-clear]').prop('hidden', false);
    });
    frame.open();
  });
  $(document).on('click', '[data-slms-image-clear]', function (e) {
    e.preventDefault();
    var wrap = $(this).closest('[data-slms-image]');
    wrap.find('[data-slms-image-id]').val('');
    wrap.find('[data-slms-image-prev]').empty();
    $(this).prop('hidden', true);
  });

  // ------------------------------------------------ payment method rows
  $('[data-slms-methods]').each(function () {
    var table = $(this);
    var body = table.find('[data-slms-method-rows]');
    function reindex() {
      body.find('[data-slms-method-row]').each(function (i) {
        $(this).find('[name]').each(function () {
          this.name = this.name.replace(/\[methods\]\[\d+\]/, '[methods][' + i + ']');
        });
      });
    }
    body.sortable({ handle: '.slms-col-handle', axis: 'y', update: reindex });
    $('[data-slms-method-add]').on('click', function () {
      var tpl = $('#tmpl-slms-method').html();
      body.append(tpl);
      reindex();
      body.find('[data-slms-method-row]').last().find('input[type=text]').first().trigger('focus');
    });
    table.on('click', '[data-slms-method-remove]', function () {
      $(this).closest('[data-slms-method-row]').remove();
      reindex();
    });
    table.closest('form').on('submit', reindex);
  });

  // ------------------------------------------------------- quiz builder
  $('[data-slms-qb]').each(function () {
    var root = $(this);
    var store = root.find('[data-slms-qb-store]');
    var list = root.find('[data-slms-qb-list]');
    var types = root.data('types') || {};
    var L = root.data('i18n') || {};
    var maxOpt = parseInt(root.data('max-options'), 10) || 10;
    var maxQ = parseInt(root.data('max-questions'), 10) || 100;
    var questions = [];
    var collapsed = {};
    try { questions = JSON.parse(store.val() || '[]') || []; } catch (e) { questions = []; }

    function choice(t) { return t === 'multiplechoice' || t === 'multipleselect'; }
    function nextId() { return questions.reduce(function (m, q) { return Math.max(m, parseInt(q.id, 10) || 0); }, 0) + 1; }
    function blank(type) {
      return { id: nextId(), type: type, question: '', required: true, points: 1, image: '', explanation: '', options: choice(type) ? ['', ''] : [], correct: [], answer: '' };
    }
    function save() {
      store.val(JSON.stringify(questions));
      var pts = questions.reduce(function (s, q) { return s + (parseFloat(q.points) || 0); }, 0);
      root.find('[data-slms-qb-summary]').text(fmt(L.summary, questions.length, Math.round(pts * 100) / 100));
    }

    function warn(q) {
      if (choice(q.type)) {
        var filled = q.options.filter(function (o) { return String(o).trim() !== ''; }).length;
        if (!q.correct.length && filled) return L.noCorrect;
      } else if (!String(q.answer || '').trim() && (parseFloat(q.points) || 0) > 0) {
        return L.noAnswer;
      }
      return '';
    }

    function card(q, i) {
      var c = collapsed[q.id];
      var typeOpts = Object.keys(types).map(function (k) { return '<option value="' + k + '"' + (k === q.type ? ' selected' : '') + '>' + esc(types[k]) + '</option>'; }).join('');
      var w = warn(q);
      var h = '<div class="slms-qb-card' + (c ? ' is-collapsed' : '') + '" data-i="' + i + '">';
      h += '<div class="slms-qb-head">'
        + '<span class="dashicons dashicons-menu slms-qb-handle" aria-hidden="true"></span>'
        + '<strong class="slms-qb-num">' + esc(L.question) + ' ' + (i + 1) + '</strong>'
        + '<span class="slms-qb-title">' + esc(q.question ? q.question.slice(0, 80) : L.untitled) + '</span>'
        + (w ? '<span class="slms-qb-warn">' + esc(w) + '</span>' : '')
        + '<span class="slms-qb-tools">'
        + '<button type="button" class="button-link" data-act="up" title="' + esc(L.moveUp) + '"' + (i === 0 ? ' disabled' : '') + '><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
        + '<button type="button" class="button-link" data-act="down" title="' + esc(L.moveDown) + '"' + (i === questions.length - 1 ? ' disabled' : '') + '><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
        + '<button type="button" class="button-link" data-act="dup" title="' + esc(L.duplicate) + '"><span class="dashicons dashicons-admin-page"></span></button>'
        + '<button type="button" class="button-link slms-qb-del" data-act="del" title="' + esc(L['delete']) + '"><span class="dashicons dashicons-trash"></span></button>'
        + '<button type="button" class="button-link" data-act="toggle" aria-expanded="' + (c ? 'false' : 'true') + '" title="' + esc(c ? L.expand : L.collapse) + '"><span class="dashicons dashicons-arrow-' + (c ? 'down' : 'up') + '"></span></button>'
        + '</span></div>';

      h += '<div class="slms-qb-body">';
      h += '<div class="slms-qb-row">'
        + '<label class="slms-qb-type">' + esc(L.type) + '<select data-f="type">' + typeOpts + '</select></label>'
        + '<label class="slms-qb-pts">' + esc(L.points) + '<input type="number" min="0" step="0.5" data-f="points" value="' + esc(q.points) + '"></label>'
        + '<label class="slms-qb-req"><input type="checkbox" data-f="required"' + (q.required ? ' checked' : '') + '> ' + esc(L.required) + '</label>'
        + '</div>';
      h += '<textarea class="slms-qb-q" rows="2" data-f="question" placeholder="' + esc(L.questionPh) + '">' + esc(q.question) + '</textarea>';

      if (choice(q.type)) {
        var multi = q.type === 'multipleselect';
        h += '<div class="slms-qb-label">' + esc(multi ? L.optionsMulti : L.options) + '</div><ul class="slms-qb-opts">';
        q.options.forEach(function (o, oi) {
          var on = q.correct.indexOf(oi) !== -1;
          h += '<li class="slms-qb-opt' + (on ? ' is-correct' : '') + '" data-o="' + oi + '">'
            + '<span class="dashicons dashicons-menu slms-qb-ohandle" aria-hidden="true"></span>'
            + '<label class="slms-qb-correct" title="' + esc(L.correct) + '"><input type="' + (multi ? 'checkbox' : 'radio') + '" name="slms-qb-c-' + q.id + '" data-f="correct" value="' + oi + '"' + (on ? ' checked' : '') + '><span class="screen-reader-text">' + esc(L.correct) + '</span></label>'
            + '<input type="text" data-f="option" value="' + esc(o) + '" placeholder="' + esc(fmt(L.optionPh, oi + 1)) + '">'
            + '<button type="button" class="button-link slms-qb-del" data-act="optdel" title="' + esc(L.removeOption) + '"' + (q.options.length <= 2 ? ' disabled' : '') + '><span class="dashicons dashicons-no-alt"></span></button>'
            + '</li>';
        });
        h += '</ul>';
        if (q.options.length < maxOpt) h += '<button type="button" class="button" data-act="optadd">+ ' + esc(L.addOption) + '</button>';
        else h += '<p class="description">' + esc(fmt(L.maxOptions, maxOpt)) + '</p>';
      } else {
        h += '<label class="slms-qb-label">' + esc(L.expected)
          + '<input type="' + (q.type === 'numeric' ? 'number' : (q.type === 'date' ? 'date' : 'text')) + '" step="any" class="widefat" data-f="answer" value="' + esc(q.answer) + '"></label>'
          + '<p class="description">' + esc(q.type === 'numeric' ? L.numericHelp : L.expectedHelp) + '</p>';
      }

      h += '<div class="slms-qb-extra">'
        + '<div class="slms-qb-img">' + (q.image ? '<img src="' + esc(q.image) + '" alt=""><button type="button" class="button-link-delete" data-act="imgdel">' + esc(L.removeImage) + '</button>'
          : '<button type="button" class="button" data-act="img"><span class="dashicons dashicons-format-image"></span> ' + esc(L.addImage) + '</button>') + '</div>'
        + '<label class="slms-qb-exp">' + esc(L.explanation) + '<textarea rows="2" data-f="explanation">' + esc(q.explanation) + '</textarea></label>'
        + '</div>';
      h += '</div></div>';
      return h;
    }

    function render() {
      if (!questions.length) {
        list.html('<p class="slms-qb-empty">' + esc(L.empty) + '</p>');
      } else {
        list.html(questions.map(card).join(''));
      }
      list.find('.slms-qb-opts').sortable({
        handle: '.slms-qb-ohandle', axis: 'y',
        update: function () {
          var i = $(this).closest('.slms-qb-card').data('i');
          var q = questions[i];
          var order = $(this).children().map(function () { return parseInt($(this).data('o'), 10); }).get();
          var opts = order.map(function (o) { return q.options[o]; });
          var corr = [];
          order.forEach(function (o, newIndex) { if (q.correct.indexOf(o) !== -1) corr.push(newIndex); });
          q.options = opts; q.correct = corr;
          save(); render();
        }
      });
      save();
    }

    list.sortable({
      handle: '.slms-qb-handle', axis: 'y', placeholder: 'slms-sort-ph', forcePlaceholderSize: true, items: '> .slms-qb-card',
      update: function () {
        var order = list.children('.slms-qb-card').map(function () { return parseInt($(this).data('i'), 10); }).get();
        questions = order.map(function (i) { return questions[i]; });
        render();
      }
    });

    // typing updates the model without re-rendering, so focus stays put
    list.on('input change', '[data-f]', function (e) {
      var el = $(this), cardEl = el.closest('.slms-qb-card'), i = cardEl.data('i'), q = questions[i], f = el.data('f');
      if (!q) return;
      if (f === 'question') { q.question = el.val(); cardEl.find('.slms-qb-title').text(q.question ? q.question.slice(0, 80) : L.untitled); }
      else if (f === 'points') q.points = Math.max(0, parseFloat(el.val()) || 0);
      else if (f === 'required') q.required = el.is(':checked');
      else if (f === 'answer') q.answer = el.val();
      else if (f === 'explanation') q.explanation = el.val();
      else if (f === 'option') q.options[parseInt(el.closest('[data-o]').data('o'), 10)] = el.val();
      else if (f === 'correct' && e.type === 'change') {
        var o = parseInt(el.val(), 10);
        if (q.type === 'multiplechoice') q.correct = [o];
        else {
          var at = q.correct.indexOf(o);
          if (el.is(':checked') && at === -1) q.correct.push(o);
          if (!el.is(':checked') && at !== -1) q.correct.splice(at, 1);
          q.correct.sort(function (a, b) { return a - b; });
        }
        cardEl.find('.slms-qb-opt').each(function () { $(this).toggleClass('is-correct', q.correct.indexOf(parseInt($(this).data('o'), 10)) !== -1); });
      } else if (f === 'type' && e.type === 'change') {
        var was = choice(q.type);
        q.type = el.val();
        if (choice(q.type) && !was) { q.options = ['', '']; q.correct = []; q.answer = ''; }
        if (!choice(q.type)) { q.options = []; q.correct = []; }
        if (q.type === 'multiplechoice' && q.correct.length > 1) q.correct = q.correct.slice(0, 1);
        render();
        return;
      }
      save();
      if (e.type === 'change' && (f === 'answer' || f === 'option' || f === 'correct' || f === 'points')) {
        var w = warn(q), head = cardEl.find('.slms-qb-head'), tag = head.find('.slms-qb-warn');
        if (w && !tag.length) head.find('.slms-qb-title').after('<span class="slms-qb-warn">' + esc(w) + '</span>');
        else if (w) tag.text(w); else tag.remove();
      }
    });

    list.on('click', '[data-act]', function (e) {
      e.preventDefault();
      var btn = $(this), act = btn.data('act'), i = btn.closest('.slms-qb-card').data('i'), q = questions[i];
      if (!q) return;
      switch (act) {
        case 'up': if (i > 0) { questions.splice(i - 1, 0, questions.splice(i, 1)[0]); } break;
        case 'down': if (i < questions.length - 1) { questions.splice(i + 1, 0, questions.splice(i, 1)[0]); } break;
        case 'dup':
          if (questions.length >= maxQ) return;
          var copy = JSON.parse(JSON.stringify(q)); copy.id = nextId(); questions.splice(i + 1, 0, copy); break;
        case 'del': if (!window.confirm(L.confirmDelete)) return; questions.splice(i, 1); break;
        case 'toggle': collapsed[q.id] = !collapsed[q.id]; break;
        case 'optadd': if (q.options.length < maxOpt) q.options.push(''); break;
        case 'optdel':
          var o = parseInt(btn.closest('[data-o]').data('o'), 10);
          if (q.options.length <= 2) return;
          q.options.splice(o, 1);
          q.correct = q.correct.filter(function (x) { return x !== o; }).map(function (x) { return x > o ? x - 1 : x; });
          break;
        case 'img':
          if (!window.wp || !wp.media) return;
          var frame = wp.media({ title: CFG.i18n.choose, button: { text: CFG.i18n.use }, library: { type: 'image' }, multiple: false });
          frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            q.image = a.sizes && a.sizes.large ? a.sizes.large.url : a.url;
            render();
          });
          frame.open();
          return;
        case 'imgdel': q.image = ''; break;
      }
      render();
      if (act === 'optadd') list.find('.slms-qb-card').eq(i).find('[data-f="option"]').last().trigger('focus');
    });

    function add(type) {
      if (!type || questions.length >= maxQ) return;
      questions.push(blank(type));
      render();
      var last = list.find('.slms-qb-card').last();
      last.find('[data-f="question"]').trigger('focus');
      if (last[0] && last[0].scrollIntoView) last[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    root.find('[data-slms-qb-add]').on('click', function () { add($(this).data('slms-qb-add')); });
    root.find('[data-slms-qb-add-other]').on('change', function () { add($(this).val()); $(this).val(''); });
    root.find('[data-slms-qb-all]').on('click', function () {
      var c = $(this).data('slms-qb-all') === 'collapse';
      questions.forEach(function (q) { collapsed[q.id] = c; });
      render();
    });

    // make sure the latest edits are in the hidden field when saving
    $('#post').on('submit', save);
    render();
  });

  // ------------------------------------------------------------ classroom

  // Show or hide assignment fields for the chosen type ("kind=quiz", "kind=upload").
  var kind = $('#slms_kind');
  function toggleKind() {
    var v = kind.val();
    $('[data-slms-show-when]').each(function () {
      var rule = String($(this).data('slms-show-when')).split('=');
      if (rule[0] === 'kind') $(this).toggle(rule[1] === v);
    });
  }
  if (kind.length) { kind.on('change', toggleKind); toggleKind(); }

  // Forum rows on the course screen: add from the template, remove a row.
  $('[data-slms-forums]').each(function () {
    var table = $(this);
    var tpl = $('[data-slms-forum-template]');
    var next = table.find('tbody tr').length + 1000;
    $('[data-slms-forum-add]').on('click', function () {
      var html = tpl.html().replace(/__i__/g, String(next++));
      var row = $(html);
      table.find('tbody').append(row);
      row.find('input[type=text]').first().trigger('focus');
    });
    table.on('click', '[data-slms-forum-remove]', function () {
      $(this).closest('tr').remove();
    });
  });

  // Grading a submission.
  $('[data-slms-grade]').each(function () {
    var form = $(this);
    var id = parseInt(form.data('slms-grade'), 10);
    var status = form.find('[data-slms-grade-status]');
    var action = 'grade';
    form.on('click', 'button[data-action]', function () { action = $(this).data('action'); });
    form.on('submit', function (e) {
      e.preventDefault();
      var buttons = form.find('button').prop('disabled', true);
      status.removeClass('slms-bad').text(T.saving || '…');
      $.ajax({
        url: CFG.api + '/staff/submission/grade',
        method: 'POST',
        beforeSend: function (x) { x.setRequestHeader('X-WP-Nonce', CFG.nonce); },
        data: {
          id: id,
          points: form.find('[name=points]').val(),
          feedback: form.find('[name=feedback]').val(),
          'return': action === 'return' ? 1 : 0
        }
      }).done(function (d) {
        var s = d.submission || {};
        status.text(action === 'return' ? T.returned : fmt(T.graded, s.score_label || s.score).replace('%%', '%'));
      }).fail(function (x) {
        var msg = x.responseJSON && x.responseJSON.message ? x.responseJSON.message : T.failed;
        status.addClass('slms-bad').text(msg);
      }).always(function () { buttons.prop('disabled', false); });
    });
  });

  // Attendance: fill every learner who has no status yet.
  $(document).on('click', '[data-slms-att-fill]', function () {
    var value = String($(this).data('slms-att-fill'));
    $(this).closest('form').find('[data-slms-att-row]').each(function () {
      var radios = $(this).find('input[type=radio]');
      if (!radios.filter(':checked').length) radios.filter('[value="' + value + '"]').prop('checked', true);
    });
  });
})(jQuery);
