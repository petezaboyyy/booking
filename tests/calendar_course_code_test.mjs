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

console.log('ok   normalized calendar event renders course code');
