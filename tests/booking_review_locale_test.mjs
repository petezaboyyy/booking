/**
 * The review page follows a language change.
 *
 * Score reasons, dates and topic names are written by the server in the language
 * it was asked for, so switching language must fetch the review data again and
 * redraw, not wait for a page refresh. The suggested rejection reason follows the
 * language too, until the approver has changed it.
 *
 * Usage (needs the jsdom devDependency): node tests/booking_review_locale_test.mjs
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const admin = fs.readFileSync('ui-handoff/modules/booking/admin.js', 'utf8');
const start = admin.indexOf('/**\n * "Competing requests" on the review page');
if (start < 0) throw new Error('could not find the review code in admin.js');
const code = admin.slice(start);

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) fail++; };
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

const row = (id, rank, extra = {}) => ({
  id, rank, is_current: id === 2, member_name: `Person ${id}`, created_at: '2037-01-01 08:00:00', topic: 'T',
  attendees: 5, reservation_text: '', overlap_dates: 1, score: 10, reasons: ['r'], ...extra
});
const payload = (reason, name) => ({
  id: 2, version: 1, occurrences: [],
  competition: {seats: 20, items: [row(2, 1, {reasons: [reason]}), row(3, 2, {member_name: name, reasons: [reason]})]},
  blocked: null, clashing: [], canRejectClashing: false
});

function setup() {
  const dom = new JSDOM(`<!doctype html><html lang="th"><body><div id="page">
    <fieldset id="booking_review_competition" hidden><span id="booking_review_competition_count"></span><div id="booking_review_competition_list"></div></fieldset>
    <div id="booking_review_occurrences"></div>
    <fieldset id="booking_review_clashing" hidden><span id="booking_review_clashing_count"></span><div id="booking_review_clashing_list"></div>
      <textarea id="booking_review_clashing_reason"></textarea><button id="booking_review_clashing_button"></button></fieldset>
  </div></body></html>`, {runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost:8080/booking-review?id=2'});
  const w = dom.window;
  const listeners = {};
  w.EventManager = {
    on: (name, fn) => { (listeners[name] ||= new Set()).add(fn); },
    off: (name, fn) => { listeners[name]?.delete(fn); }
  };
  const lang = {th: 'ยื่นคำขอก่อน', en: 'Submitted first'};
  const fetched = [];
  w.fetch = async (url) => {
    fetched.push(String(url));
    const code = new URL(url, 'http://localhost:8080/').searchParams.get('lang');
    return {ok: true, json: async () => ({success: true, data: payload(lang[code], `คนที่ 3 (${code})`)})};
  };
  w.Now = {translate: v => (w.document.documentElement.lang === 'en' ? v : `th:${v}`)};
  w.eval(code + '\nwindow.initBookingReview = initBookingReview;');
  const page = w.document.getElementById('page');
  const cleanup = w.initBookingReview(page, payload(lang.th, 'คนที่ 3 (th)'));
  const emit = name => [...(listeners[name] || [])].forEach(fn => fn({locale: w.document.documentElement.lang}));
  return {w, page, fetched, listeners, cleanup, emit, $: sel => page.querySelector(sel)};
}

console.log('\n[1] Changing language redraws the page in the new language');
{
  const t = setup();
  const reasons = () => [...t.page.querySelectorAll('.booking-competition-reasons li')].map(li => li.textContent);
  check(reasons()[0] === 'ยื่นคำขอก่อน', 'the page starts in Thai');
  check(t.fetched.length === 0, 'and has fetched nothing more');

  t.w.document.documentElement.lang = 'en';
  t.emit('locale:changed');
  t.emit('i18n:updated');
  await tick(400);
  check(t.fetched.length === 1, 'locale:changed and i18n:updated together fetch once');
  check(t.fetched[0].includes('api/booking/review/get?id=2') && t.fetched[0].includes('lang=en'), 'the review is fetched again, asking for the new language');
  check(reasons()[0] === 'Submitted first', 'the server-written reasons show in the new language without a refresh');
  check(t.page.querySelector('.booking-competition-item:nth-child(2) strong').textContent.includes('(en)'), 'and so does the rest of the list');
  check(t.$('#booking_review_competition_list').children.length === 2, 'the list is redrawn, not added to');

  t.cleanup();
  const before = t.fetched.length;
  t.emit('locale:changed');
  await tick(400);
  check(t.fetched.length === before, 'once the page is left, a language change fetches nothing');
  check((t.listeners['locale:changed']?.size || 0) === 0 && (t.listeners['i18n:updated']?.size || 0) === 0, 'and its listeners are gone');
}

console.log('\n[2] The suggested rejection reason follows the language, unless edited');
{
  const clash = {id: 7, member_name: 'x', created_at: '2037-01-01', reservation_text: '', overlap_dates: 1};
  const w = setup().w;
  const render = (box, lng) => {
    w.document.documentElement.lang = lng;
    w.eval('window.renderReviewClashing = renderReviewClashing');
    w.renderReviewClashing(w.document.getElementById('page'), {id: 4, canRejectClashing: true, clashing: [clash]});
  };
  const box = w.document.getElementById('booking_review_clashing_reason');
  render(box, 'th');
  check(box.value === 'th:The room was approved for another request at the same time', 'the suggestion starts in Thai');
  render(box, 'en');
  check(box.value === 'The room was approved for another request at the same time', 'it turns English with the language');
  box.value = 'ห้องถูกใช้แล้ว';
  render(box, 'th');
  check(box.value === 'ห้องถูกใช้แล้ว', 'but what the approver wrote is kept');
}

console.log(fail === 0 ? '\nALL PASS' : `\n${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
