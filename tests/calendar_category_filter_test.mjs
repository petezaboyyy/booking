/**
 * The calendar's category dropdown filters by category id.
 *
 * It used to compare the booking's stored topic text with the category name,
 * so renaming a category ("การเรียนการสอน" -> "การเรียน การสอน") made every
 * older booking vanish from that filter. Events now carry the category id and
 * the filter matches on it; only events without an id fall back to the name.
 *
 *   node tests/calendar_category_filter_test.mjs   (after `npm run build:eventcalendar`)
 */
import fs from 'node:fs';
import {JSDOM} from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
  runScripts: 'outside-only',
  url: 'http://localhost:8080'
});
dom.window.Now = {registerManager() {}, translate: value => value};
dom.window.eval(fs.readFileSync('Now/dist/eventcalendar.min.js', 'utf8'));

const calendar = dom.window.EventCalendar;
const events = calendar.normalizeEvents([
  // Booked under the old name, same category id as the renamed one.
  {id: '1', title: 'A, 08:00-09:00', start: '2026-10-14 08:00:00', end: '2026-10-14 09:00:00',
    allDay: false, categoryId: '4', description: 'การเรียนการสอน'},
  // Booked under the current name.
  {id: '2', title: 'B, 09:00-10:00', start: '2026-10-14 09:00:00', end: '2026-10-14 10:00:00',
    allDay: false, categoryId: '4', description: 'การเรียน การสอน'},
  // A different category.
  {id: '3', title: 'C, 10:00-11:00', start: '2026-10-14 10:00:00', end: '2026-10-14 11:00:00',
    allDay: false, categoryId: '1', description: 'ประชุม'},
  // No id stored (legacy row): matched by name only.
  {id: '4', title: 'D, 11:00-12:00', start: '2026-10-14 11:00:00', end: '2026-10-14 12:00:00',
    allDay: false, categoryId: '', description: 'การเรียน การสอน'}
], calendar.config);

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
  console.log(`ok   ${message}`);
};
const idsFor = instance => calendar.getFilteredEvents(instance).map(e => e.id).sort().join(',');

// getFilteredEvents() only needs these fields of an instance.
const makeSelect = () => dom.window.document.createElement('select');
const instance = {
  events,
  filterQuery: '',
  categoryFilter: '',
  categoryFilterElement: makeSelect(),
  config: {...calendar.config, locale: 'en'},
  categoryOptions: [
    {value: 'การเรียน การสอน', text: 'การเรียน การสอน', category_id: '4'},
    {value: 'ประชุม', text: 'ประชุม', category_id: '1'}
  ]
};
calendar.getInstance = () => instance;

assert(events[0].categoryId === '4', 'normalized events keep their category id');

calendar.updateCategoryFilterOptions(instance);

instance.categoryFilter = '';
assert(idsFor(instance) === '1,2,3,4', 'no category selected shows every event');

instance.categoryFilter = 'การเรียน การสอน';
assert(instance.categoryIdByValue?.['การเรียน การสอน'] === '4', 'the dropdown remembers each option\'s category id');
assert(idsFor(instance) === '1,2,4',
  'a renamed category still finds bookings stored under its old name, plus id-less ones by name');

instance.categoryFilter = 'ประชุม';
assert(idsFor(instance) === '3', 'another category selects only its own bookings');

console.log('\nALL PASS');
