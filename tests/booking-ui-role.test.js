import assert from 'node:assert/strict';
import {test} from 'vitest';
import '../js/booking-ui-role.js';

const {usesAdminUi} = globalThis.BookingUiRole;

test('selects the member UI only for users without staff access', () => {
  assert.equal(usesAdminUi({status: 0, permission: [], menus: []}), false);
  assert.equal(usesAdminUi({status: 1, permission: [], menus: []}), true);
  assert.equal(usesAdminUi({status: 0, permission: ['can_manage_booking'], menus: []}), true);
  assert.equal(usesAdminUi({status: 0, permission: [], menus: [{children: [{url: '/approvals'}]}]}), true);
});
