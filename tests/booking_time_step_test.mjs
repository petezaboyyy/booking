/**
 * The booking forms' start and end time are picked on iPhone-style wheels.
 *
 *  - times are on a 5-minute step; the wheels turn round without end (past 23
 *    comes 00, past 55 comes 00) by repeating their values and returning to the
 *    middle copy whenever they come to rest;
 *  - the field behind the wheels holds the value: it is hidden, filled from the
 *    loaded booking, and written only when the user actually chose something;
 *  - a stored time off the step (08:07, from before the rule) still shows.
 *
 * Usage (needs the jsdom devDependency): node tests/booking_time_step_test.mjs
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const admin = fs.readFileSync('ui-handoff/modules/booking/admin.js', 'utf8');
const start = admin.indexOf('/**\n * Force booking time fields to use a locale-independent 24-hour display.');
const end = admin.indexOf('const refreshBookingTime24Inputs');
if (start < 0 || end < 0) throw new Error('could not find the time-field code in admin.js');
const code = admin.slice(start, end);

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) fail++; };
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

const fieldHtml = id => `<span class="form-control"><input type="text" id="${id}" name="${id}" data-time-wheel autocomplete="off"></span>`;

function setup(html) {
  const dom = new JSDOM(`<!doctype html><html><body><form id="f">${html}</form></body></html>`, {runScripts: 'outside-only', url: 'http://localhost:8080/booking'});
  const w = dom.window;
  w.Now = {translate: v => v};
  w.eval(code + `
    window.initBookingTime24Inputs = initBookingTime24Inputs; window.initBookingTimeWheel = initBookingTimeWheel;
    window.syncBookingTimeFields = syncBookingTimeFields; window.BOOKING_TIME_PATTERN = BOOKING_TIME_PATTERN;
    window.BOOKING_TIME_MINUTES = BOOKING_TIME_MINUTES; window.BOOKING_TIME_HOURS = BOOKING_TIME_HOURS;
    window.BOOKING_TIME_STEP_MINUTES = BOOKING_TIME_STEP_MINUTES;`);
  return {w, form: w.document.getElementById('f'), $: id => w.document.getElementById(id)};
}

console.log('\n[1] The step');
{
  const {w} = setup('');
  check(w.BOOKING_TIME_STEP_MINUTES === 5, 'the step is 5 minutes');
  check(w.BOOKING_TIME_MINUTES.join() === '00,05,10,15,20,25,30,35,40,45,50,55', 'the minute wheel offers 00, 05 ... 55');
  check(w.BOOKING_TIME_HOURS.length === 24 && w.BOOKING_TIME_HOURS[0] === '00' && w.BOOKING_TIME_HOURS[23] === '23', 'the hour wheel offers 00 to 23');
  const p = w.BOOKING_TIME_PATTERN;
  check(['00:00', '08:05', '08:30', '12:45', '23:55'].every(t => p.test(t)), 'times on the step are valid');
  check(['08:01', '08:07', '08:59', '08:12', '24:00', '8:30', '08:3', '', '08:30:00'].every(t => !p.test(t)), 'anything else is not');
}

console.log('\n[2] Turning round: how many rows, and where the wheel stands');
{
  const {w} = setup('');
  const f = w.eval('({copies: timeWheelCopies, centre: timeWheelCentreRow, index: timeWheelIndex, start: timeWheelStart})');
  for (const count of [24, 12, 6, 2, 60]) {
    const copies = f.copies(count);
    check(copies % 2 === 1 && copies >= 3 && copies * count >= 192, `${count} values: ${copies} copies (${copies * count} rows), an odd number so there is a middle one`);
  }
  check(f.centre(7, 24) % 24 === 7 && Math.floor(f.centre(7, 24) / 24) === Math.floor(f.copies(24) / 2), 'a value is shown on the middle copy');
  check(f.index(0, 216) === 0 && f.index(40, 216) === 1 && f.index(800, 216) === 20, 'a row is one item height tall');
  check(f.index(59, 216) === 1 && f.index(61, 216) === 2, 'the nearest row wins (snaps at the half way point)');
  check(f.index(-30, 216) === 0 && f.index(999999, 216) === 215, 'scrolling past either end stays on the first or last row');
  check(JSON.stringify(f.start('14:30')) === '{"hour":14,"minuteIndex":6}' && JSON.stringify(f.start('09:00')) === '{"hour":9,"minuteIndex":0}', 'the wheels open on the stored time');
  check(JSON.stringify(f.start('')) === '{"hour":8,"minuteIndex":0}' && JSON.stringify(f.start(null)) === '{"hour":8,"minuteIndex":0}', 'an empty field opens on 08:00');
  check(JSON.stringify(f.start('08:07')) === '{"hour":8,"minuteIndex":1}' && JSON.stringify(f.start('08:04')) === '{"hour":8,"minuteIndex":0}' && JSON.stringify(f.start('23:59')) === '{"hour":23,"minuteIndex":11}', 'a time off the step opens on the step below it');
}

console.log('\n[3] The field and its button');
{
  const t = setup(`<label for="begin_time">Start time</label>${fieldHtml('begin_time')}${fieldHtml('end_time')}`);
  t.w.initBookingTime24Inputs(t.form);
  const field = t.$('begin_time');
  const trigger = field.closest('.form-control').querySelector('.booking-time-trigger');
  check(field.type === 'text' && field.dataset.time24MaskBound === undefined, 'the field is not given the old typing mask');
  check(field.readOnly && field.tabIndex === -1 && field.getAttribute('aria-hidden') === 'true', 'it only holds the value: not typed into, not a tab stop, hidden from screen readers');
  check(!!trigger && trigger.type === 'button' && trigger.textContent === 'Select time' && trigger.classList.contains('is-empty'), 'a button stands in for it and asks for a time');
  field.value = '09:35';
  check(trigger.textContent === '09:35' && !trigger.classList.contains('is-empty'), 'the button follows the field when data binding sets the value from code');
  t.w.initBookingTimeWheel(field);
  check(t.w.document.querySelectorAll('#f .booking-time-trigger').length === 2, 'initialising again does not add a second button');
  field.dispatchEvent(new t.w.Event('focus'));
  check(t.w.document.activeElement === trigger && trigger.getAttribute('aria-labelledby') === 'begin_time_label', 'clicking the label lands on the button, which is labelled by it');
  const legacy = setup(fieldHtml('begin_time'));
  legacy.$('begin_time').value = '08:07';
  legacy.w.syncBookingTimeFields(legacy.form);
  const lt = legacy.$('begin_time').closest('.form-control').querySelector('.booking-time-trigger');
  check(lt.textContent === '08:07' && lt.classList.contains('is-off-grid'), 'a stored 08:07 is shown, flagged as off the step');
  const typed = setup('<input type="text" id="begin_time">');
  typed.w.initBookingTime24Inputs(typed.form);
  check(typed.$('begin_time').dataset.time24MaskBound === 'true', 'a plain text time field (if one is ever used) is still masked as before');
}

function setupWheels(value = '') {
  const t = setup(`<label for="begin_time">Start time</label>${fieldHtml('begin_time')}<label for="end_time">End time</label>${fieldHtml('end_time')}`);
  if (value) t.$('begin_time').value = value;
  t.w.syncBookingTimeFields(t.form);
  const events = {input: 0, change: 0};
  t.$('begin_time').addEventListener('input', () => events.input++);
  t.$('begin_time').addEventListener('change', () => events.change++);
  const q = (sel, root = t.w.document) => root.querySelector(sel);
  const trigger = id => q(`#${id}`).closest('.form-control').querySelector('.booking-time-trigger');
  const panels = () => t.w.document.querySelectorAll('.booking-time-wheel');
  const selected = key => q(`.booking-time-wheel-col[data-wheel="${key}"] .is-selected`)?.textContent;
  const selectedRow = key => Number(q(`.booking-time-wheel-col[data-wheel="${key}"] .is-selected`)?.dataset.row);
  const rows = key => Array.from(t.w.document.querySelectorAll(`.booking-time-wheel-col[data-wheel="${key}"] .booking-time-wheel-item`));
  const item = (key, text, copy = 0) => rows(key).filter(i => i.textContent === text)[copy];
  const press = (key, name) => q(`.booking-time-wheel-col[data-wheel="${key}"]`).dispatchEvent(new t.w.KeyboardEvent('keydown', {key: name, bubbles: true}));
  return {...t, events, q, trigger, panels, selected, selectedRow, rows, item, press};
}

console.log('\n[4] Opening, choosing and confirming');
{
  const t = setupWheels('14:30');
  t.trigger('begin_time').click();
  check(t.panels().length === 1 && t.trigger('begin_time').getAttribute('aria-expanded') === 'true', 'pressing the button opens the wheels');
  const hourRows = t.rows('hour'), minuteRows = t.rows('minute');
  check(hourRows.length % 24 === 0 && hourRows.length >= 192, `the hour wheel repeats its 24 values (${hourRows.length} rows) so it can turn round`);
  check(minuteRows.length % 12 === 0 && minuteRows.length >= 192 && new Set(minuteRows.map(r => r.textContent)).size === 12, `the minute wheel repeats its 12 values (${minuteRows.length} rows)`);
  check(t.selected('hour') === '14' && t.selected('minute') === '30', 'both wheels open on the stored time');
  check(t.selectedRow('hour') === Math.floor(hourRows.length / 24 / 2) * 24 + 14, 'on the middle copy');

  t.item('hour', '16').click();
  t.item('minute', '35').click();
  check(t.selected('hour') === '16' && t.selected('minute') === '35', 'tapping a row moves the wheel to it');
  check(t.$('begin_time').value === '14:30' && t.events.change === 0, 'nothing is written to the field while the wheels are still moving');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '16:35', 'Done writes the chosen time to the field');
  check(t.events.input === 1 && t.events.change === 1, 'and tells the page once (input and change), so the summary and room check update');
  check(t.panels().length === 0 && t.trigger('begin_time').getAttribute('aria-expanded') === 'false' && t.trigger('begin_time').textContent === '16:35', 'the wheels close and the button shows the new time');
  check(t.q('.booking-time-wheel-layer') === null, 'nothing is left in the page');
}

console.log('\n[5] Turning round without end');
{
  let t = setupWheels('23:55');
  t.trigger('begin_time').click();
  t.press('hour', 'ArrowDown');
  check(t.selected('hour') === '00', 'past 23 comes 00');
  t.press('hour', 'ArrowUp');
  t.press('hour', 'ArrowUp');
  check(t.selected('hour') === '22', 'and back round again: up from 00 is 23, then 22');
  t.press('minute', 'ArrowDown');
  check(t.selected('minute') === '00' && t.selected('hour') === '22', 'past 55 the minutes come to 00 (the hour stays as it is)');
  t.press('minute', 'ArrowUp');
  check(t.selected('minute') === '55', 'and up from 00 is 55');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '22:55', 'a time reached by turning round is confirmed like any other');

  t = setupWheels('00:00');
  t.trigger('begin_time').click();
  t.press('hour', 'ArrowUp');
  check(t.selected('hour') === '23', 'up from 00 is 23');
  for (let i = 0; i < 30; i++) t.press('hour', 'ArrowDown');
  check(t.selected('hour') === '05', 'a lap and a bit later it is still counting correctly (23 + 30 steps = 05)');
  t.press('minute', 'PageDown');
  check(t.selected('minute') === '15', 'Page Down moves the minutes three rows (15)');
  t.press('minute', 'End');
  check(t.selected('minute') === '55', 'End goes to the last value of the turn (55)');
  t.press('minute', 'Home');
  check(t.selected('minute') === '00', 'Home to the first (00)');

  // A scroll that comes to rest in the last copy of the rows is put back on the middle copy, showing the same value.
  t = setupWheels('08:00');
  t.trigger('begin_time').click();
  const col = t.q('.booking-time-wheel-col[data-wheel="hour"]');
  const rowCount = t.rows('hour').length;
  const middle = Math.floor(rowCount / 24 / 2) * 24;
  Object.defineProperty(col, 'scrollTop', {value: (rowCount - 24 + 7) * 40 + 6, configurable: true, writable: true});
  col.dispatchEvent(new t.w.Event('scroll'));
  await tick(130);
  check(t.selected('hour') === '07', 'a hard flick that rests in the last copy selects the right hour (07)');
  check(t.selectedRow('hour') === middle + 7 && col.scrollTop === (middle + 7) * 40, 'and the wheel is put back on the middle copy showing 07, so it can keep turning either way');
  Object.defineProperty(col, 'scrollTop', {value: 3 * 40, configurable: true, writable: true});
  col.dispatchEvent(new t.w.Event('scroll'));
  await tick(130);
  check(t.selected('hour') === '03' && t.selectedRow('hour') === middle + 3, 'the same from the first copy (03)');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '03:00', 'and that is what is confirmed');
}

console.log('\n[6] Ways to close');
{
  let t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.item('hour', '11').click();
  t.q('.booking-time-wheel-cancel').click();
  check(t.$('begin_time').value === '09:00' && t.events.change === 0 && t.panels().length === 0, 'Cancel closes and leaves the time alone');

  t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.item('hour', '11').click();
  t.w.document.dispatchEvent(new t.w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
  check(t.$('begin_time').value === '09:00' && t.panels().length === 0, 'Escape does the same');

  t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.item('minute', '45').click();
  t.q('.booking-time-wheel-backdrop').click();
  check(t.$('begin_time').value === '09:45' && t.panels().length === 0, 'tapping outside keeps what was chosen, like closing a native picker');

  t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.item('hour', '12').click();
  t.w.document.dispatchEvent(new t.w.KeyboardEvent('keydown', {key: 'Enter', bubbles: true}));
  check(t.$('begin_time').value === '12:00' && t.panels().length === 0, 'Enter confirms');

  t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '09:00' && t.events.change === 0, 'confirming without moving a wheel changes nothing and fires nothing');
}

console.log('\n[7] Empty fields, stored times off the step, and one open at a time');
{
  let t = setupWheels('');
  t.trigger('begin_time').click();
  check(t.selected('hour') === '08' && t.selected('minute') === '00', 'an empty field opens on 08:00');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '', 'but leaving without choosing does not fill it in');
  t.trigger('begin_time').click();
  t.item('hour', '08').click();
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '08:00', 'tapping 08 chooses 08:00, even though the wheel was already on it');

  t = setupWheels('08:07');
  t.trigger('begin_time').click();
  check(t.selected('hour') === '08' && t.selected('minute') === '05', 'a stored 08:07 opens on 08:05');
  t.q('.booking-time-wheel-backdrop').click();
  check(t.$('begin_time').value === '08:07' && t.events.change === 0, 'and closing without moving leaves the stored time as it is (not silently rewritten)');
  t.trigger('begin_time').click();
  t.item('minute', '10').click();
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '08:10' && t.trigger('begin_time').classList.contains('is-off-grid') === false, 'choosing a time replaces it, and the warning goes');

  t = setupWheels('09:00');
  t.trigger('begin_time').click();
  t.item('hour', '10').click();
  t.w.dispatchEvent(new t.w.Event('resize'));
  t.w.document.dispatchEvent(new t.w.Event('scroll'));
  check(t.panels().length === 1 && t.selected('hour') === '10', 'the window resizing or the page scrolling (a phone\'s address bar or keyboard) moves the panel but never closes it');
  t.trigger('end_time').click();
  check(t.panels().length === 1 && t.$('begin_time').value === '10:00', 'opening another field closes the first (keeping its choice): only one set of wheels at a time');
}

console.log('\n[8] Dragging a wheel with the mouse');
{
  // 08:00 opens on the middle copy: 4 copies of 24 rows, then 8 more rows, 40px each.
  const START = (4 * 24 + 8) * 40;
  const open = value => {
    const t = setupWheels(value);
    t.trigger('begin_time').click();
    const col = t.q('.booking-time-wheel-col[data-wheel="hour"]');
    Object.defineProperty(col, 'scrollTop', {value: START, configurable: true, writable: true});
    const ptr = (target, type, y, ts, extra = {}) => {
      const e = new t.w.MouseEvent(type, {bubbles: true, cancelable: true, clientY: y, button: extra.button ?? 0});
      Object.defineProperty(e, 'pointerType', {value: extra.pointerType ?? 'mouse'});
      Object.defineProperty(e, 'pointerId', {value: 1});
      Object.defineProperty(e, 'timeStamp', {value: ts});
      target.dispatchEvent(e);
    };
    const scrolled = () => col.dispatchEvent(new t.w.Event('scroll'));
    return {...t, col, ptr, scrolled};
  };

  let t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0);
  t.ptr(t.col, 'pointermove', 100, 400);
  check(t.col.classList.contains('is-dragging') && t.col.scrollTop === START + 200, 'dragging up 200px turns the wheel by 200px (five rows)');
  t.scrolled();
  check(t.selected('hour') === '13', 'the highlight follows the wheel while it turns (08 + 5 = 13)');
  check(t.$('begin_time').value === '' || t.$('begin_time').value === '08:00', 'and nothing is written to the field yet');
  t.ptr(t.col, 'pointerup', 100, 900);
  check(!t.col.classList.contains('is-dragging') && t.selected('hour') === '13' && t.col.scrollTop === (START + 200), 'letting go after holding still settles on that row, with no glide');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '13:00', 'and the dragged-to time is confirmed like any other');

  t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0);
  t.ptr(t.col, 'pointermove', 380, 100);
  t.ptr(t.col, 'pointerup', 380, 110);
  check(t.selected('hour') === '02', 'letting go while still moving carries on a little in that direction (down 80px at 0.8px/ms: 8 - 6 = 02)');

  t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0);
  t.ptr(t.col, 'pointermove', 100, 400);
  t.ptr(t.col, 'pointerup', 100, 900);
  t.item('hour', '05').click();
  check(t.selected('hour') === '13', 'the click that follows a drag does not also pick the row under the pointer');
  await tick(5);
  t.item('hour', '05').click();
  check(t.selected('hour') === '05', 'but the next ordinary click does');

  t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0);
  t.ptr(t.col, 'pointermove', 302, 20);
  t.ptr(t.col, 'pointerup', 302, 40);
  check(!t.col.classList.contains('is-dragging') && t.col.scrollTop === START, 'a press that barely moves is a click, not a drag');
  t.item('hour', '11').click();
  check(t.selected('hour') === '11', 'and the click picks its row');

  t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0, {pointerType: 'touch'});
  t.ptr(t.col, 'pointermove', 100, 400, {pointerType: 'touch'});
  t.ptr(t.col, 'pointerup', 100, 900, {pointerType: 'touch'});
  check(t.col.scrollTop === START && !t.col.classList.contains('is-dragging'), 'a finger is left to the browser\'s own scrolling');
  t.ptr(t.col, 'pointerdown', 300, 0, {button: 2});
  t.ptr(t.col, 'pointermove', 100, 400, {button: 2});
  t.ptr(t.col, 'pointerup', 100, 900, {button: 2});
  check(t.col.scrollTop === START + 0 || t.col.scrollTop === START, 'and so is the right mouse button');

  t = open('08:00');
  t.ptr(t.col, 'pointerdown', 300, 0);
  t.ptr(t.col, 'pointermove', 300 - 2000, 800);
  t.ptr(t.col, 'pointerup', 300 - 2000, 1400);
  check(t.selected('hour') === '10', 'a long drag over two laps and a bit still lands on the right hour (08 + 50 = 10)');
  t.q('.booking-time-wheel-done').click();
  check(t.$('begin_time').value === '10:00', 'and is confirmed');
}

console.log(fail === 0 ? '\nALL PASS' : `\n${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
