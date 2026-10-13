/**
 * "Competing requests" on the booking review page.
 *
 * Lists the other pending requests for the same room at overlapping times in
 * the server's order, each with the reasons behind its score. The order is
 * advice for the approver; nothing here approves or rejects anything.
 *
 * Usage (needs the jsdom devDependency): node tests/booking_review_competition_test.mjs
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const admin = fs.readFileSync('ui-handoff/modules/booking/admin.js', 'utf8');
const start = admin.indexOf('/**\n * "Competing requests" on the review page');
const end = admin.indexOf('function initBookingReview(', start);
if (start < 0 || end < 0) throw new Error('could not find the competition code in admin.js');
const code = admin.slice(start, end);

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : '  FAIL ') + msg); if (!ok) fail++; };

const item = (id, rank, extra = {}) => ({
  id, rank, is_current: false, member_name: `Person ${id}`, created_at: `2037-01-01 0${id}:00:00`,
  topic: 'ประชุม', attendees: 20, reservation_text: '4 พ.ค. 09:00-11:00', overlap_dates: 1,
  score: 60 - rank * 10, reasons: ['Submitted first', 'Seats used: 20/50'], submitted_order: id, ...extra
});

function setup(data) {
  const dom = new JSDOM(`<!doctype html><html><body><div id="page">
    <fieldset id="booking_review_competition" hidden>
      <span id="booking_review_competition_count">0</span>
      <div id="booking_review_competition_list"></div>
    </fieldset></div></body></html>`, {runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost:8080/booking-review?id=1'});
  const w = dom.window;
  w.Now = {translate: v => v};
  w.eval(code + '\nwindow.renderReviewCompetition = renderReviewCompetition;');
  const page = w.document.getElementById('page');
  w.renderReviewCompetition(page, data);
  return {w, page, $: sel => page.querySelector(sel)};
}

const group = () => ({
  id: 2,
  competition: {seats: 50, items: [item(3, 1), item(2, 2, {is_current: true}), item(1, 3)]}
});

console.log('\n[1] Nothing to compare');
{
  const {$} = setup({id: 1, competition: {seats: 50, items: []}});
  check($('#booking_review_competition').hidden === true, 'no competing requests: the section stays hidden');
  const one = setup({id: 1, competition: {seats: 50, items: [item(1, 1, {is_current: true})]}});
  check(one.$('#booking_review_competition').hidden === true, 'a request on its own has nothing to compare either');
  const missing = setup({id: 1});
  check(missing.$('#booking_review_competition').hidden === true, 'a review payload without the field is fine');
}

console.log('\n[2] The group is listed in the server\'s order');
{
  const {$, page} = setup(group());
  const rows = [...page.querySelectorAll('.booking-competition-item')];
  check($('#booking_review_competition').hidden === false, 'the section is shown');
  check($('#booking_review_competition_count').textContent === '3', 'with the number of requests');
  check(rows.map(r => r.querySelector('.booking-competition-rank').textContent).join() === '#1,#2,#3', 'rows follow the rank');
  check(rows[1].classList.contains('is-current') && !!rows[1].querySelector('.booking-competition-tag'), 'the request being reviewed is marked');
  check(rows[0].querySelector('a')?.getAttribute('href') === '/booking-review?id=3', 'another request links to its own review page');
  check(!rows[1].querySelector('a'), 'the one being reviewed does not link to itself');
  check(rows[0].querySelectorAll('.booking-competition-reasons li').length === 2, 'each row lists the reasons for its score');
  check(rows[0].querySelector('.booking-competition-score').textContent.includes('50'), 'and shows the score');
  check(rows[0].textContent.includes('20/50'), 'attendees are shown against the room\'s seats');
}

console.log('\n[3] Names are text, not markup');
{
  const data = group();
  data.competition.items[0].member_name = '<img src=x onerror="window.pwned=1">';
  data.competition.items[0].reasons = ['<b>bold</b>'];
  const {page, w} = setup(data);
  check(!page.querySelector('img') && !page.querySelector('.booking-competition-reasons b'), 'no element is created from request data');
  check(page.textContent.includes('<img src=x'), 'it is displayed as the text it is');
  check(w.pwned === undefined, 'and nothing ran');
}

console.log('\n[4] The rules are all there is: nothing else is offered');
{
  const {page} = setup(group());
  check(!page.querySelector('button'), 'the section has no buttons, so there is nothing to click that sends data anywhere');
}

// ---------------------------------------------------------------------------
// A request whose room an approved booking holds, and the requests it left behind
// ---------------------------------------------------------------------------
const tick = (ms = 0) => new Promise(r => setTimeout(r, ms));

function setupClash(data, {confirmAnswer = true, response = {data: {success: true, message: 'ok'}}} = {}) {
  const dom = new JSDOM(`<!doctype html><html><body><div id="page">
    <fieldset id="booking_review_blocked" hidden><div id="booking_review_blocked_body"></div></fieldset>
    <fieldset id="booking_review_clashing" hidden>
      <span id="booking_review_clashing_count">0</span>
      <div id="booking_review_clashing_list"></div>
      <textarea id="booking_review_clashing_reason"></textarea>
      <button type="button" id="booking_review_clashing_button"></button>
    </fieldset></div></body></html>`, {runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost:8080/booking-review?id=1'});
  const w = dom.window;
  const calls = [];
  const errors = [];
  const questions = [];
  w.Now = {translate: v => v};
  w.NotificationManager = {error: m => errors.push(m)};
  w.DialogManager = {confirm: async (q, title) => { questions.push({q, title}); return confirmAnswer; }};
  w.httpAction = {post: async (url, params) => { calls.push({url, params}); return response; }};
  w.eval(code + '\nwindow.renderReviewBlocked = renderReviewBlocked; window.renderReviewClashing = renderReviewClashing;');
  const page = w.document.getElementById('page');
  w.renderReviewBlocked(page, data);
  w.renderReviewClashing(page, data);
  return {w, page, $: sel => page.querySelector(sel), calls, errors, questions};
}

const clash = (id, extra = {}) => ({id, member_name: `Loser ${id}`, created_at: '2037-02-01 09:00:00', reservation_text: '1 ก.ค. 09:00-11:00', overlap_dates: 1, ...extra});

console.log('\n[5] A request that lost the room is told so');
{
  const lost = setupClash({id: 9, blocked: {conflicts: [{date: '2037-07-01', begin_time: '09:00', end_time: '11:00', approved_begin_time: '09:00', approved_end_time: '11:00', reservation_id: 4}], approved_ids: [4]}});
  check(lost.$('#booking_review_blocked').hidden === false, 'the notice is shown');
  check(lost.$('#booking_review_blocked_body').textContent.includes('cannot be approved'), 'and says the request cannot be approved');
  check(lost.$('#booking_review_blocked_body a')?.getAttribute('href') === '/booking-review?id=4', 'it links to the booking that holds the room');
  check(lost.$('#booking_review_blocked_body').textContent.includes('2037-07-01 09:00-11:00'), 'and names the date');
  check(setupClash({id: 9, blocked: null}).$('#booking_review_blocked').hidden === true, 'no notice when nothing clashes');
  check(setupClash({id: 9}).$('#booking_review_blocked').hidden === true, 'or when the field is missing');
}

console.log('\n[6] An approved request lists what it left behind');
{
  const won = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7), clash(8)]});
  check(won.$('#booking_review_clashing').hidden === false, 'the section is shown');
  check(won.$('#booking_review_clashing_count').textContent === '2', 'with the number of requests');
  check(won.page.querySelectorAll('#booking_review_clashing_list .booking-competition-item').length === 2, 'one row per request');
  check(won.page.querySelector('#booking_review_clashing_list a')?.getAttribute('href') === '/booking-review?id=7', 'each links to its own review page');
  check(won.$('#booking_review_clashing_reason').value === 'The room was approved for another request at the same time', 'the reason box starts with a sensible default');
  check(setupClash({id: 4, canRejectClashing: true, clashing: []}).$('#booking_review_clashing').hidden === true, 'nothing left behind: hidden');
  check(setupClash({id: 4, canRejectClashing: false, clashing: [clash(7)]}).$('#booking_review_clashing').hidden === true, 'hidden for someone who cannot reject them');
  const xss = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7, {member_name: '<img src=x onerror="window.pwned=1">'})]});
  check(!xss.page.querySelector('img') && xss.w.pwned === undefined, 'names are shown as text, never as markup');
}

console.log('\n[7] Rejecting them together');
{
  const ok = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7), clash(8)]});
  ok.$('#booking_review_clashing_reason').value = '  ห้องถูกใช้แล้ว  ';
  ok.$('#booking_review_clashing_button').click();
  await tick(5);
  check(ok.questions.length === 1 && ok.questions[0].q.includes('(2)'), 'the approver is asked to confirm, with the number of requests');
  check(ok.calls.length === 1 && ok.calls[0].url === 'api/booking/review/rejectconflicts', 'it posts to the reject-clashing endpoint');
  check(ok.calls[0].params.id === 4 && ok.calls[0].params.approval_reason === 'ห้องถูกใช้แล้ว', 'for the approved booking, with the trimmed reason');
  check(ok.$('#booking_review_clashing_button').disabled === false, 'and the button is usable again');

  const declined = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7)]}, {confirmAnswer: false});
  declined.$('#booking_review_clashing_button').click();
  await tick(5);
  check(declined.calls.length === 0, 'declining the confirmation sends nothing');

  const empty = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7)]});
  empty.$('#booking_review_clashing_reason').value = '   ';
  empty.$('#booking_review_clashing_button').click();
  await tick(5);
  check(empty.calls.length === 0 && empty.questions.length === 0 && empty.errors.length === 1, 'an empty reason is refused before anything is asked or sent');

  const refused = setupClash({id: 4, canRejectClashing: true, clashing: [clash(7)]}, {response: {data: {success: false, message: 'Permission required'}}});
  refused.$('#booking_review_clashing_button').click();
  await tick(5);
  check(refused.errors.join() === 'Permission required', 'a refusal from the server is shown');
  check(refused.$('#booking_review_clashing_button').disabled === false, 'and the button can be used again');
}

console.log(fail === 0 ? '\nALL PASS' : `\n${fail} FAILED`);
process.exit(fail === 0 ? 0 : 1);
