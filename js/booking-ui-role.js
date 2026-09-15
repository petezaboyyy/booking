(function (global) {
  'use strict';

  const staffRoutes = new Set(['/approvals', '/room-management', '/booking-settings']);

  function hasStaffMenu(items) {
    return Array.isArray(items) && items.some(item => {
      const path = String(item?.url || '').split('?')[0];
      return staffRoutes.has(path) || hasStaffMenu(item?.children);
    });
  }

  function usesAdminUi(user) {
    const permissions = Array.isArray(user?.permission) ? user.permission : [];
    return Number(user?.status) === 1
      || permissions.includes('can_manage_booking')
      || permissions.includes('can_config')
      || hasStaffMenu(user?.menus);
  }

  global.BookingUiRole = {usesAdminUi};
})(typeof window === 'undefined' ? globalThis : window);
