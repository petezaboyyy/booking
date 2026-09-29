import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

for (const file of ['modules/booking/admin.js', 'ui-handoff/modules/booking/admin.js']) {
  const source = readFileSync(new URL(`../${file}`, import.meta.url), 'utf8');
  const start = source.indexOf('function initBookingAccessoryFilter(');
  const end = source.indexOf('\nfunction initBookingForm(', start);
  assert(start >= 0 && end > start, `${file}: accessory filter exists`);

  const room = new EventTarget();
  room.value = '11';
  const select = {selected: ['1', '2'], options: [], disabled: false};
  const trigger = {disabled: false, classList: {toggle() {}}};
  const instance = {trigger};
  const factory = {
    updateOptions(element, options) {
      element.options = options;
      element.selected = element.selected.filter(value => options.some(option => option.value === value));
    },
    updateDisplay() {}
  };
  const browser = {Now: {getManager: () => ({getInstanceByElement: () => instance})}};
  const init = new Function('window', 'MultiSelectElementFactory', `${source.slice(start, end)}\nreturn initBookingAccessoryFilter;`)(browser, factory);
  const classes = new Set();
  const hint = {
    dataset: {i18n: 'Select required equipment'},
    textContent: 'Select required equipment',
    classList: {toggle: (name, on) => (on ? classes.add(name) : classes.delete(name))}
  };
  const form = {querySelector: selector => ({'#room_id': room, '#result_accessories': hint}[selector] || select)};
  const cleanup = init(form, {options: {
    room_id: [
      {value: '11', accessories: ['1', '3']},
      {value: '9', accessories: ['2']},
      {value: '10', accessories: []}
    ],
    accessories: [{value: '1'}, {value: '2'}, {value: '3'}]
  }});

  assert.deepEqual(select.options.map(option => option.value), ['1', '3']);
  assert.deepEqual(select.selected, ['1']);
  assert.equal(classes.has('is-warning'), false, `${file}: room with equipment keeps the normal hint`);
  room.value = '9';
  room.dispatchEvent(new Event('change'));
  assert.deepEqual(select.options.map(option => option.value), ['2']);
  assert.deepEqual(select.selected, []);
  room.value = '10';
  room.dispatchEvent(new Event('change'));
  assert.equal(select.options.length, 0);
  assert.equal(trigger.disabled, true);
  assert.equal(classes.has('is-warning'), true, `${file}: room without equipment shows the warning`);
  assert.equal(hint.dataset.i18n, 'No equipment available');
  room.value = '';
  room.dispatchEvent(new Event('change'));
  assert.equal(classes.has('is-warning'), false, `${file}: no room selected is not a warning`);
  assert.equal(hint.dataset.i18n, 'Select required equipment');
  cleanup();
}

console.log('Room equipment filter: PASS');
