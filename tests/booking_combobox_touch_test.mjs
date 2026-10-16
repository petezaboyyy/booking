/**
 * The booking form's dropdown lists (room, topic) on a phone.
 *
 * A finger landing on a row is the start of a swipe as often as it is a tap. The
 * rows used to select on pointerdown, so scrolling the list picked whichever room
 * was under the finger. They now select on click only (a click is only sent for a
 * tap), and the list is styled to take touch scrolling without scrolling the page.
 *
 * Usage (needs the jsdom devDependency): node tests/booking_combobox_touch_test.mjs
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const admin = fs.readFileSync('ui-handoff/modules/booking/admin.js', 'utf8');
const start = admin.indexOf('function createRoomlyCombobox(');
const end = admin.indexOf('// Repeat field:');
if (start < 0 || end < 0) throw new Error('could not find the combobox code in admin.js');
const code = admin.slice(start, end);

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) fail++; };

const dom = new JSDOM(`<!doctype html><html><body>
  <div class="roomly-field-wrap"><div class="roomly-select-btn">
    <select id="room_id"><option value="">-</option><option value="1">Room A</option><option value="2">Room B</option><option value="3">Room C</option></select>
  </div></div></body></html>`, {runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost:8080/booking'});
const w = dom.window;
w.Now = {translate: v => v};
w.eval(code + '\nwindow.createRoomlyCombobox = createRoomlyCombobox;');

const select = w.document.getElementById('room_id');
const picked = [];
const box = w.createRoomlyCombobox(select, {onSelect: v => picked.push(v)});
const fire = (el, type) => el.dispatchEvent(new w.Event(type, {bubbles: true, cancelable: true}));

console.log('\n[1] Touching a row does not choose it');
box.open('');
const dropdown = w.document.querySelector('.roomly-combobox-dropdown');
let items = dropdown.querySelectorAll('.roomly-combobox-item');
check(items.length === 3, 'the list shows the three rooms');
for (const type of ['touchstart', 'pointerdown', 'mousedown', 'pointermove', 'touchmove']) {
  fire(items[1], type);
}
check(picked.length === 0 && select.value === '', 'a finger landing and moving over a row chooses nothing');
check(dropdown.classList.contains('is-open'), 'and the list stays open to be scrolled');

console.log('\n[2] A tap (click) chooses it');
fire(items[1], 'click');
check(picked.join() === '2' && select.value === '2', 'a click on "Room B" chooses it');
check(!dropdown.classList.contains('is-open'), 'and closes the list');

console.log('\n[3] Scrolling the list does not re-place it');
box.open('');
dropdown.style.top = '123px';
const scroll = new w.Event('scroll', {bubbles: false});
dropdown.dispatchEvent(scroll);
check(dropdown.style.top === '123px', 'a scroll inside the list leaves it where it is');

console.log('\n[4] The list takes touch scrolling');
const css = fs.readFileSync('templates/booking/style.css', 'utf8');
const rule = css.slice(css.indexOf('.roomly-combobox-dropdown {'));
const block = rule.slice(0, rule.indexOf('}'));
check(/overflow-y:\s*auto/.test(block), 'it scrolls vertically');
check(/touch-action:\s*pan-y/.test(block), 'a vertical finger drag is a scroll');
check(/overscroll-behavior:\s*contain/.test(block), 'it does not scroll the page behind it');

console.log(fail === 0 ? '\nALL PASS' : `\n${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
