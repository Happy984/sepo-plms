/* Sepo LMS — certificate designer.
 *
 * A design is { name, page: {size, orientation, background, bgImage, bgFit},
 * elements: [...] } in PDF points from the top-left corner. The page is drawn
 * at one CSS pixel per point and scaled to fit; text uses the faces whose
 * metrics match the PDF's standard fonts (Arial/Helvetica, Times New Roman,
 * Courier New), so what is on screen is what the PDF prints.
 *
 * The same drawing code renders the read-only thumbnails on the designs list.
 */
(function () {
  'use strict';
  var CFG = window.SepoLmsDesigner || {};
  var T = CFG.i18n || {};
  var MEDIA = CFG.media || {};
  var FAMILIES = {
    sans: 'Arial, Helvetica, "Liberation Sans", sans-serif',
    serif: '"Times New Roman", Times, "Liberation Serif", serif',
    mono: '"Courier New", Courier, "Liberation Mono", monospace'
  };
  var SNAP_PX = 6;

  function t(k) { return T[k] || k; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function round(n) { return Math.round(n * 100) / 100; }
  function uid() { return 'e' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }

  function pageSize(design) {
    var s = (CFG.sizes || {})[design.page.size] || [595.28, 841.89];
    return design.page.orientation === 'portrait' ? [s[0], s[1]] : [s[1], s[0]];
  }

  function resolve(el, sample, showFields) {
    var empty = false;
    var text = String(el.text || '').replace(/\{([a-z_]+)\}/g, function (m, key) {
      if (showFields) return m;
      if (!(key in sample)) return m;
      var v = String(sample[key] || '').trim();
      if (!v) empty = true;
      return v;
    });
    if (empty && el.hideEmpty) return null;
    return text;
  }

  function imageUrl(el) {
    if (el.src === 'logo') return CFG.logo || '';
    return MEDIA[el.media] || '';
  }

  // ------------------------------------------------------------ drawing
  /** Draw one element into its node. */
  function paint(node, el, opts) {
    node.style.left = el.x + 'px';
    node.style.top = el.y + 'px';
    node.style.width = el.w + 'px';
    node.className = 'slms-dz-el slms-dz-el--' + el.type + (el.locked ? ' is-locked' : '') + (opts.selected === el.id ? ' is-selected' : '');
    node.setAttribute('data-id', el.id);
    var html = '';
    if (el.type === 'text') {
      node.style.height = '';
      var text = resolve(el, opts.sample, opts.showFields);
      node.classList.toggle('is-hidden-when-empty', text === null);
      if (text === null) text = resolve(el, opts.sample, true) || '';
      html = '<div class="slms-dz-text">' + esc(text) + '</div>';
      node.innerHTML = html;
      var box = node.firstChild;
      box.style.fontFamily = FAMILIES[el.family] || FAMILIES.sans;
      box.style.fontWeight = el.bold ? '700' : '400';
      box.style.fontStyle = el.italic ? 'italic' : 'normal';
      box.style.fontSize = el.size + 'px';
      box.style.lineHeight = String(el.lineHeight || 1.2);
      box.style.letterSpacing = (el.tracking || 0) + 'px';
      box.style.color = el.color;
      box.style.textAlign = el.align;
      box.style.textTransform = el.upper ? 'uppercase' : 'none';
      box.style.whiteSpace = el.fit ? 'nowrap' : 'pre-wrap';
      if (el.fit) {
        var size = el.size;
        while (size > 6 && box.scrollWidth > box.clientWidth + 0.5) { size -= 0.5; box.style.fontSize = size + 'px'; }
      }
      el.h = round(Math.max(1, node.offsetHeight || el.size * (el.lineHeight || 1.2)));
    } else {
      node.style.height = el.h + 'px';
      if (el.type === 'image') {
        var url = imageUrl(el);
        html = url ? '<img alt="" src="' + esc(url) + '" style="object-fit:' + (el.fit === 'stretch' ? 'fill' : el.fit) + '">' : '<span class="slms-dz-noimg">' + esc(el.src === 'logo' ? t('addLogo') : t('addImage')) + '</span>';
      } else if (el.type === 'line') {
        html = '<span class="slms-dz-rule slms-dz-rule--' + el.dir + '" style="background:' + esc(el.stroke) + ';' + (el.dir === 'v' ? 'width:' : 'height:') + el.weight + 'px"></span>';
      } else if (el.type === 'rect') {
        html = '<span class="slms-dz-box" style="background:' + (el.fill || 'transparent') + ';' + (el.stroke ? 'outline:' + el.weight + 'px solid ' + el.stroke + ';outline-offset:-' + (el.weight / 2) + 'px' : '') + '"></span>';
      }
      node.innerHTML = html;
    }
    if (opts.selected === el.id && opts.handles) addHandles(node, el);
  }

  function paintPage(page, design, opts) {
    var size = pageSize(design);
    page.style.width = size[0] + 'px';
    page.style.height = size[1] + 'px';
    page.style.backgroundColor = design.page.background || '#ffffff';
    var bg = design.page.bgImage ? MEDIA[design.page.bgImage] : '';
    page.style.backgroundImage = bg ? 'url("' + bg.replace(/"/g, '%22') + '")' : 'none';
    page.style.backgroundSize = design.page.bgFit === 'stretch' ? '100% 100%' : design.page.bgFit;
    page.style.backgroundPosition = 'center';
    page.style.backgroundRepeat = 'no-repeat';
    var keep = {};
    design.elements.forEach(function (el, i) {
      var node = page.querySelector('[data-id="' + el.id + '"]');
      if (!node) { node = document.createElement('div'); page.appendChild(node); }
      node.style.zIndex = String(i + 1);
      paint(node, el, opts);
      keep[el.id] = true;
    });
    Array.prototype.slice.call(page.querySelectorAll('.slms-dz-el')).forEach(function (n) { if (!keep[n.getAttribute('data-id')]) n.remove(); });
  }

  function addHandles(node, el) {
    var list = el.type === 'text' ? ['w', 'e'] : el.type === 'line' ? (el.dir === 'v' ? ['n', 's'] : ['w', 'e']) : ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
    if (el.locked) return;
    list.forEach(function (h) {
      var s = document.createElement('span');
      s.className = 'slms-dz-handle slms-dz-handle--' + h;
      s.setAttribute('data-handle', h);
      node.appendChild(s);
    });
  }

  // --------------------------------------------------------- thumbnails
  function thumbs() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-slms-dz-thumb]'), function (holder) {
      var design;
      try { design = JSON.parse(holder.getAttribute('data-slms-dz-thumb')); } catch (e) { return; }
      var size = pageSize(design);
      var page = document.createElement('div');
      page.className = 'slms-dz-page slms-dz-page--thumb';
      holder.appendChild(page);
      paintPage(page, design, { sample: CFG.sample || {}, showFields: false });
      var fit = function () {
        var scale = holder.parentNode.clientWidth / size[0];
        page.style.transform = 'scale(' + scale + ')';
        holder.style.height = Math.round(size[1] * scale) + 'px';
      };
      fit();
      window.addEventListener('resize', fit);
    });
  }

  // ------------------------------------------------------------ editor
  function Designer(root) {
    var design;
    try { design = JSON.parse(root.getAttribute('data-slms-designer')); } catch (e) { return; }
    var selected = null, showFields = false, zoom = 'fit', scale = 1;
    var undo = [], redo = [], saved = JSON.stringify(design), dirty = false;

    root.innerHTML = ''
      + '<div class="slms-dz-top">'
      + '<label class="screen-reader-text" for="slms-dz-title">' + esc(t('name')) + '</label>'
      + '<input id="slms-dz-title" class="slms-dz-title" data-title value="' + esc(design.name) + '">'
      + '<button type="button" class="button" data-undo title="' + esc(t('undo')) + ' (Ctrl+Z)" aria-label="' + esc(t('undo')) + '">↶</button>'
      + '<button type="button" class="button" data-redo title="' + esc(t('redo')) + ' (Ctrl+Shift+Z)" aria-label="' + esc(t('redo')) + '">↷</button>'
      + '<label class="slms-dz-check"><input type="checkbox" data-fields> ' + esc(t('fieldsShow')) + '</label>'
      + '<select data-zoom aria-label="Zoom"><option value="fit">' + esc(t('zoomFit')) + '</option><option value="0.5">50%</option><option value="0.75">75%</option><option value="1">100%</option></select>'
      + '<span class="slms-dz-spacer"></span>'
      + '<button type="button" class="button" data-preview>' + esc(t('preview')) + '</button>'
      + '<button type="button" class="button button-primary" data-save>' + esc(t('save')) + '</button>'
      + '</div>'
      + '<div class="slms-dz-main">'
      + '<aside class="slms-dz-left">'
      + '<h3>' + esc(t('add')) + '</h3><div class="slms-dz-add">'
      + '<button type="button" class="button" data-add="text">' + esc(t('addText')) + '</button>'
      + '<select data-add-field aria-label="' + esc(t('addField')) + '"><option value="">' + esc(t('addField')) + '</option>'
      + Object.keys(CFG.fields || {}).map(function (k) { return '<option value="' + esc(k) + '">' + esc(CFG.fields[k]) + '</option>'; }).join('') + '</select>'
      + '<button type="button" class="button" data-add="logo">' + esc(t('addLogo')) + '</button>'
      + '<button type="button" class="button" data-add="image">' + esc(t('addImage')) + '</button>'
      + '<button type="button" class="button" data-add="line">' + esc(t('addLine')) + '</button>'
      + '<button type="button" class="button" data-add="rect">' + esc(t('addBox')) + '</button>'
      + '</div>'
      + '<h3>' + esc(t('layers')) + '</h3><p class="description">' + esc(t('layersHint')) + '</p><ol class="slms-dz-layers" data-layers></ol>'
      + '</aside>'
      + '<div class="slms-dz-stage" data-stage><div class="slms-dz-sizer" data-sizer><div class="slms-dz-page" data-page tabindex="0"></div></div></div>'
      + '<aside class="slms-dz-right" data-props></aside>'
      + '</div>';

    var page = root.querySelector('[data-page]');
    var stage = root.querySelector('[data-stage]');
    var sizer = root.querySelector('[data-sizer]');
    var props = root.querySelector('[data-props]');
    var layers = root.querySelector('[data-layers]');
    var guideV = document.createElement('div'); guideV.className = 'slms-dz-guide slms-dz-guide--v'; guideV.hidden = true;
    var guideH = document.createElement('div'); guideH.className = 'slms-dz-guide slms-dz-guide--h'; guideH.hidden = true;

    function el(id) { return design.elements.filter(function (e) { return e.id === id; })[0] || null; }
    function opts() { return { sample: CFG.sample || {}, showFields: showFields, selected: selected, handles: true }; }

    function draw() {
      paintPage(page, design, opts());
      page.appendChild(guideV); page.appendChild(guideH);
      layout();
      drawLayers();
      root.querySelector('[data-undo]').disabled = !undo.length;
      root.querySelector('[data-redo]').disabled = !redo.length;
    }

    function drawOne(e) {
      var node = page.querySelector('[data-id="' + e.id + '"]');
      if (node) paint(node, e, opts());
    }

    function layout() {
      var size = pageSize(design);
      var avail = stage.clientWidth - 48;
      scale = zoom === 'fit' ? Math.min(1.2, Math.max(0.2, avail / size[0])) : parseFloat(zoom);
      page.style.transform = 'scale(' + scale + ')';
      sizer.style.width = Math.round(size[0] * scale) + 'px';
      sizer.style.height = Math.round(size[1] * scale) + 'px';
    }

    function layerLabel(e) {
      if (e.label) return e.label;
      if (e.type === 'text') return (e.text || '').replace(/\s+/g, ' ').slice(0, 40) || t('typeText');
      if (e.type === 'image') return e.src === 'logo' ? t('addLogo') : t('typeImage');
      return e.type === 'line' ? t('typeLine') : t('typeRect');
    }

    function drawLayers() {
      var icons = { text: 'T', image: '▣', line: '—', rect: '□' };
      layers.innerHTML = design.elements.slice().reverse().map(function (e) {
        return '<li><button type="button" class="slms-dz-layer' + (e.id === selected ? ' is-on' : '') + '" data-layer="' + esc(e.id) + '">'
          + '<span class="slms-dz-layer-icon" aria-hidden="true">' + icons[e.type] + '</span><span class="slms-dz-layer-name">' + esc(layerLabel(e)) + '</span>'
          + (e.locked ? '<span class="slms-dz-lock" title="' + esc(t('locked')) + '">🔒</span>' : '') + '</button></li>';
      }).join('');
    }

    // ------------------------------------------------------- history
    function commit() {
      var now = JSON.stringify(design);
      var last = undo.length ? undo[undo.length - 1].after : saved;
      if (now === last) return;
      undo.push({ before: last, after: now, selected: selected });
      if (undo.length > 150) undo.shift();
      redo = [];
      dirty = now !== saved;
      root.querySelector('[data-undo]').disabled = false;
      root.querySelector('[data-redo]').disabled = true;
    }
    function restore(json) {
      design = JSON.parse(json);
      if (selected && !el(selected)) selected = null;
      root.querySelector('[data-title]').value = design.name;
      dirty = json !== saved;
      draw(); showProps();
    }
    function doUndo() { var s = undo.pop(); if (!s) return; redo.push(s); restore(s.before); }
    function doRedo() { var s = redo.pop(); if (!s) return; undo.push(s); restore(s.after); }

    // ------------------------------------------------------- selection
    function select(id) {
      selected = id;
      draw();
      showProps();
    }

    // ------------------------------------------------------- properties
    function field(label, control, wide) {
      return '<label class="slms-dz-f' + (wide ? ' slms-dz-f--wide' : '') + '"><span>' + esc(label) + '</span>' + control + '</label>';
    }
    function num(key, val, step, min) { return '<input type="number" data-p="' + key + '" value="' + round(val) + '" step="' + (step || 1) + '"' + (min !== undefined ? ' min="' + min + '"' : '') + '>'; }
    function color(key, val) { return '<input type="color" data-p="' + key + '" value="' + esc(val || '#000000') + '">'; }
    function check(key, val, label) { return '<label class="slms-dz-check"><input type="checkbox" data-p="' + key + '"' + (val ? ' checked' : '') + '> ' + esc(label) + '</label>'; }
    function select2(key, val, options) {
      return '<select data-p="' + key + '">' + Object.keys(options).map(function (k) { return '<option value="' + esc(k) + '"' + (String(val) === k ? ' selected' : '') + '>' + esc(options[k]) + '</option>'; }).join('') + '</select>';
    }

    function showProps() {
      var e = selected ? el(selected) : null;
      var h = '';
      if (!e) {
        var p = design.page;
        h += '<h3>' + esc(t('page')) + '</h3>'
          + field(t('size'), select2('page.size', p.size, { a4: t('a4'), letter: t('letter') }))
          + field(t('orientation'), select2('page.orientation', p.orientation, { landscape: t('landscape'), portrait: t('portrait') }))
          + field(t('background'), color('page.background', p.background))
          + '<div class="slms-dz-f slms-dz-f--wide"><span>' + esc(t('bgImage')) + '</span><div class="slms-dz-row">'
          + '<button type="button" class="button" data-pick="page">' + esc(p.bgImage ? t('replace') : t('choose')) + '</button>'
          + (p.bgImage ? '<button type="button" class="button-link slms-danger-link" data-unpick="page">' + esc(t('remove')) + '</button>' : '') + '</div></div>'
          + (p.bgImage ? field(t('fit'), select2('page.bgFit', p.bgFit, { cover: t('fitCover'), contain: t('fitContain'), stretch: t('fitStretch') })) : '')
          + '<p class="description slms-dz-hint">' + esc(t('pageHint')) + '</p>';
        props.innerHTML = h;
        return;
      }
      h += '<h3>' + esc(layerLabel(e)) + '</h3>';
      if (e.type === 'text') {
        h += field(t('text'), '<textarea data-p="text" rows="3">' + esc(e.text) + '</textarea>', true)
          + field(t('insertField'), '<select data-insert><option value="">' + esc(t('insertField')) + '</option>' + Object.keys(CFG.fields || {}).map(function (k) { return '<option value="' + esc(k) + '">' + esc(CFG.fields[k]) + '</option>'; }).join('') + '</select>', true)
          + field(t('family'), select2('family', e.family, { sans: t('sans'), serif: t('serif'), mono: t('mono') }), true)
          + '<div class="slms-dz-row slms-dz-f--wide">'
          + '<button type="button" class="button slms-dz-toggle' + (e.bold ? ' is-on' : '') + '" data-toggle="bold" aria-pressed="' + e.bold + '"><b>B</b> ' + esc(t('bold')) + '</button>'
          + '<button type="button" class="button slms-dz-toggle' + (e.italic ? ' is-on' : '') + '" data-toggle="italic" aria-pressed="' + e.italic + '"><i>I</i> ' + esc(t('italic')) + '</button></div>'
          + field(t('fontSize'), num('size', e.size, 0.5, 4)) + field(t('color'), color('color', e.color))
          + '<div class="slms-dz-f slms-dz-f--wide"><span>' + esc(t('align')) + '</span><div class="slms-dz-seg">'
          + ['left', 'center', 'right'].map(function (a) { return '<button type="button" class="button' + (e.align === a ? ' is-on' : '') + '" data-align="' + a + '" aria-pressed="' + (e.align === a) + '">' + esc(t(a)) + '</button>'; }).join('') + '</div></div>'
          + field(t('tracking'), num('tracking', e.tracking, 0.5)) + field(t('lineHeight'), num('lineHeight', e.lineHeight, 0.05, 0.8))
          + check('upper', e.upper, t('upper')) + check('fit', e.fit, t('fitText')) + check('hideEmpty', e.hideEmpty, t('hideEmpty'));
      } else if (e.type === 'image') {
        h += field(t('source'), select2('src', e.src, { logo: t('srcLogo'), media: t('srcMedia') }), true);
        if (e.src === 'media') h += '<div class="slms-dz-row slms-dz-f--wide"><button type="button" class="button" data-pick="el">' + esc(e.media ? t('replace') : t('choose')) + '</button></div>';
        else if (!CFG.logo) h += '<p class="description">' + esc(t('noLogo')) + '</p>';
        h += field(t('fit'), select2('fit', e.fit, { contain: t('fitContain'), cover: t('fitCover'), stretch: t('fitStretch') }), true);
      } else if (e.type === 'line') {
        h += field(t('direction'), select2('dir', e.dir, { h: t('horizontal'), v: t('vertical') })) + field(t('color'), color('stroke', e.stroke)) + field(t('weight'), num('weight', e.weight, 0.1, 0.1));
      } else if (e.type === 'rect') {
        h += field(t('fill'), color('fill', e.fill || '#ffffff')) + check('noFill', !e.fill, t('noFill'))
          + field(t('border'), color('stroke', e.stroke || '#1f2937')) + check('noBorder', !e.stroke, t('noBorder'))
          + field(t('weight'), num('weight', e.weight, 0.1, 0.1));
      }
      h += '<h4>' + esc(t('position')) + '</h4><div class="slms-dz-grid">'
        + field('X', num('x', e.x, 1)) + field('Y', num('y', e.y, 1))
        + field('W', num('w', e.w, 1, 1)) + (e.type === 'text' ? '' : field('H', num('h', e.h, 1, 1))) + '</div>'
        + '<button type="button" class="button button-small" data-center>' + esc(t('centerH')) + '</button>'
        + field(t('label'), '<input type="text" data-p="label" value="' + esc(e.label || '') + '" placeholder="' + esc(layerLabel(e)) + '">', true)
        + check('locked', e.locked, t('locked'))
        + '<h4>' + esc(t('arrange')) + '</h4><div class="slms-dz-row slms-dz-wrap">'
        + '<button type="button" class="button button-small" data-order="front">' + esc(t('toFront')) + '</button>'
        + '<button type="button" class="button button-small" data-order="up">' + esc(t('forward')) + '</button>'
        + '<button type="button" class="button button-small" data-order="down">' + esc(t('backward')) + '</button>'
        + '<button type="button" class="button button-small" data-order="back">' + esc(t('toBack')) + '</button></div>'
        + '<div class="slms-dz-row"><button type="button" class="button" data-dup>' + esc(t('duplicate')) + '</button>'
        + '<button type="button" class="button slms-danger-link" data-del>' + esc(t('delete')) + '</button></div>';
      props.innerHTML = h;
    }

    function setProp(key, input, final) {
      var e = selected ? el(selected) : null;
      var v = input.type === 'checkbox' ? input.checked : input.type === 'number' ? parseFloat(input.value) : input.value;
      if (input.type === 'number' && isNaN(v)) return;
      if (key.indexOf('page.') === 0) {
        var k = key.slice(5);
        if ((k === 'size' || k === 'orientation') && final) {
          var before = pageSize(design);
          design.page[k] = v;
          var after = pageSize(design);
          // Keep the layout: move and stretch elements with the page.
          var fx = after[0] / before[0], fy = after[1] / before[1];
          design.elements.forEach(function (e2) {
            e2.x = round(e2.x * fx); e2.w = round(e2.w * fx); e2.y = round(e2.y * fy);
            if (e2.type !== 'text' && !(e2.type === 'line' && e2.dir === 'h')) e2.h = round(e2.h * fy);
          });
          draw();
        } else {
          design.page[k] = v;
          draw();
        }
        if (final) { commit(); showProps(); }
        return;
      }
      if (!e) return;
      if (key === 'noFill') e.fill = v ? '' : (props.querySelector('[data-p="fill"]').value || '#ffffff');
      else if (key === 'noBorder') e.stroke = v ? '' : (props.querySelector('[data-p="stroke"]').value || '#1f2937');
      else if (key === 'fill') { if (!props.querySelector('[data-p="noFill"]').checked) e.fill = v; }
      else if (key === 'stroke' && e.type === 'rect') { if (!props.querySelector('[data-p="noBorder"]').checked) e.stroke = v; }
      else if (key === 'dir') {
        e.dir = v;
        var len = Math.max(e.w, e.h);
        if (v === 'v') { e.h = len; e.w = 8; } else { e.w = len; e.h = 8; }
      } else e[key] = v;
      drawOne(e);
      if (key === 'label' || key === 'text' || key === 'locked') drawLayers();
      if (final) {
        commit();
        if (['src', 'dir', 'locked', 'noFill', 'noBorder'].indexOf(key) !== -1) { draw(); showProps(); }
        else syncPosition(e);
      }
    }

    function syncPosition(e) {
      ['x', 'y', 'w', 'h'].forEach(function (k) {
        var inp = props.querySelector('[data-p="' + k + '"]');
        if (inp && document.activeElement !== inp) inp.value = round(e[k]);
      });
    }

    props.addEventListener('input', function (ev) {
      var key = ev.target.getAttribute('data-p');
      if (key && ev.target.type !== 'checkbox' && ev.target.tagName !== 'SELECT') setProp(key, ev.target, false);
    });
    props.addEventListener('change', function (ev) {
      var key = ev.target.getAttribute('data-p');
      if (key) { setProp(key, ev.target, true); return; }
      if (ev.target.hasAttribute('data-insert') && ev.target.value) {
        var ta = props.querySelector('[data-p="text"]');
        var token = '{' + ev.target.value + '}';
        var at = ta.selectionStart || ta.value.length;
        ta.value = ta.value.slice(0, at) + token + ta.value.slice(ta.selectionEnd || at);
        ev.target.value = '';
        setProp('text', ta, true);
        ta.focus();
      }
    });
    props.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      var e = selected ? el(selected) : null;
      if (b.hasAttribute('data-toggle') && e) { e[b.getAttribute('data-toggle')] = !e[b.getAttribute('data-toggle')]; drawOne(e); commit(); showProps(); }
      if (b.hasAttribute('data-align') && e) { e.align = b.getAttribute('data-align'); drawOne(e); commit(); showProps(); }
      if (b.hasAttribute('data-center') && e) { e.x = round((pageSize(design)[0] - e.w) / 2); drawOne(e); commit(); syncPosition(e); }
      if (b.hasAttribute('data-order') && e) reorder(e, b.getAttribute('data-order'));
      if (b.hasAttribute('data-dup')) duplicate();
      if (b.hasAttribute('data-del')) remove();
      if (b.hasAttribute('data-pick')) pick(b.getAttribute('data-pick'));
      if (b.hasAttribute('data-unpick')) { design.page.bgImage = 0; draw(); commit(); showProps(); }
    });

    function reorder(e, how) {
      var list = design.elements, i = list.indexOf(e);
      list.splice(i, 1);
      var to = how === 'front' ? list.length : how === 'back' ? 0 : how === 'up' ? Math.min(list.length, i + 1) : Math.max(0, i - 1);
      list.splice(to, 0, e);
      draw(); commit();
    }

    function duplicate() {
      var e = selected ? el(selected) : null;
      if (!e) return;
      var c = clone(e);
      c.id = uid(); c.x = round(c.x + 12); c.y = round(c.y + 12); c.locked = false;
      design.elements.splice(design.elements.indexOf(e) + 1, 0, c);
      selected = c.id;
      draw(); commit(); showProps();
    }

    function remove() {
      var e = selected ? el(selected) : null;
      if (!e) return;
      design.elements.splice(design.elements.indexOf(e), 1);
      selected = null;
      draw(); commit(); showProps();
    }

    // ------------------------------------------------------- media
    function pick(target) {
      if (!window.wp || !wp.media) return;
      var frame = wp.media({ title: t('choose'), library: { type: 'image' }, multiple: false, button: { text: t('choose') } });
      frame.on('select', function () {
        var a = frame.state().get('selection').first().toJSON();
        var url = (a.sizes && (a.sizes.large || a.sizes.full) || a).url;
        MEDIA[a.id] = url;
        if (target === 'page') design.page.bgImage = a.id;
        else {
          var e = el(selected);
          if (!e) return;
          e.src = 'media'; e.media = a.id;
          if (target === 'new' && a.width && a.height) { e.h = round(e.w * a.height / a.width); }
        }
        draw(); commit(); showProps();
      });
      frame.open();
    }

    // ------------------------------------------------------- adding
    function add(kind, field) {
      var size = pageSize(design);
      var e = { id: uid(), x: 0, y: 0, w: 200, h: 20, locked: false, label: '' };
      if (kind === 'text') {
        var big = field === 'name' ? 32 : field === 'course' || field === 'title' ? 22 : 13;
        Object.assign(e, { type: 'text', text: field ? '{' + field + '}' : t('newText'), family: field === 'name' || field === 'title' ? 'serif' : 'sans', bold: field === 'title' || field === 'course', italic: false, size: big, color: '#1f2937', align: 'center', tracking: 0, upper: false, lineHeight: 1.2, fit: !!field && ['name', 'course', 'title'].indexOf(field) !== -1, hideEmpty: !!field && ['grade', 'grade_letter', 'signatory', 'issuer'].indexOf(field) !== -1, w: 360 });
      } else if (kind === 'logo' || kind === 'image') {
        Object.assign(e, { type: 'image', src: kind === 'logo' ? 'logo' : 'media', media: 0, fit: 'contain', w: 150, h: 70 });
      } else if (kind === 'line') {
        Object.assign(e, { type: 'line', dir: 'h', stroke: '#1f2937', weight: 1, w: 200, h: 8 });
      } else {
        Object.assign(e, { type: 'rect', fill: '', stroke: '#1f2937', weight: 1, w: 220, h: 120 });
      }
      e.x = round((size[0] - e.w) / 2);
      e.y = round((size[1] - (e.type === 'text' ? e.size * 1.2 : e.h)) / 2);
      design.elements.push(e);
      selected = e.id;
      draw(); commit(); showProps();
      if (kind === 'image') pick('new');
      if (kind === 'text' && !field) { var ta = props.querySelector('[data-p="text"]'); if (ta) { ta.focus(); ta.select(); } }
    }

    root.querySelector('.slms-dz-add').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-add]');
      if (b) add(b.getAttribute('data-add'));
    });
    root.querySelector('[data-add-field]').addEventListener('change', function () {
      if (this.value) { add('text', this.value); this.value = ''; }
    });
    layers.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-layer]');
      if (b) select(b.getAttribute('data-layer'));
    });

    // ------------------------------------------------------- dragging
    var action = null;

    function toPage(ev) {
      var r = page.getBoundingClientRect();
      return { x: (ev.clientX - r.left) / scale, y: (ev.clientY - r.top) / scale };
    }

    /**
     * Snap moving edges to the page (edges and centre) and to other elements'
     * edges and centres. edgesX/edgesY are the positions that are moving;
     * returns how far to shift them, and shows a guide where they meet.
     */
    function snap(e, edgesX, edgesY, noSnap) {
      guideV.hidden = true; guideH.hidden = true;
      var out = { dx: 0, dy: 0 };
      if (noSnap) return out;
      var size = pageSize(design), tol = SNAP_PX / scale;
      var xs = [0, size[0] / 2, size[0]], ys = [0, size[1] / 2, size[1]];
      design.elements.forEach(function (o) {
        if (o.id === e.id) return;
        xs.push(o.x, o.x + o.w / 2, o.x + o.w);
        ys.push(o.y, o.y + o.h / 2, o.y + o.h);
      });
      function best(points, edges) {
        var hit = null;
        edges.forEach(function (edge) {
          points.forEach(function (p) {
            var d = p - edge;
            if (Math.abs(d) <= tol && (!hit || Math.abs(d) < Math.abs(hit.d))) hit = { d: d, at: p };
          });
        });
        return hit;
      }
      var hx = edgesX.length ? best(xs, edgesX) : null;
      var hy = edgesY.length ? best(ys, edgesY) : null;
      if (hx) { out.dx = hx.d; guideV.hidden = false; guideV.style.left = hx.at + 'px'; }
      if (hy) { out.dy = hy.d; guideH.hidden = false; guideH.style.top = hy.at + 'px'; }
      return out;
    }

    page.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0) return;
      var handle = ev.target.closest('[data-handle]');
      var node = ev.target.closest('.slms-dz-el');
      if (!node) { if (selected) select(null); page.focus(); return; }
      var e = el(node.getAttribute('data-id'));
      if (selected !== e.id) select(e.id);
      page.focus({ preventScroll: true });
      if (e.locked) return;
      ev.preventDefault();
      var start = toPage(ev);
      action = { e: e, handle: handle ? handle.getAttribute('data-handle') : '', start: start, orig: { x: e.x, y: e.y, w: e.w, h: e.h }, moved: false };
      page.setPointerCapture(ev.pointerId);
    });

    page.addEventListener('pointermove', function (ev) {
      if (!action) return;
      var p = toPage(ev), e = action.e, o = action.orig;
      var dx = p.x - action.start.x, dy = p.y - action.start.y;
      if (!action.moved && Math.abs(dx) * scale < 2 && Math.abs(dy) * scale < 2) return;
      action.moved = true;
      var h = action.handle;
      if (!h) {
        var nx = o.x + dx, ny = o.y + dy;
        var sm = snap(e, [nx, nx + o.w / 2, nx + o.w], [ny, ny + o.h / 2, ny + o.h], ev.altKey);
        e.x = round(nx + sm.dx); e.y = round(ny + sm.dy);
      } else {
        var left = o.x, right = o.x + o.w, top = o.y, bottom = o.y + o.h;
        if (h.indexOf('w') !== -1) left = Math.min(right - 4, o.x + dx);
        if (h.indexOf('e') !== -1) right = Math.max(left + 4, right + dx);
        if (h.indexOf('n') !== -1) top = Math.min(bottom - 4, o.y + dy);
        if (h.indexOf('s') !== -1) bottom = Math.max(top + 4, bottom + dy);
        // Shift keeps an image's proportions from a corner.
        if (ev.shiftKey && e.type === 'image' && h.length === 2) {
          var ratio = o.h / o.w, nh = (right - left) * ratio;
          if (h.indexOf('n') !== -1) top = bottom - nh; else bottom = top + nh;
        }
        var ex = h.indexOf('w') !== -1 ? [left] : h.indexOf('e') !== -1 ? [right] : [];
        var ey = h.indexOf('n') !== -1 ? [top] : h.indexOf('s') !== -1 ? [bottom] : [];
        var sr = snap(e, ex, ey, ev.altKey || (ev.shiftKey && e.type === 'image'));
        if (h.indexOf('w') !== -1) left += sr.dx; else if (h.indexOf('e') !== -1) right += sr.dx;
        if (h.indexOf('n') !== -1) top += sr.dy; else if (h.indexOf('s') !== -1) bottom += sr.dy;
        e.x = round(left); e.w = round(Math.max(4, right - left));
        if (e.type !== 'text') { e.y = round(top); e.h = round(Math.max(4, bottom - top)); }
      }
      drawOne(e);
      syncPosition(e);
    });

    function endAction() {
      if (!action) return;
      var moved = action.moved;
      action = null;
      guideV.hidden = true; guideH.hidden = true;
      if (moved) { commit(); draw(); }
    }
    page.addEventListener('pointerup', endAction);
    page.addEventListener('pointercancel', endAction);
    page.addEventListener('dblclick', function (ev) {
      var node = ev.target.closest('.slms-dz-el--text');
      if (!node) return;
      var ta = props.querySelector('[data-p="text"]');
      if (ta) { ta.focus(); ta.select(); }
    });

    // ------------------------------------------------------- keyboard
    document.addEventListener('keydown', function (ev) {
      var tag = (ev.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || ev.target.isContentEditable;
      var mod = ev.ctrlKey || ev.metaKey;
      if (mod && ev.key.toLowerCase() === 's') { ev.preventDefault(); save(); return; }
      if (typing) return;
      if (mod && ev.key.toLowerCase() === 'z') { ev.preventDefault(); if (ev.shiftKey) doRedo(); else doUndo(); return; }
      if (mod && ev.key.toLowerCase() === 'y') { ev.preventDefault(); doRedo(); return; }
      var e = selected ? el(selected) : null;
      if (!e) return;
      if (mod && ev.key.toLowerCase() === 'd') { ev.preventDefault(); duplicate(); return; }
      if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); remove(); return; }
      if (ev.key === 'Escape') { select(null); return; }
      var step = ev.shiftKey ? 10 : 1;
      var moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
      if (moves[ev.key] && !e.locked) {
        ev.preventDefault();
        e.x = round(e.x + moves[ev.key][0]); e.y = round(e.y + moves[ev.key][1]);
        drawOne(e); syncPosition(e);
        clearTimeout(e._nudge);
        e._nudge = setTimeout(function () { delete e._nudge; commit(); }, 400);
      }
    });

    // ------------------------------------------------------- top bar
    root.querySelector('[data-title]').addEventListener('input', function () { design.name = this.value; });
    root.querySelector('[data-title]').addEventListener('change', commit);
    root.querySelector('[data-undo]').addEventListener('click', doUndo);
    root.querySelector('[data-redo]').addEventListener('click', doRedo);
    root.querySelector('[data-fields]').addEventListener('change', function () { showFields = this.checked; draw(); });
    root.querySelector('[data-zoom]').addEventListener('change', function () { zoom = this.value; layout(); });
    root.querySelector('[data-preview]').addEventListener('click', function () {
      var f = document.querySelector('[data-slms-dz-preview]');
      f.querySelector('[name="design"]').value = JSON.stringify(strip(design));
      f.submit();
    });
    root.querySelector('[data-save]').addEventListener('click', save);

    function strip(d) {
      var c = clone(d);
      c.elements.forEach(function (e) { delete e._nudge; });
      return c;
    }

    function save() {
      var f = document.querySelector('[data-slms-dz-save]');
      var b = root.querySelector('[data-save]');
      b.disabled = true; b.textContent = t('saving');
      dirty = false;
      f.querySelector('[name="design"]').value = JSON.stringify(strip(design));
      f.submit();
    }

    window.addEventListener('beforeunload', function (ev) {
      if (!dirty) return;
      ev.preventDefault();
      ev.returnValue = t('unsaved');
    });
    window.addEventListener('resize', layout);

    // Fonts load after first paint; measure text again once they are in.
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
    draw();
    showProps();
  }

  function boot() {
    thumbs();
    Array.prototype.forEach.call(document.querySelectorAll('[data-slms-designer]'), Designer);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
