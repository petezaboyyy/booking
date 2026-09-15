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
const deleteOnlyBulkActionTables = new Set(['rooms', 'bookingApprovals', 'usage']);

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
    const beginDate = element.querySelector('#begin_date');
    const endDate = element.querySelector('#end_date');
    const beginTime = element.querySelector('#begin_time');
    const endTime = element.querySelector('#end_time');
    const excludedInput = element.querySelector('#excluded_dates');
    const preview = element.querySelector('#booking_occurrence_preview');
    const result = element.querySelector('#result_excluded_dates');
    if (!scheduleType || !recurrenceDays || !beginDate || !endDate || !excludedInput || !preview) {
        return () => {};
    }

    // Mirrors Helper::MAX_BOOKING_RANGE_DAYS — rendering one checkbox per day
    // of a multi-year range would lock up the page.
    const MAX_BOOKING_RANGE_DAYS = 366;

    const recurrenceGroup = recurrenceDays.closest('.width50');
    const parseExcluded = value => new Set(
        (Array.isArray(value) ? value : String(value || '').split(','))
            .map(item => String(item).trim())
            .filter(item => /^\d{4}-\d{2}-\d{2}$/.test(item))
    );
    const excluded = parseExcluded(data?.excluded_dates ?? excludedInput.value);
    const parseDate = value => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
    };
    const formatDate = date => [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0')
    ].join('-');
    const selectedWeekdays = () => new Set(
        Array.from(recurrenceDays.selectedOptions).map(option => Number(option.value))
    );

    const renderOccurrences = () => {
        const first = parseDate(beginDate.value);
        const last = parseDate(endDate.value);
        preview.replaceChildren();
        if (!first || !last || first > last) {
            excludedInput.value = '';
            if (result) result.textContent = '';
            return;
        }

        const rangeDays = Math.floor((last.getTime() - first.getTime()) / 86400000) + 1;
        if (rangeDays > MAX_BOOKING_RANGE_DAYS) {
            excludedInput.value = '';
            if (result) {
                result.textContent = Now.translate('The reservation period must not exceed :days days')
                    .replace(':days', MAX_BOOKING_RANGE_DAYS);
                result.classList.add('error');
            }
            return;
        }

        const weekly = scheduleType.value === 'weekly-slot';
        const weekdays = selectedWeekdays();
        const availableDates = [];
        const cursor = new Date(first.getTime());
        while (cursor <= last) {
            if (!weekly || weekdays.has(cursor.getDay())) {
                availableDates.push(formatDate(cursor));
            }
            cursor.setDate(cursor.getDate() + 1);
        }

        const availableSet = new Set(availableDates);
        Array.from(excluded).forEach(date => {
            if (!availableSet.has(date)) excluded.delete(date);
        });
        excludedInput.value = Array.from(excluded).sort().join(',');

        availableDates.forEach(date => {
            const item = document.createElement('div');
            item.className = `booking-occurrence-item${excluded.has(date) ? ' is-excluded' : ''}`;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = !excluded.has(date);
            checkbox.id = `occurrence_${date.replaceAll('-', '')}`;
            const label = document.createElement('label');
            label.htmlFor = checkbox.id;
            const dateObject = parseDate(date);
            const dateLabel = dateObject
                ? dateObject.toLocaleDateString(document.documentElement.lang || undefined, {
                    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
                })
                : date;
            label.textContent = `${dateLabel} ${beginTime?.value || ''}-${endTime?.value || ''}`;
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) excluded.delete(date);
                else excluded.add(date);
                item.classList.toggle('is-excluded', !checkbox.checked);
                excludedInput.value = Array.from(excluded).sort().join(',');
                updateSummary(availableDates.length);
            });
            item.append(checkbox, label);
            preview.appendChild(item);
        });
        updateSummary(availableDates.length);
    };

    const updateSummary = total => {
        if (!result) return;
        const selected = Math.max(0, total - excluded.size);
        result.textContent = `${Now.translate('Selected dates')}: ${selected} / ${total}`;
        result.classList.toggle('error', total > 0 && selected === 0);
    };
    const scheduleChange = () => {
        const weekly = scheduleType.value === 'weekly-slot';
        if (recurrenceGroup) {
            recurrenceGroup.style.display = weekly ? '' : 'none';
        }
        recurrenceDays.disabled = !weekly;
        renderOccurrences();
    };

    scheduleType.addEventListener('change', scheduleChange);
    [beginDate, endDate, beginTime, endTime, recurrenceDays].filter(Boolean).forEach(input => {
        input.addEventListener('change', renderOccurrences);
        input.addEventListener('input', renderOccurrences);
    });
    scheduleChange();

    return () => {
        scheduleType.removeEventListener('change', scheduleChange);
        [beginDate, endDate, beginTime, endTime, recurrenceDays].filter(Boolean).forEach(input => {
            input.removeEventListener('change', renderOccurrences);
            input.removeEventListener('input', renderOccurrences);
        });
    };
}

function initBookingReview(element, data) {
    const container = element.querySelector('#booking_review_occurrences');
    if (!container) return () => {};

    const occurrences = Array.isArray(data?.occurrences) ? data.occurrences : [];
    container.replaceChildren();
    if (occurrences.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'comment';
        empty.textContent = Now.translate('No booking dates');
        container.appendChild(empty);
        return () => {};
    }

    occurrences.forEach(occurrence => {
        const item = document.createElement('div');
        item.className = `booking-occurrence-item${occurrence.status === 4 ? ' is-cancelled' : ''}`;

        const details = document.createElement('span');
        details.className = 'booking-occurrence-details';
        const date = document.createElement('strong');
        date.textContent = occurrence.date;
        const time = document.createElement('small');
        time.textContent = `${occurrence.begin_time}-${occurrence.end_time} · ${Now.translate(occurrence.status_text)}`;
        details.append(date, time);
        if (occurrence.cancellation_reason) {
            const reason = document.createElement('small');
            reason.textContent = occurrence.cancellation_reason;
            details.appendChild(reason);
        }
        item.appendChild(details);

        // The review endpoint is restricted to approvers and the cancellation
        // endpoint validates permission again. Use the concrete status here so
        // data-binding coercion cannot hide the action for an approved date.
        if (Number(occurrence.status) === 1) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-danger icon-delete booking-occurrence-cancel';
            button.title = Now.translate('Cancel this date');
            button.textContent = Now.translate('Cancel this date');
            button.dataset.action = 'click.prevent:requestApi';
            button.dataset.apiUrl = 'api/booking/review/canceloccurrence';
            button.dataset.apiMethod = 'post';
            button.dataset.paramId = String(data.id);
            button.dataset.paramOccurrenceId = String(occurrence.id);
            button.dataset.paramVersion = String(data.version);
            button.dataset.paramApprovalReason = '{approval_reason}';
            button.dataset.requiredFields = 'approval_reason';
            button.dataset.requiredMessage = 'Decision note is required';
            button.dataset.confirm = 'Confirm cancellation of this date';
            button.dataset.loadingText = 'Cancelling';
            button.dataset.notifySuccess = 'true';
            item.appendChild(button);
        }
        container.appendChild(item);
    });

    return () => {};
}
