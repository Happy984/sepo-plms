/* Sepo LMS — viewing handed-in files in the page.
 *
 * A button with data-slms-view="{url}" data-kind="pdf|image|text|audio|video"
 * data-name="{file name}" opens the file in a panel under its list, using what
 * the browser already has: its own PDF viewer, image and text display, and
 * the audio and video players. Pressing the same button again closes it.
 */
(function () {
  'use strict';
  var L = window.SepoLmsViewer || {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function body(kind, url, name) {
    if (kind === 'image') return '<img src="' + esc(url) + '" alt="' + esc(name) + '">';
    if (kind === 'audio') return '<audio controls preload="metadata" src="' + esc(url) + '"></audio>';
    if (kind === 'video') return '<video controls preload="metadata" src="' + esc(url) + '"></video>';
    return '<iframe src="' + esc(url) + '" title="' + esc(name) + '"></iframe>';
  }

  function close(panel) {
    var btn = panel.slmsButton;
    if (btn) { btn.setAttribute('aria-expanded', 'false'); btn.textContent = L.view || 'View'; }
    panel.remove();
  }

  function open(btn) {
    var list = btn.closest('ul, ol') || btn.parentNode;
    var existing = list.nextElementSibling && list.nextElementSibling.matches('[data-slms-viewer]') ? list.nextElementSibling : null;
    if (existing) {
      var same = existing.slmsButton === btn;
      close(existing);
      if (same) return;
    }
    var url = btn.getAttribute('data-slms-view');
    var kind = btn.getAttribute('data-kind') || 'pdf';
    var name = btn.getAttribute('data-name') || '';
    var panel = document.createElement('div');
    panel.className = 'slms-viewer slms-viewer--' + kind;
    panel.setAttribute('data-slms-viewer', '');
    panel.innerHTML = '<div class="slms-viewer-bar"><strong class="slms-viewer-name">' + esc(name) + '</strong>'
      + '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(L.newTab || 'Open in a new tab') + '</a>'
      + '<button type="button" class="slms-viewer-close" aria-label="' + esc(L.close || 'Close') + '">✕</button></div>'
      + '<div class="slms-viewer-body">' + body(kind, url, name) + '</div>';
    panel.slmsButton = btn;
    list.parentNode.insertBefore(panel, list.nextSibling);
    panel.querySelector('.slms-viewer-close').addEventListener('click', function () { close(panel); btn.focus(); });
    btn.setAttribute('aria-expanded', 'true');
    btn.textContent = L.hide || 'Hide';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-slms-view]') : null;
    if (!btn) return;
    e.preventDefault();
    open(btn);
  });
})();
