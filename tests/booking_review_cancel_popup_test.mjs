/**
 * Cancelling one approved date on the booking review page.
 *
 * The per-date button used to take its reason from the decision note that
 * "Cancel by officer" also reads, so a note written for one date sat one
 * click away from cancelling the whole booking. It now asks in a popup of
 * its own. This drives that popup with the real DialogManager.
 *
 * Usage (needs the jsdom devDependency): node tests/booking_review_cancel_popup_test.mjs
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

// Run from the repository root, like calendar_course_code_test.mjs.
const ROOT = '';
const admin = fs.readFileSync(ROOT + 'ui-handoff/modules/booking/admin.js', 'utf8');
const start = admin.indexOf('/**\n * Popup that cancels one approved date');
const reviewAt = admin.indexOf('function initBookingReview(', start);
const end = admin.indexOf('\n}\n', reviewAt) + 3;
if (start < 0 || reviewAt < 0) throw new Error('could not find the popup code in admin.js');
const code = admin.slice(start, end);

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) fail++; };
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

function setup(occurrences, response = {data: {success: true, message: 'Booking date cancelled'}}) {
  const dom = new JSDOM(`<!doctype html><html><body>
    <div id="page"><div id="booking_review_occurrences"></div>
    <textarea id="approval_reason" name="approval_reason">PAGE NOTE FOR THE WHOLE BOOKING</textarea></div>
  </body></html>`, {runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost:8080/booking-review?id=7'});
  const w = dom.window;
  const calls = [];
  const errors = [];
  w.Now = {translate: v => v};
  w.EventManager = {emit() {}, on() {}, off() {}};
  w.NotificationManager = {error: m => errors.push(m), success() {}};
  w.httpAction = {post: async (url, params, opts, ctx) => { calls.push({url, params, ctx}); return response; }};
  w.eval(fs.readFileSync(ROOT + 'Now/js/DialogManager.js', 'utf8'));
  w.DialogManager.config.animation = false;
  w.DialogManager.config.duration = 0;
  w.eval(code + '\nwindow.initBookingReview = initBookingReview;');
  const page = w.document.getElementById('page');
  w.initBookingReview(page, {id: 7, version: 3, occurrences});
  return {w, page, calls, errors};
}
const openDialog = w => [...w.document.querySelectorAll('.dialog')]
  .find(d => d.isConnected && !d.hidden && d.getAttribute('aria-hidden') !== 'true');
const occ = (id, date, status = 1) => ({id, date, begin_time: '08:00', end_time: '12:00', status, status_text: status === 1 ? 'Approved' : 'Cancelled'});

// ---------------------------------------------------------------
console.log('\n[1] The cards look as before');
{
  const {page} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28'), occ(13, '2026-10-01', 4)]);
  const buttons = page.querySelectorAll('.booking-occurrence-cancel');
  check(buttons.length === 2, 'an approved date gets a button, a cancelled one does not (' + buttons.length + ')');
  check(buttons[0].textContent === 'Cancel this date', 'the button keeps its label');
  check(buttons[0].className.includes('btn-danger'), 'and its red style');
  check(!buttons[0].dataset.action && !buttons[0].dataset.paramApprovalReason,
    'it no longer reads the decision note below');
}

// ---------------------------------------------------------------
console.log('\n[2] Clicking opens a popup naming that date');
{
  const {w, page, calls} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28')]);
  page.querySelectorAll('.booking-occurrence-cancel')[1].click();
  await tick(5);
  const d = openDialog(w);
  check(!!d, 'a popup appears');
  check(d?.querySelector('.dialog-title')?.textContent === 'Cancel booking date', 'titled Cancel booking date');
  check(d?.textContent.includes('2026-09-28') && d?.textContent.includes('08:00-12:00'), 'it shows the date and time being cancelled');
  check(!d?.textContent.includes('2026-09-24'), 'and not the other date');
  check(d?.querySelector('.booking-occurrence-cancel-scope')?.textContent.startsWith('Only this date'), 'it says the other dates stay');
  check(!!d?.querySelector('textarea'), 'it has its own reason box');
  check(w.document.activeElement === d?.querySelector('textarea'), 'the reason box has focus');
  check(calls.length === 0, 'nothing is sent just by opening it');
}

// ---------------------------------------------------------------
console.log('\n[3] An empty reason keeps the popup open');
{
  const {w, page, calls} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28')]);
  page.querySelector('.booking-occurrence-cancel').click();
  await tick(5);
  const d = openDialog(w);
  d.querySelector('textarea').value = '   ';
  d.querySelector('[data-role="confirm"]').click();
  await tick(5);
  check(d.isConnected && openDialog(w) === d, 'the popup is still open');
  const err = d.querySelector('.error');
  check(err && !err.hidden && err.textContent === 'Please fill in', 'it says the reason is required');
  check(calls.length === 0, 'nothing is sent');
}

// ---------------------------------------------------------------
console.log('\n[4] Confirming sends only this date, with the popup reason');
{
  const {w, page, calls} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28')]);
  const btn = page.querySelectorAll('.booking-occurrence-cancel')[1];
  btn.click();
  await tick(5);
  const d = openDialog(w);
  d.querySelector('textarea').value = '  room needed for an exam  ';
  d.querySelector('[data-role="confirm"]').click();
  await tick(20);
  check(calls.length === 1, 'one request');
  const c = calls[0] || {params: {}};
  check(c.url === 'api/booking/review/canceloccurrence', 'to the per-date endpoint, not cancelofficer');
  check(c.params.id === 7 && c.params.occurrence_id === 12 && c.params.version === 3, 'for booking 7, date 12, version 3');
  check(c.params.approval_reason === 'room needed for an exam', 'with the popup reason, trimmed');
  check(!JSON.stringify(c.params).includes('PAGE NOTE'), 'the decision note on the page is not used');
  check(!openDialog(w), 'the popup closes');
  check(!btn.disabled && btn.textContent === 'Cancel this date', 'the button is back to normal afterwards');
}

// ---------------------------------------------------------------
console.log('\n[5] The last approved date warns that the booking goes with it');
{
  const {w, page} = setup([occ(11, '2026-09-24', 4), occ(12, '2026-09-28')]);
  page.querySelector('.booking-occurrence-cancel').click();
  await tick(5);
  const scope = openDialog(w)?.querySelector('.booking-occurrence-cancel-scope');
  check(scope?.classList.contains('is-last'), 'the warning is styled as a warning');
  check(scope?.textContent.includes('whole booking will be cancelled'), 'it says the whole booking will be cancelled');
}

// ---------------------------------------------------------------
console.log('\n[6] Close backs out');
{
  const {w, page, calls} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28')]);
  page.querySelector('.booking-occurrence-cancel').click();
  await tick(5);
  const d = openDialog(w);
  d.querySelector('textarea').value = 'typed then changed my mind';
  [...d.querySelectorAll('.dialog-footer button')].find(b => b.textContent === 'Close').click();
  await tick(5);
  check(!openDialog(w), 'the popup closes');
  check(calls.length === 0, 'nothing is sent');
}

// ---------------------------------------------------------------
console.log('\n[7] A refusal from the server is shown');
{
  const {w, page, errors} = setup([occ(11, '2026-09-24'), occ(12, '2026-09-28')],
    {data: {success: false, message: 'No data available'}});
  const btn = page.querySelector('.booking-occurrence-cancel');
  btn.click();
  await tick(5);
  const d = openDialog(w);
  d.querySelector('textarea').value = 'x';
  d.querySelector('[data-role="confirm"]').click();
  await tick(20);
  check(errors.includes('No data available'), 'the server message is shown as an error');
  check(!btn.disabled, 'the button can be used again');
}

console.log(fail === 0 ? '\nALL PASS' : `\n${fail} FAILURES`);
process.exit(fail ? 1 : 0);
