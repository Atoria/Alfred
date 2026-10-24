/*!
 * Alfred overlay — "How is this calculated?" for Laravel apps.
 * Vanilla JS, no dependencies, rendered in a shadow root so host CSS can't touch it.
 */
(function () {
  'use strict';

  var CONFIG_SELECTOR = 'script[data-alfred]';

  function readConfig() {
    var tags = document.querySelectorAll(CONFIG_SELECTOR);
    var tag = tags[tags.length - 1];
    if (!tag) return null;
    try { return JSON.parse(tag.getAttribute('data-alfred')); } catch (e) { return null; }
  }

  // Livewire's wire:navigate re-runs body scripts: only refresh the page token then.
  if (window.__alfred) { window.__alfred.refresh(); return; }

  var config = readConfig();
  if (!config || !config.token) return;
  var NAME = (config.name || 'Alfred');

  // ------------------------------------------------------------------ Livewire
  // Tag Livewire round-trips so their queries join this page's context
  // (lazy widgets, filters, pagination all load data after the first paint).
  function hookLivewire() {
    if (!window.Livewire || typeof window.Livewire.hook !== 'function' || hookLivewire.done) return;
    hookLivewire.done = true;
    window.Livewire.hook('request', function (ctx) {
      if (ctx && ctx.options) {
        ctx.options.headers = ctx.options.headers || {};
        ctx.options.headers['X-Alfred-Token'] = config.token;
      }
    });
  }
  hookLivewire();
  document.addEventListener('livewire:init', hookLivewire);

  // ------------------------------------------------------------------ helpers
  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return el;
  }

  // ------------------------------------------------------------------ buttons & icons
  // Icons: 24×24 stroke paths (drawn for Alfred, in the style of common open icon sets).
  var ICONS = {
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    pr: '<circle cx="6" cy="5.5" r="2.5"/><circle cx="6" cy="18.5" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M6 8v8"/><path d="M18 16V9.5A3.5 3.5 0 0 0 14.5 6H11"/><path d="m13 3.5-2.5 2.5L13 8.5"/>',
    ticket: '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h15A1.5 1.5 0 0 1 21 7.5V10a2 2 0 0 0 0 4v2.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 16.5V14a2 2 0 0 0 0-4z"/><path d="M15 6v12" stroke-dasharray="1.5 2.5"/>',
    download: '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
    trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7"/><path d="M9 7V4.5h6V7"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    refresh: '<path d="M20 12a8 8 0 0 1-14.3 4.9"/><path d="M4 12A8 8 0 0 1 18.3 7.1"/><path d="M18.5 3v4.5H14"/><path d="M5.5 21v-4.5H10"/>',
    edit: '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m14.5 6.5 3 3"/>',
    back: '<path d="M15 5l-7 7 7 7"/>',
    external: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
    camera: '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2l1.5-2h6l1.5 2h2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="13" r="3.5"/>',
    copy: '<rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2"/><path d="M15.5 8.5V5.5A1.5 1.5 0 0 0 14 4H5.5A1.5 1.5 0 0 0 4 5.5V14a1.5 1.5 0 0 0 1.5 1.5h3"/>',
    wand: '<path d="m3 21 12-12"/><path d="m13.5 6.5 4 4"/><path d="M18 2.5v3M16.5 4h3M21 8v2M20 9h2M9 3v2M8 4h2"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.6c-.6.3-1 .9-1 1.6v.5"/><path d="M12 17.2v.1"/>',
    flag: '<path d="M5 21V4"/><path d="M5 4.5h11.5l-2 4 2 4H5"/>',
    table: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M9.5 9.5v10"/>',
    history: '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8.5"/><path d="M3.5 3.5v5h5"/><path d="M12 7.5V12l3 2"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    alert: '<path d="M12 3 2 20h20z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
    plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
    send: '<path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
    arrow: '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
    text: '<path d="M4 6h16M4 12h11M4 18h7"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>'
  };

  function icon(name, cls) {
    var s = document.createElement('span');
    s.className = 'ic' + (cls ? ' ' + cls : '');
    s.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    return s;
  }

  // btn('Save', { kind: 'primary'|'ghost'|'danger', size: 'sm', block: true, icon: 'check', href, external, onclick, title })
  function btn(label, o) {
    o = o || {};
    var el = h(o.href ? 'a' : 'button', {
      class: 'btn' + (o.kind ? ' ' + o.kind : '') + (o.size ? ' ' + o.size : '') + (o.block ? ' block' : ''),
      href: o.href || null, target: o.external ? '_blank' : null, rel: o.external ? 'noopener' : null,
      type: o.href ? null : 'button', title: o.title || null, 'aria-label': o.aria || null, onclick: o.onclick || null
    });
    if (o.icon) el.appendChild(icon(o.icon));
    if (label) el.appendChild(h('span', { class: 'lbl', text: label }));
    if (o.external) el.appendChild(icon('external', 'trail'));
    return el;
  }

  function setLabel(b, text) { var l = b.querySelector('.lbl'); if (l) l.textContent = text; }
  function busy(b, text) {
    if (!b) return;
    b.disabled = true; b.classList.add('busy');
    var l = b.querySelector('.lbl');
    if (l) { if (b.__label === undefined) b.__label = l.textContent; if (text) l.textContent = text; }
  }
  function idle(b, text) {
    if (!b) return;
    b.disabled = false; b.classList.remove('busy');
    var l = b.querySelector('.lbl');
    if (l) l.textContent = text || (b.__label !== undefined ? b.__label : l.textContent);
    b.__label = undefined;
  }

  // The form's buttons, kept in view at the bottom of the panel.
  function actionBar(items) { return h('div', { class: 'actions' }, items); }
  function backTo(label, fn) { return h('div', { class: 'backrow' }, [btn(label, { kind: 'ghost', size: 'sm', icon: 'back', onclick: fn })]); }

  // A big, self-explaining action: icon, title, one line of help.
  function tile(o) {
    var el = h(o.href ? 'a' : 'button', {
      class: 'tile' + (o.done ? ' done' : ''), href: o.href || null, target: o.href ? '_blank' : null, rel: o.href ? 'noopener' : null,
      type: o.href ? null : 'button', onclick: o.onclick || null
    }, [
      h('span', { class: 'tile-ic ' + (o.done ? 'green' : (o.tone || 'blue')) }, [icon(o.done ? 'check' : o.icon)]),
      h('span', { class: 'tile-tx' }, [h('b', { text: o.title }), o.text ? h('small', { text: o.text }) : null]),
      icon(o.href ? 'external' : 'arrow', 'tile-go')
    ]);
    return el;
  }

  function success(text, link) {
    return h('div', { class: 'ok' }, [icon('check'), h('span', { class: 'ok-tx', text: text }), link || null]);
  }

  function clean(s, max) {
    s = (s || '').replace(/\s+/g, ' ').trim();
    return max && s.length > max ? s.slice(0, max) : s;
  }

  // A plain URL to one of Alfred's endpoints (images, downloads, scripts). In a
  // multi-tenant app it carries the page's signed tenant reference.
  function link(path) {
    var url = config.endpoint + path;
    if (!config.tenant) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'alfred_tenant=' + encodeURIComponent(config.tenant);
  }

  function request(method, path, body, retried) {
    var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (config.csrf) headers['X-CSRF-TOKEN'] = config.csrf;
    if (config.tenant) headers['X-Alfred-Tenant'] = config.tenant;
    return fetch(config.endpoint + path, {
      method: method,
      headers: headers,
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      // 419: the session's CSRF token changed since the page loaded (signed in
      // elsewhere, session renewed). Get the current one and try once more.
      if (res.status === 419 && !retried && method !== 'GET') {
        return refreshCsrf().then(function (ok) {
          return ok ? request(method, path, body, true) : { res: res, json: { message: 'Your session has expired. Reload the page and try again.' } };
        });
      }
      return res.json().catch(function () { return {}; }).then(function (json) {
        if (res.status === 419) json = { message: 'Your session has expired. Reload the page and try again.' };
        return { res: res, json: json };
      });
    });
  }

  function refreshCsrf() {
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (config.tenant) headers['X-Alfred-Tenant'] = config.tenant;
    return fetch(config.endpoint + '/csrf', { headers: headers, credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (json) {
        if (!json || !json.csrf) return false;
        config.csrf = json.csrf;
        return true;
      })
      .catch(function () { return false; });
  }

  function failure(res, json) {
    var msg = json.message;
    if (!msg && (res.status === 502 || res.status === 503 || res.status === 504)) msg = 'The server took too long to answer. Please try again.';
    var err = new Error(msg || ('Request failed (' + res.status + ')'));
    err.status = res.status;
    return err;
  }

  // Long AI work answers "202 + task id" right away and keeps going on the
  // server; wait for it here, so every caller just gets the final result.
  function waitFor(task, started) {
    var elapsed = (Date.now() - started) / 1000;
    var delay = elapsed < 20 ? 1500 : elapsed < 90 ? 3000 : 5000;
    return new Promise(function (resolve) { setTimeout(resolve, delay); }).then(function () {
      return request('GET', '/tasks/' + task).then(function (r) {
        if (r.res.status === 202) return waitFor(task, started);
        if (!r.res.ok) throw failure(r.res, r.json);
        return r.json;
      }, function () {
        // network blip: keep waiting, the work carries on on the server
        if ((Date.now() - started) / 1000 > 900) throw new Error('Lost the connection to ' + NAME + '. Please try again.');
        return waitFor(task, started);
      });
    });
  }

  function api(method, path, body) {
    return request(method, path, body).then(function (r) {
      if (r.res.status === 202 && r.json && r.json.task) return waitFor(r.json.task, Date.now());
      if (!r.res.ok) throw failure(r.res, r.json);
      return r.json;
    });
  }

  // ------------------------------------------------------------------ describing the element
  var HEADING = 'h1,h2,h3,h4,h5,h6,legend,caption,dt,label,[role=heading],[class*="heading"],[class*="title"],[class*="label"]';

  function livewireName(el) {
    var raw = el.getAttribute('wire:snapshot');
    if (!raw) return null;
    try { var s = JSON.parse(raw); return s && s.memo && s.memo.name; } catch (e) { return null; }
  }

  function columnHeader(cell) {
    var row = cell.closest('tr');
    var table = cell.closest('table');
    if (!row || !table) return null;
    var index = 0;
    for (var c = row.firstElementChild; c && c !== cell; c = c.nextElementSibling) index += c.colSpan || 1;
    var head = table.querySelector('thead tr:last-child') || table.querySelector('tr');
    if (!head || head === row) return null;
    var i = 0;
    for (var th = head.firstElementChild; th; th = th.nextElementSibling) {
      var span = th.colSpan || 1;
      if (index >= i && index < i + span) return clean(th.innerText || th.textContent, 120) || null;
      i += span;
    }
    return null;
  }

  function kindOf(el) {
    if (el.closest('canvas,svg') || el.querySelector && el.querySelector('canvas') && el.offsetHeight > 80) return 'chart';
    if (el.closest('td,th,[role=cell],[role=gridcell]')) return 'table-cell';
    if (el.tagName === 'TABLE') return 'table';
    var t = clean(el.innerText || el.textContent, 60);
    if (t && /^[^A-Za-z]*\d[\d\s.,%£$€:+\-\/]*[^A-Za-z]*$/.test(t)) return 'stat';
    return 'element';
  }

  function labelsFor(el, cellText) {
    var out = [];
    function add(s) {
      s = clean(s, 140);
      if (s && s.length > 1 && s !== cellText && out.indexOf(s) === -1 && out.length < 6) out.push(s);
    }
    add(el.getAttribute('aria-label'));
    add(el.getAttribute('title'));

    var node = el;
    for (var depth = 0; node && node !== document.body && depth < 8; depth++, node = node.parentElement) {
      add(node.getAttribute && node.getAttribute('aria-label'));
      var heading = node.querySelector && node.querySelector(HEADING);
      if (heading && heading !== el && !heading.contains(el)) add(heading.innerText || heading.textContent);
      var prev = node.previousElementSibling;
      if (prev && (prev.innerText || '').length < 80) add(prev.innerText);
    }
    var table = el.closest('table');
    if (table) {
      var caption = table.querySelector('caption');
      if (caption) add(caption.innerText);
    }
    return out;
  }

  function cssPath(el) {
    var parts = [];
    for (var n = el; n && n.nodeType === 1 && n !== document.body && parts.length < 5; n = n.parentElement) {
      var p = n.tagName.toLowerCase();
      if (n.id) { parts.unshift(p + '#' + n.id); break; }
      var cls = (n.getAttribute('class') || '').trim().split(/\s+/).filter(function (c) { return c && c.indexOf(':') === -1; }).slice(0, 2);
      if (cls.length) p += '.' + cls.join('.');
      parts.unshift(p);
    }
    return parts.join(' > ');
  }

  // ------------------------------------------------------------------ front-end source
  // Which Vue / React / Svelte component rendered an element. Development
  // builds keep the component's source file on the DOM node; production
  // builds strip it, and then only component names (React) and the Inertia
  // page are known. The server only accepts files that exist in readable code.
  function inertiaPage() {
    try {
      var node = document.querySelector('[data-page]');
      if (!node) return null;
      var raw = node.tagName === 'SCRIPT' ? node.textContent : node.getAttribute('data-page');
      var page = raw && JSON.parse(raw);
      return page && typeof page.component === 'string' ? page.component : null;
    } catch (e) { return null; }
  }

  function frontendSource(el) {
    var files = [], names = [], framework = null;
    function addFile(f) { if (typeof f === 'string' && f && files.indexOf(f) === -1 && files.length < 8) files.push(f.split('?')[0]); }
    function addName(n) { if (typeof n === 'string' && n && /^[A-Za-z0-9_$.-]{1,80}$/.test(n) && names.indexOf(n) === -1 && names.length < 8) names.push(n); }

    try {
      for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
        // Vue 3
        if (n.__vueParentComponent) {
          framework = 'vue';
          for (var i = n.__vueParentComponent; i; i = i.parent) {
            if (i.type) { addFile(i.type.__file); addName(i.type.__name || i.type.name); }
          }
          break;
        }
        // Vue 2
        if (n.__vue__) {
          framework = 'vue';
          for (var v = n.__vue__; v; v = v.$parent) {
            if (v.$options) { addFile(v.$options.__file); addName(v.$options.name); }
          }
          break;
        }
        // Svelte (dev)
        if (n.__svelte_meta && n.__svelte_meta.loc) {
          framework = framework || 'svelte';
          addFile(n.__svelte_meta.loc.file);
          continue;
        }
        // React
        var key = Object.keys(n).find(function (k) { return k.indexOf('__reactFiber$') === 0 || k.indexOf('__reactInternalInstance$') === 0; });
        if (key) {
          framework = 'react';
          for (var f = n[key]; f; f = f.return) {
            if (f._debugSource) addFile(f._debugSource.fileName); // React 18 and older, dev
            if (typeof f.type === 'function' || (f.type && typeof f.type === 'object')) addName(f.type.displayName || f.type.name || (f.type.render && f.type.render.name));
          }
          break;
        }
      }
    } catch (e) { /* framework internals changed; names/files are a bonus */ }

    var page = inertiaPage();
    if (!framework && !page && !files.length) return null;
    return { framework: framework, page: page, files: files, components: names };
  }

  function describe(el) {
    var cell = el.closest('td,th,[role=cell],[role=gridcell]');
    var target = cell || el;
    var text = clean(target.innerText || target.textContent, 300);
    var components = [];
    for (var n = el; n; n = n.parentElement) {
      if (n.hasAttribute && n.hasAttribute('wire:id')) {
        var name = livewireName(n);
        if (name && components.indexOf(name) === -1) components.push(name);
      }
    }
    var keyed = el.closest('[data-explain]');
    return {
      explain_key: keyed ? keyed.getAttribute('data-explain') : null,
      kind: kindOf(el),
      tag: target.tagName.toLowerCase(),
      labels: labelsFor(target, text),
      column: cell ? columnHeader(cell) : null,
      text: text,
      components: components,
      selector: cssPath(target),
      frontend: frontendSource(target)
    };
  }

  // ------------------------------------------------------------------ UI
  var host = h('div', { id: 'alfred-root' });
  host.style.cssText = 'all:initial;position:fixed;inset:auto;z-index:2147483646;';
  document.body.appendChild(host);
  var root = host.attachShadow({ mode: 'open' });

  root.appendChild(h('style', { text: [
    ':host{--bg:#fff;--fg:#1d1d1f;--muted:#6b6b73;--line:#e4e4e8;--soft:#f5f5f7;--accent:#3b5bdb;--accent-fg:#fff;--ok:#1f8a4c;--warn:#a15c00;--warn-bg:#fff4e0;--shadow:0 12px 40px rgba(0,0,0,.18);}',
    '@media (prefers-color-scheme:dark){:host{--bg:#1c1c20;--fg:#f2f2f5;--muted:#a1a1aa;--line:#33333a;--soft:#26262c;--accent:#7c93ff;--accent-fg:#0b0b10;--ok:#4ade80;--warn:#fbbf24;--warn-bg:#3a2c10;}}',
    '*{box-sizing:border-box;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-variant-ligatures:none;}',
    '.fab{position:fixed;bottom:20px;width:44px;height:44px;border-radius:50%;border:0;background:var(--accent);color:var(--accent-fg);font:600 20px/44px ui-sans-serif,system-ui;cursor:pointer;box-shadow:var(--shadow);}',
    '.fab{transition:right .15s,left .15s}.fab.right.beside{right:calc(min(420px,100vw) + 16px)}.fab.left.beside{left:calc(min(420px,100vw) + 16px)}.hint.right.beside{right:calc(min(420px,100vw) + 16px)}.hint.left.beside{left:calc(min(420px,100vw) + 16px)}',
    '.fab.img{padding:0;overflow:hidden;background:var(--bg)}.fab img{width:100%;height:100%;object-fit:cover;display:block;border-radius:50%}.fab.img.on{background:var(--bg)}',
    '.fab.right{right:20px}.fab.left{left:20px}.fab.on{outline:3px solid var(--accent);outline-offset:3px;background:var(--fg);color:var(--bg)}',
    '.hint{position:fixed;bottom:74px;padding:8px 12px;border-radius:8px;background:var(--fg);color:var(--bg);font-size:13px;box-shadow:var(--shadow);}',
    '.hint.right{right:20px}.hint.left{left:20px}',
    '.box{position:fixed;pointer-events:none;border:2px solid var(--accent);background:color-mix(in srgb,var(--accent) 8%,transparent);border-radius:4px;transition:all .06s ease-out;}',
    '.tag{position:fixed;pointer-events:none;max-width:320px;padding:3px 8px;border-radius:6px;background:var(--accent);color:var(--accent-fg);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
    '.panel{position:fixed;top:0;right:0;width:min(420px,100vw);height:100vh;background:var(--bg);color:var(--fg);border-left:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column;font-size:14px;line-height:1.5;}',
    '.panel.left{right:auto;left:0;border-left:0;border-right:1px solid var(--line)}',
    '.body{padding:16px;overflow:auto;flex:1}',
    'h2{font-size:18px;margin:0 0 6px;line-height:1.3}',
    'h3{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:18px 0 6px}',
    'p{margin:0 0 8px}ol,ul{margin:0;padding-left:20px}li{margin:3px 0}',
    '.badges{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}',
    '.badge{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--soft);color:var(--muted)}',
    '.badge.ok{color:var(--ok)}',
    '.formula{background:var(--soft);border-radius:8px;padding:10px 12px;font-weight:600}',
    '.warn{background:var(--warn-bg);color:var(--warn);border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:13px}',
    '.err{color:#c92a2a}',
    '.thread{margin-top:6px}.turns{display:flex;flex-direction:column;gap:10px;margin:6px 0 10px}.turn{border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:var(--soft)}.turn .q{font-weight:600;margin:0 0 4px}.turn .a{margin:0 0 6px}.turn .cluster{margin-top:4px}.turn details{margin-top:6px;border-top:0;padding-top:0}',
    '.askrow{display:flex;gap:8px;align-items:flex-end}.askrow textarea{flex:1;min-height:42px;resize:vertical;margin:0}',
    '.info{display:flex;gap:8px;align-items:flex-start;background:var(--soft);border-radius:8px;padding:9px 12px;margin:10px 0 4px;font-size:13px;color:var(--fg)}.info svg{flex-shrink:0;margin-top:2px;color:var(--accent)}',
    '.muted{color:var(--muted);font-size:13px}',
    'details{margin-top:14px;border-top:1px solid var(--line);padding-top:10px}summary{cursor:pointer;color:var(--muted);font-size:13px}',
    'code,pre,.diff{font-variant-ligatures:none;font-feature-settings:"liga" 0,"calt" 0}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}pre{white-space:pre-wrap;word-break:break-word;background:var(--soft);padding:8px;border-radius:6px}',
    '.spin{width:18px;height:18px;border:2px solid var(--line);border-top-color:var(--accent);border-radius:50%;animation:s 0.8s linear infinite;display:inline-block;vertical-align:middle;margin-right:8px}@keyframes s{to{transform:rotate(360deg)}}',
    '.tbl{overflow:auto;max-height:55vh;border:1px solid var(--line);border-radius:8px;margin-top:8px}',
    'table{border-collapse:collapse;font-size:12px;min-width:100%}th,td{padding:5px 8px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis}th{position:sticky;top:0;background:var(--soft)}',
    '.diff{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.45;border:1px solid var(--line);border-radius:8px;overflow:auto;max-height:50vh;margin-top:8px;white-space:pre}',
    '.diff div{padding:0 10px;min-width:max-content}.diff .add{background:rgba(46,160,67,.16)}.diff .del{background:rgba(248,81,73,.16)}.diff .hunk{color:var(--muted);background:var(--soft)}.diff .fh{font-weight:600;background:var(--soft);padding-top:4px;padding-bottom:4px}',
    '.status{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--soft);color:var(--muted)}.status.applied{color:var(--ok)}.status.failed,.status.discarded{color:#c92a2a}',
    '.example{border-left:3px solid var(--accent);background:var(--soft);border-radius:0 8px 8px 0;padding:10px 12px;margin-top:10px}',
    'dl.gloss{margin:0}dl.gloss dt{font-weight:600;margin-top:6px}dl.gloss dd{margin:0;color:var(--muted)}',
    ':host{--line-strong:#d4d4dc;--danger:#d92d20;--accent-soft:color-mix(in srgb,var(--accent) 12%,transparent);--ring:0 0 0 3px color-mix(in srgb,var(--accent) 28%,transparent)}',
    '@media (prefers-color-scheme:dark){:host{--line-strong:#46464f;--danger:#f97066}}',
    '.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:36px;padding:0 14px;border-radius:10px;border:1px solid var(--line-strong);background:var(--bg);color:var(--fg);font-size:13px;font-weight:600;line-height:1;letter-spacing:.005em;cursor:pointer;text-decoration:none;white-space:nowrap;box-shadow:0 1px 2px rgba(16,24,40,.06);transition:background .12s,border-color .12s,box-shadow .12s,transform .06s,color .12s;-webkit-tap-highlight-color:transparent}',
    '.btn:hover{background:var(--soft);border-color:color-mix(in srgb,var(--fg) 22%,var(--line))}',
    '.btn:active{transform:translateY(1px)}',
    '.btn:focus-visible,.tile:focus-visible,.tabs button:focus-visible,.iconbtn:focus-visible{outline:none;box-shadow:var(--ring)}',
    '.btn.primary{background:var(--accent);border-color:transparent;color:var(--accent-fg);box-shadow:0 1px 2px rgba(16,24,40,.14),inset 0 1px 0 rgba(255,255,255,.14)}',
    '.btn.primary:hover{background:color-mix(in srgb,var(--accent) 86%,#000)}',
    '.btn.ghost{background:transparent;border-color:transparent;box-shadow:none;color:var(--muted)}',
    '.btn.ghost:hover{background:var(--soft);color:var(--fg)}',
    '.btn.danger{background:transparent;border-color:transparent;box-shadow:none;color:var(--danger)}',
    '.btn.danger:hover{background:color-mix(in srgb,var(--danger) 10%,transparent)}',
    '.btn.sm{height:30px;padding:0 10px;font-size:12.5px;border-radius:8px;gap:6px}',
    '.btn.block{display:flex;width:100%;height:42px;font-size:14px}',
    '.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}',
    '.btn.busy{opacity:.9;cursor:progress}.btn.busy .ic:not(.trail){display:none}',
    '.btn.busy::before{content:"";width:14px;height:14px;flex:none;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:s .7s linear infinite}',
    '.ic{display:inline-flex;flex:none}.ic svg{display:block}.ic.trail{opacity:.55;margin-left:-1px}.ic.trail svg{width:13px;height:13px}',
    '.row{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;align-items:center}',
    '.row.split{display:grid;grid-template-columns:1fr 1fr}',
    '.cluster{display:flex;flex-wrap:wrap;gap:2px;margin:14px 0 0 -8px}',
    '.actions{position:sticky;bottom:-16px;z-index:2;display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:22px -16px -16px;padding:12px 16px 16px;background:var(--bg);border-top:1px solid var(--line)}',
    '.actions .btn.primary{flex:1 1 auto}',
    '.backrow{margin:-6px 0 12px -8px}',
    '.tiles{display:grid;gap:8px;margin-top:8px}',
    '.tile{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--bg);color:var(--fg);cursor:pointer;text-decoration:none;font:inherit;transition:border-color .12s,box-shadow .12s,transform .06s,background .12s}',
    '.tile:hover{border-color:color-mix(in srgb,var(--accent) 45%,var(--line));box-shadow:0 4px 14px rgba(16,24,40,.08)}',
    '.tile:active{transform:translateY(1px)}.tile:disabled{opacity:.6;cursor:progress}',
    '.tile-ic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex:none}',
    '.tile-ic.blue{background:var(--accent-soft);color:var(--accent)}.tile-ic.violet{background:rgba(124,58,237,.12);color:#7c3aed}.tile-ic.amber{background:rgba(245,158,11,.16);color:#b45309}.tile-ic.green{background:rgba(31,138,76,.13);color:var(--ok)}',
    '.tile-tx{flex:1;min-width:0}.tile-tx b{display:block;font-size:14px;font-weight:600;line-height:1.3}.tile-tx small{display:block;color:var(--muted);font-size:12.5px;margin-top:2px;line-height:1.4}',
    '.tile-go{color:var(--muted);transition:transform .12s,color .12s}.tile:hover .tile-go{color:var(--accent);transform:translateX(2px)}',
    '.tile.done{background:color-mix(in srgb,var(--ok) 5%,var(--bg))}',
    'header{display:flex;align-items:center;gap:4px;padding:12px 10px 12px 16px;border-bottom:1px solid var(--line);}',
    'header b{font-size:15px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.iconbtn{display:inline-grid;place-items:center;width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:var(--muted);cursor:pointer}.iconbtn:hover{background:var(--soft);color:var(--fg)}',
    '.tabs{display:inline-flex;gap:2px;padding:3px;margin:6px 0 14px;border-radius:10px;background:var(--soft)}',
    '.tabs button{border:0;border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;color:var(--muted);background:transparent;cursor:pointer}',
    '.tabs button.on{background:var(--bg);color:var(--fg);box-shadow:0 1px 2px rgba(16,24,40,.1)}',
    'input,textarea,select.pick{width:100%;border:1px solid var(--line-strong);background:var(--bg);color:var(--fg);border-radius:10px;padding:9px 11px;font-size:13.5px;margin-bottom:12px;transition:border-color .12s,box-shadow .12s}',
    'input:focus,textarea:focus,select.pick:focus{outline:none;border-color:var(--accent);box-shadow:var(--ring)}',
    'textarea{min-height:84px;resize:vertical;line-height:1.5}',
    'label.f{display:block;font-size:12.5px;font-weight:600;color:var(--fg);margin-bottom:6px}',
    'input[type=checkbox]{width:16px;height:16px;margin:2px 0 0;flex:none;accent-color:var(--accent)}',
    '.check{display:flex;gap:10px;align-items:flex-start;font-size:13px;margin:8px 0;padding:10px 12px;border:1px solid var(--line);border-radius:10px;cursor:pointer;line-height:1.45}',
    '.check:hover{background:var(--soft)}.check.feature{border-color:color-mix(in srgb,var(--accent) 35%,var(--line));background:color-mix(in srgb,var(--accent) 5%,var(--bg))}',
    '.ok{display:flex;align-items:center;gap:10px;background:color-mix(in srgb,var(--ok) 11%,var(--bg));color:var(--ok);border:1px solid color-mix(in srgb,var(--ok) 25%,transparent);border-radius:10px;padding:8px 8px 8px 12px;margin-bottom:10px;font-weight:600;font-size:13px}',
    '.ok .ok-tx{flex:1}.ok .btn{color:var(--fg)}',
    '.shots{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 8px}',
    '.shot{border:1px solid var(--line);border-radius:10px;padding:6px;width:172px;font-size:12px;color:var(--muted);background:var(--bg)}',
    '.shot img{display:block;width:100%;height:104px;object-fit:contain;object-position:center;border-radius:6px;background:var(--soft);margin-bottom:6px;cursor:zoom-in}',
    '.shot .shot-foot{display:flex;align-items:center;justify-content:space-between;gap:4px}.shot label{display:flex;gap:6px;align-items:center;cursor:pointer}.shot label input{margin:0}',
    '.kbd{font-size:11.5px;color:var(--muted)}',
    '.pv{position:fixed;top:12px;left:50%;transform:translateX(-50%);display:flex;gap:6px;align-items:center;padding:6px 6px 6px 14px;border-radius:14px;background:#111827;color:#f9fafb;font-size:13px;font-weight:500;box-shadow:0 10px 30px rgba(0,0,0,.28);max-width:calc(100vw - 24px);transition:left .15s,max-width .15s}',
    '.pv.beside{left:calc((100vw - min(420px,100vw)) / 2);max-width:calc(100vw - min(420px,100vw) - 24px)}',
    '.pv .dot{width:8px;height:8px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.25);flex:none;animation:pulse 2s ease-in-out infinite}@keyframes pulse{50%{box-shadow:0 0 0 7px rgba(245,158,11,0)}}',
    '.pv .pv-tx{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:0 6px 0 4px;min-width:0}.pv .pv-tx b{font-weight:700;color:#fbbf24}',
    '.pv .btn.warn{background:#f59e0b;border-color:#f59e0b;color:#111827}.pv .btn.warn:hover{background:#fbbf24}',
    '.pv .btn{height:30px;border-color:rgba(255,255,255,.14);background:rgba(255,255,255,.08);color:#f9fafb;box-shadow:none;font-size:12.5px;padding:0 10px;border-radius:9px}',
    '.pv .btn:hover{background:rgba(255,255,255,.16)}.pv .btn.primary{background:#f59e0b;border-color:transparent;color:#1a1200}.pv .btn.primary:hover{background:#fbbf24}',
    '.pv .btn.ghost{background:transparent;border-color:transparent;color:#d1d5db}.pv .btn.ghost:hover{background:rgba(255,255,255,.1);color:#fff}',
    '.toast{position:fixed;bottom:76px;padding:8px 8px 8px 14px;border-radius:12px;background:#111827;color:#f9fafb;font-size:13px;box-shadow:0 10px 30px rgba(0,0,0,.28);display:flex;gap:6px;align-items:center;max-width:min(440px,90vw)}.toast.right{right:20px}.toast.left{left:20px}.toast span.msg{margin-right:6px}',
    '.toast .btn{height:28px;padding:0 10px;font-size:12px;border-color:rgba(255,255,255,.14);background:rgba(255,255,255,.08);color:#fff;box-shadow:none}.toast .btn:hover{background:rgba(255,255,255,.16)}',
    '.section{margin-top:22px}',
    'details.fold{margin:6px 0 0;border:1px solid var(--line);border-radius:10px;padding:10px 12px}details.fold summary{font-weight:600;color:var(--fg)}details.fold[open] summary{margin-bottom:10px}',
    '.item{border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:8px;cursor:pointer;transition:border-color .12s,box-shadow .12s}.item:hover{border-color:color-mix(in srgb,var(--accent) 45%,var(--line));box-shadow:0 4px 14px rgba(16,24,40,.08)}'
  ].join('\n') }));

  // Custom accent colour (ALFRED_COLOR); the server only lets plain CSS colour values through.
  if (config.color) {
    root.appendChild(h('style', { text: ':host{--accent:' + config.color + ';--accent-fg:' + readableOn(config.color) + '}' }));
  }

  var side = config.position === 'bottom-left' ? 'left' : 'right';
  var fab = h('button', { class: 'fab ' + side, title: NAME + ': how is this calculated? (Alt+Shift+A)', 'aria-label': NAME + ': explain something on this page', onclick: toggleInspect });
  setFabIcon(config.icon);

  // ALFRED_ICON: an image, or a short text/emoji. A broken image falls back to "?".
  function setFabIcon(icon) {
    fab.textContent = '';
    fab.classList.remove('img');
    if (icon && icon.type === 'image' && icon.src) {
      var img = h('img', { src: icon.src, alt: '' });
      img.addEventListener('error', function () { setFabIcon(null); });
      fab.classList.add('img');
      fab.appendChild(img);
    } else {
      fab.textContent = (icon && icon.text) || '?';
    }
  }

  // Black or white text, whichever reads better on a #hex accent; white otherwise.
  function readableOn(color) {
    var m = /^#([0-9a-f]{3}|[0-9a-f]{6})/i.exec(color);
    if (!m) return '#fff';
    var hex = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
    var rgb = [0, 2, 4].map(function (i) {
      var c = parseInt(hex.substr(i, 2), 16) / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    var lum = 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
    return lum > 0.45 ? '#0b0b10' : '#fff';
  }
  var hint = h('div', { class: 'hint ' + side, text: 'Click any number, chart or column to see how it is calculated · Esc to cancel' });
  var box = h('div', { class: 'box' });
  var tag = h('div', { class: 'tag' });
  hint.style.display = box.style.display = tag.style.display = 'none';
  root.appendChild(box);
  root.appendChild(tag);
  root.appendChild(hint);
  root.appendChild(fab);

  var panel = null;
  var inspecting = false;
  var hovered = null;
  var last = null; // { element, descriptor, explanation, thread }

  function isOurs(e) {
    return e.composedPath && e.composedPath().indexOf(host) !== -1;
  }

  function toggleInspect() { inspecting ? stopInspect() : startInspect(); }

  function startInspect() {
    inspecting = true;
    fab.classList.add('on');
    hint.style.display = 'block';
    document.addEventListener('mousemove', onMove, true);
    document.addEventListener('click', onPick, true);
    document.documentElement.style.cursor = 'crosshair';
  }

  function stopInspect() {
    inspecting = false;
    hovered = null;
    fab.classList.remove('on');
    hint.style.display = box.style.display = tag.style.display = 'none';
    document.removeEventListener('mousemove', onMove, true);
    document.removeEventListener('click', onPick, true);
    document.documentElement.style.cursor = '';
  }

  function onMove(e) {
    if (isOurs(e)) { box.style.display = tag.style.display = 'none'; return; }
    var el = e.target;
    if (!el || el === hovered || el.nodeType !== 1) return;
    hovered = el;
    var r = el.getBoundingClientRect();
    box.style.display = tag.style.display = 'block';
    box.style.left = r.left - 2 + 'px'; box.style.top = r.top - 2 + 'px';
    box.style.width = r.width + 4 + 'px'; box.style.height = r.height + 4 + 'px';
    var d = describe(el);
    tag.textContent = (d.column || d.labels[0] || d.kind) + (d.kind === 'chart' ? ' · chart' : '');
    tag.style.left = Math.max(4, r.left) + 'px';
    tag.style.top = (r.top > 26 ? r.top - 24 : r.bottom + 4) + 'px';
  }

  function onPick(e) {
    if (isOurs(e)) return;
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    var el = e.target;
    stopInspect();
    if (config.canEdit || config.canReport) choose(el); else explain(el, false);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (inspecting) stopInspect(); else if (panel) closePanel();
    }
    if (e.altKey && e.shiftKey && (e.key === 'A' || e.key === 'a' || e.code === 'KeyA')) {
      e.preventDefault();
      toggleInspect();
    }
  });

  // ------------------------------------------------------------------ panel
  function openPanel(title) {
    Array.prototype.forEach.call(root.querySelectorAll('.toast'), function (t) { t.remove(); });
    fab.classList.add('beside'); hint.classList.add('beside');
    var pvBar = root.querySelector('.pv'); if (pvBar) pvBar.classList.add('beside');
    if (!panel) {
      panel = h('aside', { class: 'panel' + (side === 'left' ? ' left' : ''), role: 'dialog', 'aria-label': NAME });
      root.appendChild(panel);
    }
    panel.textContent = '';
    var body = h('div', { class: 'body' });
    panel.appendChild(h('header', {}, [
      h('b', { text: title || 'How is this calculated?' }),
      config.canEdit ? btn('Changes', { kind: 'ghost', size: 'sm', icon: 'history', title: 'Recent changes', onclick: history }) : null,
      h('button', { class: 'iconbtn', type: 'button', title: 'Close (Esc)', 'aria-label': 'Close', onclick: closePanel }, [icon('x')])
    ]));
    panel.appendChild(body);
    return body;
  }

  function closePanel() {
    fab.classList.remove('beside'); hint.classList.remove('beside');
    var pvBar = root.querySelector('.pv'); if (pvBar) pvBar.classList.remove('beside');
    if (panel) { panel.remove(); panel = null; }
  }

  function loading(body, text) {
    body.textContent = '';
    var p = h('p', {}, [h('span', { class: 'spin' }), text]);
    body.appendChild(p);
    // Reassure on long AI work; it keeps running on the server either way.
    setTimeout(function () {
      if (p.isConnected) body.appendChild(h('p', { class: 'muted', text: 'Still working. Bigger questions and changes can take a minute or two. Keep this panel open.' }));
    }, 25000);
  }

  function failed(body, err) {
    body.textContent = '';
    body.appendChild(h('p', { class: 'err', text: err.message || String(err) }));
    if (last) body.appendChild(h('div', { class: 'row' }, [btn('Try again', { icon: 'refresh', onclick: function () { explain(last.element, false); } })]));
  }

  function explain(el, force) {
    var descriptor = describe(el);
    last = { element: el, descriptor: descriptor, explanation: null, thread: null };
    var body = openPanel();
    var slow = setTimeout(function () {
      loading(body, NAME + ' is reading the code behind this… new explanations take 10–40 seconds; after that they are instant.');
    }, 400);
    loading(body, 'Looking it up…');
    api('POST', '/explain', { token: config.token, element: descriptor, force: !!force })
      .then(function (exp) { clearTimeout(slow); last.explanation = exp; render(body, exp); })
      .catch(function (err) {
        clearTimeout(slow);
        if (err.status === 410) err.message = 'This page has expired for ' + NAME + '. Reload the page and try again.';
        failed(body, err);
      });
  }

  function list(items, ordered) {
    return h(ordered ? 'ol' : 'ul', {}, (items || []).map(function (s) { return h('li', { text: s }); }));
  }

  function glossary(items) {
    var dl = h('dl', { class: 'gloss' });
    (items || []).forEach(function (g) {
      var parts = String(g).split(/\s+[—–-]\s+/);
      dl.appendChild(h('dt', { text: parts[0] }));
      if (parts.length > 1) dl.appendChild(h('dd', { text: parts.slice(1).join(' — ') }));
    });
    return dl;
  }

  function render(body, exp, tab) {
    body.textContent = '';
    body.appendChild(h('h2', { text: exp.title }));

    var badges = [];
    if (exp.verified) badges.push(h('span', { class: 'badge ok', text: '✓ Verified' + (exp.edited ? ' · edited' : '') }));
    else badges.push(h('span', { class: 'badge', text: 'AI-generated · may be imprecise' }));
    if (!exp.verified && exp.confidence) badges.push(h('span', { class: 'badge', text: 'Confidence: ' + exp.confidence }));
    if (exp.private) badges.push(h('span', { class: 'badge', title: 'Your account can\u2019t add explanations for everyone, so this one is kept just for you.', text: 'Only you see this' }));
    body.appendChild(h('div', { class: 'badges' }, badges));

    if (exp.stale) body.appendChild(h('div', { class: 'warn', text: 'The code behind this has changed since it was verified — this explanation may be out of date.' }));

    var dev = exp.developer && (exp.developer.summary || (exp.developer.flow && exp.developer.flow.length)) ? exp.developer : null;
    tab = dev ? (tab || 'business') : 'business';
    if (dev) {
      body.appendChild(h('div', { class: 'tabs', role: 'tablist' }, [
        h('button', { class: tab === 'business' ? 'on' : '', role: 'tab', text: 'Explanation', onclick: function () { render(body, exp, 'business'); } }),
        h('button', { class: tab === 'developer' ? 'on' : '', role: 'tab', text: 'For developers', onclick: function () { render(body, exp, 'developer'); } })
      ]));
    }

    if (tab === 'business') {
      body.appendChild(h('p', { text: exp.summary }));
      if (exp.formula) body.appendChild(h('div', { class: 'formula', text: exp.formula }));
      if (exp.example) body.appendChild(h('div', { class: 'example' }, [h('b', { text: 'Example: ' }), exp.example]));
      if (exp.steps && exp.steps.length) { body.appendChild(h('h3', { text: 'How it is worked out' })); body.appendChild(list(exp.steps, true)); }
      if (exp.data_sources && exp.data_sources.length) { body.appendChild(h('h3', { text: 'Where the data comes from' })); body.appendChild(list(exp.data_sources)); }
      if (exp.caveats && exp.caveats.length) { body.appendChild(h('h3', { text: 'Worth knowing' })); body.appendChild(list(exp.caveats)); }
      if (exp.glossary && exp.glossary.length) { body.appendChild(h('h3', { text: 'Terms on this screen' })); body.appendChild(glossary(exp.glossary)); }
    } else {
      if (dev.summary) body.appendChild(h('p', { text: dev.summary }));
      if (dev.flow && dev.flow.length) { body.appendChild(h('h3', { text: 'Flow' })); body.appendChild(list(dev.flow, true)); }
      if (dev.queries && dev.queries.length) { body.appendChild(h('h3', { text: 'Queries' })); body.appendChild(list(dev.queries)); }
      if (dev.caching) { body.appendChild(h('h3', { text: 'Caching' })); body.appendChild(h('p', { text: dev.caching })); }
      if (dev.gotchas && dev.gotchas.length) { body.appendChild(h('h3', { text: 'Gotchas' })); body.appendChild(list(dev.gotchas)); }
      if (exp.query) { body.appendChild(h('h3', { text: 'Query behind the value' })); body.appendChild(h('pre', { text: exp.query.sql + (exp.query.source ? '\n— ' + exp.query.source : '') })); }
    }

    if (exp.has_records) body.appendChild(h('div', { class: 'row' }, [btn('Show the records behind this', { kind: 'primary', block: true, icon: 'table', onclick: function () { records(exp); } })]));
    if (exp.can_verify) {
      var tools = [];
      if (!exp.verified || exp.stale) tools.push(btn(exp.stale ? 'Verify again' : 'Mark as verified', { kind: exp.has_records ? 'ghost' : null, size: 'sm', icon: 'check', onclick: function (e) { verify(exp, e.currentTarget); } }));
      tools.push(btn('Edit', { kind: 'ghost', size: 'sm', icon: 'edit', onclick: function () { edit(exp); } }));
      tools.push(btn('Regenerate', { kind: 'ghost', size: 'sm', icon: 'refresh', title: 'Ask the AI again, from scratch', onclick: function () { if (last) explain(last.element, true); } }));
      tools.push(btn('History', { kind: 'ghost', size: 'sm', icon: 'history', title: 'Earlier versions of this explanation', onclick: function () { historyView(exp); } }));
      body.appendChild(h('div', { class: 'cluster' }, tools));
    }

    if (config.followUps) body.appendChild(followUps(exp));
    var next = nextSteps(exp);
    if (next) body.appendChild(next);

    var meta = [exp.cached ? 'Saved explanation' + (exp.generated_at ? ' · ' + new Date(exp.generated_at).toLocaleString() : '') : 'Just generated'];
    if (exp.can_verify) meta.push([exp.model, exp.tokens ? (exp.tokens.input + ' in / ' + exp.tokens.output + ' out tokens') : null, exp.verified_by ? 'verified by ' + exp.verified_by : null].filter(Boolean).join(' · '));
    body.appendChild(h('p', { class: 'muted', style: 'margin-top:16px', text: meta.filter(Boolean).join(' · ') }));
  }

  // ------------------------------------------------------------------ follow-ups
  // A short private thread under the explanation: "Does this include refunds?"
  function followUps(exp) {
    var wrap = h('div', { class: 'thread' });
    var thread = last && last.thread && last.thread.explanation === exp.id ? last.thread : null;
    wrap.appendChild(h('h3', { class: 'section', text: thread && thread.turns.length ? 'Your questions' : 'Ask a follow-up' }));
    var list = h('div', { class: 'turns' });
    wrap.appendChild(list);
    (thread ? thread.turns : []).forEach(function (t) { list.appendChild(turnView(exp, thread, t)); });

    var box = h('textarea', { rows: '2', placeholder: 'e.g. "Does this include refunds?", "Which date counts as this week?"' });
    var ask = btn('Ask', { kind: 'primary', size: 'sm', icon: 'send' });
    var status = h('p', { class: 'muted', style: 'display:none' });
    function submit() {
      var q = box.value.trim();
      if (q.length < 3) { box.focus(); return; }
      busy(ask, 'Asking…');
      box.disabled = true;
      status.style.display = '';
      status.textContent = '';
      status.appendChild(h('span', { class: 'spin' }));
      status.appendChild(document.createTextNode(NAME + ' is looking into it… usually a few seconds, longer if it needs to read more code.'));
      api('POST', '/explanations/' + exp.id + '/ask', { token: config.token, element: last.descriptor, question: q, thread: thread ? thread.id : null })
        .then(function (res) {
          last.thread = { id: res.thread, explanation: exp.id, turns: res.turns };
          var body = panel && panel.querySelector('.body');
          if (body) { render(body, exp, 'business'); var t = body.querySelector('.thread'); if (t) t.scrollIntoView({ block: 'end' }); }
        })
        .catch(function (err) {
          idle(ask); box.disabled = false;
          status.textContent = '';
          status.className = 'err';
          status.textContent = err.status === 410 && !thread ? 'This page has expired for ' + NAME + '. Reload the page and try again.' : (err.message || String(err));
          if (err.status === 410 && thread) last.thread = null;
        });
    }
    ask.addEventListener('click', submit);
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); } });
    wrap.appendChild(h('div', { class: 'askrow' }, [box, ask]));
    wrap.appendChild(status);
    return wrap;
  }

  function turnView(exp, thread, t) {
    var parts = [
      h('p', { class: 'q', text: t.question }),
      h('p', { class: 'a', text: t.answer })
    ];
    var meta = [];
    if (t.confidence && t.confidence !== 'high') meta.push(h('span', { class: 'badge', text: 'Confidence: ' + t.confidence }));
    if (exp.can_verify) {
      meta.push(t.kept
        ? h('span', { class: 'badge ok', text: '✓ Added to the explanation' })
        : btn('Add to the explanation', { kind: 'ghost', size: 'sm', icon: 'plus', title: 'Everyone will see this answer under "Worth knowing"', onclick: function (e) { keep(exp, thread, t, e.currentTarget); } }));
    }
    if (meta.length) parts.push(h('div', { class: 'cluster' }, meta));
    if (t.developer) parts.push(h('details', {}, [h('summary', { text: 'For developers' }), h('p', { class: 'muted', text: t.developer })]));
    return h('div', { class: 'turn' }, parts);
  }

  function keep(exp, thread, t, button) {
    busy(button, 'Adding…');
    api('POST', '/explanations/' + exp.id + '/keep', { thread: thread.id, turn: t.index })
      .then(function (fresh) {
        t.kept = true;
        last.explanation = fresh;
        var body = panel && panel.querySelector('.body');
        if (body) render(body, fresh, 'business');
      })
      .catch(function (err) { idle(button); alertInPanel(err); });
  }

  // ------------------------------------------------------------------ next steps
  // Carry on from the explanation without picking the element again; what
  // Alfred found (and the follow-ups) goes along to the change or ticket.
  function nextSteps(exp) {
    if (!last || !last.element || (!config.canEdit && !config.canReport)) return null;
    var el = last.element;
    var ctx = { explanation: exp.id, thread: last.thread && last.thread.explanation === exp.id ? last.thread.id : null };
    var tiles = [];
    if (config.canEdit) tiles.push(tile({ icon: 'wand', tone: 'violet', title: 'Change this', text: 'Starts from what ' + NAME + ' found here', onclick: function () { changeForm(el, null, ctx); } }));
    if (config.canReport) tiles.push(tile({ icon: 'flag', tone: 'amber', title: 'Report or request', text: 'The ticket includes this explanation' + (ctx.thread ? ' and your questions' : ''), onclick: function () { reportForm(el, null, ctx); } }));
    return h('div', {}, [h('h3', { class: 'section', text: 'Next' }), h('div', { class: 'tiles' }, tiles)]);
  }

  // Back to the explanation as it was (no new request).
  function backToExplanation() {
    if (!last || !last.explanation) return;
    render(openPanel(), last.explanation, 'business');
  }

  // Every generation, edit, verification and restore of an explanation (verifiers).
  var ACTIONS = { generated: 'Generated', regenerated: 'Regenerated', edited: 'Edited', verified: 'Verified', kept: 'Follow-up added', restored: 'Restored' };
  function historyView(exp) {
    var body = openPanel('History: ' + exp.title);
    body.appendChild(backTo('Back to explanation', function () { render(openPanel(), exp, 'business'); }));
    loading(body, 'Loading history…');
    api('GET', '/explanations/' + exp.id + '/history').then(function (res) {
      body.textContent = '';
      body.appendChild(backTo('Back to explanation', function () { render(openPanel(), exp, 'business'); }));
      if (!res.versions.length) { body.appendChild(h('p', { class: 'muted', text: 'No earlier versions yet.' })); return; }
      body.appendChild(h('div', { class: 'tiles' }, res.versions.map(function (v, i) {
        return tile({ icon: i === 0 ? 'check' : 'history', tone: i === 0 ? 'green' : 'blue',
          title: 'v' + v.version + ' · ' + (ACTIONS[v.action] || v.action) + (i === 0 ? ' (current)' : ''),
          text: [v.title, v.by, v.at ? new Date(v.at).toLocaleString() : null].filter(Boolean).join(' · '),
          onclick: function () { versionView(exp, v, i === 0); } });
      })));
    }).catch(function (err) { failed(body, err); });
  }

  function versionView(exp, v, current) {
    var body = openPanel('Version ' + v.version);
    body.appendChild(backTo('Back to history', function () { historyView(exp); }));
    var s = v.snapshot || {};
    body.appendChild(h('h2', { text: s.title || '' }));
    body.appendChild(h('div', { class: 'badges' }, [h('span', { class: 'badge', text: (ACTIONS[v.action] || v.action) + (v.by ? ' by ' + v.by : '') }), v.at ? h('span', { class: 'badge', text: new Date(v.at).toLocaleString() }) : null]));
    if (s.summary) body.appendChild(h('p', { text: s.summary }));
    if (s.formula) body.appendChild(h('div', { class: 'formula', text: s.formula }));
    if (s.steps && s.steps.length) { body.appendChild(h('h3', { text: 'How it is worked out' })); body.appendChild(list(s.steps, true)); }
    if (s.caveats && s.caveats.length) { body.appendChild(h('h3', { text: 'Worth knowing' })); body.appendChild(list(s.caveats)); }
    if (!current) {
      var go = btn('Restore this version', { kind: 'primary', icon: 'undo', onclick: function () {
        busy(go, 'Restoring…');
        api('POST', '/explanations/' + exp.id + '/history/' + v.version + '/restore', {})
          .then(function (fresh) { if (last) last.explanation = fresh; render(openPanel(), fresh, 'business'); })
          .catch(function (err) { idle(go); alertInPanel(err); });
      } });
      body.appendChild(h('div', { class: 'row' }, [go]));
      body.appendChild(h('p', { class: 'muted', text: 'Restoring adds a new version; nothing is lost.' }));
    }
  }

  function records(exp) {
    var body = openPanel('Records behind: ' + exp.title);
    loading(body, 'Loading records…');
    api('POST', '/explanations/' + exp.id + '/records', { token: config.token })
      .then(function (res) {
        body.textContent = '';
        body.appendChild(backTo('Back to explanation', function () { render(openPanel(), exp); }));
        if (!res.rows.length) { body.appendChild(h('p', { class: 'muted', style: 'margin-top:12px', text: 'No records.' })); return; }
        body.appendChild(h('p', { class: 'muted', style: 'margin-top:12px', text: res.rows.length + (res.truncated ? '+ records (showing the first ' + res.rows.length + ')' : ' record(s)') }));
        var thead = h('tr', {}, res.columns.map(function (c) { return h('th', { text: c, title: c }); }));
        var rows = res.rows.map(function (r) {
          return h('tr', {}, res.columns.map(function (c) {
            var v = r[c];
            v = v === null || v === undefined ? '—' : String(v);
            return h('td', { text: v, title: v });
          }));
        });
        body.appendChild(h('div', { class: 'tbl' }, [h('table', {}, [h('thead', {}, [thead]), h('tbody', {}, rows)])]));
        if (res.sql) body.appendChild(h('details', {}, [h('summary', { text: 'Query' }), h('pre', { text: res.sql })]));
      })
      .catch(function (err) { failed(body, err); });
  }

  function verify(exp, button) {
    busy(button, 'Verifying…');
    api('POST', '/explanations/' + exp.id + '/verify', {})
      .then(function (fresh) { render(openPanel(), fresh); })
      .catch(function (err) { idle(button); alertInPanel(err); });
  }

  function alertInPanel(err) {
    if (panel) panel.querySelector('.body').prepend(h('p', { class: 'err', text: err.message }));
  }

  function edit(exp) {
    var body = openPanel('Edit explanation');
    function field(label, el) { return h('div', {}, [h('label', { class: 'f', text: label }), el]); }
    var title = h('input', { value: exp.title });
    var summary = h('textarea', {}); summary.value = exp.summary || '';
    var formula = h('input', { value: exp.formula || '' });
    var steps = h('textarea', {}); steps.value = (exp.steps || []).join('\n');
    var sources = h('textarea', {}); sources.value = (exp.data_sources || []).join('\n');
    var caveats = h('textarea', {}); caveats.value = (exp.caveats || []).join('\n');
    var example = h('textarea', {}); example.value = exp.example || '';
    var gloss = h('textarea', {}); gloss.value = (exp.glossary || []).join('\n');
    function lines(t) { return t.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean); }
    var save = btn('Save & verify', { kind: 'primary', icon: 'check', onclick: function () {
      busy(save, 'Saving…');
      api('PUT', '/explanations/' + exp.id, {
        title: title.value, summary: summary.value, formula: formula.value || null,
        steps: lines(steps), data_sources: lines(sources), caveats: lines(caveats),
        example: example.value.trim() || null, glossary: lines(gloss)
      }).then(function (fresh) { render(openPanel(), fresh); })
        .catch(function (err) { idle(save); alertInPanel(err); });
    } });
    [field('Title', title), field('Summary', summary), field('Formula', formula), field('Example', example),
      field('Steps (one per line)', steps), field('Data sources (one per line)', sources), field('Worth knowing (one per line)', caveats),
      field('Terms (one per line, "Term — meaning")', gloss),
      actionBar([save, btn('Cancel', { kind: 'ghost', onclick: function () { render(openPanel(), exp); } })])
    ].forEach(function (n) { body.appendChild(n); });
  }

  // ------------------------------------------------------------------ screenshots
  var libPromise = null;
  function screenshotLib() {
    if (window.htmlToImage) return Promise.resolve(window.htmlToImage);
    if (!libPromise) {
      libPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = link('/vendor/html-to-image.js');
        s.onload = function () { window.htmlToImage ? resolve(window.htmlToImage) : reject(new Error('screenshot library missing')); };
        s.onerror = function () { libPromise = null; reject(new Error('could not load screenshot library')); };
        document.head.appendChild(s);
      });
    }
    return libPromise;
  }

  function pageBackground(el) {
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var bg = getComputedStyle(n).backgroundColor;
      if (bg && bg !== 'transparent' && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)/.test(bg)) return bg;
    }
    return getComputedStyle(document.documentElement).backgroundColor || '#ffffff';
  }

  // Images from other sites must allow CORS to be drawn into a screenshot; one
  // that doesn't (e.g. ui-avatars.com sends a broken header) fails the whole
  // capture. So test them first and leave the failing ones out.
  var imageOk = {};
  function unreadableImages(node) {
    var urls = [];
    Array.prototype.forEach.call(node.querySelectorAll('img'), function (img) {
      var src = img.currentSrc || img.src;
      if (!src || src.indexOf('data:') === 0 || src.indexOf('blob:') === 0) return;
      try { if (new URL(src, location.href).origin === location.origin) return; } catch (e) { return; }
      if (!(src in imageOk) && urls.indexOf(src) === -1) urls.push(src);
    });
    return Promise.all(urls.map(function (src) {
      var timeout = new Promise(function (resolve) { setTimeout(function () { resolve(false); }, 3000); });
      var probe = fetch(src, { mode: 'cors', credentials: 'omit', cache: 'force-cache' })
        .then(function (r) { return r.ok; }, function () { return false; });
      return Promise.race([probe, timeout]).then(function (ok) {
        imageOk[src] = ok;
        if (!ok) console.info('[Alfred] leaving an image out of the screenshot (its server does not allow it to be copied):', src);
      });
    })).then(function () {
      return Object.keys(imageOk).filter(function (u) { return !imageOk[u]; });
    });
  }

  // The screenshot library renders the page as an SVG image, which must be
  // valid XML. Alpine/Livewire/Vue attributes (x-on:click, @click, :class,
  // wire:key, v-on:…) are fine in HTML but invalid XML, so the whole image
  // fails to load. While a screenshot is being made, strip them from the
  // serialized copy only — the live page is never touched, and they don't
  // affect how anything looks.
  var serializerPatches = 0, originalSerialize = null;
  var BAD_ATTR = /\s(?!xmlns[:=]|xlink:|xml:)[^\s=<>"'\/]*[:@][^\s=<>"'\/]*="[^"]*"/g;
  function patchSerializer() {
    if (serializerPatches++ > 0 || typeof XMLSerializer === 'undefined') return;
    originalSerialize = XMLSerializer.prototype.serializeToString;
    XMLSerializer.prototype.serializeToString = function (n) {
      return originalSerialize.call(this, n).replace(BAD_ATTR, '');
    };
  }
  function unpatchSerializer() {
    if (--serializerPatches > 0 || !originalSerialize) return;
    XMLSerializer.prototype.serializeToString = originalSerialize;
    originalSerialize = null;
  }

  function renderImage(lib, node, options) {
    return unreadableImages(node).then(function (bad) {
      var opts = Object.assign({
        pixelRatio: Math.min(2, window.devicePixelRatio || 1),
        backgroundColor: pageBackground(node),
        // second safety net: an image that still can't be fetched becomes a transparent pixel
        imagePlaceholder: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
        filter: function (n) {
          if (n === host || n.tagName === 'SCRIPT') return false;
          if (n.tagName === 'IMG' && bad.length && bad.indexOf(n.currentSrc || n.src) !== -1) return false;
          return true;
        }
      }, options || {});
      patchSerializer();
      return lib.toCanvas(node, opts).catch(function (err) {
        // Cross-origin web fonts are the other usual failure: retry without embedding them.
        console.warn('[Alfred] screenshot failed, retrying without web fonts:', err);
        return lib.toCanvas(node, Object.assign({}, opts, { skipFonts: true }));
      }).finally(unpatchSerializer);
    });
  }

  // The selected element in context: its nearest card-sized container, with the element outlined.
  function captureElement(el) {
    if (!config.screenshots) return Promise.resolve(null);
    var target = el.closest('td,th,[role=cell],[role=gridcell]') || el;
    var box = target;
    for (var i = 0, n = target; n && n !== document.body && i < 8; i++, n = n.parentElement) {
      box = n;
      if (n.offsetWidth >= 360 && n.offsetHeight >= 160) break;
    }
    var prev = target.style.outline, prevOffset = target.style.outlineOffset;
    target.style.outline = '3px solid #f59e0b';
    target.style.outlineOffset = '2px';
    return screenshotLib()
      .then(function (lib) { return renderImage(lib, box); })
      .then(function (canvas) { return canvas.toDataURL('image/jpeg', 0.85); })
      .catch(function (err) { console.warn('[Alfred] "before" screenshot failed:', err); return null; })
      .finally(function () { target.style.outline = prev; target.style.outlineOffset = prevOffset; });
  }

  // What the user currently sees (the page under the panel, without Alfred's UI).
  function captureViewport() {
    if (!config.screenshots) return Promise.resolve(null);
    var x = window.scrollX, y = window.scrollY, w = window.innerWidth, hgt = window.innerHeight;
    return screenshotLib()
      .then(function (lib) { return renderImage(lib, document.body, { pixelRatio: 1 }); })
      .then(function (full) {
        var bodyRect = document.body.getBoundingClientRect();
        var sx = Math.max(0, x - (bodyRect.left + x)), sy = Math.max(0, y - (bodyRect.top + y));
        var c = document.createElement('canvas');
        c.width = Math.min(w, full.width); c.height = Math.min(hgt, full.height);
        c.getContext('2d').drawImage(full, sx, sy, c.width, c.height, 0, 0, c.width, c.height);
        return c.toDataURL('image/jpeg', 0.85);
      });
  }

  function uploadShot(changeId, kind, image) {
    if (!image) return Promise.resolve(null);
    return api('POST', '/changes/' + changeId + '/screenshots', { kind: kind, image: image });
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? resolve() : reject(new Error('copy failed')); } catch (e) { reject(e); }
      ta.remove();
    });
  }

  // ------------------------------------------------------------------ pull requests (GitHub, GitLab, Bitbucket, …)
  function canOpenPr(c) {
    return !!config.pullRequests && c.has_edits && !c.pull_request && ['proposed', 'applied', 'reverted'].indexOf(c.status) !== -1;
  }

  function prLabel(pr) {
    return (String(pr.key).charAt(0) === '!' ? 'MR ' : 'PR ') + pr.key + (pr.label ? ' (' + pr.label + ')' : '');
  }

  function providerPicker(providers, onChange) {
    var pick = h('select', { class: 'pick' }, providers.map(function (p, i) {
      return h('option', { value: String(i), text: p.label + (p.target ? ' — ' + p.target : '') });
    }));
    pick.addEventListener('change', function () { onChange(providers[+pick.value]); });
    return pick;
  }

  function prView(c) {
    var body = openPanel('Pull request');
    loading(body, 'Preparing…');
    api('GET', '/changes/' + c.id + '/pull-request').then(function (d) { prForm(c, d); }).catch(function (err) { failed(body, err); });
  }

  function prForm(c, d) {
    var body = openPanel('Pull request');
    body.appendChild(backTo('Back to change', function () { api('GET', '/changes/' + c.id).then(function (fresh) { renderChange(fresh, last && last.element); }); }));
    var made = d.pull_request && d.pull_request.key ? d.pull_request : null;
    if (made) {
      body.appendChild(success((made.label || '') + ' ' + (String(made.key).charAt(0) === '!' ? 'merge request' : 'pull request') + ' ' + made.key + ' opened', btn('View', { size: 'sm', href: made.url, external: true })));
      body.appendChild(h('p', { class: 'muted', text: 'Branch: ' + made.branch + '. A developer can now review and merge it.' }));
      return;
    }
    if (!d.providers.length) { body.appendChild(h('p', { class: 'muted', text: 'No git provider is set up. Set ALFRED_PULL_REQUESTS (github, gitlab or bitbucket) and its settings.' })); return; }
    if (!d.can_open) { body.appendChild(h('p', { class: 'muted', text: 'This change has no code to put in a pull request.' })); return; }

    var chosen = d.providers[0];
    var title = h('input', { value: d.title });
    body.appendChild(h('label', { class: 'f', text: 'Title' }));
    body.appendChild(title);
    if (d.providers.length > 1) {
      body.appendChild(h('label', { class: 'f', text: 'Repository' }));
      body.appendChild(providerPicker(d.providers, function (p) { chosen = p; setLabel(go, goLabel()); note.textContent = noteText(); }));
    }
    function goLabel() { return 'Open ' + chosen.noun + ' in ' + chosen.label; }
    function noteText() {
      return NAME + ' commits this change to a new branch (' + d.branch + ') from the default branch of ' + (chosen.target || 'the repository') + ' and opens a draft ' + chosen.noun + ' for review. Nothing changes on this server.';
    }
    var note = h('p', { class: 'muted', text: noteText() });
    body.appendChild(note);
    var go = btn(goLabel(), { kind: 'primary', icon: 'pr', onclick: function () {
      busy(go, 'Opening ' + chosen.noun + '…');
      api('POST', '/changes/' + c.id + '/pull-request', { provider: chosen.name, title: title.value }).then(function (res) {
        d.pull_request = res; c.pull_request = res;
        prForm(c, d);
        if (res.warnings && res.warnings.length) alertInPanel(new Error(res.warnings.join(' ')));
      }).catch(function (err) { idle(go, goLabel()); alertInPanel(err); });
    } });
    body.appendChild(actionBar([go]));
  }

  // ------------------------------------------------------------------ tickets (Jira, GitHub, Trello, …)
  function ticketView(c) {
    var body = openPanel('Ticket');
    loading(body, 'Preparing the ticket…');
    api('GET', '/changes/' + c.id + '/ticket').then(function (d) { ticketForm(c, d); }).catch(function (err) { failed(body, err); });
  }

  function shotUrl(c, kind, download) {
    return link('/changes/' + c.id + '/screenshots/' + kind + '?t=' + Date.now() + (download ? '&download=1' : ''));
  }

  function ticketForm(c, d) {
    var body = openPanel('Ticket');
    body.appendChild(backTo(c.kind === 'request' ? 'Back to request' : 'Back to change', function () { api('GET', '/changes/' + c.id).then(function (fresh) { renderChange(fresh, last && last.element); }); }));

    var made = d.ticket && d.ticket.key ? d.ticket : null;
    if (made) body.appendChild(success((made.label || 'Ticket') + ' ticket ' + made.key + ' created', btn('Open', { size: 'sm', href: made.url, external: true })));
    if (d.pull_request && d.pull_request.key) body.appendChild(success(prLabel(d.pull_request) + ' opened', btn('View', { size: 'sm', href: d.pull_request.url, external: true })));

    var title = h('input', { value: d.title });
    var desc = h('textarea', { rows: '8' }); desc.value = d.description;
    var diff = h('input', { type: 'checkbox' }); diff.checked = !!d.include_diff;
    // Once the ticket exists, its text is only there to copy: fold it away.
    var form = made ? h('details', { class: 'fold' }, [h('summary', { text: 'Ticket text' })]) : body;
    form.appendChild(h('label', { class: 'f', text: 'Title' }));
    form.appendChild(title);
    form.appendChild(h('label', { class: 'f', text: 'Description' }));
    form.appendChild(desc);
    form.appendChild(h('label', { class: 'check' }, [diff, h('span', { text: c.kind === 'request' ? 'Include page details and where it lives in the code' : 'Include the code diff, page details and where it lives in the code' })]));
    if (made) body.appendChild(form);

    // Screenshots
    var picks = {};
    var shotsBox = h('div', { class: 'shots' });
    function drawShots(kinds) {
      shotsBox.textContent = '';
      if (!kinds.length) shotsBox.appendChild(h('p', { class: 'muted', text: 'No screenshots yet.' }));
      kinds.forEach(function (k) {
        if (!(k in picks)) picks[k] = true;
        var cb = h('input', { type: 'checkbox' }); cb.checked = picks[k];
        cb.addEventListener('change', function () { picks[k] = cb.checked; });
        shotsBox.appendChild(h('div', { class: 'shot' }, [
          h('img', { src: shotUrl(c, k), alt: k + ' screenshot', title: 'Open full size', onclick: function () { window.open(shotUrl(c, k), '_blank'); } }),
          h('div', { class: 'shot-foot' }, [
            h('label', {}, [cb, k === 'before' ? 'Before' : 'Current view']),
            btn('', { kind: 'ghost', size: 'sm', icon: 'download', href: shotUrl(c, k, true), title: 'Download', aria: 'Download' })
          ])
        ]));
      });
    }
    var kinds = d.screenshots.slice();
    if (d.screenshots_enabled && !made) {
      body.appendChild(h('h3', { text: 'Screenshots' }));
      body.appendChild(shotsBox);
      drawShots(kinds);
      var snapLabel = function () { return kinds.indexOf('after') === -1 ? 'Add screenshot of current view' : 'Retake current view'; };
      var snap = btn(snapLabel(), { size: 'sm', icon: 'camera', onclick: function () {
        busy(snap, 'Capturing…');
        captureViewport()
          .then(function (img) { if (!img) throw new Error('Screenshots are turned off.'); return uploadShot(c.id, 'after', img); })
          .then(function (res) { kinds = res.screenshots; d.screenshots = kinds; picks.after = true; drawShots(kinds); idle(snap, snapLabel()); })
          .catch(function (err) {
            console.warn('[Alfred] "current view" screenshot failed:', err);
            idle(snap, snapLabel());
            var msg = err && err.message ? err.message : (err && err.type ? 'a page resource failed to load (' + err.type + ')' : String(err));
            alertInPanel(new Error('Could not take the screenshot: ' + msg + '. Details are in the browser console.'));
          });
      } });
      body.appendChild(snap);
      if (c.status === 'applied') body.appendChild(h('p', { class: 'muted', style: 'margin-top:6px', text: 'The change is applied, so the current view shows the result.' }));
    }

    function fullText() {
      return '# ' + title.value + '\n\n' + desc.value + '\n\n' + (diff.checked ? d.details_markdown : '');
    }
    var copy = btn('Copy text', { icon: 'copy', title: 'Copy the ticket as Markdown', onclick: function () {
      copyText(fullText()).then(function () { setLabel(copy, 'Copied'); setTimeout(function () { setLabel(copy, 'Copy text'); }, 1500); })
        .catch(function () { alertInPanel(new Error('Could not copy — select the text manually.')); });
    } });

    var trackers = d.trackers || [];
    if (trackers.length && !made) {
      var chosen = trackers[0];
      var createLabel = function () { return 'Create in ' + chosen.label + (chosen.target ? ' · ' + chosen.target : ''); };
      if (trackers.length > 1) {
        var pick = h('select', { class: 'pick', 'aria-label': 'Send to' }, trackers.map(function (t, i) {
          return h('option', { value: String(i), text: t.label + (t.target ? ' — ' + t.target : '') });
        }));
        pick.addEventListener('change', function () { chosen = trackers[+pick.value]; setLabel(create, createLabel()); });
        body.appendChild(h('label', { class: 'f', style: 'margin-top:18px', text: 'Send to' }));
        body.appendChild(pick);
      }
      // "Also open a pull request": the ticket is created first, so the PR carries its key and link.
      var withPr = null, prChosen = null;
      var prs = d.pull_requests || [];
      if (prs.length) {
        prChosen = prs[0];
        withPr = h('input', { type: 'checkbox' }); withPr.checked = true;
        var prLine = function (p) { return 'Also open a ' + p.noun + ' in ' + p.label + (p.target ? ' (' + p.target + ')' : ''); };
        var prText = h('b', { text: prLine(prChosen) });
        body.appendChild(h('label', { class: 'check feature' }, [withPr, h('span', {}, [prText, h('br'), h('span', { class: 'muted', text: 'So a developer can review the code. Linked to the ticket.' })])]));
        if (prs.length > 1) body.appendChild(providerPicker(prs, function (p) { prChosen = p; prText.textContent = prLine(p); }));
      }
      var create = btn(createLabel(), { kind: 'primary', icon: 'send', onclick: function () {
        busy(create, withPr && withPr.checked ? 'Creating ticket and ' + prChosen.noun + '…' : 'Creating…');
        api('POST', '/changes/' + c.id + '/ticket', {
          tracker: chosen.name, title: title.value, description: desc.value, include_diff: diff.checked,
          screenshots: kinds.filter(function (k) { return picks[k]; }),
          pull_request: !!(withPr && withPr.checked), pull_request_provider: prChosen ? prChosen.name : null
        }).then(function (res) {
          d.ticket = res; d.screenshots = kinds; d.pull_request = res.pull_request;
          ticketForm(c, d);
          if (res.warnings && res.warnings.length) alertInPanel(new Error(res.warnings.join(' ')));
        }).catch(function (err) { idle(create, createLabel()); alertInPanel(err); });
      } });
      body.appendChild(actionBar([create, copy]));
    } else if (made) {
      body.appendChild(actionBar([btn('Open ' + made.key, { kind: 'primary', href: made.url, external: true }), copy]));
    } else {
      body.appendChild(h('p', { class: 'muted', style: 'margin-top:14px', text: 'No ticket tracker is connected, so copy the text and attach the downloaded screenshots. To create tickets directly, set ALFRED_TICKETS (Jira, GitHub, GitLab, Linear, Trello, ClickUp or Asana).' }));
      body.appendChild(actionBar([copy]));
      copy.classList.add('primary');
    }
  }

  // ------------------------------------------------------------------ change this
  function choose(el) {
    var d = describe(el);
    var body = openPanel(d.column || d.labels[0] || 'Selected element');
    body.appendChild(h('p', { class: 'muted', text: 'What would you like to do with this?' }));
    var options = [tile({ icon: 'help', tone: 'blue', title: 'How is this calculated?', text: 'A plain-language explanation of where it comes from', onclick: function () { explain(el, false); } })];
    if (config.canEdit) options.push(tile({ icon: 'wand', tone: 'violet', title: 'Change this', text: config.editMode === 'write' ? 'Describe a change: see the code, apply it, undo it' : 'Describe a change: preview it, then send it for review', onclick: function () { changeForm(el); } }));
    if (config.canReport) options.push(tile({ icon: 'flag', tone: 'amber', title: 'Report or request', text: 'Something wrong or missing? Send a ticket with a screenshot', onclick: function () { reportForm(el); } }));
    body.appendChild(h('div', { class: 'tiles' }, options));
  }

  // "Report / request": no AI — a note, a screenshot and the code location, straight to a ticket.
  function reportForm(el, previous, ctx) {
    var d = describe(el);
    var shot = captureElement(el); // now, while it looks the way the reporter means
    var body = openPanel('Report: ' + (d.column || d.labels[0] || d.tag));
    body.appendChild(ctx ? backTo('Back to explanation', backToExplanation) : backTo('Back', function () { choose(el); }));
    var box = h('textarea', { placeholder: 'What is wrong, or what would you like? e.g. "This total should leave out cancelled orders", "Please add a filter by region"', rows: '6' });
    if (previous) box.value = previous;
    var go = btn('Prepare ticket', { kind: 'primary', icon: 'ticket' });
    function submit() {
      var text = box.value.trim();
      if (text.length < 3) { box.focus(); return; }
      busy(go, 'Preparing…');
      api('POST', '/requests', { token: config.token, element: d, text: text, explanation: ctx ? ctx.explanation : null, thread: ctx ? ctx.thread : null })
        .then(function (c) {
          return shot.then(function (img) { return img ? uploadShot(c.id, 'before', img).catch(function () {}) : null; })
            .then(function () { ticketView(c); });
        })
        .catch(function (err) {
          idle(go);
          if (err.status === 410) err.message = 'This page has expired for ' + NAME + '. Reload the page and try again.';
          alertInPanel(err);
        });
    }
    go.addEventListener('click', submit);
    box.addEventListener('keydown', function (e) { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submit(); });
    body.appendChild(h('label', { class: 'f', text: 'Your request' }));
    body.appendChild(box);
    body.appendChild(h('p', { class: 'muted', text: 'A screenshot of this element is attached, along with where it lives in the code' + (ctx ? ', ' + NAME + '\u2019s explanation' + (ctx.thread ? ' and your questions' : '') : '') + ', so developers can find it straight away. You can edit the ticket before sending it.' }));
    body.appendChild(actionBar([go, h('span', { class: 'kbd', text: '⌘/Ctrl + Enter' })]));
    setTimeout(function () { box.focus(); }, 30);
  }

  function changeForm(el, previous, ctx) {
    var d = describe(el);
    var body = openPanel('Change: ' + (d.column || d.labels[0] || d.tag));
    body.appendChild(ctx ? backTo('Back to explanation', backToExplanation) : backTo('Back', function () { choose(el); }));
    var box = h('textarea', { placeholder: 'e.g. "move this a bit to the left", "make the badge green", "bold the total"', rows: '5' });
    if (previous) box.value = previous;
    var go = btn('Propose change', { kind: 'primary', icon: 'wand' });
    function submit() {
      var text = box.value.trim();
      if (text.length < 3) { box.focus(); return; }
      busy(go, 'Working…');
      propose(el, d, text, ctx);
    }
    go.addEventListener('click', submit);
    box.addEventListener('keydown', function (e) { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submit(); });
    body.appendChild(h('label', { class: 'f', text: 'What should change?' }));
    body.appendChild(box);
    body.appendChild(h('p', { class: 'muted', text: config.editMode === 'write'
      ? 'Nothing is changed until you click Apply. Applied changes can be undone.'
      : 'Nothing is written on this server. You can preview the change, then open a pull request or a ticket.' }));
    body.appendChild(actionBar([go, h('span', { class: 'kbd', text: '⌘/Ctrl + Enter' })]));
    setTimeout(function () { box.focus(); }, 30);
  }

  function propose(el, d, instruction, ctx) {
    var body = openPanel('Change');
    loading(body, NAME + ' is finding the code and preparing the change… this usually takes 20–60 seconds.');
    var before = captureElement(el); // taken now, while the page still looks the way it was
    api('POST', '/changes', { token: config.token, element: d, instruction: instruction, explanation: ctx ? ctx.explanation : null, thread: ctx ? ctx.thread : null })
      .then(function (c) {
        renderChange(c, el);
        before.then(function (img) { return uploadShot(c.id, 'before', img); })
          .catch(function (err) { console.warn('[Alfred] could not upload the "before" screenshot:', err); });
      })
      .catch(function (err) {
        if (err.status === 410) err.message = 'This page has expired for ' + NAME + '. Reload the page and try again.';
        body.textContent = '';
        body.appendChild(h('p', { class: 'err', text: err.message }));
        body.appendChild(h('div', { class: 'row' }, [btn('Edit request', { icon: 'edit', onclick: function () { changeForm(el, instruction, ctx); } })]));
      });
  }

  // "Not quite right": the same request again, with what's wrong. The agent
  // gets the previous diff and any error the preview hit; the old proposal is
  // replaced by the new one.
  function refineForm(c, preset) {
    var body = openPanel('Refine: ' + (c.summary || 'change #' + c.id));
    body.appendChild(backTo('Back to the change', function () { api('GET', '/changes/' + c.id).then(function (fresh) { renderChange(fresh, last && last.element); }); }));
    body.appendChild(h('p', { class: 'muted', text: '“' + c.instruction + '”' }));
    if (c.preview_error) body.appendChild(h('div', { class: 'warn', text: 'The preview error is included automatically: ' + c.preview_error }));
    var box = h('textarea', { rows: '4', placeholder: 'What is wrong, or what should be different? e.g. "the tags show up as text", "make it red only when it is 0%", "use the warning colour instead"' });
    if (preset) box.value = preset;
    var go = btn('Try again', { kind: 'primary', icon: 'wand' });
    function submit() {
      var text = box.value.trim();
      if (text.length < 3) { box.focus(); return; }
      busy(go, 'Working…');
      var panelBody = openPanel('Change');
      loading(panelBody, NAME + ' is looking at what went wrong and preparing a new attempt… usually 20–60 seconds.');
      api('POST', '/changes/' + c.id + '/refine', { token: config.token, feedback: text })
        .then(function (fresh) {
          if (config.preview && config.preview.id === c.id) {
            // Previewing the old attempt: show the new one in its place.
            panelBody.textContent = '';
            panelBody.appendChild(success('New attempt ready: ' + (fresh.summary || 'change #' + fresh.id)));
            renderChange(fresh, last && last.element);
          } else {
            renderChange(fresh, last && last.element);
          }
        })
        .catch(function (err) {
          panelBody.textContent = '';
          panelBody.appendChild(h('p', { class: 'err', text: err.status === 410 ? 'This page has expired for ' + NAME + '. Reload the page and try again.' : err.message }));
          panelBody.appendChild(h('div', { class: 'row' }, [btn('Edit', { icon: 'edit', onclick: function () { refineForm(c, text); } })]));
        });
    }
    go.addEventListener('click', submit);
    box.addEventListener('keydown', function (e) { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') submit(); });
    body.appendChild(h('label', { class: 'f', text: 'What should be different?' }));
    body.appendChild(box);
    body.appendChild(h('p', { class: 'muted', text: NAME + ' sees the previous attempt' + (c.preview_error ? ', the error it caused' : '') + ' and your note. The previous proposal is replaced by the new one.' }));
    body.appendChild(actionBar([go, h('span', { class: 'kbd', text: '⌘/Ctrl + Enter' })]));
    setTimeout(function () { box.focus(); box.setSelectionRange(box.value.length, box.value.length); }, 30);
  }

  // HTML tags showing as text on the page: the usual sign that markup was put
  // into a string the framework escapes.
  function rawHtmlOnPage() {
    var tag = /<\/?[a-z][a-z0-9-]*(\s+[a-z:@_-]+(\s*=\s*("[^"]*"|'[^']*'|[^\s>]+))?)*\s*\/?>/i;
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        var p = n.parentElement;
        if (!p || p.closest('script,style,noscript,textarea,code,pre,template,[contenteditable],#alfred-root')) return NodeFilter.FILTER_REJECT;
        return tag.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
      }
    });
    var n = walker.nextNode();
    return n ? clean(n.nodeValue, 120) : null;
  }

  function diffView(diff) {
    var lines = (diff || '').replace(/\n$/, '').split('\n');
    return h('div', { class: 'diff' }, lines.map(function (line) {
      var cls = '';
      if (/^(diff --git|--- |\+\+\+ )/.test(line)) cls = 'fh';
      else if (line.charAt(0) === '@') cls = 'hunk';
      else if (line.charAt(0) === '+') cls = 'add';
      else if (line.charAt(0) === '-') cls = 'del';
      if (/^(--- |\+\+\+ )/.test(line)) return null; // the diff --git line already names the file
      return h('div', { class: cls, text: line.replace(/^diff --git a\/(\S+) b\/\S+$/, '$1') || ' ' });
    }));
  }

  // Front-end files (.vue, .tsx, .css…) only show after the front-end build, so preview can't show them.
  function buildNote(c) {
    var only = c.frontend_files.length === c.files.length;
    var text;
    if (c.status === 'applied') text = 'Front-end change: it appears as soon as your front-end is rebuilt. With the Vite dev server running (npm run dev) that has already happened, just look at the page. Otherwise run npm run build.';
    else if (c.mode === 'write') text = 'Front-end change: ' + (only ? 'preview can\'t show it. ' : 'preview only shows the server-side part. ') + 'Apply it and the Vite dev server (npm run dev) shows it right away, or run npm run build. You can undo it afterwards.';
    else text = 'Front-end change: ' + (only ? 'preview can\'t show it. ' : 'preview only shows the server-side part. ') + 'It appears once a developer applies it and the front-end is built, so send it as a pull request or ticket.';
    return h('div', { class: 'info' }, [icon('info'), h('span', { text: text })]);
  }

  function statusLabel(c) {
    return h('span', { class: 'status ' + c.status, text: c.status });
  }

  function renderChange(c, el) {
    if (c.kind === 'request') return renderRequest(c);
    var body = openPanel('Change');
    if (el && last && last.element === el && last.explanation) body.appendChild(backTo('Back to explanation', backToExplanation));
    body.appendChild(h('h2', { text: c.summary || 'Proposed change' }));
    body.appendChild(h('div', { class: 'badges' }, [statusLabel(c), h('span', { class: 'badge', text: c.mode === 'write' ? 'write mode' : 'propose mode' })]));
    body.appendChild(h('p', { class: 'muted', text: '“' + c.instruction + '”' + (c.requested_by ? ' — ' + c.requested_by : '') }));
    if (c.status === 'failed') body.appendChild(h('p', { class: 'err', text: c.error || NAME + ' could not prepare this change.' }));
    if (c.refines) body.appendChild(h('p', { class: 'muted', text: 'A new attempt at change #' + c.refines + '.' }));
    if (c.preview_error) {
      body.appendChild(h('div', { class: 'warn' }, [
        h('b', { text: 'Previewing this broke the page. ' }), c.preview_error,
        h('div', { class: 'row', style: 'margin-top:8px' }, [btn('Fix it with ' + NAME, { kind: 'primary', size: 'sm', icon: 'wand', onclick: function () { refineForm(c, 'Fix the error the preview ran into.'); } })])
      ]));
    }
    if (c.notes) body.appendChild(h('div', { class: 'warn', text: c.notes }));

    if (!c.has_edits) {
      if (c.status !== 'failed') body.appendChild(h('p', { text: NAME + ' did not propose any code change.' }));
      if (c.can_refine) body.appendChild(h('div', { class: 'row' }, [btn('Try again with more detail…', { icon: 'edit', onclick: function () { refineForm(c); } })]));
    } else {
      body.appendChild(h('h3', { text: c.files.length === 1 ? 'File' : c.files.length + ' files' }));
      if (c.diff) body.appendChild(diffView(c.diff));
      else body.appendChild(h('p', { class: 'muted', text: 'The code itself is only shown to people who may see code.' }));
      if (c.needs_build) body.appendChild(buildNote(c));
    }

    // 1. Try it: one clear main action.
    var main = [];
    if (c.can_apply) main.push(btn(c.status === 'reverted' ? 'Apply again' : 'Apply to the code', { kind: 'primary', icon: 'check', onclick: function (e) { act(c, 'apply', e.currentTarget); } }));
    if (c.can_preview) main.push(btn('Preview on this page', { kind: c.can_apply ? null : 'primary', icon: 'eye', title: 'See the change on the real page. Only you see it.', onclick: function (e) { startPreview(c, e.currentTarget); } }));
    if (c.can_revert) main.push(btn('Undo', { icon: 'undo', onclick: function (e) { act(c, 'revert', e.currentTarget); } }));
    if (c.can_refine && c.status !== 'failed') main.push(btn('Refine…', { kind: 'ghost', icon: 'edit', title: 'Not quite right? Tell ' + NAME + ' what to change', onclick: function () { refineForm(c); } }));
    if (main.length === 1) main[0].classList.add('block');
    if (main.length) body.appendChild(h('div', { class: 'row' + (main.length === 2 ? ' split' : '') }, main));

    // 2. Hand it over: pull request and ticket.
    var hand = [];
    if (c.pull_request) hand.push(tile({ done: true, href: c.pull_request.url, title: prLabel(c.pull_request) + ' opened', text: 'View it in ' + (c.pull_request.label || 'your repository') }));
    else if (canOpenPr(c)) hand.push(tile({ icon: 'pr', tone: 'violet', title: 'Open pull request', text: 'A draft with this code, for a developer to review', onclick: function () { prView(c); } }));
    if (c.ticket) hand.push(tile({ done: true, href: c.ticket.url, title: (c.ticket.label || 'Ticket') + ' ' + c.ticket.key, text: 'Open the ticket' }));
    else if (c.status !== 'failed') hand.push(tile({ icon: 'ticket', tone: 'amber', title: 'Prepare ticket', text: 'With screenshots, the diff and where it lives in the code', onclick: function () { ticketView(c); } }));
    if (hand.length) {
      body.appendChild(h('h3', { class: 'section', text: 'Send for review' }));
      body.appendChild(h('div', { class: 'tiles' }, hand));
    }

    // 3. Everything else, quietly.
    var more = [];
    if (c.has_edits) more.push(btn('Download patch', { kind: 'ghost', size: 'sm', icon: 'download', href: link('/changes/' + c.id + '/patch') }));
    if (el && (c.status === 'proposed' || c.status === 'discarded' || !c.has_edits)) more.push(btn('Try different wording', { kind: 'ghost', size: 'sm', icon: 'text', onclick: function () { changeForm(el, c.instruction); } }));
    if (c.can_discard) more.push(btn('Discard', { kind: 'danger', size: 'sm', icon: 'trash', onclick: function (e) { act(c, 'discard', e.currentTarget); } }));
    if (more.length) body.appendChild(h('div', { class: 'cluster' }, more));

    if (c.trace && c.trace.length) {
      body.appendChild(h('details', {}, [h('summary', { text: 'What ' + NAME + ' tried (' + c.trace.length + ' steps)' })].concat(c.trace.map(function (t) {
        var args = t.arguments && t.arguments.length > 300 ? t.arguments.slice(0, 300) + '…' : t.arguments;
        var res = t.result && t.result.length > 600 ? t.result.slice(0, 600) + '…' : t.result;
        return h('div', { style: 'margin-top:8px' }, [
          h('div', { class: 'muted', text: '#' + t.step + ' ' + t.tool + (args ? ' ' + args : '') }),
          h('pre', { text: res || '' })
        ]);
      }))));
    }

    if (c.mode !== 'write' && c.has_edits) {
      body.appendChild(h('p', { class: 'muted', style: 'margin-top:12px', text: 'Apply it in your repository with: git apply alfred-change-' + c.id + '.patch' }));
    }
    body.appendChild(h('p', { class: 'muted', style: 'margin-top:16px', text: [c.model, c.tokens ? (c.tokens.input + ' in / ' + c.tokens.output + ' out tokens') : null, c.created_at ? new Date(c.created_at).toLocaleString() : null].filter(Boolean).join(' · ') }));
  }

  function renderRequest(c) {
    var body = openPanel('Request');
    body.appendChild(h('h2', { text: c.summary || 'Request' }));
    body.appendChild(h('div', { class: 'badges' }, [h('span', { class: 'status', text: 'request' })]));
    body.appendChild(h('p', { text: '“' + c.instruction + '”' + (c.requested_by ? ' — ' + c.requested_by : '') }));
    var where = locationLines(c.location);
    if (where.length) { body.appendChild(h('h3', { text: 'Where in the code' })); body.appendChild(list(where)); }
    body.appendChild(h('div', { class: 'tiles', style: 'margin-top:18px' }, [c.ticket
      ? tile({ done: true, href: c.ticket.url, title: (c.ticket.label || 'Ticket') + ' ' + c.ticket.key, text: 'Open the ticket' })
      : tile({ icon: 'ticket', tone: 'amber', title: 'Prepare ticket', text: 'With the screenshot and where it lives in the code', onclick: function () { ticketView(c); } })]));
  }

  function locationLines(loc) {
    if (!loc) return [];
    var out = [];
    if (loc.explanation && loc.explanation.title) {
      out.push(NAME + ' explained it as: ' + loc.explanation.title);
      (loc.explanation.followups || []).forEach(function (t) { out.push('Asked: ' + t.question + ' \u2014 ' + t.answer); });
    }
    var fe = loc.frontend || {};
    (fe.files || []).forEach(function (f) { out.push('Front-end component: ' + f); });
    if (fe.page) out.push('Inertia page: ' + fe.page + (fe.page_file ? ' — ' + fe.page_file : ''));
    (loc.components || []).forEach(function (x) { out.push('Component: ' + (x['class'] || x.name) + (x.file ? ' — ' + x.file : '')); });
    if (loc.route || loc.action) out.push('Route: ' + [loc.route, loc.action].filter(Boolean).join(' → ') + (loc.controller_file ? ' — ' + loc.controller_file : ''));
    (loc.views || []).forEach(function (v) { out.push('View: ' + v); });
    (loc.code || []).forEach(function (x) { out.push('Loads data at: ' + x); });
    return out;
  }

  function act(c, action, button) {
    busy(button, action === 'apply' ? 'Applying…' : action === 'revert' ? 'Undoing…' : 'Discarding…');
    api('POST', '/changes/' + c.id + '/' + action, {})
      .then(function (fresh) {
        if (action === 'apply' || action === 'revert') {
          remember({ id: fresh.id, summary: fresh.summary, status: fresh.status });
          var body = openPanel('Change');
          loading(body, action === 'apply' ? 'Applied. Reloading the page…' : 'Undone. Reloading the page…');
          setTimeout(function () { location.reload(); }, 500);
        } else {
          renderChange(fresh, last && last.element);
        }
      })
      .catch(function (err) { idle(button); alertInPanel(err); });
  }

  function history() {
    var body = openPanel('Recent changes');
    loading(body, 'Loading…');
    api('GET', '/changes')
      .then(function (res) {
        body.textContent = '';
        if (!res.changes.length) { body.appendChild(h('p', { class: 'muted', text: 'No changes yet.' })); return; }
        res.changes.forEach(function (c) {
          body.appendChild(h('div', { class: 'item', onclick: function () { renderChange(c, null); } }, [
            h('div', { style: 'display:flex;gap:8px;align-items:center;justify-content:space-between' }, [h('b', { text: c.summary || c.instruction }), statusLabel(c)]),
            h('div', { class: 'muted', text: [c.files.join(', '), c.requested_by, c.created_at ? new Date(c.created_at).toLocaleString() : null].filter(Boolean).join(' · ') })
          ]));
        });
      })
      .catch(function (err) { failed(body, err); });
  }

  // After "Apply" reloads the page, offer Undo for a while.
  var STORE_KEY = 'alfred:last-change';
  function remember(v) { try { sessionStorage.setItem(STORE_KEY, JSON.stringify(v)); } catch (e) {} }
  function recall() {
    try { var v = sessionStorage.getItem(STORE_KEY); sessionStorage.removeItem(STORE_KEY); return v ? JSON.parse(v) : null; } catch (e) { return null; }
  }
  (function toastAfterReload() {
    var v = config.canEdit && recall();
    if (!v) return;
    var toast = h('div', { class: 'toast ' + side }, [
      h('span', { class: 'msg', text: (v.status === 'applied' ? 'Applied: ' : 'Undone: ') + (v.summary || 'change') }),
      v.status === 'applied' && config.editMode === 'write' ? btn('Undo', { icon: 'undo', onclick: function () {
        toast.remove();
        api('POST', '/changes/' + v.id + '/revert', {})
          .then(function (fresh) { remember({ id: fresh.id, summary: fresh.summary, status: fresh.status }); location.reload(); })
          .catch(function (err) { var b = openPanel('Change'); b.appendChild(h('p', { class: 'err', text: err.message })); });
      } }) : null,
      btn('Details', { onclick: function () { toast.remove(); api('GET', '/changes/' + v.id).then(function (c) { renderChange(c, null); }); } }),
      btn('', { icon: 'x', aria: 'Dismiss', onclick: function () { toast.remove(); } })
    ]);
    root.appendChild(toast);
    setTimeout(function () { if (toast.isConnected) toast.remove(); }, 30000);
  })();

  // ------------------------------------------------------------------ live preview
  function setCookie(name, value, maxAge) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function startPreview(c, button) {
    busy(button, 'Starting preview…');
    api('POST', '/changes/' + c.id + '/preview', {})
      .then(function () {
        // The server set the preview cookie (HttpOnly): just reload.
        var body = openPanel('Preview');
        loading(body, 'Loading the page with this change…');
        setTimeout(function () { location.reload(); }, 300);
      })
      .catch(function (err) { idle(button); alertInPanel(err); });
  }

  function stopPreview() {
    // The cookie is HttpOnly, so the server removes it and brings you back here.
    location.href = config.endpoint + '/preview/stop?to=' + encodeURIComponent(location.pathname + location.search);
  }

  (function previewBar() {
    var p = config.preview;
    if (!p) return;
    var skipped = Object.keys(p.skipped || {});
    var bar = h('div', { class: 'pv', role: 'status' }, [
      h('span', { class: 'dot' }),
      h('span', { class: 'pv-tx', title: (p.summary || '') + ' — only you see this' }, [h('b', { text: 'Preview' }), ' ' + (p.summary || 'change #' + p.id) + (skipped.length ? ' (partly)' : '')]),
      btn('Details', { kind: 'ghost', onclick: function () { api('GET', '/changes/' + p.id).then(function (c) { renderChange(c, null); if (skipped.length) alertInPanel(new Error(skipped.map(function (f) { return f + ': ' + p.skipped[f]; }).join(' · '))); }); } }),
      config.canEdit || config.canReport ? btn('Ticket', { icon: 'ticket', title: 'Prepare a ticket with a screenshot of this preview', onclick: function (e) { fromPreview(e.currentTarget, 'ticket'); } }) : null,
      config.pullRequests ? btn('Open pull request', { kind: 'primary', icon: 'pr', title: 'Open a pull request with this change, with a screenshot of this preview', onclick: function (e) { fromPreview(e.currentTarget, 'pr'); } }) : null,
      config.canEdit ? btn('Refine', { kind: 'ghost', icon: 'edit', title: 'Not quite right? Tell ' + NAME + ' what to change', onclick: function () { api('GET', '/changes/' + p.id).then(function (c) { refineForm(c); }).catch(alertInPanel); } }) : null,
      btn('Stop', { kind: 'ghost', icon: 'x', title: 'Stop previewing and show the page as it is', onclick: stopPreview })
    ]);
    root.appendChild(bar);

    // Raw tags on the page right after previewing? Say so, and offer the fix.
    function checkRawHtml() {
      if (!config.canEdit || bar.querySelector('.pv-warn')) return;
      var sample = rawHtmlOnPage();
      if (!sample) return;
      var warn = btn('HTML is showing as text', { kind: 'warn', icon: 'alert', title: 'Found on the page: ' + sample, onclick: function () {
        api('GET', '/changes/' + p.id).then(function (c) {
          refineForm(c, 'The page now shows HTML tags as text, e.g. “' + sample + '”. Style it without putting markup in strings (use the framework\u2019s own styling, e.g. a colour method), or render it as HTML safely.');
        }).catch(alertInPanel);
      } });
      warn.classList.add('pv-warn');
      bar.insertBefore(warn, bar.children[2]);
    }
    setTimeout(checkRawHtml, 600);
    document.addEventListener('livewire:navigated', function () { setTimeout(checkRawHtml, 600); });

    // From the preview straight to a pull request or ticket. What you see now (the previewed result)
    // is saved as the "current view" screenshot first, so reviewers see how it looks.
    function fromPreview(button, what) {
      busy(button, 'Capturing…');
      var shot = config.screenshots
        ? captureViewport().then(function (img) { return img ? uploadShot(p.id, 'after', img) : null; }).catch(function (err) { console.warn('[Alfred] preview screenshot failed:', err); return null; })
        : Promise.resolve(null);
      shot.then(function () { return api('GET', '/changes/' + p.id); }).then(function (c) {
        idle(button);
        if (what === 'pr') { if (c.pull_request) renderChange(c, null); else prView(c); } else ticketView(c);
      }).catch(function (err) { idle(button); alertInPanel(err); });
    }
  })();

  // ------------------------------------------------------------------ public
  window.__alfred = {
    refresh: function () {
      var c = readConfig();
      if (c && c.token) config = c;
    },
    inspect: startInspect,
    explain: function (el) { explain(el, false); }
  };

  document.addEventListener('livewire:navigated', function () {
    window.__alfred.refresh();
    if (!document.body.contains(host)) document.body.appendChild(host);
  });
})();
