/*!
 * Nuvis Medcare X — global UI behaviours
 * Loaded on every authenticated page via includes/header.php.
 *
 *  - Toasts (NX.toast) — also replaces blocking window.alert()
 *  - Styled confirm dialogs for [data-nx-confirm] forms, links and buttons
 *  - Command palette (Ctrl/Cmd+K or "/"): pages, actions, live patient search
 *  - Keyboard shortcuts + help sheet ("?")
 *  - Light/dark theme and collapsible sidebar (remembered per browser)
 *  - Real-time notifications (SSE) with unread count, kept across page loads
 *  - Modals: Esc / backdrop click to close, focus on open
 *  - Double-submit protection on forms
 */
(function () {
  'use strict';

  var ctx = window.NX_CONTEXT || {};
  var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    del: function (k) { try { localStorage.removeItem(k); } catch (e) {} }
  };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'text') n.textContent = attrs[k];
      else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function icon(name, cls) { return el('span', { class: 'material-symbols-outlined ' + (cls || ''), 'aria-hidden': 'true', text: name }); }

  function relTime(ts) {
    var t = typeof ts === 'number' ? ts : Date.parse(String(ts || '').replace(' ', 'T'));
    if (!t) return '';
    var d = Math.round((Date.now() - t) / 1000);
    if (d < 45) return 'Just now';
    if (d < 3600) return Math.round(d / 60) + ' min ago';
    if (d < 86400) return Math.round(d / 3600) + ' h ago';
    return new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  }

  var NX = window.NX = window.NX || {};

  /* ---------------------------------------------------------------- Toasts */
  var toastHost;
  NX.toast = function (title, message, type, opts) {
    opts = opts || {};
    type = type || 'success';
    if (!toastHost) {
      toastHost = el('div', { class: 'nx-toasts', role: 'region', 'aria-live': 'polite', 'aria-label': 'Notifications' });
      document.body.appendChild(toastHost);
    }
    var icons = { success: 'check_circle', error: 'error', info: 'info', warning: 'warning' };
    var colors = { success: 'text-emerald-600', error: 'text-red-600', info: 'text-blue-600', warning: 'text-amber-600' };
    var close = el('button', { type: 'button', class: 'nx-icon-btn !w-7 !h-7 -mr-1 -mt-1', 'aria-label': 'Dismiss' }, [icon('close', '!text-[18px]')]);
    var body = el('div', { class: 'flex-1 min-w-0' }, [
      el('div', { class: 'text-sm font-semibold text-slate-900', text: title || '' }),
      message ? el('div', { class: 'text-[13px] text-slate-600 mt-0.5 break-words', text: message }) : null
    ]);
    if (opts.action) {
      body.appendChild(el('button', { type: 'button', class: 'mt-2 text-[13px] font-semibold text-blue-700 hover:underline', text: opts.action.label, onclick: function () { opts.action.run(); dismiss(); } }));
    }
    var t = el('div', { class: 'nx-toast is-' + type, role: type === 'error' ? 'alert' : 'status' }, [icon(icons[type] || 'info', colors[type] + ' fill'), body, close]);
    function dismiss() { t.classList.add('is-leaving'); setTimeout(function () { t.remove(); }, 200); }
    close.addEventListener('click', dismiss);
    toastHost.appendChild(t);
    var ttl = opts.duration || (type === 'error' ? 8000 : 4500);
    var timer = setTimeout(dismiss, ttl);
    t.addEventListener('mouseenter', function () { clearTimeout(timer); });
    t.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 2000); });
    return dismiss;
  };

  // alert() blocks the whole clinic workflow; show it as a toast instead.
  var nativeAlert = window.alert;
  window.alert = function (msg) {
    msg = String(msg == null ? '' : msg);
    var bad = /(error|fail|exception|invalid|denied|not configured|could not|unable)/i.test(msg);
    try { NX.toast(bad ? 'Something needs attention' : 'Done', msg, bad ? 'error' : 'success'); }
    catch (e) { nativeAlert(msg); }
  };

  /* --------------------------------------------------------------- Dialogs */
  var dialog;
  NX.confirm = function (message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!window.HTMLDialogElement) { resolve(window.confirm(message)); return; }
      if (!dialog) {
        dialog = el('dialog', { class: 'nx-dialog', 'aria-labelledby': 'nx-dlg-title' });
        document.body.appendChild(dialog);
      }
      var danger = opts.danger !== undefined ? opts.danger : /(delete|remove|revoke|cancel|clear|flush|void|discard)/i.test(message);
      dialog.innerHTML = '';
      var ok = el('button', { type: 'button', class: 'nx-btn text-white ' + (danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-700 hover:bg-blue-800'), text: opts.okLabel || (danger ? 'Yes, continue' : 'Continue') });
      var cancel = el('button', { type: 'button', class: 'btn-secondary', text: 'Cancel' });
      dialog.appendChild(el('div', { class: 'p-6' }, [
        el('div', { class: 'flex gap-4' }, [
          el('div', { class: 'w-10 h-10 rounded-full flex items-center justify-center shrink-0 ' + (danger ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-700') }, [icon(danger ? 'warning' : 'help')]),
          el('div', { class: 'min-w-0' }, [
            el('h2', { id: 'nx-dlg-title', class: 'text-base font-semibold text-slate-900', text: opts.title || (danger ? 'Please confirm' : 'Continue?') }),
            el('p', { class: 'text-sm text-slate-600 mt-1 break-words', text: message })
          ])
        ]),
        el('div', { class: 'flex justify-end gap-2 mt-6' }, [cancel, ok])
      ]));
      function done(v) { dialog.close(); resolve(v); }
      ok.addEventListener('click', function () { done(true); });
      cancel.addEventListener('click', function () { done(false); });
      dialog.addEventListener('cancel', function (e) { e.preventDefault(); done(false); }, { once: true });
      dialog.addEventListener('click', function (e) { if (e.target === dialog) done(false); });
      dialog.showModal();
      cancel.focus();
    });
  };

  // [data-nx-confirm] on forms, links and submit buttons
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('a[data-nx-confirm], button[data-nx-confirm], input[type=submit][data-nx-confirm]');
    if (!trigger || trigger.dataset.nxConfirmed) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    NX.confirm(trigger.getAttribute('data-nx-confirm')).then(function (yes) {
      if (!yes) return;
      if (trigger.tagName === 'A') { window.location.href = trigger.href; return; }
      trigger.dataset.nxConfirmed = '1';
      var form = trigger.form;
      if (form) {
        if (form.requestSubmit) form.requestSubmit(trigger); else trigger.click();
      } else trigger.click();
      setTimeout(function () { delete trigger.dataset.nxConfirmed; }, 0);
    });
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[data-nx-confirm]') || form.dataset.nxConfirmed) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    NX.confirm(form.getAttribute('data-nx-confirm')).then(function (yes) {
      if (!yes) return;
      form.dataset.nxConfirmed = '1';
      var submitter = e.submitter;
      if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); else form.submit();
      setTimeout(function () { delete form.dataset.nxConfirmed; }, 0);
    });
  }, true);

  /* ------------------------------------------- Double-submit protection */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    setTimeout(function () {
      if (e.defaultPrevented || form.target === '_blank' || form.hasAttribute('data-nx-no-lock')) return;
      var btn = e.submitter || form.querySelector('button[type=submit], button:not([type])');
      if (!btn || btn.disabled) return;
      // Keep the clicked button's name/value in the request
      if (btn.name) {
        var h = el('input', { type: 'hidden', name: btn.name, value: btn.value });
        form.appendChild(h);
      }
      btn.classList.add('is-loading');
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
      setTimeout(function () { btn.disabled = false; btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy'); }, 6000);
    }, 0);
  });
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    $all('button.is-loading').forEach(function (b) { b.disabled = false; b.classList.remove('is-loading'); });
  });

  /* ---------------------------------------------------------------- Modals */
  function isOverlay(n) {
    return !!(n && n.nodeType === 1 && n.tagName === 'DIV' && (n.hasAttribute('data-nx-modal') ||
      (/modal/i.test(n.id || '') && n.classList.contains('fixed') && n.classList.contains('inset-0'))));
  }
  function visibleModals() {
    return $all('[data-nx-modal], div[id*="odal"]').filter(function (m) {
      return isOverlay(m) && !m.classList.contains('hidden') && getComputedStyle(m).display !== 'none';
    });
  }
  NX.openModal = function (id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('flex');
    m.dataset.nxReturnFocus = '1';
    NX._lastFocus = document.activeElement;
    setTimeout(function () {
      var f = m.querySelector('[autofocus], input:not([type=hidden]):not([disabled]), select, textarea, button:not([aria-label="Close"])');
      if (f) try { f.focus({ preventScroll: true }); } catch (e) {}
    }, 30);
  };
  NX.closeModal = function (id) {
    var m = typeof id === 'string' ? document.getElementById(id) : id;
    if (!m) return;
    m.classList.add('hidden');
    if (m.id === 'modal-document-viewer') { var f = $('#doc-viewer-frame'); if (f) f.src = 'about:blank'; }
    if (NX._lastFocus && NX._lastFocus.focus) try { NX._lastFocus.focus({ preventScroll: true }); } catch (e) {}
  };
  // Close on backdrop click
  document.addEventListener('mousedown', function (e) {
    if (isOverlay(e.target) && !e.target.classList.contains('hidden') && !e.target.hasAttribute('data-nx-static')) {
      var target = e.target;
      document.addEventListener('mouseup', function up(ev) {
        if (ev.target === target) NX.closeModal(target);
      }, { once: true });
    }
  });

  /* ------------------------------------------------------- Menus/popovers */
  function bindMenu(btnId, menuId, onOpen) {
    var btn = document.getElementById(btnId), menu = document.getElementById(menuId);
    if (!btn || !menu) return null;
    function set(open) {
      menu.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open && onOpen) onOpen();
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); closeMenus(menu); set(menu.hidden); });
    document.addEventListener('click', function (e) { if (!menu.hidden && !menu.contains(e.target)) set(false); });
    menu.addEventListener('click', function (e) { if (e.target.closest('a, button.nx-menu-item')) set(false); });
    return { menu: menu, set: set };
  }
  var menus = [];
  function closeMenus(except) { menus.forEach(function (m) { if (m && m.menu !== except) m.set(false); }); }

  /* --------------------------------------------------------- Theme toggle */
  function applyThemeIcon() {
    var dark = document.documentElement.classList.contains('dark');
    $all('[data-nx-theme-icon]').forEach(function (i) { i.textContent = dark ? 'light_mode' : 'dark_mode'; });
    $all('[data-nx-theme-toggle]').forEach(function (b) { b.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme'); });
  }
  NX.toggleTheme = function () {
    var dark = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', dark);
    store.set('nx-theme', dark ? 'dark' : 'light');
    applyThemeIcon();
  };
  NX.toggleSidebar = function () {
    var c = !document.documentElement.classList.contains('nx-sidebar-collapsed');
    document.documentElement.classList.toggle('nx-sidebar-collapsed', c);
    store.set('nx-sidebar', c ? 'collapsed' : 'open');
  };

  /* -------------------------------------------------------- Notifications */
  var notifKey = 'nx-notifs:' + (ctx.tenant || 'default');
  var notifs = [];
  try { notifs = JSON.parse(store.get(notifKey, '[]')) || []; } catch (e) { notifs = []; }
  function saveNotifs() { store.set(notifKey, JSON.stringify(notifs.slice(0, 30))); }
  function renderNotifs() {
    var list = $('#notif-list'), empty = $('#notif-empty'), badge = $('#notif-badge');
    if (!list) return;
    list.innerHTML = '';
    notifs.forEach(function (n) {
      var appt = /appointment|booked/i.test(n.title || '');
      var item = el('a', { href: n.patient_id ? 'patient_detail.php?id=' + encodeURIComponent(n.patient_id) : '#', class: 'flex gap-3 px-4 py-3 border-b border-slate-100 hover:bg-slate-50 transition ' + (n.read ? '' : 'bg-blue-50/40') }, [
        el('span', { class: 'w-8 h-8 rounded-full flex items-center justify-center shrink-0 ' + (appt ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700') }, [icon(appt ? 'event_available' : 'person_add', '!text-[18px]')]),
        el('span', { class: 'min-w-0 flex-1' }, [
          el('span', { class: 'block text-[13px] font-semibold text-slate-900', text: n.title || 'Notification' }),
          el('span', { class: 'block text-[13px] text-slate-600 leading-snug', text: n.message || '' }),
          el('span', { class: 'block text-[11px] text-slate-400 mt-1', 'data-nx-time': n.receivedAt, text: relTime(n.receivedAt) })
        ]),
        n.read ? null : el('span', { class: 'nx-dot bg-blue-600 mt-1.5', 'aria-label': 'Unread' })
      ]);
      list.appendChild(item);
    });
    if (empty) empty.style.display = notifs.length ? 'none' : '';
    var unread = notifs.filter(function (n) { return !n.read; }).length;
    if (badge) {
      badge.textContent = unread > 9 ? '9+' : String(unread);
      badge.classList.toggle('hidden', unread === 0);
    }
    var bell = $('#notif-bell-btn');
    if (bell) bell.setAttribute('aria-label', unread ? 'Notifications, ' + unread + ' unread' : 'Notifications');
  }
  function addNotif(data) {
    var key = [data.title, data.patient_id, data.timestamp].join('|');
    if (notifs.some(function (n) { return n.key === key; })) return;
    notifs.unshift({ key: key, title: data.title, message: data.message, patient_id: data.patient_id || '', receivedAt: Date.now(), read: false });
    saveNotifs();
    renderNotifs();
    NX.toast(data.title, data.message, 'info', data.patient_id ? { action: { label: 'Open chart', run: function () { location.href = 'patient_detail.php?id=' + encodeURIComponent(data.patient_id); } } } : {});
  }
  window.addNotificationItem = addNotif; // legacy name
  window.clearAllNotifications = function () { notifs = []; saveNotifs(); renderNotifs(); };

  function startStream() {
    if (typeof EventSource === 'undefined') return;
    var es, retries = 0;
    function connect() {
      es = new EventSource('api/notifications/stream.php');
      es.onmessage = function (event) {
        var data;
        try { data = JSON.parse(event.data); } catch (e) { return; }
        retries = 0;
        if (data.status === 'unauthenticated' || data.enabled === false) {
          es.close();
          var s = $('#notif-status'); if (s) s.textContent = data.enabled === false ? 'Live updates are turned off for this clinic' : 'Sign in again to receive updates';
          return;
        }
        if (data.title && data.message) addNotif(data);
      };
      es.onerror = function () {
        // Server closes the stream every ~25s by design; back off if it keeps failing
        if (es.readyState === EventSource.CLOSED) {
          retries++;
          if (retries < 8) setTimeout(connect, Math.min(30000, 2000 * retries));
        }
      };
    }
    connect();
    window.addEventListener('pagehide', function () { if (es) es.close(); });
  }

  /* ------------------------------------------------------ Command palette */
  var palette, paletteInput, paletteList, paletteItems = [], paletteIndex = 0, searchTimer, searchSeq = 0;
  var actions = [
    { label: 'Register a new patient', icon: 'person_add', url: 'register_patient.php', need: ['patients', 2], keywords: 'new add patient create', hint: 'N' },
    { label: 'Book an appointment', icon: 'calendar_add_on', url: 'appointment.php?action=book', need: ['appointments', 2], keywords: 'schedule booking new' },
    { label: 'Start a clinical encounter', icon: 'stethoscope', url: 'clinical_visit.php', need: ['encounters', 2], keywords: 'visit consult soap' },
    { label: 'Create an invoice', icon: 'receipt_long', url: 'billing.php', need: ['billing', 2], keywords: 'bill charge payment' },
    { label: 'Switch light / dark theme', icon: 'contrast', run: function () { NX.toggleTheme(); }, keywords: 'dark mode theme night', hint: 'Shift D' },
    { label: 'Keyboard shortcuts', icon: 'keyboard', run: function () { NX.showHelp(); }, keywords: 'help keys', hint: '?' },
    { label: 'Sign out', icon: 'logout', url: 'actions/logout.php', keywords: 'logout exit' }
  ];

  function buildPalette() {
    palette = el('div', { class: 'nx-palette-backdrop', hidden: '', role: 'presentation' });
    var box = el('div', { class: 'nx-palette', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Search' });
    var wrap = el('div', { class: 'relative' }, [icon('search', 'absolute left-4 top-1/2 -translate-y-1/2 text-slate-400')]);
    paletteInput = el('input', { type: 'text', placeholder: 'Search patients by name, MRN or phone — or jump to a page', autocomplete: 'off', spellcheck: 'false', 'aria-controls': 'nx-palette-list', role: 'combobox', 'aria-expanded': 'true' });
    wrap.appendChild(paletteInput);
    paletteList = el('div', { id: 'nx-palette-list', class: 'nx-palette-list', role: 'listbox' });
    var foot = el('div', { class: 'nx-palette-foot' }, [
      el('span', {}, [el('kbd', { text: '↑' }), ' ', el('kbd', { text: '↓' }), ' to move']),
      el('span', {}, [el('kbd', { text: 'Enter' }), ' to open']),
      el('span', {}, [el('kbd', { text: 'Esc' }), ' to close'])
    ]);
    box.appendChild(wrap); box.appendChild(paletteList); box.appendChild(foot);
    palette.appendChild(box);
    document.body.appendChild(palette);

    palette.addEventListener('mousedown', function (e) { if (e.target === palette) NX.closePalette(); });
    paletteInput.addEventListener('input', function () { paletteIndex = 0; renderPalette(paletteInput.value); });
    paletteInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); movePalette(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); movePalette(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); runPaletteItem(paletteItems[paletteIndex], e.ctrlKey || e.metaKey); }
      else if (e.key === 'Escape') { e.preventDefault(); NX.closePalette(); }
    });
  }
  function score(text, q) {
    text = text.toLowerCase();
    if (!q) return 1;
    if (text.indexOf(q) === 0) return 3;
    if (text.indexOf(' ' + q) !== -1) return 2;
    if (text.indexOf(q) !== -1) return 1;
    return 0;
  }
  function renderPalette(q, remote) {
    q = (q || '').trim().toLowerCase();
    var sections = [];
    if (remote && (remote.patients.length || remote.appointments.length)) {
      if (remote.patients.length) sections.push({ title: 'Patients', items: remote.patients.map(function (p) {
        return { label: p.name, icon: 'person', sub: [p.mrn, p.summary].filter(Boolean).join(' · '), meta: p.allergies ? 'Allergy: ' + p.allergies : p.phone, warn: !!p.allergies, url: 'patient_detail.php?id=' + encodeURIComponent(p.id), initials: p.initials };
      }) });
      if (remote.appointments.length) sections.push({ title: 'Upcoming appointments', items: remote.appointments.map(function (a) {
        return { label: a.patient_name, icon: 'event', sub: a.doctor_name, meta: a.appointment_date + ' · ' + a.time, url: 'appointment.php?date=' + encodeURIComponent(a.appointment_date) };
      }) });
    } else if (remote && q.length >= 2) {
      sections.push({ title: 'Patients', empty: 'No patients match “' + q + '”.', items: [{ label: 'Register “' + q + '” as a new patient', icon: 'person_add', url: 'register_patient.php' }] });
    } else if (q.length >= 2) {
      sections.push({ title: 'Patients', loading: true, items: [] });
    }
    var pages = (ctx.nav || []).map(function (n) { return { label: n.label, icon: n.icon, url: n.url, s: Math.max(score(n.label, q), score(n.keywords || '', q) ? 1 : 0), hint: 'Page' }; })
      .filter(function (n) { return n.s > 0; }).sort(function (a, b) { return b.s - a.s; });
    var canDo = function (a) { return !a.need || !ctx.can || (ctx.can[a.need[0]] || 0) >= a.need[1]; };
    var acts = actions.filter(canDo).map(function (a) { return Object.assign({ s: Math.max(score(a.label, q), score(a.keywords || '', q) ? 1 : 0) }, a); })
      .filter(function (a) { return a.s > 0; });
    if (pages.length) sections.push({ title: q ? 'Pages' : 'Go to', items: pages.slice(0, q ? 5 : 10) });
    if (acts.length) sections.push({ title: 'Actions', items: acts.slice(0, q ? 4 : 7) });

    paletteList.innerHTML = '';
    paletteItems = [];
    sections.forEach(function (sec) {
      paletteList.appendChild(el('div', { class: 'nx-palette-section', text: sec.title }));
      if (sec.loading) paletteList.appendChild(el('div', { class: 'px-3 py-2 text-[13px] text-slate-500', text: 'Searching…' }));
      if (sec.empty) paletteList.appendChild(el('div', { class: 'px-3 pb-1 text-[13px] text-slate-500', text: sec.empty }));
      sec.items.forEach(function (it) {
        var idx = paletteItems.length;
        var lead = it.initials
          ? el('span', { class: 'nx-avatar w-8 h-8 text-[11px]', text: it.initials })
          : el('span', { class: 'w-8 h-8 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center shrink-0' }, [icon(it.icon, '!text-[18px]')]);
        var row = el('div', { class: 'nx-palette-item', role: 'option', id: 'nx-opt-' + idx, 'aria-selected': idx === paletteIndex ? 'true' : 'false' }, [
          lead,
          el('span', { class: 'min-w-0' }, [
            el('span', { class: 'block truncate font-medium', text: it.label }),
            it.sub ? el('span', { class: 'block truncate text-xs text-slate-500', text: it.sub }) : null
          ]),
          it.meta ? el('span', { class: 'nx-meta ' + (it.warn ? '!text-red-600 font-medium' : ''), text: it.meta }) : (it.hint ? el('span', { class: 'nx-meta', text: it.hint }) : null)
        ]);
        row.addEventListener('mousemove', function () { if (paletteIndex !== idx) { paletteIndex = idx; highlight(); } });
        row.addEventListener('click', function (e) { runPaletteItem(it, e.ctrlKey || e.metaKey); });
        paletteList.appendChild(row);
        paletteItems.push(it);
      });
    });
    if (!paletteItems.length && !(q.length >= 2 && !remote)) paletteList.appendChild(el('div', { class: 'nx-empty', text: 'Nothing matches that. Try a patient name or MRN.' }));
    if (paletteIndex >= paletteItems.length) paletteIndex = 0;
    highlight();

    if (!remote && q.length >= 2) {
      clearTimeout(searchTimer);
      var seq = ++searchSeq;
      searchTimer = setTimeout(function () {
        fetch('actions/quick_search.php?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { patients: [], appointments: [] }; })
          .catch(function () { return { patients: [], appointments: [] }; })
          .then(function (data) {
            if (seq !== searchSeq || palette.hidden) return;
            renderPalette(paletteInput.value, { patients: data.patients || [], appointments: data.appointments || [] });
          });
      }, 160);
    }
  }
  function highlight() {
    $all('.nx-palette-item', paletteList).forEach(function (r, i) { r.setAttribute('aria-selected', i === paletteIndex ? 'true' : 'false'); });
    var cur = document.getElementById('nx-opt-' + paletteIndex);
    if (cur) { cur.scrollIntoView({ block: 'nearest' }); paletteInput.setAttribute('aria-activedescendant', cur.id); }
  }
  function movePalette(d) {
    if (!paletteItems.length) return;
    paletteIndex = (paletteIndex + d + paletteItems.length) % paletteItems.length;
    highlight();
  }
  function runPaletteItem(it, newTab) {
    if (!it) return;
    if (it.run) { NX.closePalette(); it.run(); return; }
    if (it.url) { if (newTab) window.open(it.url, '_blank'); else window.location.href = it.url; }
  }
  NX.openPalette = function (initial) {
    if (!palette) buildPalette();
    palette.hidden = false;
    document.body.style.overflow = 'hidden';
    paletteInput.value = initial || '';
    paletteIndex = 0;
    renderPalette(paletteInput.value);
    setTimeout(function () { paletteInput.focus(); }, 10);
  };
  NX.closePalette = function () {
    if (!palette || palette.hidden) return;
    palette.hidden = true;
    document.body.style.overflow = '';
  };

  /* ------------------------------------------------------- Shortcut help */
  var helpDialog;
  var shortcuts = [
    ['Search patients and pages', [isMac ? '⌘' : 'Ctrl', 'K']],
    ['Search (alternative)', ['/']],
    ['Go to dashboard', ['G', 'D']],
    ['Go to patients', ['G', 'P']],
    ['Go to appointments', ['G', 'A']],
    ['Go to clinical encounter', ['G', 'E']],
    ['Go to billing', ['G', 'B']],
    ['Go to inventory', ['G', 'I']],
    ['Register a new patient', ['N']],
    ['Switch light / dark theme', ['Shift', 'D']],
    ['Collapse or expand sidebar', ['[']],
    ['Close a dialog or panel', ['Esc']],
    ['Show this list', ['?']]
  ];
  NX.showHelp = function () {
    if (!window.HTMLDialogElement) return;
    if (!helpDialog) {
      helpDialog = el('dialog', { class: 'nx-dialog !max-w-[460px]', 'aria-labelledby': 'nx-help-title' });
      var rows = shortcuts.map(function (s) {
        return el('div', { class: 'flex items-center justify-between py-2 border-b border-slate-100 last:border-0' }, [
          el('span', { class: 'text-sm text-slate-700', text: s[0] }),
          el('span', { class: 'flex gap-1' }, s[1].map(function (k) { return el('kbd', { text: k }); }))
        ]);
      });
      var close = el('button', { type: 'button', class: 'nx-icon-btn', 'aria-label': 'Close' }, [icon('close')]);
      helpDialog.appendChild(el('div', { class: 'p-6' }, [
        el('div', { class: 'flex items-start justify-between mb-3' }, [
          el('div', {}, [
            el('h2', { id: 'nx-help-title', class: 'text-base font-semibold text-slate-900', text: 'Keyboard shortcuts' }),
            el('p', { class: 'text-xs text-slate-500 mt-0.5', text: 'Shortcuts work anywhere except while typing in a field.' })
          ]), close
        ])
      ].concat(rows)));
      close.addEventListener('click', function () { helpDialog.close(); });
      helpDialog.addEventListener('click', function (e) { if (e.target === helpDialog) helpDialog.close(); });
      document.body.appendChild(helpDialog);
    }
    helpDialog.showModal();
  };

  /* ---------------------------------------------------- Keyboard routing */
  var gPending = 0;
  var goMap = { d: 'index.php', p: 'patients.php', a: 'appointment.php', e: 'clinical_visit.php', b: 'billing.php', i: 'inventory.php', s: 'settings.php' };
  function typing(t) {
    return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
  }
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (palette && !palette.hidden) NX.closePalette(); else NX.openPalette();
      return;
    }
    if (e.key === 'Escape') {
      if (palette && !palette.hidden) { NX.closePalette(); return; }
      closeMenus();
      var open = visibleModals();
      if (open.length) { NX.closeModal(open[open.length - 1]); return; }
      var drawer = $('#mobileSidebar');
      if (drawer && drawer.classList.contains('translate-x-0') && window.toggleMobileSidebar) window.toggleMobileSidebar();
      return;
    }
    if (typing(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
    if (document.querySelector('dialog[open]')) return;
    var k = e.key;
    if (gPending && Date.now() - gPending < 1200 && goMap[k.toLowerCase()]) { e.preventDefault(); gPending = 0; location.href = goMap[k.toLowerCase()]; return; }
    gPending = 0;
    if (k === '/') { e.preventDefault(); NX.openPalette(); }
    else if (k === '?') { e.preventDefault(); NX.showHelp(); }
    else if (k === 'g' || k === 'G') { gPending = Date.now(); }
    else if (k === 'n' || k === 'N') { if (!e.shiftKey) location.href = 'register_patient.php'; }
    else if (k === 'D' && e.shiftKey) { NX.toggleTheme(); }
    else if (k === '[') { NX.toggleSidebar(); }
  });

  /* ------------------------------------------------- Live table filtering */
  // <input data-nx-filter="#tableId"> filters that table's body rows as you type.
  function bindTableFilters() {
    $all('input[data-nx-filter]').forEach(function (input) {
      var table = $(input.getAttribute('data-nx-filter'));
      if (!table) return;
      var counter = input.getAttribute('data-nx-filter-count') ? $(input.getAttribute('data-nx-filter-count')) : null;
      input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        $all('tbody tr', table).forEach(function (tr) {
          if (tr.hasAttribute('data-nx-empty-row')) return;
          var hit = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
          tr.style.display = hit ? '' : 'none';
          if (hit) shown++;
        });
        if (counter) counter.textContent = String(shown);
      });
    });
  }

  // <table data-nx-sortable> — click a header to sort the rows on this page.
  function bindSortableTables() {
    $all('table[data-nx-sortable]').forEach(function (table) {
      $all('thead th', table).forEach(function (th, col) {
        if (th.hasAttribute('data-nx-nosort') || !th.textContent.trim()) return;
        th.setAttribute('data-nx-sort', '');
        th.setAttribute('tabindex', '0');
        function sort() {
          var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
          $all('thead th', table).forEach(function (h) { h.removeAttribute('aria-sort'); });
          th.setAttribute('aria-sort', dir);
          var body = table.tBodies[0];
          var rows = $all('tr', body).filter(function (r) { return !r.hasAttribute('data-nx-empty-row'); });
          rows.sort(function (a, b) {
            var ca = a.cells[col], cb = b.cells[col];
            var va = ca ? (ca.getAttribute('data-sort') || ca.textContent.trim()) : '';
            var vb = cb ? (cb.getAttribute('data-sort') || cb.textContent.trim()) : '';
            var na = parseFloat(va.replace(/[^0-9.\-]/g, '')), nb = parseFloat(vb.replace(/[^0-9.\-]/g, ''));
            var r = (!isNaN(na) && !isNaN(nb) && /^[^a-z]*$/i.test(va + vb)) ? na - nb : va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
            return dir === 'ascending' ? r : -r;
          });
          rows.forEach(function (r) { body.appendChild(r); });
        }
        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
      });
    });
  }

  /* ---------------------------------------------- Password rule checker */
  function bindPasswordForm() {
    var form = $('[data-nx-password-form]');
    if (!form) return;
    var pw = form.querySelector('[name=new_password]'), cf = form.querySelector('[name=confirm_password]');
    var rules = { len: function (v) { return v.length >= 8; }, upper: function (v) { return /[A-Z]/.test(v); }, digit: function (v) { return /\d/.test(v); } };
    function check() {
      Object.keys(rules).forEach(function (r) {
        var li = form.querySelector('[data-rule="' + r + '"]');
        var ok = rules[r](pw.value);
        if (li) { li.classList.toggle('text-emerald-700', ok); li.firstElementChild.className = 'nx-dot ' + (ok ? 'bg-emerald-500' : 'bg-slate-300'); }
      });
      var mm = form.querySelector('[data-nx-pw-mismatch]');
      var mismatch = cf.value && cf.value !== pw.value;
      if (mm) mm.classList.toggle('hidden', !mismatch);
      cf.setCustomValidity(mismatch ? "Passwords don't match" : '');
    }
    pw.addEventListener('input', check);
    cf.addEventListener('input', check);
  }

  // Broken patient/doctor photos: hide the broken-image glyph and alt text
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && !t.classList.contains('nx-img-failed')) t.classList.add('nx-img-failed');
  }, true);

  /* -------------------------------------------------------------- Boot */
  function boot() {
    $all('img').forEach(function (img) { if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) img.classList.add('nx-img-failed'); });
    if (ctx.toast && ctx.toast.title) NX.toast(ctx.toast.title, ctx.toast.message, ctx.toast.type === 'error' ? 'error' : (ctx.toast.type || 'success'));

    $all('[data-nx-mod]').forEach(function (k) { k.textContent = isMac ? '⌘' : 'Ctrl'; });
    $all('[data-nx-palette-open]').forEach(function (b) { b.addEventListener('click', function () { NX.openPalette(); }); });
    $all('[data-nx-help-open]').forEach(function (b) { b.addEventListener('click', function () { NX.showHelp(); }); });
    $all('[data-nx-theme-toggle]').forEach(function (b) { b.addEventListener('click', NX.toggleTheme); });
    $all('[data-nx-sidebar-toggle]').forEach(function (b) { b.addEventListener('click', NX.toggleSidebar); });
    $all('[data-nx-notif-clear]').forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); window.clearAllNotifications(); }); });
    applyThemeIcon();

    menus.push(bindMenu('nx-profile-btn', 'nx-profile-menu'));
    menus.push(bindMenu('notif-bell-btn', 'notif-dropdown', function () {
      // Opening the panel marks everything as read
      setTimeout(function () {
        var changed = false;
        notifs.forEach(function (n) { if (!n.read) { n.read = true; changed = true; } });
        if (changed) { saveNotifs(); var b = $('#notif-badge'); if (b) b.classList.add('hidden'); }
      }, 1200);
    }));
    renderNotifs();
    startStream();

    bindTableFilters();
    bindSortableTables();
    bindPasswordForm();

    // Keep relative timestamps fresh
    setInterval(function () { $all('[data-nx-time]').forEach(function (n) { n.textContent = relTime(+n.getAttribute('data-nx-time') || n.getAttribute('data-nx-time')); }); }, 60000);

    // Follow the OS theme while the user hasn't picked one
    if (window.matchMedia && !store.get('nx-theme', null)) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (store.get('nx-theme', null)) return;
        document.documentElement.classList.toggle('dark', e.matches);
        applyThemeIcon();
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
