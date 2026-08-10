// Show each booking topic in the calendar's day-list modal. EventCalendar
// already supports descriptions, but compact day lists hide them by default.
if (window.EventCalendar && !EventCalendar._bookingTopicDescriptionsEnabled) {
    const originalBuildModalEventItem = EventCalendar.buildModalEventItem.bind(EventCalendar);
    EventCalendar.buildModalEventItem = (instance, event, options = {}) => {
        const eventWithTopicLabel = event?.description
            ? {...event, description: `${Now.translate('Topic')}: ${event.description}`}
            : event;
        return originalBuildModalEventItem(instance, eventWithTopicLabel, {
            ...options,
            includeDescription: true
        });
    };
    EventCalendar._bookingTopicDescriptionsEnabled = true;
}

EventManager.on('router:initialized', () => {
    RouterManager.register('/rooms', {
        template: 'booking/catalog.html',
        title: '{LNG_All rooms}',
        requireAuth: true
    });

    RouterManager.register('/my-bookings', {
        template: 'booking/my-bookings.html',
        title: '{LNG_My bookings}',
        requireAuth: true
    });

    RouterManager.register('/booking', {
        template: 'booking/booking-recurring.html',
        title: '{LNG_Book a room}',
        requireAuth: true
    });

    RouterManager.register('/approvals', {
        template: 'booking/approvals.html',
        title: '{LNG_Booking approvals}',
        requireAuth: true
    });

    RouterManager.register('/booking-review', {
        template: 'booking/review.html',
        title: '{LNG_Review booking}',
        menuPath: '/approvals',
        requireAuth: true
    });

    RouterManager.register('/room-management', {
        template: 'booking/rooms.html',
        title: '{LNG_Rooms}',
        requireAuth: true
    });

    RouterManager.register('/booking-settings', {
        template: 'booking/settings.html',
        title: '{LNG_Settings}',
        requireAuth: true
    });

    RouterManager.register('/booking-categories', {
        template: 'booking/categories.html',
        title: '{LNG_Categories}',
        requireAuth: true
    });

    RouterManager.register('/', {
        template: 'booking/calendar.html',
        title: '{LNG_Reservation calendar}',
        requireAuth: false,
        requireGuest: false
    });
});

/**
 * Force booking time fields to use a locale-independent 24-hour display.
 * Native input[type="time"] follows the browser/OS locale and may show AM/PM.
 */
function initBookingTime24Inputs(root = document) {
    ['begin_time', 'end_time'].forEach(id => {
        const input = root.querySelector(`#${id}`);
        if (!input) return;

        const currentValue = input.value;
        input.type = 'text';
        input.inputMode = 'numeric';
        input.maxLength = 5;
        input.placeholder = 'HH:MM';
        input.pattern = '(?:[01][0-9]|2[0-3]):[0-5][0-9]';
        input.title = 'Use 24-hour time format (HH:MM)';

        // Preserve the native time value (already serialized as HH:MM) when
        // converting a cached template from type="time" to type="text".
        if (currentValue) input.value = currentValue.slice(0, 5);

        if (input.dataset.time24MaskBound === 'true') return;
        input.dataset.time24MaskBound = 'true';

        const formatTimeDigits = value => {
            let digits = String(value || '').replace(/\D/g, '');

            // Allow shorthand such as 930 -> 09:30.
            if (digits.length >= 3 && parseInt(digits.charAt(0), 10) > 2) {
                digits = `0${digits}`;
            }

            digits = digits.slice(0, 4);
            return digits.length > 2
                ? `${digits.slice(0, 2)}:${digits.slice(2)}`
                : digits;
        };

        input.addEventListener('input', () => {
            input.value = formatTimeDigits(input.value);
        });

        input.addEventListener('blur', () => {
            const digits = input.value.replace(/\D/g, '');
            if (digits.length === 1 || digits.length === 2) {
                input.value = `${digits.padStart(2, '0')}:00`;
            } else if (digits.length > 2) {
                input.value = formatTimeDigits(input.value).padEnd(5, '0');
            }
        });
    });
}

const refreshBookingTime24Inputs = () => {
    requestAnimationFrame(() => initBookingTime24Inputs());
};

EventManager.on('route:changed', refreshBookingTime24Inputs);
EventManager.on('page:loaded', refreshBookingTime24Inputs);

// These tables expose only the Delete bulk action. Remove the placeholder and
// keep Delete selected; the Process button still requires selected rows.
const deleteOnlyBulkActionTables = new Set(['rooms', 'bookingApprovals']);

function syncDeleteOnlyBulkAction(tableId) {
    if (!deleteOnlyBulkActionTables.has(tableId)) return;

    const actionSelect = document.getElementById(`select_action_${tableId}`);
    const actionButton = document.getElementById(`action_button_${tableId}`);
    if (!actionSelect || !actionButton) return;

    Array.from(actionSelect.options)
        .filter(option => option.value === '')
        .forEach(option => option.remove());

    const deleteOption = Array.from(actionSelect.options).find(option => option.value === 'delete');
    if (deleteOption && actionSelect.value !== 'delete') {
        deleteOption.selected = true;
        actionSelect.value = 'delete';
        actionSelect.dispatchEvent(new Event('change', {bubbles: true}));
    }

    // Keep the select in the DOM because TableManager's submit handler reads
    // its value, but hide it so the user sees only one red Delete button.
    actionSelect.hidden = true;
    actionSelect.style.display = 'none';
    actionSelect.setAttribute('aria-hidden', 'true');

    actionButton.value = 'Delete';
    actionButton.setAttribute('value', 'Delete');
    actionButton.textContent = Now.translate('Delete');
    actionButton.dataset.i18n = 'Delete';
    actionButton.classList.remove('btn-info', 'btn-success', 'btn-primary', 'btn-warning');
    actionButton.classList.add('btn-danger');
}

EventManager.on('table:selectionChange', event => {
    syncDeleteOnlyBulkAction(event.data?.tableId);
});

EventManager.on('table:render', event => {
    syncDeleteOnlyBulkAction(event.data?.tableId);
});

const refreshDeleteOnlyBulkActions = () => {
    requestAnimationFrame(() => {
        deleteOnlyBulkActionTables.forEach(tableId => syncDeleteOnlyBulkAction(tableId));
    });
};
EventManager.on('route:changed', refreshDeleteOnlyBulkActions);
EventManager.on('page:loaded', refreshDeleteOnlyBulkActions);

// Direct DOM fallback keeps this behavior reliable if table events are
// re-bound while API data is being rendered.
document.addEventListener('change', event => {
    const table = event.target.closest('table[data-table]');
    if (event.target.matches('input[type="checkbox"]') && deleteOnlyBulkActionTables.has(table?.dataset.table)) {
        requestAnimationFrame(() => syncDeleteOnlyBulkAction(table.dataset.table));
    }
});

function formatRoomWithImage(cell, rawValue, rowData, attributes) {
    const opts = attributes.lookupOptions || attributes.tableDataOptions || attributes.tableFilterOptions;

    // Normalizer: build a map value->text
    const makeMap = (options) => {
        if (!options) return new Map();
        if (Array.isArray(options)) {
            // [{value,text}, ...]
            return new Map(options.map(o => [String(o.value), o.text]));
        }
        // object map {val: label, ...}
        return new Map(Object.entries(options).map(([k, v]) => [String(k), v]));
    };

    const map = makeMap(opts);

    const key = rawValue === null || rawValue === undefined ? '' : String(rawValue);
    const label = map.has(key) ? map.get(key) : (rawValue && rawValue.text) ? rawValue.text : key;
    const roomNumber = rowData?.room_number ? `<small>${Utils.string.escape(rowData.room_number)}</small>` : '';

    const thumbHtml = rowData?.first_image_url
        ? `<span class="booking-table-thumb"><img src="${Utils.string.escape(rowData.first_image_url)}" alt="${Utils.string.escape(label || 'Room')}" loading="lazy"></span>`
        : '<span class="booking-table-thumb booking-table-thumb--placeholder icon-office" aria-hidden="true"></span>';

    cell.innerHTML =
        `<span class="booking-table-cell">${thumbHtml}<span class="booking-table-label"><strong>${Utils.string.escape(label || '-')}</strong>${roomNumber}</span></span>`;
}

function initBookingSettings(element, data) {
    const approveLevel = element.querySelector('#booking_approve_level');
    if (!approveLevel) {
        return () => {};
    }

    const approveChange = () => {
        element.querySelectorAll('.can-approve').forEach(el => {
            const level = parseInt(el.dataset.level || '0', 10);
            el.style.display = level > 0 && level <= parseInt(approveLevel.value || '0', 10) ? 'flex' : 'none';
        });
    };
    approveLevel.addEventListener('change', approveChange);
    approveChange();

    return () => {
        approveLevel.removeEventListener('change', approveChange);
    };
}

function initBookingForm(element, data) {
    const scheduleType = element.querySelector('#schedule_type');
    const recurrenceDays = element.querySelector('#recurrence_days');
    if (!scheduleType || !recurrenceDays) {
        return () => {};
    }

    const recurrenceGroup = recurrenceDays.closest('.width50');
    const scheduleChange = () => {
        const weekly = scheduleType.value === 'weekly-slot';
        if (recurrenceGroup) {
            recurrenceGroup.style.display = weekly ? '' : 'none';
        }
        recurrenceDays.disabled = !weekly;
    };

    scheduleType.addEventListener('change', scheduleChange);
    scheduleChange();

    return () => {
        scheduleType.removeEventListener('change', scheduleChange);
    };
}
