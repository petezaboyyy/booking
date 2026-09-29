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

// Positioning: the view switcher and category filter should be grouped together
// inside a single .ec-view-controls wrapper (so the toolbar reads as two flex
// groups - nav vs. right-side controls - instead of three evenly spaced ones),
// with the filter sitting after the switcher inside that wrapper.
const positioned = dom.window.document.createElement('div');
calendar.create(positioned, {
  events: categoryEvents,
  defaultView: 'month',
  showCategoryFilter: true,
  showViewSwitcher: true
});
const header = positioned.querySelector('.ec-header');
const controls = header.querySelector('.ec-view-controls');
if (!controls) {
  throw new Error('View switcher and category filter are not grouped inside a .ec-view-controls wrapper');
}
const switcherIndex = Array.from(controls.children).findIndex(el => el.classList.contains('ec-view-switcher'));
const categoryIndex = Array.from(controls.children).findIndex(el => el.classList.contains('ec-category-filter'));
if (!(switcherIndex >= 0 && categoryIndex > switcherIndex)) {
  throw new Error(`Category filter is not positioned after the view switcher: switcherIndex=${switcherIndex}, categoryIndex=${categoryIndex}`);
}

console.log('ok   view switcher and category filter are grouped together, filter to the right of the switcher');

// Custom dropdown: the period-picker's year <select> and the category filter
// should open a .roomly-combobox-dropdown panel (same markup/classes as the
// room-search combobox on the booking form) instead of relying on the
// browser's native <select> listbox, so every dropdown in the app looks the
// same when opened.
const dropdownHost = dom.window.document.createElement('div');
calendar.create(dropdownHost, {
  events: categoryEvents,
  defaultView: 'month',
  showPeriodPicker: true,
  showCategoryFilter: true,
  showViewSwitcher: true
});

const yearSelect = dropdownHost.querySelector('.ec-period-year');
if (!yearSelect) {
  throw new Error('Period picker did not render a .ec-period-year select');
}

yearSelect.dispatchEvent(new dom.window.MouseEvent('mousedown', {bubbles: true, cancelable: true}));
const openPanel = dom.window.document.querySelector('.roomly-combobox-dropdown.is-open');
if (!openPanel) {
  throw new Error('Clicking the year select did not open a .roomly-combobox-dropdown panel');
}

const items = openPanel.querySelectorAll('.roomly-combobox-item');
if (items.length < 2) {
  throw new Error(`Expected multiple year options in the custom dropdown, got ${items.length}`);
}

const targetValue = items[1].dataset.value;
items[1].dispatchEvent(new dom.window.MouseEvent('pointerdown', {bubbles: true, cancelable: true}));
if (yearSelect.value !== targetValue) {
  throw new Error(`Selecting a custom dropdown item did not update the underlying select: expected ${targetValue}, got ${yearSelect.value}`);
}
if (dom.window.document.querySelector('.roomly-combobox-dropdown.is-open')) {
  throw new Error('Custom dropdown panel did not close after selecting an item');
}

console.log('ok   period-picker year select opens a room-booking-style custom dropdown and selection updates the underlying select');

const beforeDestroyPanelCount = dom.window.document.querySelectorAll('.roomly-combobox-dropdown').length;
calendar.destroy(dropdownHost);
const afterDestroyPanelCount = dom.window.document.querySelectorAll('.roomly-combobox-dropdown').length;
if (!(afterDestroyPanelCount < beforeDestroyPanelCount)) {
  throw new Error('Destroying the calendar did not remove its custom dropdown panels from the document');
}

console.log('ok   destroying the calendar removes its custom dropdown panels');

// Locale reactivity: switching language must update the category filter's
// "All categories" placeholder immediately (it used to be excluded from the
// options-signature check, so a locale switch with no other category change
// silently kept showing the old language), and must re-fetch from the API
// when one is configured so the categories themselves (localized server-side
// via Booking\Helper\Controller::getTopicOptions()) pick up the new language
// too - a plain re-render alone can't relabel data it already fetched.
const localeHost = dom.window.document.createElement('div');
calendar.create(localeHost, {
  events: [],
  defaultView: 'month',
  showCategoryFilter: true
});
const localeInstance = calendar.getInstance(localeHost);
localeInstance.categoryOptions = [{value: 'การเรียน', text: 'การเรียน'}];
calendar.updateCategoryFilterOptions(localeInstance);

const allOptionBefore = localeHost.querySelector('.ec-category-filter option[value=""]');
if (!allOptionBefore || allOptionBefore.textContent !== 'ทุกหมวดหมู่') {
  throw new Error(`Expected Thai "All categories" label before locale switch, got "${allOptionBefore?.textContent}"`);
}

localeInstance.config.locale = 'en';
localeInstance.config.localePreference = 'en';
dom.window.document.dispatchEvent(new dom.window.Event('locale:changed'));

const allOptionAfter = localeHost.querySelector('.ec-category-filter option[value=""]');
if (!allOptionAfter || allOptionAfter.textContent !== 'All categories') {
  throw new Error(`"All categories" label did not update after locale:changed, got "${allOptionAfter?.textContent}"`);
}

console.log('ok   category filter "All categories" label updates immediately on locale:changed');

const apiHost = dom.window.document.createElement('div');
calendar.create(apiHost, {
  api: '/fake-events-api',
  defaultView: 'month',
  showCategoryFilter: true
});
const apiInstance = calendar.getInstance(apiHost);
let loadEventsCallCount = 0;
const originalLoadEvents = calendar.loadEvents;
calendar.loadEvents = function (instance) {
  if (instance === apiInstance) loadEventsCallCount++;
  return Promise.resolve();
};
try {
  dom.window.document.dispatchEvent(new dom.window.Event('locale:changed'));
} finally {
  calendar.loadEvents = originalLoadEvents;
}
if (loadEventsCallCount !== 1) {
  throw new Error(`Expected loadEvents to re-fetch once on locale change for an API-backed calendar, got ${loadEventsCallCount} calls`);
}

console.log('ok   locale:changed re-fetches events/categories from the API instead of only re-rendering cached data');
