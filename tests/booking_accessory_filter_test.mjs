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
  const form = {querySelector: selector => selector === '#room_id' ? room : select};
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
  room.value = '9';
  room.dispatchEvent(new Event('change'));
  assert.deepEqual(select.options.map(option => option.value), ['2']);
  assert.deepEqual(select.selected, []);
  room.value = '10';
  room.dispatchEvent(new Event('change'));
  assert.equal(select.options.length, 0);
  assert.equal(trigger.disabled, true);
  cleanup();
}

console.log('Room equipment filter: PASS');
