/*!
 * Nuvis Medcare X — page actions (data-on-click, data-on-change, ...).
 *
 * The Content-Security-Policy no longer allows inline event handlers, so buttons carry
 * data-on-click="openModal('x')" and this file runs them. It does NOT use eval: each action is
 * parsed here and may only call the functions listed below plus a few safe DOM methods, so
 * markup that someone manages to inject cannot call anything else.
 * Add a new page function to ACTIONS (tests/ActionAllowlistTest.php fails until you do).
 */
(function () {
  'use strict';

  var ACTIONS = {
    addConsentField: 1,
    addCustomField: 1,
    addCustomLabRow: 1,
    addFinalizeRow: 1,
    addInvoiceRow: 1,
    addPastInvoiceRow: 1,
    addProviderRow: 1,
    autoFillPatient: 1,
    calculateInvoiceOwed: 1,
    clearDeveloperErrorLogs: 1,
    closeAddRxModal: 1,
    closeChangePasswordModal: 1,
    closeEditRxModal: 1,
    closeModal: 1,
    closePastVisitModal: 1,
    closeTenantModal: 1,
    closeUserModal: 1,
    closeWebhookModal: 1,
    deleteWebhookSubscription: 1,
    encCopyKey: 1,
    encGenerateKey: 1,
    encLoad: 1,
    encRun: 1,
    fetchDeveloperErrorLogs: 1,
    fetchRedisDiagnostics: 1,
    filterUserRole: 1,
    flushRedisCache: 1,
    generateApiKeyPrompt: 1,
    initializeGitRepo: 1,
    launchGoogleDrivePicker: 1,
    nxAlertAudience: 1,
    nxDismissDevAlert: 1,
    nxToggleInsuranceOther: 1,
    onFinalizeInsuranceChange: 1,
    onFinalizeInventorySelect: 1,
    onInventoryItemSelect: 1,
    onPastInventorySelect: 1,
    openAddRxModal: 1,
    openChangePasswordModal: 1,
    openConsentForm: 1,
    openCreateInvoiceForVisit: 1,
    openDetailedRestockModal: 1,
    openDocumentViewer: 1,
    openEditLabModal: 1,
    openEditModal: 1,
    openEditRxModal: 1,
    openLocationModal: 1,
    openModal: 1,
    openPastVisitModal: 1,
    openTenantModal: 1,
    openUserModal: 1,
    openWebhookModal: 1,
    recalculateBillingInvoiceTotals: 1,
    recalculateFinalizeInvoiceTotals: 1,
    recalculatePastInvoiceTotals: 1,
    refreshGitStatus: 1,
    removeProviderRow: 1,
    renderConsentPreview: 1,
    revokeApiKey: 1,
    saveGitSettings: 1,
    searchUsers: 1,
    submitWebhookForm: 1,
    switchDevTab: 1,
    switchGitSubTab: 1,
    testDrive: 1,
    testRedisPing: 1,
    testSdcPing: 1,
    testWebhookEndpoint: 1,
    toggleDrive: 1,
    toggleFinalizeInvoiceFields: 1,
    toggleMobileSidebar: 1,
    toggleProviderDetails: 1,
    toggleRedisConfig: 1,
    triggerGitPull: 1,
    triggerStartRedisDaemon: 1,
    uploadVsdcCertificate: 1,
    viewCardDetails: 1
  };
  // Members that may be read or called on this / event / a looked-up element / classList.
  var MEMBERS = { form: 1, submit: 1, closest: 1, remove: 1, close: 1, showModal: 1, parentElement: 1,
                  classList: 1, add: 1, toggle: 1, value: 1, target: 1, stopPropagation: 1, preventDefault: 1 };
  var NX_MEMBERS = { openModal: 1, closeModal: 1 };

  function Parser(src, el, ev) { this.s = src; this.i = 0; this.el = el; this.ev = ev; }
  var P = Parser.prototype;

  P.ws = function () { while (this.i < this.s.length && /\s/.test(this.s[this.i])) this.i++; };
  P.fail = function (m) { throw new Error(m + ' at ' + this.i + ' in: ' + this.s); };
  P.expect = function (c) { this.ws(); if (this.s[this.i] !== c) this.fail('expected ' + c); this.i++; };

  P.program = function () {
    var prevent = false;
    for (;;) {
      this.ws();
      if (this.i >= this.s.length) break;
      var isReturn = /^return\b/.test(this.s.slice(this.i));
      if (isReturn) this.i += 6;
      var v = this.expr();
      if (isReturn && v === false) prevent = true;
      this.ws();
      if (this.s[this.i] === ';') this.i++;
      else if (this.i < this.s.length) this.fail('unexpected text');
    }
    return prevent;
  };

  P.expr = function () {
    this.ws();
    if (this.s[this.i] === '!') { this.i++; return !this.expr(); }
    return this.chain();
  };

  P.string = function () {
    var q = this.s[this.i++], out = '';
    while (this.i < this.s.length && this.s[this.i] !== q) {
      var c = this.s[this.i++];
      if (c === '\\') {
        var n = this.s[this.i++];
        out += n === 'n' ? '\n' : n === 't' ? '\t' : n === 'u' ? String.fromCharCode(parseInt(this.s.substr(this.i, 4), 16)) : n;
        if (n === 'u') this.i += 4;
      } else out += c;
    }
    if (this.s[this.i] !== q) this.fail('unterminated string');
    this.i++;
    return out;
  };

  P.json = function () {
    var start = this.i, depth = 0, inStr = false, q = '';
    for (; this.i < this.s.length; this.i++) {
      var c = this.s[this.i];
      if (inStr) { if (c === '\\') this.i++; else if (c === q) inStr = false; continue; }
      if (c === '"') { inStr = true; q = c; }
      else if (c === '{' || c === '[') depth++;
      else if (c === '}' || c === ']') { depth--; if (depth === 0) { this.i++; break; } }
    }
    return JSON.parse(this.s.slice(start, this.i));
  };

  P.args = function () {
    var out = [];
    for (;;) {
      this.ws();
      if (this.s[this.i] === ')') { this.i++; return out; }
      out.push(this.expr());
      this.ws();
      if (this.s[this.i] === ',') this.i++;
    }
  };

  P.chain = function () {
    this.ws();
    var c = this.s[this.i], cur, self, root;
    if (c === "'" || c === '"') return this.string();
    if (c === '{' || c === '[') return this.json();
    if (/[0-9-]/.test(c)) {
      var m = /^-?\d+(\.\d+)?/.exec(this.s.slice(this.i));
      if (!m) this.fail('bad number');
      this.i += m[0].length;
      return parseFloat(m[0]);
    }
    var id = /^[A-Za-z_$][\w$]*/.exec(this.s.slice(this.i));
    if (!id) this.fail('unexpected character');
    this.i += id[0].length;
    var name = id[0];
    if (name === 'true') return true;
    if (name === 'false') return false;
    if (name === 'null') return null;
    if (name === 'undefined') return undefined;
    if (name === 'this') { cur = this.el; root = 'el'; }
    else if (name === 'event') { cur = this.ev; root = 'el'; }
    else if (name === 'document') { cur = document; root = 'document'; }
    else if (name === 'window') { cur = window; root = 'window'; }
    else if (name === 'NX') { cur = window.NX; root = 'NX'; }
    else if (name === 'confirm') { cur = window.confirm; self = window; root = 'call'; }
    else if (ACTIONS[name] === 1 && typeof window[name] === 'function') { cur = window[name]; self = window; root = 'call'; }
    else this.fail('not an allowed action: ' + name);

    for (;;) {
      this.ws();
      var ch = this.s[this.i];
      if (ch === '.') {
        this.i++;
        var mm = /^[A-Za-z_$][\w$]*/.exec(this.s.slice(this.i));
        if (!mm) this.fail('bad member');
        this.i += mm[0].length;
        var key = mm[0];
        var ok = (root === 'document' && key === 'getElementById' && cur === document) ||
                 (root === 'window' && key === 'print' && cur === window) ||
                 (root === 'NX' && NX_MEMBERS[key] === 1 && cur === window.NX) ||
                 (root === 'el' && MEMBERS[key] === 1) ||
                 (root === 'result' && MEMBERS[key] === 1);
        if (!ok) this.fail('member not allowed: ' + key);
        self = cur; cur = cur[key];
        if (root === 'document' || root === 'window' || root === 'NX') root = (typeof cur === 'function') ? root : 'result';
      } else if (ch === '(') {
        this.i++;
        if (typeof cur !== 'function') this.fail('not a function');
        var a = this.args();
        cur = cur.apply(self, a);
        self = undefined;
        if (root === 'document' || root === 'window' || root === 'NX' || root === 'call') root = 'result';
      } else return cur;
    }
  };

  function run(src, el, ev) {
    try {
      if (new Parser(src, el, ev).program()) ev.preventDefault();
    } catch (err) {
      if (window.console) console.error('Action blocked or invalid:', err.message);
    }
  }

  function handle(ev) {
    var attr = 'data-on-' + ev.type;
    var node = ev.target && ev.target.nodeType === 1 ? ev.target : (ev.target && ev.target.parentElement);
    if (ev.type === 'error') {                       // load errors do not bubble: target only
      if (node && node.hasAttribute && node.hasAttribute(attr)) run(node.getAttribute(attr), node, ev);
      return;
    }
    while (node && node !== document) {
      if (node.hasAttribute && node.hasAttribute(attr)) {
        run(node.getAttribute(attr), node, ev);
        if (ev.cancelBubble) break;
      }
      node = node.parentElement;
    }
  }

  ['click', 'change', 'input', 'submit', 'keyup'].forEach(function (t) { document.addEventListener(t, handle, false); });
  document.addEventListener('error', handle, true);
})();
