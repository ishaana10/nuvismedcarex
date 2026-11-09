// Run with: node --test tests/voice.test.js
const test = require('node:test');
const assert = require('node:assert');
const V = require('../assets/js/voice.js');

test('parses a spoken prescription line', () => {
  assert.deepStrictEqual(
    V.parseRx('Amoxicillin 500 milligrams three times a day for seven days take with food'),
    { medication_name: 'Amoxicillin', dosage: '500 mg', frequency: '3x daily', duration: '7 days', instructions: 'Take with food' });
});

test('number words become digits', () => {
  assert.strictEqual(V.numberWordsToDigits('five hundred and one hundred fifty two'), '500 and 152');
});

test('leaves unheard fields blank rather than guessing', () => {
  const rx = V.parseRx('amlodipine five milligrams once daily');
  assert.strictEqual(rx.duration, '');
  assert.strictEqual(rx.instructions, '');
});

test('spoken punctuation, but clinical words like period are untouched', () => {
  assert.strictEqual(V.appendDictation('', 'fever for three days full stop cough new line no chest pain'),
    'Fever for three days. Cough\nNo chest pain');
  assert.strictEqual(V.appendDictation('', 'last period was in october'), 'Last period was in october');
});
