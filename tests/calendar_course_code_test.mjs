import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
  runScripts: 'outside-only',
  url: 'http://localhost:8080'
});
dom.window.Now = {registerManager() {}, translate: value => value};
dom.window.eval(fs.readFileSync('Now/dist/eventcalendar.min.js', 'utf8'));
dom.window.eval(
  fs.readFileSync('ui-handoff/modules/booking/admin.js', 'utf8')
    .split('// Preserve response actions')[0]
);

const calendar = dom.window.EventCalendar;
const event = calendar.normalizeEvents([{
  id: '3',
  title: 'C002, 10:00-12:00',
  start: '2026-09-25 10:00:00',
  end: '2026-09-25 12:00:00',
  allDay: false,
  room_number: 'C002',
  room_name: 'ห้องเรียน 2',
  description: 'การเรียน',
  course_code: '204321'
}], calendar.config)[0];
const html = calendar.buildModalEventItem(
  {config: {...calendar.config, locale: 'th'}},
  event,
  {includeDescription: true, referenceDate: new Date(2026, 8, 25)}
);

if (!html.includes('Course code: 204321')) {
  throw new Error('Normalized calendar event did not render its course code');
}

if (!calendar.eventMatchesFilter(event, '204321')) {
  throw new Error('Dashboard calendar did not find an event by course code');
}
if (!calendar.eventMatchesFilter(event, 'C002 204321')) {
  throw new Error('Dashboard calendar did not combine room and course-code terms');
}

console.log('ok   normalized calendar event renders course code');
console.log('ok   dashboard calendar finds an event by course code');
console.log('ok   dashboard calendar combines room and course-code terms');

// Same start time, scrambled room order - a month cell used to fall back to
// sorting by the event title string, which only looked room-ordered by
// coincidence (room code happens to lead the title). Assert the room field
// itself drives the tiebreak.
const sameSlot = (room, id) => ({
  id, title: `R-${room}, 08:00-12:00`,
  start: '2026-09-22 08:00:00', end: '2026-09-22 12:00:00',
  allDay: false, room_number: room
});
const scrambled = calendar.normalizeEvents(
  ['0006', '0002', '0001', '0029', '0004'].map((room, i) => sameSlot(room, i)),
  calendar.config
);
const monthOrder = calendar
  .getMonthInlineEventsForDate(scrambled, new Date(2026, 8, 22))
  .map(e => e.room_number);
const expected = ['0001', '0002', '0004', '0006', '0029'];
if (monthOrder.join(',') !== expected.join(',')) {
  throw new Error(`Month cell did not sort same-time events by room: got ${monthOrder.join(',')}`);
}

console.log('ok   month cell sorts same-time events by room, not by title text');
