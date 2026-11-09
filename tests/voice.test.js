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

test('growing repeats from the recogniser collapse instead of piling up', () => {
  assert.strictEqual(
    V.collapseResults(['stomach', 'stomach pain', 'stomach pain in', 'stomach pain in the lower abdomen']),
    'stomach pain in the lower abdomen');
  assert.strictEqual(V.collapseResults(['migraine', 'Migraine', 'back pain', 'nausea']), 'migraine back pain nausea');
});

test('personal fixes and word list correct notes', () => {
  const c = V.makeCorrector({ words: 'sevilla = Seville\nMetoclopramide\nhigh pertension = hypertension' });
  assert.strictEqual(c.text('Sevilla with high pertension'), 'Seville with hypertension');
  assert.strictEqual(c.text('give metoclopramid for nausea'), 'give Metoclopramide for nausea');
  assert.strictEqual(c.text('headache and nausea'), 'headache and nausea'); // ordinary words untouched
});

test('spoken medicine names snap to inventory items', () => {
  const c = V.makeCorrector({ drugs: ['Amoxicillin 500mg Capsule', 'Paracetamol 500mg Tablet'] });
  assert.strictEqual(c.drug('Amoxicilin'), 'Amoxicillin');
  assert.strictEqual(c.drug('Paracetamol'), 'Paracetamol');
  assert.strictEqual(c.drug('Zolpidem'), 'Zolpidem'); // not stocked: left as heard
});
