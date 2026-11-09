/*!
 * Nuvis Medcare X — optional voice dictation for clinical encounters
 *
 *  - [data-voice-for="<textarea id>"]  mic button that dictates into a note field
 *  - [data-voice-rx]                   mic button that dictates one prescription line
 *                                      and fills the form for the doctor to check
 *
 * Only wired up when the signed-in doctor has turned dictation on (window.NX_VOICE).
 * Uses the browser's SpeechRecognition. Nothing is saved by this file: text lands in
 * ordinary form fields and the doctor still presses save.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.NXVoice = api;
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', api.init);
    else api.init();
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---------- spoken text → clean text ---------- */

  var ONES = { zero: 0, one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10,
    eleven: 11, twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15, sixteen: 16, seventeen: 17, eighteen: 18, nineteen: 19 };
  var TENS = { twenty: 20, thirty: 30, forty: 40, fifty: 50, sixty: 60, seventy: 70, eighty: 80, ninety: 90 };

  /** "five hundred" -> "500", "one hundred fifty two" -> "152". Leaves everything else alone. */
  function numberWordsToDigits(text) {
    var parts = text.split(/(\s+)/); // keep the spacing
    var out = [];
    var i = 0;
    while (i < parts.length) {
      var w = parts[i].toLowerCase();
      if (!(w in ONES) && !(w in TENS)) { out.push(parts[i]); i++; continue; }
      var total = 0, last = i, j = i;
      while (j < parts.length) {
        var t = parts[j].toLowerCase();
        if (/^\s+$/.test(parts[j])) { j++; continue; }
        var low = total % 100;
        if (t in TENS && low === 0) { total += TENS[t]; last = j; }
        else if (t in ONES && (low === 0 || (low >= 20 && low % 10 === 0 && ONES[t] < 10))) { total += ONES[t]; last = j; }
        else if (t === 'hundred' && total > 0 && total < 10) { total *= 100; last = j; }
        else break;
        j++;
      }
      out.push(String(total));
      i = last + 1;
    }
    return out.join('');
  }

  var COMMANDS = [
    [/\s*\bnew paragraph\b\s*/gi, '\n\n'],
    [/\s*\bnew line\b\s*/gi, '\n'],
    [/\s*\bfull stop\b/gi, '.'],
    [/\s*\bquestion mark\b/gi, '?'],
    [/\s*\bcomma\b/gi, ',']
  ];

  /**
   * Turn what was heard into text for a note. Only unambiguous spoken commands are
   * honoured: "period" and "colon" are left alone because they are also clinical words.
   */
  function applyVoiceCommands(heard) {
    var t = heard;
    COMMANDS.forEach(function (c) { t = t.replace(c[0], c[1]); });
    // A new sentence or line starts with a capital.
    return t.replace(/([.?!]\s+|\n\s*)([a-z])/g, function (_, sep, ch) { return sep + ch.toUpperCase(); });
  }

  /** Join a new chunk onto what is already in the field, with sensible spacing and capitals. */
  function appendDictation(existing, chunk) {
    chunk = applyVoiceCommands(String(chunk || '').trim());
    if (!chunk) return existing;
    var prev = existing || '';
    var atStart = prev.trim() === '' || /[.?!]\s*$/.test(prev) || /\n\s*$/.test(prev);
    if (atStart) chunk = chunk.charAt(0).toUpperCase() + chunk.slice(1);
    var glue = '';
    if (prev && !/[\s\n]$/.test(prev) && !/^[.,?!\n]/.test(chunk)) glue = ' ';
    return prev + glue + chunk;
  }

  /* ---------- spoken prescription → fields ---------- */

  var DOSE_RE = /(\d+(?:\.\d+)?)\s*(milligrams?|milligrammes?|mg|micrograms?|mcg|grams?|g|millilit(?:er|re)s?|ml|international units?|iu|units?|puffs?|drops?|tablets?|capsules?)\b/i;
  var DOSE_UNIT = { milligram: 'mg', milligrams: 'mg', milligramme: 'mg', milligrammes: 'mg', mg: 'mg', microgram: 'mcg', micrograms: 'mcg',
    mcg: 'mcg', gram: 'g', grams: 'g', g: 'g', milliliter: 'ml', milliliters: 'ml', millilitre: 'ml', millilitres: 'ml', ml: 'ml',
    'international unit': 'IU', 'international units': 'IU', iu: 'IU', unit: 'units', units: 'units' };

  var FREQ_PATTERNS = [
    [/\b(\d+)\s*(?:times?|x)\s*(?:a|per|each)\s*day\b/i, function (m) { return m[1] + 'x daily'; }],
    [/\b(\d+)\s*x\s*daily\b/i, function (m) { return m[1] + 'x daily'; }],
    [/\bonce\s*(?:a|per|each)?\s*day\b|\bonce daily\b|\bdaily\b|\bone time a day\b/i, function () { return '1x daily'; }],
    [/\btwice\s*(?:a|per|each)?\s*day\b|\btwice daily\b/i, function () { return '2x daily'; }],
    [/\bthrice\s*(?:a|per|each)?\s*day\b/i, function () { return '3x daily'; }],
    [/\bevery\s+(\d+)\s*hours?\b/i, function (m) { return 'every ' + m[1] + ' hours'; }],
    [/\bevery\s+(\d+)\s*days?\b/i, function (m) { return 'every ' + m[1] + ' days'; }],
    [/\bat\s+(?:bed\s*time|night)\b|\bbefore bed\b/i, function () { return 'at bedtime'; }],
    [/\b(?:as needed|when needed|p\.?r\.?n\.?)\b/i, function () { return 'as needed'; }],
    [/\b(b\.?i\.?d\.?|t\.?i\.?d\.?|q\.?i\.?d\.?|o\.?d\.?|q\.?d\.?)\b/i, function (m) { return m[1].replace(/\./g, '').toUpperCase(); }]
  ];

  var DURATION_RE = /\bfor\s+(?:the next\s+)?(\d+|an?|one)\s*(days?|weeks?|months?)\b/i;

  function cap(s) { s = s.trim(); return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
  function tidyName(s) {
    return s.replace(/^(?:prescribe|give|start|add|medication|the)\s+/i, '')
      .replace(/[,.\s]+$/, '').replace(/\s+/g, ' ').trim()
      .replace(/\b([a-z])([a-z]*)/gi, function (_, a, b) { return a.toUpperCase() + b.toLowerCase(); });
  }

  /**
   * "Amoxicillin 500 milligrams three times a day for seven days take with food"
   *   -> { medication_name, dosage, frequency, duration, instructions }
   * Anything not recognised is left blank for the doctor to type; nothing is guessed.
   */
  function parseRx(heard) {
    var text = numberWordsToDigits(String(heard || '').replace(/\s+/g, ' ').trim());
    var rest = text;
    var out = { medication_name: '', dosage: '', frequency: '', duration: '', instructions: '' };
    var firstAt = text.length;
    var cut = function (m) {
      if (m.index < firstAt) firstAt = m.index;
      rest = rest.replace(m[0], ' ');
    };

    var m = DOSE_RE.exec(text);
    if (m) {
      var unit = m[2].toLowerCase();
      out.dosage = m[1] + (/^(tablets?|capsules?|puffs?|drops?)$/.test(unit) ? ' ' + unit : ' ' + (DOSE_UNIT[unit] || unit));
      cut(m);
    }

    for (var i = 0; i < FREQ_PATTERNS.length; i++) {
      var f = FREQ_PATTERNS[i][0].exec(text);
      if (f) { out.frequency = FREQ_PATTERNS[i][1](f); cut(f); break; }
    }

    var d = DURATION_RE.exec(text);
    if (d) {
      var n = /^an?$|^one$/i.test(d[1]) ? '1' : d[1];
      var u = d[2].toLowerCase().replace(/s$/, '');
      out.duration = n + ' ' + u + (n === '1' ? '' : 's');
      cut(d);
    }

    // Name: whatever was said before the first recognised detail; the rest is instructions.
    out.medication_name = tidyName(text.slice(0, firstAt));
    var after = rest.slice(firstAt)
      .replace(/\b(?:and|then|for|instructions?|please)\b/gi, ' ')
      .replace(/\s+/g, ' ').replace(/^[,.\s]+|[,.\s]+$/g, '');
    out.instructions = cap(after);
    return out;
  }

  /* ---------- browser wiring ---------- */

  function getRecognition() {
    return (typeof window !== 'undefined') && (window.SpeechRecognition || window.webkitSpeechRecognition) || null;
  }

  function init() {
    var cfg = window.NX_VOICE;
    if (!cfg || !cfg.enabled) return;
    var Rec = getRecognition();
    var buttons = [].slice.call(document.querySelectorAll('[data-voice-for], [data-voice-rx]'));
    if (!Rec) {
      buttons.forEach(function (b) {
        b.disabled = true;
        b.title = 'This browser cannot take dictation. Chrome, Edge or Safari can; you can also just type.';
        b.classList.add('opacity-50', 'cursor-not-allowed');
      });
      return;
    }

    var active = null; // { rec, btn, stop() }

    function setBtn(btn, listening) {
      btn.setAttribute('aria-pressed', listening ? 'true' : 'false');
      btn.classList.toggle('nx-voice-on', listening);
      var label = btn.querySelector('[data-voice-label]');
      if (label) label.textContent = listening ? 'Stop' : (btn.hasAttribute('data-voice-rx') ? 'Dictate' : 'Dictate');
      var ic = btn.querySelector('.material-symbols-outlined');
      if (ic) ic.textContent = listening ? 'stop_circle' : 'mic';
    }

    function say(btn, msg) {
      var id = btn.getAttribute('data-voice-status');
      var node = id && document.getElementById(id);
      if (node) node.textContent = msg || '';
    }

    function stopActive() { if (active) active.stop(); }

    function start(btn, onFinal, onInterim) {
      stopActive();
      var rec = new Rec();
      rec.lang = cfg.lang || 'en-US';
      rec.continuous = true;
      rec.interimResults = true;
      var done = false;
      var session = {
        stop: function () { if (done) return; done = true; try { rec.stop(); } catch (e) {} finish(); }
      };
      function finish() {
        setBtn(btn, false);
        if (active === session) active = null;
        say(btn, '');
      }
      rec.onresult = function (ev) {
        var interim = '';
        for (var i = ev.resultIndex; i < ev.results.length; i++) {
          var r = ev.results[i];
          if (r.isFinal) onFinal(r[0].transcript);
          else interim += r[0].transcript;
        }
        if (onInterim) onInterim(interim);
      };
      rec.onerror = function (ev) {
        var why = ev && ev.error;
        var msg = why === 'not-allowed' || why === 'service-not-allowed'
          ? 'Microphone is blocked. Allow it in the browser address bar, or type instead.'
          : why === 'no-speech' ? 'Heard nothing. Try again closer to the microphone.'
          : why === 'network' ? 'Dictation needs an internet connection.'
          : why === 'aborted' ? '' : 'Dictation stopped (' + why + '). You can keep typing.';
        done = true; finish();
        if (msg) say(btn, msg);
      };
      rec.onend = function () { if (!done) { done = true; finish(); } };
      try { rec.start(); } catch (e) { say(btn, 'Could not start the microphone.'); return; }
      active = session;
      setBtn(btn, true);
      say(btn, 'Listening… speak normally. Say "new line" or "full stop" for punctuation.');
    }

    buttons.forEach(function (btn) {
      setBtn(btn, false);
      btn.addEventListener('click', function () {
        if (btn.getAttribute('aria-pressed') === 'true') { stopActive(); return; }

        if (btn.hasAttribute('data-voice-rx')) {
          var form = btn.closest('form');
          var heard = '';
          start(btn, function (t) {
            heard = (heard + ' ' + t).trim();
            var rx = parseRx(heard);
            ['medication_name', 'dosage', 'frequency', 'duration', 'instructions'].forEach(function (k) {
              var f = form && form.querySelector('[name="' + k + '"]');
              if (f) f.value = rx[k];
            });
            var tr = document.getElementById(btn.getAttribute('data-voice-heard'));
            if (tr) tr.textContent = '“' + heard + '”';
          }, function (interim) {
            say(btn, interim ? 'Hearing: ' + interim : 'Listening…');
          });
          return;
        }

        var ta = document.getElementById(btn.getAttribute('data-voice-for'));
        if (!ta) return;
        ta.focus();
        start(btn, function (t) {
          ta.value = appendDictation(ta.value, t);
          ta.dispatchEvent(new Event('input', { bubbles: true }));
          ta.scrollTop = ta.scrollHeight;
        }, function (interim) {
          say(btn, interim ? 'Hearing: ' + interim : 'Listening…');
        });
      });
    });

    // Never keep the microphone open across a save, a modal close or leaving the page.
    document.addEventListener('submit', stopActive, true);
    window.addEventListener('pagehide', stopActive);
    document.addEventListener('visibilitychange', function () { if (document.hidden) stopActive(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') stopActive(); });
  }

  return { init: init, parseRx: parseRx, appendDictation: appendDictation, applyVoiceCommands: applyVoiceCommands, numberWordsToDigits: numberWordsToDigits };
});
