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

// Different time slots, same rooms - the day-detail modal groups by room
// then time within it (R-0001 08:00, R-0001 13:00, R-0002 08:00, ...). The
// month cell's inline preview must read the same way, not time-first, or
// the "+N more" list and the visible chips disagree on ordering.
const slot = (room, startH, endH, id) => ({
  id, title: `R-${room}, ${String(startH).padStart(2, '0')}:00-${String(endH).padStart(2, '0')}:00`,
  start: `2026-09-22 ${String(startH).padStart(2, '0')}:00:00`,
  end: `2026-09-22 ${String(endH).padStart(2, '0')}:00:00`,
  allDay: false, room_number: room
});
const mixedTimes = calendar.normalizeEvents([
  slot('0004', 13, 16, 1), slot('0001', 8, 12, 2), slot('0002', 13, 16, 3),
  slot('0001', 13, 16, 4), slot('0004', 8, 12, 5), slot('0002', 8, 12, 6)
], calendar.config);
const mixedOrder = calendar
  .getMonthInlineEventsForDate(mixedTimes, new Date(2026, 8, 22))
  .map(e => e.title);
const mixedExpected = [
  'R-0001, 08:00-12:00', 'R-0001, 13:00-16:00',
  'R-0002, 08:00-12:00', 'R-0002, 13:00-16:00',
  'R-0004, 08:00-12:00', 'R-0004, 13:00-16:00'
];
if (mixedOrder.join('|') !== mixedExpected.join('|')) {
  throw new Error(`Month cell did not group different-time events by room first: got ${mixedOrder.join('|')}`);
}

console.log('ok   month cell groups events by room first, then by time within the room');

// Category filter (booking Topic): opt-in dropdown that isolates events by
// event.description. Off by default so the small "check this room's
// schedule" modal on the booking form doesn't grow a control it has no use
// for; the main reservation calendar opts in via data-show-category-filter.
const categoryEvents = [
  {id: '1', title: 'R-0001, 08:00-12:00', description: 'การเรียน', start: '2026-09-05 08:00:00', end: '2026-09-05 12:00:00', room_number: '0001'},
  {id: '2', title: 'R-0002, 08:00-12:00', description: 'ประชุม', start: '2026-09-05 08:00:00', end: '2026-09-05 12:00:00', room_number: '0002'},
  {id: '3', title: 'R-0004, 09:00-10:00', description: 'การเรียน', start: '2026-09-06 09:00:00', end: '2026-09-06 10:00:00', room_number: '0004'}
];

const opted = dom.window.document.createElement('div');
const optedInstance = calendar.create(opted, {
  events: categoryEvents,
  defaultView: 'month',
  currentDate: new Date(2026, 8, 1),
  showCategoryFilter: true
});
const select = opted.querySelector('.ec-category-filter');
if (!select) {
  throw new Error('showCategoryFilter:true did not render a .ec-category-filter dropdown');
}
const optionValues = Array.from(select.options).map(o => o.value);
if (optionValues.join('|') !== ['', 'การเรียน', 'ประชุม'].join('|')) {
  throw new Error(`Category dropdown options did not match the loaded events: got [${optionValues.join(', ')}]`);
}

calendar.setCategoryFilter(optedInstance, 'การเรียน');
const onlyStudyClass = calendar.getFilteredEvents(optedInstance).map(e => e.id);
if (onlyStudyClass.join(',') !== '1,3') {
  throw new Error(`Selecting a category did not filter events correctly: got [${onlyStudyClass.join(', ')}]`);
}

calendar.setCategoryFilter(optedInstance, '');
if (calendar.getFilteredEvents(optedInstance).length !== 3) {
  throw new Error('Clearing the category filter did not restore all events');
}

const notOpted = dom.window.document.createElement('div');
calendar.create(notOpted, {events: categoryEvents, defaultView: 'month'});
if (notOpted.querySelector('.ec-category-filter')) {
  throw new Error('Calendar without showCategoryFilter rendered the category dropdown anyway');
}

console.log('ok   category filter dropdown is populated from loaded events and isolates matching bookings');
console.log('ok   category filter is opt-in and absent from calendars that do not request it');

// The dropdown must show the real "use" categories (Helper::getTopicOptions(),
// sent by the calendar API as a 'categories' key alongside events), not every
// distinct string that has ever ended up in a booking's free-text topic -
// e.g. course names like "วิชาที่ 3" that aren't categories at all.
const noisyEvents = [
  {id: '1', title: 'R-0001, 08:00-12:00', description: 'วิชาที่ 3', start: '2026-09-05 08:00:00', end: '2026-09-05 12:00:00', room_number: '0001'},
  {id: '2', title: 'R-0002, 08:00-12:00', description: 'การเรียน', start: '2026-09-05 08:00:00', end: '2026-09-05 12:00:00', room_number: '0002'}
];
const authoritative = dom.window.document.createElement('div');
const authoritativeInstance = calendar.create(authoritative, {
  events: noisyEvents,
  defaultView: 'month',
  currentDate: new Date(2026, 8, 1),
  showCategoryFilter: true
});
// Simulate what loadEvents() stores after reading the API's 'categories' key.
authoritativeInstance.categoryOptions = [
  {value: 'การเรียน', text: 'การเรียน', category_id: '1', requires_course_code: true},
  {value: 'ประชุม', text: 'ประชุม', category_id: '2', requires_course_code: false},
  {value: 'สัมมนา', text: 'สัมมนา', category_id: '3', requires_course_code: false},
  {value: 'จัดเลี้ยง', text: 'จัดเลี้ยง', category_id: '4', requires_course_code: false}
];
calendar.updateCategoryFilterOptions(authoritativeInstance);
const authoritativeSelect = authoritative.querySelector('.ec-category-filter');
const authoritativeValues = Array.from(authoritativeSelect.options).map(o => o.value);
const expectedCategories = ['', 'การเรียน', 'ประชุม', 'สัมมนา', 'จัดเลี้ยง'];
if (authoritativeValues.join('|') !== expectedCategories.join('|')) {
  throw new Error(`Category dropdown ignored the authoritative category list: got [${authoritativeValues.join(', ')}], expected [${expectedCategories.join(', ')}]`);
}
if (authoritativeValues.includes('วิชาที่ 3')) {
  throw new Error('Category dropdown included a booking\'s free-text topic instead of sticking to the real categories');
}

console.log('ok   category dropdown uses the authoritative "use" category list, not stray booking topic text');

// Positioning: the dropdown should sit rightmost, after the view switcher,
// grouped with the view controls rather than sandwiched next to the search box.
const positioned = dom.window.document.createElement('div');
calendar.create(positioned, {
  events: categoryEvents,
  defaultView: 'month',
  showCategoryFilter: true,
  showViewSwitcher: true
});
const header = positioned.querySelector('.ec-header');
const switcherIndex = Array.from(header.children).findIndex(el => el.classList.contains('ec-view-switcher'));
const categoryIndex = Array.from(header.children).findIndex(el => el.classList.contains('ec-category-filter'));
if (!(switcherIndex >= 0 && categoryIndex > switcherIndex)) {
  throw new Error(`Category filter is not positioned after the view switcher: switcherIndex=${switcherIndex}, categoryIndex=${categoryIndex}`);
}

console.log('ok   category filter sits to the right of the view switcher');
