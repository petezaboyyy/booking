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

// Preserve response actions (e.g. modal action with room photo and details) from onEventClickApi
if (window.EventCalendar && !EventCalendar._extractResponsePayloadPatched) {
    const origExtractResponsePayload = EventCalendar.extractResponsePayload?.bind(EventCalendar);
    EventCalendar.extractResponsePayload = function(response) {
        if (!response) return null;
        if (response.data && response.data.actions) {
            return response.data;
        }
        if (response.actions) {
            return response;
        }
        return origExtractResponsePayload ? origExtractResponsePayload(response) : (response?.data?.data ?? response?.data ?? response ?? null);
    };
    EventCalendar._extractResponsePayloadPatched = true;
}

// Ensure BackdropManager and Modal use z-indices above #roomly-calendar-modal (999999)
if (window.BackdropManager) {
    BackdropManager.config.baseZIndex = 1000000;
}
if (window.Modal && !Modal._roomlyZIndexPatched) {
    const origModalShow = Modal.prototype.show;
    Modal.prototype.show = function() {
        if (this.modal) {
            this.modal.style.zIndex = '1000005';
            const dialog = this.modal.querySelector('.modal-dialog');
            if (dialog) dialog.style.zIndex = '1000010';
        }
        return origModalShow.apply(this, arguments);
    };
    Modal._roomlyZIndexPatched = true;
}

// Patch showDayDetailModal to handle string/number IDs and reliably trigger booking view on event click
if (window.EventCalendar && !EventCalendar._dayDetailModalPatched) {
    EventCalendar.showDayDetailModal = function(instance, date, events) {
        const {config} = instance;
        const strings = this.getLocaleStrings(config.locale);

        const eventListHtml = (events || []).map(event => this.buildModalEventItem(instance, event, {
            clickable: true,
            localeStrings: strings,
            context: 'day-list',
            referenceDate: date
        })).join('');

        const content = (events && events.length > 0)
            ? eventListHtml
            : `<div class="ec-no-events">${strings.noEvents || 'ไม่มีกิจกรรม'}</div>`;

        this.showModal(content, {
            title: `${strings.dayNames[date.getDay()]} ${this.formatDate(date, config.locale)}`,
            onEventClick: (eventId) => {
                const event = (events || []).find(e => String(e.id) === String(eventId)) ||
                              (instance.events || []).find(e => String(e.id) === String(eventId));
                if (event) {
                    this.handleEventClick(instance, event, {stopPropagation: () => {}});
                }
            }
        });
    };
    EventCalendar._dayDetailModalPatched = true;
}

// EventCalendar Skeleton Loading override
if (window.EventCalendar) {
    EventCalendar.renderLoading = function(instance) {
        const { contentElement } = instance;
        if (!contentElement) return;
        contentElement.className = 'ec-content ec-month-view ec-loading-skeleton-view';
        let skeletonDays = '';
        for (let i = 0; i < 35; i++) {
            const hasBar = (i % 3 === 0 || i % 5 === 0);
            skeletonDays += `
                <div class="ec-day-cell skeleton-day-cell">
                    <div class="skeleton-shimmer skeleton-bar" style="width: 24px; height: 16px; margin: 4px; border-radius: 3px;"></div>
                    ${hasBar ? '<div class="skeleton-shimmer skeleton-bar" style="height: 18px; margin: 2px 4px; border-radius: 4px; opacity: 0.75;"></div>' : ''}
                </div>`;
        }
        contentElement.innerHTML = `
            <div class="ec-month-grid">
                <div class="ec-weekday-header">
                    ${['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'].map(() => '<div class="ec-weekday"><div class="skeleton-shimmer skeleton-bar" style="height: 14px; width: 60%; margin: auto; border-radius: 3px;"></div></div>').join('')}
                </div>
                <div class="ec-skeleton-month-body" style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr));">
                    ${skeletonDays}
                </div>
            </div>`;
    };
}

// Calendar Room Filter Initialization & SPA Lifecycle Binding
function initCalendarRoomFilter() {
    const filterInputs = document.querySelectorAll('[data-calendar-filter], #calendar_room_filter, #member_calendar_room_filter');
    if (!filterInputs.length) return;

    filterInputs.forEach(input => {
        const root = input.closest('.card-groups, .roomly-calendar-panel, .content, main, body') || document;
        const calendarEl = root.querySelector('[data-event-calendar], [data-component="eventcalendar"], [data-component="event-calendar"], .event-calendar');
        if (!calendarEl) return;

        const clearBtn = input.parentElement?.querySelector('[data-calendar-filter-clear], .roomly-calendar-filter-clear, .calendar-filter-clear');

        const syncClearVisibility = () => {
            if (clearBtn) {
                clearBtn.style.display = input.value.trim() ? 'inline-flex' : 'none';
            }
        };

        const applyFilter = (query) => {
            syncClearVisibility();
            if (window.EventCalendar) {
                const inst = typeof EventCalendar.getInstance === 'function'
                    ? EventCalendar.getInstance(calendarEl)
                    : (calendarEl._eventCalendar || null);

                if (inst) {
                    if (typeof EventCalendar.setFilter === 'function') {
                        EventCalendar.setFilter(inst, query);
                    } else {
                        inst._filterRawQuery = query;
                        inst.filterQuery = String(query || '').trim().toLowerCase();
                        if (inst.segmentCache) inst.segmentCache.clear();
                        if (typeof EventCalendar.render === 'function') {
                            EventCalendar.render(inst);
                        }
                    }
                }
            }
        };

        if (!input._filterInitialized && !input._calendarBoundInstance) {
            input._filterInitialized = true;

            input.addEventListener('input', (e) => {
                applyFilter(e.target.value);
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    input.value = '';
                    applyFilter('');
                    input.blur();
                }
            });

            if (clearBtn && !clearBtn._filterInitialized) {
                clearBtn._filterInitialized = true;
                clearBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    input.value = '';
                    applyFilter('');
                    input.focus();
                });
            }
        } else if (input._calendarBoundInstance) {
            input._filterInitialized = true;
        }

        // Sync initial state
        if (calendarEl._eventCalendar?.filterQuery && !input.value) {
            input.value = calendarEl._eventCalendar._filterRawQuery || '';
        }
        syncClearVisibility();
    });
}

// Bind to SPA and lifecycle events
if (window.EventManager?.on) {
    EventManager.on('route:changed', () => setTimeout(initCalendarRoomFilter, 50));
    EventManager.on('page:loaded', () => setTimeout(initCalendarRoomFilter, 50));
    EventManager.on('calendar:loaded', () => setTimeout(initCalendarRoomFilter, 50));
}
document.addEventListener('route:changed', () => setTimeout(initCalendarRoomFilter, 50));
document.addEventListener('page:loaded', () => setTimeout(initCalendarRoomFilter, 50));
document.addEventListener('DOMContentLoaded', () => setTimeout(initCalendarRoomFilter, 100));
window.addEventListener('pageshow', () => setTimeout(initCalendarRoomFilter, 100));
setTimeout(initCalendarRoomFilter, 150);

// TableManager Skeleton Loading override
function renderTableSkeletonLoading(table) {
    const tableElement = table?.element || table;
    if (!tableElement || !(tableElement instanceof HTMLElement)) return;
    const tbody = tableElement.querySelector('tbody');
    if (!tbody) return;

    const thead = tableElement.querySelector('thead');
    const headerCells = thead ? thead.querySelectorAll('tr:last-child > th, tr:first-child > th') : [];
    const colCount = Math.max(headerCells.length || 0, table?.columns?.size || 0, 5);

    const colTypes = [];
    headerCells.forEach(th => {
        if (th.classList.contains('check-column')) {
            colTypes.push('check');
        } else if (th.dataset.formatter === 'formatRoomWithImage' || th.dataset.field === 'room_name' || th.dataset.field === 'room_id') {
            colTypes.push('room');
        } else if (th.classList.contains('center') || th.dataset.field === 'status' || th.dataset.format === 'datetime' || th.dataset.sort === 'begin') {
            colTypes.push('center');
        } else if (th.classList.contains('icons') || th.classList.contains('row-actions')) {
            colTypes.push('actions');
        } else {
            colTypes.push('text');
        }
    });

    while (colTypes.length < colCount) {
        colTypes.push('text');
    }

    const skeletonRows = [];
    const rowCount = Math.min(table?.config?.pageSize || 6, 6);
    const textWidths = ['75%', '60%', '85%', '70%', '50%', '80%'];

    for (let r = 0; r < rowCount; r++) {
        let rowHtml = '<tr class="skeleton-table-row">';
        colTypes.forEach((type, colIndex) => {
            const alignCenter = type === 'check' || type === 'center' || type === 'actions';
            const cellClass = alignCenter ? ' class="center"' : '';
            rowHtml += `<td${cellClass}>`;

            if (type === 'check') {
                rowHtml += '<div class="skeleton-shimmer skeleton-checkbox" style="margin:0 auto;"></div>';
            } else if (type === 'room') {
                rowHtml += `
                    <div class="booking-table-cell">
                        <div class="skeleton-shimmer skeleton-thumb"></div>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;">
                            <div class="skeleton-shimmer skeleton-bar" style="width:${textWidths[(r + colIndex) % textWidths.length]};height:14px;"></div>
                            <div class="skeleton-shimmer skeleton-bar" style="width:40%;height:10px;"></div>
                        </div>
                    </div>`;
            } else if (type === 'actions') {
                rowHtml += '<div class="skeleton-shimmer skeleton-btn-sm" style="margin:0 auto;"></div>';
            } else if (type === 'center') {
                rowHtml += '<div class="skeleton-shimmer skeleton-badge" style="margin:0 auto;"></div>';
            } else {
                rowHtml += `<div class="skeleton-shimmer skeleton-bar" style="width:${textWidths[(r + colIndex) % textWidths.length]};height:14px;"></div>`;
            }

            rowHtml += '</td>';
        });
        rowHtml += '</tr>';
        skeletonRows.push(rowHtml);
    }

    tbody.innerHTML = skeletonRows.join('');
}

if (window.TableManager) {
    TableManager.showLoading = renderTableSkeletonLoading;
}

// DropdownPanel fix for fixed positioning and preventing scroll jumps
if (window.DropdownPanel) {
    const originalShow = DropdownPanel.prototype.show;
    DropdownPanel.prototype.show = function(targetElement, content, options = {}) {
        if (this.panel) {
            this.panel.style.setProperty('position', 'fixed', 'important');
            if (targetElement && targetElement.getBoundingClientRect) {
                const rect = targetElement.getBoundingClientRect();
                this.panel.style.minWidth = Math.round(rect.width) + 'px';
            }
        }
        if (content && typeof content.focus === 'function' && !content._preventScrollPatched) {
            content._preventScrollPatched = true;
            const originalFocus = content.focus;
            content.focus = function(opts) {
                return originalFocus.call(this, Object.assign({ preventScroll: true }, opts));
            };
        }
        const result = originalShow.call(this, targetElement, content, options);
        if (this.panel) {
            this.panel.style.setProperty('position', 'fixed', 'important');
            this._updatePosition();
        }
        return result;
    };
    if (DropdownPanel.instance && DropdownPanel.instance.panel) {
        DropdownPanel.instance.panel.style.setProperty('position', 'fixed', 'important');
    }
}

// MultiSelectElementFactory Single-Line Pill Display with +N Overflow
if (window.MultiSelectElementFactory) {
    MultiSelectElementFactory.updateDisplay = function(instance) {
        const { element, selectedDisplay, trigger } = instance;
        if (!element || element.tagName !== 'SELECT' || !selectedDisplay) return;

        const selectedOptions = Array.from(element.selectedOptions).filter(opt => opt.value !== '');
        const selectedCount = selectedOptions.length;

        if (selectedCount === 0) {
            selectedDisplay.innerHTML = '';
            selectedDisplay.classList.add('placeholder');
            return;
        }

        selectedDisplay.classList.remove('placeholder');

        const render = () => {
            const containerWidth = selectedDisplay.clientWidth || (trigger ? trigger.clientWidth - 50 : 0) || 400;
            selectedDisplay.innerHTML = '';

            const chips = selectedOptions.map(opt => {
                const span = document.createElement('span');
                span.className = 'roomly-pill-item';
                span.textContent = opt.textContent.trim();
                return span;
            });

            // Append temporarily to measure widths
            chips.forEach(c => selectedDisplay.appendChild(c));
            const gap = 6;
            const widths = chips.map(c => c.offsetWidth || (c.textContent.length * 10 + 20));
            selectedDisplay.innerHTML = '';

            let currentWidth = 0;
            let fitCount = 0;

            for (let i = 0; i < widths.length; i++) {
                const isLast = (i === widths.length - 1);
                const moreBadgeWidth = isLast ? 0 : 38;

                if (currentWidth + widths[i] + (i > 0 ? gap : 0) + moreBadgeWidth <= containerWidth || i === 0) {
                    currentWidth += widths[i] + (i > 0 ? gap : 0);
                    fitCount++;
                } else {
                    break;
                }
            }

            for (let i = 0; i < fitCount; i++) {
                selectedDisplay.appendChild(chips[i]);
            }

            const remaining = selectedCount - fitCount;
            if (remaining > 0) {
                const moreBadge = document.createElement('span');
                moreBadge.className = 'roomly-pill-more';
                moreBadge.textContent = `+${remaining}`;
                moreBadge.title = selectedOptions.slice(fitCount).map(o => o.textContent.trim()).join(', ');
                selectedDisplay.appendChild(moreBadge);
            }
        };

        render();
        if (!selectedDisplay.clientWidth && trigger) {
            requestAnimationFrame(render);
        }
    };
}

window.addEventListener('resize', () => {
    const elementManager = window.Now?.getManager?.('element');
    if (elementManager && window.MultiSelectElementFactory) {
        document.querySelectorAll('select[multiple]').forEach(sel => {
            const inst = elementManager.getInstanceByElement(sel);
            if (inst) {
                MultiSelectElementFactory.updateDisplay(inst);
            }
        });
    }
});

const ROOMLY_ROLE_UI_VERSION = '20260915-user-ui-1';

function roleTemplateGuard(routePath, memberTemplate, adminTemplate, memberTitle, adminTitle = memberTitle) {
    return (_params, _from, authManager) => {
        const user = authManager?.getUser?.() || authManager?.state?.user;
        const usesAdminUi = window.BookingUiRole?.usesAdminUi(user)
            ?? Number(user?.status) === 1;
        const route = RouterManager.routes?.get(routePath);

        if (route) {
            const selectedTemplate = usesAdminUi ? adminTemplate : memberTemplate;
            const templateRoot = usesAdminUi ? '/templates/' : '/ui-handoff/templates/';
            route.template = window.location.origin
                + templateRoot + selectedTemplate
                + '?v=' + ROOMLY_ROLE_UI_VERSION;
            route.title = usesAdminUi ? adminTitle : memberTitle;
        }

        document.documentElement.dataset.uiRole = usesAdminUi ? 'admin' : 'member';
        return true;
    };
}

EventManager.on('router:initialized', () => {
    RouterManager.register('/rooms', {
        template: window.location.origin + '/ui-handoff/templates/booking/catalog.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_All rooms}',
        beforeEnter: roleTemplateGuard(
            '/rooms',
            'booking/catalog.html',
            'booking/catalog.html',
            '{LNG_All rooms}'
        ),
        requireAuth: true
    });

    RouterManager.register('/my-bookings', {
        template: window.location.origin + '/ui-handoff/templates/booking/my-bookings.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_My bookings}',
        beforeEnter: roleTemplateGuard(
            '/my-bookings',
            'booking/my-bookings.html',
            'booking/my-bookings.html',
            '{LNG_My bookings}'
        ),
        requireAuth: true
    });

    RouterManager.register('/booking', {
        template: window.location.origin + '/ui-handoff/templates/booking/booking-recurring.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_Book a room}',
        beforeEnter: roleTemplateGuard(
            '/booking',
            'booking/booking-recurring.html',
            'booking/booking-recurring.html',
            '{LNG_Book a room}'
        ),
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
        template: window.location.origin + '/ui-handoff/templates/index.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_Dashboard}',
        beforeEnter: roleTemplateGuard(
            '/',
            'index.html',
            'booking/calendar.html',
            '{LNG_Dashboard}',
            '{LNG_Reservation calendar}'
        ),
        requireAuth: true,
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

document.addEventListener('click', (event) => {
    const themeToggle = event.target.closest('[data-roomly-theme-toggle]');
    if (themeToggle) {
        event.preventDefault();
        const configManager = window.Now?.getManager?.('config') || window.AppConfigManager;
        if (configManager?.toggle) {
            configManager.toggle();
        }
        return;
    }

    const languageToggle = event.target.closest?.('[data-roomly-language-toggle]');
    if (languageToggle) {
        event.preventDefault();
        event.stopPropagation();
        const i18nManager = window.Now?.getManager?.('i18n') || window.I18nManager;
        if (i18nManager?.setLocale) {
            const current = i18nManager.getCurrentLocale?.() || document.documentElement.lang || 'en';
            const nextLocale = current.toLowerCase().startsWith('th') ? 'en' : 'th';
            i18nManager.setLocale(nextLocale, true);
        }
    }
}, true);

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
    const recurrenceGroup = recurrenceDays ? recurrenceDays.closest('.roomly-recurrence-days-group, .width50') : null;

    const roomIdInput = element.querySelector('#room_id');
    const attendeesInput = element.querySelector('#attendees');
    const topicInput = element.querySelector('#topic');
    const useInput = element.querySelector('#use');
    const beginDateInput = element.querySelector('#begin_date');
    const beginTimeInput = element.querySelector('#begin_time');
    const endDateInput = element.querySelector('#end_date');
    const endTimeInput = element.querySelector('#end_time');

    // Summary Card & Room Picker elements
    const summaryRoomName = element.querySelector('#summary-room-name');
    const summaryRoomImg = element.querySelector('#summary-room-img');
    const summaryRoomIcon = element.querySelector('#summary-room-icon');
    const pickerBadge = element.querySelector('#roomly-selected-badge');
    const pickerImg = element.querySelector('#roomly-picker-img');
    const pickerIcon = element.querySelector('#roomly-picker-icon');
    const summaryTopic = element.querySelector('#summary-topic');
    const summaryAttendees = element.querySelector('#summary-attendees');
    const summaryDates = element.querySelector('#summary-dates');
    const summaryTimes = element.querySelector('#summary-times');
    const summaryRepeat = element.querySelector('#summary-repeat');

    // Build room image map from data options or preloaded data
    const roomImageMap = new Map();
    if (data?.options?.room_id && Array.isArray(data.options.room_id)) {
        data.options.room_id.forEach(opt => {
            if (opt.value && opt.image) {
                roomImageMap.set(String(opt.value), opt.image);
            }
        });
    }
    if (data?.room_id && data?.room_image) {
        roomImageMap.set(String(data.room_id), data.room_image);
    }

    const pickerBadges = Array.from(element.querySelectorAll('.roomly-picker-badge, .roomly-room-badge'));
    const onAnyBadgeClick = (e) => {
        const badge = e.currentTarget;
        const select = badge.parentElement ? badge.parentElement.querySelector('select') : null;
        if (!select) return;
        if (select.id === 'room_id') {
            const searchEl = element.querySelector('#room_id_search');
            if (searchEl) {
                searchEl.focus();
                searchEl.click();
                return;
            }
        }
        if (typeof select.showPicker === 'function') {
            try {
                select.showPicker();
            } catch (err) {
                select.focus();
            }
        } else {
            select.focus();
            select.click();
        }
    };
    pickerBadges.forEach(badge => {
        badge.addEventListener('click', onAnyBadgeClick);
    });

    const ensureSelectPlaceholders = () => {
        if (roomIdInput) {
            if (roomIdInput.options && roomIdInput.options.length > 0) {
                const firstOpt = roomIdInput.options[0];
                if (!firstOpt.value) {
                    firstOpt.textContent = '';
                    firstOpt.removeAttribute('data-i18n');
                    delete firstOpt.dataset.i18n;
                    firstOpt.dataset.placeholder = 'true';
                }
            }
            if (!roomIdInput.value || roomIdInput.value === '0' || roomIdInput.selectedIndex <= 0) {
                roomIdInput.value = '';
                if (roomIdInput.options && roomIdInput.options.length > 0) {
                    roomIdInput.selectedIndex = 0;
                }
            }
        }
        if (useInput) {
            if (useInput.options && useInput.options.length > 0) {
                const firstOpt = useInput.options[0];
                if (!firstOpt.value) {
                    firstOpt.textContent = '';
                    firstOpt.removeAttribute('data-i18n');
                    delete firstOpt.dataset.i18n;
                    firstOpt.dataset.placeholder = 'true';
                }
            }
            if (!useInput.value || useInput.value === '0' || useInput.selectedIndex <= 0) {
                useInput.value = '';
                if (useInput.options && useInput.options.length > 0) {
                    useInput.selectedIndex = 0;
                }
            }
        }
        const accessoriesInput = element.querySelector('#accessories');
        if (accessoriesInput) {
            if (accessoriesInput.options) {
                Array.from(accessoriesInput.options).forEach(opt => {
                    if (opt.value === '' && opt.disabled) {
                        opt.textContent = '';
                    }
                });
            }
            const elementManager = window.Now?.getManager?.('element');
            const inst = elementManager?.getInstanceByElement?.(accessoriesInput);
            if (inst && window.MultiSelectElementFactory) {
                window.MultiSelectElementFactory.updateDisplay(inst);
            }
        }
    };

    const syncTopicFromUse = () => {
        if (!topicInput || !useInput) return;
        const selectedOption = useInput.selectedOptions?.[0];
        topicInput.value = useInput.value
            ? (selectedOption?.textContent?.trim() || useInput.value)
            : '';
    };

    const updateLiveSummary = () => {
        ensureSelectPlaceholders();
        syncTopicFromUse();
        // 1. Room Name & Room Image (1/4 of summary card)
        if (roomIdInput) {
            const selectedOpt = roomIdInput.selectedOptions?.[0];
            const roomVal = roomIdInput.value;
            const roomText = selectedOpt && selectedOpt.value && selectedOpt.value !== '0' ? selectedOpt.textContent.trim() : '';
            const roomImgUrl = (selectedOpt && selectedOpt.dataset.image) || roomImageMap.get(String(roomVal)) || '';

            if (summaryRoomName) {
                if (roomText) {
                    summaryRoomName.textContent = roomText;
                    summaryRoomName.classList.add('has-selection');
                } else {
                    summaryRoomName.textContent = '—';
                    summaryRoomName.classList.remove('has-selection');
                }
            }

            // Update Summary Room Image (1/4 of the card)
            if (summaryRoomImg && summaryRoomIcon) {
                if (roomImgUrl) {
                    summaryRoomImg.src = roomImgUrl;
                    summaryRoomImg.style.display = 'block';
                    summaryRoomIcon.style.display = 'none';
                } else {
                    summaryRoomImg.src = '';
                    summaryRoomImg.style.display = 'none';
                    summaryRoomIcon.style.display = '';
                }
            }

            // Update Picker Badge Image
            if (pickerImg && pickerIcon) {
                if (roomImgUrl) {
                    pickerImg.src = roomImgUrl;
                    pickerImg.style.display = 'block';
                    pickerIcon.style.display = 'none';
                } else {
                    pickerImg.src = '';
                    pickerImg.style.display = 'none';
                    pickerIcon.style.display = '';
                }
            }
        }

        // 2. Topic
        if (summaryTopic && topicInput) {
            const topicVal = topicInput.value.trim();
            summaryTopic.textContent = topicVal || '—';
        }

        // 3. Attendees
        if (summaryAttendees && attendeesInput) {
            const attVal = attendeesInput.value.trim();
            summaryAttendees.textContent = attVal ? attVal : '1';
        }

        // 4. Dates
        if (summaryDates && beginDateInput) {
            const bDate = beginDateInput.value;
            const eDate = endDateInput ? endDateInput.value : '';
            if (bDate && eDate && bDate !== eDate) {
                summaryDates.textContent = `${bDate} → ${eDate}`;
            } else if (bDate) {
                summaryDates.textContent = bDate;
            } else {
                summaryDates.textContent = '—';
            }
        }

        // 5. Times
        if (summaryTimes && beginTimeInput) {
            const bTime = beginTimeInput.value;
            const eTime = endTimeInput ? endTimeInput.value : '';
            if (bTime && eTime) {
                summaryTimes.textContent = `${bTime} - ${eTime}`;
            } else if (bTime) {
                summaryTimes.textContent = `${bTime}`;
            } else {
                summaryTimes.textContent = '—';
            }
        }

        // 6. Repeat
        if (summaryRepeat && scheduleType) {
            if (scheduleType.value === 'weekly-slot') {
                const checkedDays = recurrenceDays ? Array.from(recurrenceDays.selectedOptions || []).map(o => o.textContent.trim()).filter(Boolean) : [];
                summaryRepeat.textContent = checkedDays.length > 0
                    ? `${Now.translate('Selected weekdays')}: ${checkedDays.join(', ')}`
                    : Now.translate('Selected weekdays');
            } else {
                summaryRepeat.textContent = Now.translate('Every day');
            }
        }
    };

    const scheduleChange = () => {
        const weekly = scheduleType ? scheduleType.value === 'weekly-slot' : false;
        if (recurrenceGroup) {
            recurrenceGroup.style.display = weekly ? '' : 'none';
        }
        if (recurrenceDays) {
            recurrenceDays.disabled = !weekly;
        }
        updateLiveSummary();
    };

    if (scheduleType) {
        scheduleType.addEventListener('change', scheduleChange);
    }
    if (recurrenceDays) {
        recurrenceDays.addEventListener('change', updateLiveSummary);
    }
    if (roomIdInput) {
        roomIdInput.addEventListener('change', updateLiveSummary);
    }
    if (attendeesInput) {
        attendeesInput.addEventListener('input', updateLiveSummary);
    }
    if (topicInput) {
        topicInput.addEventListener('input', updateLiveSummary);
    }
    const onUseChange = () => {
        syncTopicFromUse();
        updateLiveSummary();
    };
    if (useInput) {
        useInput.addEventListener('change', onUseChange);
    }
    if (beginDateInput) {
        beginDateInput.addEventListener('change', updateLiveSummary);
    }
    if (beginTimeInput) {
        beginTimeInput.addEventListener('input', updateLiveSummary);
    }
    if (endDateInput) {
        endDateInput.addEventListener('change', updateLiveSummary);
    }
    if (endTimeInput) {
        endTimeInput.addEventListener('input', updateLiveSummary);
    }

    // Interactive Validation for Required Fields
    // User requirement: "ช่วยทำให้ตรงกรอบพวกที่จำเป็น จะแสดงขึ้นเมื่อเรากดคลิกแล้วไปคลิกกรอบอื่นค่อยขึ้นแสดง"
    // 1. Initial state on load: completely clean (no red borders, no "* จำเป็น").
    // 2. When user clicks/focuses a field: track user interaction, but do NOT show error yet.
    // 3. When user leaves the field (blur / clicks another frame): ONLY THEN evaluate and show red border + "* จำเป็น" if empty!
    // 4. When user types or selects a value: immediately clear the red border and "* จำเป็น".
    // 5. On submit: validate all required fields, show error on empty fields, and focus first invalid.
    const requiredInputs = [
        roomIdInput,
        attendeesInput,
        useInput,
        beginDateInput,
        beginTimeInput,
        endDateInput,
        endTimeInput
    ].filter(Boolean);

    function isFieldBlank(input) {
        if (!input) return false;
        const val = input.value ? input.value.trim() : '';
        if (!val) return true;
        if (input.id === 'room_id') {
            return val === '0' || val === '' || input.selectedIndex <= 0;
        }
        if (input.id === 'attendees') {
            const n = parseFloat(val);
            return isNaN(n) || n <= 0;
        }
        if (input.id === 'begin_time' || input.id === 'end_time') {
            return !/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(val);
        }
        return false;
    }

    function checkFieldValidity(input, force = false) {
        if (!input) return true;
        const wrap = input.closest('.roomly-field-wrap');
        if (!wrap) return true;

        if (force) {
            input._userClicked = true;
            input._touched = true;
        }

        if (!input._touched) {
            wrap.classList.remove('has-error');
            return true;
        }

        const blank = isFieldBlank(input);
        if (blank) {
            wrap.classList.add('has-error');
            return false;
        } else {
            wrap.classList.remove('has-error');
            return true;
        }
    }

    const clearInitialErrors = () => {
        requiredInputs.forEach(input => {
            if (!input._userClicked) {
                input._touched = false;
                const wrap = input.closest('.roomly-field-wrap');
                if (wrap) wrap.classList.remove('has-error');
            }
        });
    };

    const validationCleanups = [];
    requiredInputs.forEach(input => {
        input._userClicked = false;
        input._touched = false;
        const wrap = input.closest('.roomly-field-wrap');
        if (wrap) wrap.classList.remove('has-error');

        // Track user interaction: when user clicks or focuses into the field
        const onUserFocus = (e) => {
            if (e && e.isTrusted) {
                input._userClicked = true;
            }
        };

        // When user clicks away to another frame / blurs: ONLY THEN show error if blank
        const onBlur = () => {
            if (!input._userClicked) return;
            input._touched = true;
            checkFieldValidity(input);
        };

        // When user types or inputs a value: immediately clear the error
        const onInput = () => {
            if (!isFieldBlank(input)) {
                if (wrap) wrap.classList.remove('has-error');
            }
        };

        // When user selects a value in dropdown: immediately clear error
        const onChange = (e) => {
            if (!isFieldBlank(input)) {
                if (wrap) wrap.classList.remove('has-error');
            } else if (input._userClicked && input._touched) {
                checkFieldValidity(input);
            }
        };

        input.addEventListener('focus', onUserFocus);
        input.addEventListener('pointerdown', onUserFocus);
        if (wrap) {
            wrap.addEventListener('pointerdown', onUserFocus);
        }
        input.addEventListener('blur', onBlur);
        input.addEventListener('input', onInput);
        input.addEventListener('change', onChange);

        validationCleanups.push(
            () => input.removeEventListener('focus', onUserFocus),
            () => input.removeEventListener('pointerdown', onUserFocus),
            () => { if (wrap) wrap.removeEventListener('pointerdown', onUserFocus); },
            () => input.removeEventListener('blur', onBlur),
            () => input.removeEventListener('input', onInput),
            () => input.removeEventListener('change', onChange)
        );
    });

    const onSubmit = (e) => {
        let firstInvalid = null;
        requiredInputs.forEach(input => {
            const isValid = checkFieldValidity(input, true);
            if (!isValid && !firstInvalid) {
                firstInvalid = input;
            }
        });

        if (firstInvalid) {
            e.preventDefault();
            e.stopImmediatePropagation();
            firstInvalid.focus();
            return false;
        }
    };
    element.addEventListener('submit', onSubmit, true);

    // =========================================================================
    // Searchable Combobox for Room Selection with Live Filtering
    // =========================================================================
    let roomCombobox = null;
    if (roomIdInput) {
        const container = roomIdInput.closest('.roomly-select-btn');
        if (container) {
            container.classList.add('roomly-combobox');

            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'roomly-combobox-input';
            searchInput.id = 'room_id_search';
            searchInput.autocomplete = 'off';
            searchInput.spellcheck = false;
            searchInput.placeholder = (window.Now && typeof Now.translate === 'function')
                ? Now.translate('Select or search room...')
                : 'พิมพ์ค้นหา หรือเลือกห้อง...';
            searchInput.setAttribute('role', 'combobox');
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.setAttribute('aria-autocomplete', 'list');

            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'roomly-combobox-clear';
            clearBtn.setAttribute('aria-label', 'Clear room selection');
            clearBtn.innerHTML = '&times;';
            clearBtn.style.display = 'none';

            // Attached directly to document.body to avoid clipping, z-index and overflow issues
            const dropdown = document.createElement('div');
            dropdown.className = 'roomly-combobox-dropdown';
            dropdown.setAttribute('role', 'listbox');
            dropdown.style.display = 'none';
            document.body.appendChild(dropdown);

            container.insertBefore(searchInput, roomIdInput);
            container.insertBefore(clearBtn, roomIdInput);

            let activeIndex = -1;
            let isOpen = false;

            const updateClearBtn = () => {
                const hasValue = Boolean((searchInput.value && searchInput.value.trim()) || (roomIdInput.value && roomIdInput.value !== '0'));
                if (hasValue) {
                    clearBtn.classList.add('is-visible');
                    clearBtn.style.setProperty('display', 'inline-flex', 'important');
                } else {
                    clearBtn.classList.remove('is-visible');
                    clearBtn.style.setProperty('display', 'none', 'important');
                }
            };

            const escapeText = (str) => String(str || '').replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[m]));

            const getVisibleItems = () => Array.from(dropdown.querySelectorAll('.roomly-combobox-item')).filter(el => el.style.display !== 'none');

            const highlightItem = (items, index) => {
                items.forEach((item, i) => {
                    if (i === index) {
                        item.classList.add('is-highlighted');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('is-highlighted');
                    }
                });
            };

            const updateDropdownPosition = () => {
                if (!isOpen) return;
                const rect = container.getBoundingClientRect();
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth;

                if (rect.bottom < 0 || rect.top > viewportHeight) {
                    closeDropdown();
                    return;
                }

                dropdown.style.position = 'fixed';
                dropdown.style.boxSizing = 'border-box';
                dropdown.style.zIndex = '100000';

                const width = Math.round(rect.width);
                dropdown.style.width = `${width}px`;
                dropdown.style.minWidth = '260px';
                dropdown.style.maxWidth = `${viewportWidth - 16}px`;

                let left = Math.round(rect.left);
                if (left + width > viewportWidth - 8) {
                    left = Math.max(8, viewportWidth - width - 8);
                }
                if (left < 8) left = 8;
                dropdown.style.left = `${left}px`;

                const dropdownHeight = Math.min(320, dropdown.offsetHeight || dropdown.scrollHeight || 260);
                const spaceBelow = viewportHeight - rect.bottom;
                const spaceAbove = rect.top;

                if (spaceBelow < dropdownHeight + 10 && spaceAbove > spaceBelow) {
                    dropdown.style.top = `${Math.round(Math.max(8, rect.top - dropdownHeight - 6))}px`;
                    dropdown.style.bottom = 'auto';
                } else {
                    dropdown.style.top = `${Math.round(rect.bottom + 6)}px`;
                    dropdown.style.bottom = 'auto';
                }
            };

            const getAvailableRoomOptions = () => {
                let opts = Array.from(roomIdInput.options || []).filter(opt => opt.value && opt.value !== '' && opt.value !== '0');
                if (opts.length === 0 && data?.options?.room_id && Array.isArray(data.options.room_id)) {
                    data.options.room_id.forEach(item => {
                        if (item.value && item.value !== '' && item.value !== '0') {
                            const opt = document.createElement('option');
                            opt.value = String(item.value);
                            opt.textContent = item.label || item.text || item.name || String(item.value);
                            if (item.image) opt.dataset.image = item.image;
                            roomIdInput.appendChild(opt);
                        }
                    });
                    opts = Array.from(roomIdInput.options || []).filter(opt => opt.value && opt.value !== '' && opt.value !== '0');
                }
                return opts;
            };

            const renderItems = (filterQuery = '') => {
                const query = filterQuery.trim().toLowerCase();
                dropdown.innerHTML = '';
                const options = getAvailableRoomOptions();

                let matchCount = 0;
                options.forEach(opt => {
                    const text = opt.textContent.trim();
                    const val = String(opt.value);
                    const img = (opt.dataset && opt.dataset.image) || roomImageMap.get(val) || '';
                    const isMatch = !query || text.toLowerCase().includes(query);

                    if (!isMatch) return;
                    matchCount++;

                    const item = document.createElement('div');
                    item.className = 'roomly-combobox-item' + (String(roomIdInput.value) === val ? ' is-selected' : '');
                    item.dataset.value = val;
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', String(roomIdInput.value) === val ? 'true' : 'false');

                    const iconWrap = document.createElement('span');
                    iconWrap.className = 'roomly-combobox-item-icon';
                    if (img) {
                        const imgEl = document.createElement('img');
                        imgEl.src = img;
                        imgEl.alt = text;
                        iconWrap.appendChild(imgEl);
                    } else {
                        iconWrap.innerHTML = '<span class="icon-office" aria-hidden="true"></span>';
                    }
                    item.appendChild(iconWrap);

                    const labelEl = document.createElement('span');
                    labelEl.className = 'roomly-combobox-item-text';
                    if (query && text.toLowerCase().includes(query)) {
                        const idx = text.toLowerCase().indexOf(query);
                        const before = text.slice(0, idx);
                        const match = text.slice(idx, idx + query.length);
                        const after = text.slice(idx + query.length);
                        labelEl.innerHTML = `${escapeText(before)}<mark class="roomly-combobox-highlight">${escapeText(match)}</mark>${escapeText(after)}`;
                    } else {
                        labelEl.textContent = text;
                    }
                    item.appendChild(labelEl);

                    if (String(roomIdInput.value) === val) {
                        const check = document.createElement('span');
                        check.className = 'roomly-combobox-check icon-check';
                        check.setAttribute('aria-hidden', 'true');
                        item.appendChild(check);
                    }

                    const handleSelect = (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectRoom(val, text);
                    };
                    item.addEventListener('pointerdown', handleSelect);
                    item.addEventListener('click', handleSelect);

                    dropdown.appendChild(item);
                });

                if (matchCount === 0) {
                    const emptyEl = document.createElement('div');
                    emptyEl.className = 'roomly-combobox-empty';
                    const noRoomsText = (window.Now && typeof Now.translate === 'function')
                        ? Now.translate('No rooms found')
                        : 'ไม่พบห้องที่ตรงกับการค้นหา';
                    emptyEl.textContent = noRoomsText;
                    dropdown.appendChild(emptyEl);
                }

                updateDropdownPosition();
            };

            const openDropdown = (filterQuery = '') => {
                isOpen = true;
                renderItems(filterQuery);
                dropdown.style.display = 'block';
                dropdown.classList.add('is-open');
                searchInput.setAttribute('aria-expanded', 'true');
                container.classList.add('is-open');
                activeIndex = -1;
                updateDropdownPosition();

                requestAnimationFrame(() => {
                    updateDropdownPosition();
                    const selectedItem = dropdown.querySelector('.roomly-combobox-item.is-selected');
                    if (selectedItem) {
                        selectedItem.scrollIntoView({ block: 'nearest' });
                    }
                });
            };

            const closeDropdown = () => {
                if (!isOpen) return;
                isOpen = false;
                dropdown.style.display = 'none';
                dropdown.classList.remove('is-open');
                searchInput.setAttribute('aria-expanded', 'false');
                container.classList.remove('is-open');
                activeIndex = -1;
                syncInputWithSelect();
            };

            const syncInputWithSelect = () => {
                const selectedOpt = roomIdInput.selectedOptions?.[0];
                if (selectedOpt && selectedOpt.value && selectedOpt.value !== '0') {
                    searchInput.value = selectedOpt.textContent.trim();
                } else if (!isOpen) {
                    searchInput.value = '';
                }
                updateClearBtn();
            };

            const selectRoom = (val, text) => {
                roomIdInput.value = val;
                Array.from(roomIdInput.options).forEach((opt, idx) => {
                    opt.selected = (opt.value === val);
                    if (opt.value === val) roomIdInput.selectedIndex = idx;
                });
                searchInput.value = text;
                updateClearBtn();
                closeDropdown();
                roomIdInput.dispatchEvent(new Event('change', { bubbles: true }));
                updateLiveSummary();
            };

            searchInput.addEventListener('focus', () => {
                // Clicking or focusing opens dropdown with ALL available options
                openDropdown('');
                setTimeout(() => {
                    searchInput.select();
                }, 20);
            });

            searchInput.addEventListener('click', (e) => {
                if (!isOpen) {
                    openDropdown('');
                }
            });

            searchInput.addEventListener('input', () => {
                if (!isOpen) {
                    isOpen = true;
                    dropdown.style.display = 'block';
                    dropdown.classList.add('is-open');
                    searchInput.setAttribute('aria-expanded', 'true');
                    container.classList.add('is-open');
                }
                renderItems(searchInput.value);
                updateClearBtn();
                activeIndex = -1;
            });

            searchInput.addEventListener('keydown', (e) => {
                if (!isOpen) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
                        e.preventDefault();
                        openDropdown('');
                    }
                    return;
                }

                const items = getVisibleItems();
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (items.length > 0) {
                        activeIndex = (activeIndex + 1) % items.length;
                        highlightItem(items, activeIndex);
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items.length > 0) {
                        activeIndex = (activeIndex - 1 + items.length) % items.length;
                        highlightItem(items, activeIndex);
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (activeIndex >= 0 && items[activeIndex]) {
                        const item = items[activeIndex];
                        selectRoom(item.dataset.value, item.querySelector('.roomly-combobox-item-text')?.textContent || '');
                    } else if (items.length === 1) {
                        const item = items[0];
                        selectRoom(item.dataset.value, item.querySelector('.roomly-combobox-item-text')?.textContent || '');
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeDropdown();
                } else if (e.key === 'Tab') {
                    closeDropdown();
                }
            });

            clearBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                roomIdInput.value = '';
                roomIdInput.selectedIndex = 0;
                Array.from(roomIdInput.options).forEach((opt, idx) => {
                    opt.selected = (idx === 0);
                });
                searchInput.value = '';
                updateClearBtn();
                openDropdown('');
                searchInput.focus();
                roomIdInput.dispatchEvent(new Event('change', { bubbles: true }));
                updateLiveSummary();
            });

            const arrow = container.querySelector('.roomly-select-arrow');
            if (arrow) {
                arrow.style.cursor = 'pointer';
                arrow.style.pointerEvents = 'auto';
                arrow.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (isOpen) {
                        closeDropdown();
                    } else {
                        searchInput.focus();
                        openDropdown('');
                    }
                });
            }

            container.addEventListener('click', (e) => {
                if (e.target === clearBtn || clearBtn.contains(e.target) || dropdown.contains(e.target) || e.target === searchInput || (arrow && arrow.contains(e.target))) return;
                if (!isOpen) {
                    searchInput.focus();
                    openDropdown('');
                }
            });

            // Stop pointerdown inside dropdown from closing it
            dropdown.addEventListener('pointerdown', (e) => {
                e.stopPropagation();
            });

            const onDocClick = (e) => {
                if (!container.contains(e.target) && !dropdown.contains(e.target)) {
                    closeDropdown();
                }
            };
            document.addEventListener('pointerdown', onDocClick);

            const onViewportChange = () => {
                if (isOpen) {
                    updateDropdownPosition();
                }
            };
            window.addEventListener('scroll', onViewportChange, true);
            window.addEventListener('resize', onViewportChange);

            roomCombobox = {
                sync: () => {
                    syncInputWithSelect();
                    if (isOpen) renderItems(searchInput.value || '');
                },
                destroy: () => {
                    document.removeEventListener('pointerdown', onDocClick);
                    window.removeEventListener('scroll', onViewportChange, true);
                    window.removeEventListener('resize', onViewportChange);
                    searchInput.remove();
                    clearBtn.remove();
                    dropdown.remove();
                    container.classList.remove('roomly-combobox');
                }
            };
        }
    }

    const onOptionsChanged = () => {
        if (roomIdInput && roomIdInput.options) {
            Array.from(roomIdInput.options).forEach(opt => {
                if (opt.value && opt.dataset && opt.dataset.image) {
                    roomImageMap.set(String(opt.value), opt.dataset.image);
                }
            });
        }
        if (roomCombobox) {
            roomCombobox.sync();
        }
        ensureSelectPlaceholders();
        syncTopicFromUse();
        clearInitialErrors();
        updateLiveSummary();
    };
    if (roomIdInput) {
        roomIdInput.addEventListener('optionschanged', onOptionsChanged);
    }
    const useSelect = useInput;
    if (useSelect) {
        useSelect.addEventListener('optionschanged', onOptionsChanged);
    }
    const accessoriesSelect = element.querySelector('#accessories');
    const onAccessoriesChange = () => {
        const elementManager = window.Now?.getManager?.('element');
        const inst = elementManager?.getInstanceByElement?.(accessoriesSelect);
        if (inst && window.MultiSelectElementFactory) {
            window.MultiSelectElementFactory.updateDisplay(inst);
        }
    };
    if (accessoriesSelect) {
        accessoriesSelect.addEventListener('optionschanged', () => {
            onOptionsChanged();
            setTimeout(onAccessoriesChange, 50);
        });
        accessoriesSelect.addEventListener('change', onAccessoriesChange);
    }

    // Initial update and delayed updates to guarantee clean initial load
    ensureSelectPlaceholders();
    clearInitialErrors();
    scheduleChange();
    updateLiveSummary();

    const timer1 = setTimeout(() => {
        if (roomCombobox) roomCombobox.sync();
        ensureSelectPlaceholders();
        clearInitialErrors();
        updateLiveSummary();
    }, 80);
    const timer2 = setTimeout(() => {
        if (roomCombobox) roomCombobox.sync();
        ensureSelectPlaceholders();
        clearInitialErrors();
        updateLiveSummary();
    }, 300);
    const timer3 = setTimeout(() => {
        clearInitialErrors();
    }, 600);

    // Modal Booking Calendar Popup Handler
    const openModalBtn = document.getElementById('btn-open-calendar-modal');
    const modal = document.getElementById('roomly-calendar-modal');
    const closeModalBtn = document.getElementById('btn-close-calendar-modal');

    let onModalKeyDown = null;
    let onModalBackdropClick = null;
    let onOpenModalClick = null;
    let onCloseModalClick = null;

    if (openModalBtn && modal) {
        const openModal = () => {
            // Detach from parent and append directly to document.body so position: fixed is truly viewport-relative
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            modal.classList.add('is-active', 'show');
            modal.setAttribute('aria-hidden', 'false');
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';

            // Initialize EventCalendar fresh each time (after modal is visible)
            const calEl = modal.querySelector('#modal-roomly-calendar');
            if (calEl && window.EventCalendar) {
                // Destroy any stale instance from a previous open
                if (calEl._eventCalendar) {
                    try {
                        if (typeof EventCalendar.cleanupInstance === 'function') {
                            EventCalendar.cleanupInstance(calEl._eventCalendar);
                        }
                    } catch (e) { /* ignore */ }
                    delete calEl._eventCalendar;
                    // Also remove from internal state map
                    if (EventCalendar.state && EventCalendar.state.instances) {
                        EventCalendar.state.instances.delete(calEl);
                    }
                    calEl.innerHTML = ''; // Clear stale DOM structure
                }
                // Wait one frame so the modal layout is computed (display:flex is active)
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        EventCalendar.create(calEl, {
                            api: 'api/booking/calendar',
                            onEventClickApi: 'api/booking/view?id={id}',
                            view: 'month',
                            showToday: true,
                            showViewSwitcher: true,
                            showPeriodPicker: true,
                            showFilter: true,
                            filterTarget: '#modal_calendar_room_filter',
                            onDateClick: (date, instance) => {
                                const events = typeof EventCalendar.getEventsForDate === 'function'
                                    ? EventCalendar.getEventsForDate(EventCalendar.getFilteredEvents(instance), date)
                                    : [];
                                EventCalendar.showDayDetailModal(instance, date, events);
                            }
                        });
                    });
                });
            }
        };

        const closeModal = () => {
            modal.classList.remove('is-active', 'show');
            modal.setAttribute('aria-hidden', 'true');
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        };

        onOpenModalClick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            openModal();
        };

        onCloseModalClick = (e) => {
            e.preventDefault();
            closeModal();
        };

        onModalBackdropClick = (e) => {
            if (e.target === modal) {
                closeModal();
            }
        };

        onModalKeyDown = (e) => {
            if (e.key === 'Escape' && modal.classList.contains('is-active')) {
                // If a child modal (day details or room/booking details) is currently visible, let it close first
                const childModal = document.querySelector('.modal.show, .modal[aria-hidden="false"]:not([inert])');
                if (childModal) {
                    return;
                }
                closeModal();
            }
        };

        const onChildModalClose = () => {
            if (modal && modal.classList.contains('is-active')) {
                document.documentElement.style.overflow = 'hidden';
                document.body.style.overflow = 'hidden';
            }
        };
        document.addEventListener('transitionend', onChildModalClose);

        openModalBtn.addEventListener('click', onOpenModalClick);
        if (closeModalBtn) closeModalBtn.addEventListener('click', onCloseModalClick);
        modal.addEventListener('click', onModalBackdropClick);
        document.addEventListener('keydown', onModalKeyDown);
    }

    return () => {
        clearTimeout(timer1);
        clearTimeout(timer2);
        clearTimeout(timer3);
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        if (openModalBtn && onOpenModalClick) openModalBtn.removeEventListener('click', onOpenModalClick);
        if (closeModalBtn && onCloseModalClick) closeModalBtn.removeEventListener('click', onCloseModalClick);
        if (modal && onModalBackdropClick) modal.removeEventListener('click', onModalBackdropClick);
        if (onModalKeyDown) document.removeEventListener('keydown', onModalKeyDown);
        if (typeof onChildModalClose === 'function') document.removeEventListener('transitionend', onChildModalClose);
        if (modal) {
            modal.classList.remove('is-active', 'show');
            if (modal.parentElement === document.body) modal.remove();
        }
        element.removeEventListener('submit', onSubmit, true);
        if (roomCombobox) {
            roomCombobox.destroy();
        }
        if (roomIdInput) {
            roomIdInput.removeEventListener('optionschanged', onOptionsChanged);
        }
        if (useSelect) {
            useSelect.removeEventListener('optionschanged', onOptionsChanged);
        }
        if (accessoriesSelect) {
            accessoriesSelect.removeEventListener('optionschanged', onOptionsChanged);
            accessoriesSelect.removeEventListener('change', onAccessoriesChange);
        }
        validationCleanups.forEach(cleanup => cleanup());
        pickerBadges.forEach(badge => badge.removeEventListener('click', onAnyBadgeClick));
        if (scheduleType) scheduleType.removeEventListener('change', scheduleChange);
        if (recurrenceDays) recurrenceDays.removeEventListener('change', updateLiveSummary);
        if (roomIdInput) roomIdInput.removeEventListener('change', updateLiveSummary);
        if (attendeesInput) attendeesInput.removeEventListener('input', updateLiveSummary);
        if (topicInput) topicInput.removeEventListener('input', updateLiveSummary);
        if (useInput) useInput.removeEventListener('change', onUseChange);
        if (beginDateInput) beginDateInput.removeEventListener('change', updateLiveSummary);
        if (beginTimeInput) beginTimeInput.removeEventListener('input', updateLiveSummary);
        if (endDateInput) endDateInput.removeEventListener('change', updateLiveSummary);
        if (endTimeInput) endTimeInput.removeEventListener('input', updateLiveSummary);
    };
}

// Ensure DatePicker calendar has correct Thai/English locale, arrows, and exact month days
(function patchDatePickerCalendar() {
    // Thai month and weekday names using Unicode escapes to guarantee zero encoding corruption
    const TH_MONTHS = [
        '\u0e21\u0e01\u0e23\u0e32\u0e04\u0e21', // มกราคม
        '\u0e01\u0e38\u0e21\u0e20\u0e32\u0e1e\u0e31\u0e19\u0e18\u0e4c', // กุมภาพันธ์
        '\u0e21\u0e35\u0e19\u0e32\u0e04\u0e21', // มีนาคม
        '\u0e40\u0e21\u0e29\u0e32\u0e22\u0e19', // เมษายน
        '\u0e1e\u0e24\u0e29\u0e20\u0e32\u0e04\u0e21', // พฤษภาคม
        '\u0e21\u0e34\u0e16\u0e38\u0e19\u0e32\u0e22\u0e19', // มิถุนายน
        '\u0e01\u0e23\u0e01\u0e0e\u0e32\u0e04\u0e21', // กรกฎาคม
        '\u0e2a\u0e34\u0e07\u0e2b\u0e32\u0e04\u0e21', // สิงหาคม
        '\u0e01\u0e31\u0e19\u0e22\u0e32\u0e22\u0e19', // กันยายน
        '\u0e15\u0e38\u0e25\u0e32\u0e04\u0e21', // ตุลาคม
        '\u0e1e\u0e24\u0e28\u0e08\u0e34\u0e01\u0e32\u0e22\u0e19', // พฤศจิกายน
        '\u0e18\u0e31\u0e19\u0e27\u0e32\u0e04\u0e21' // ธันวาคม
    ];
    const TH_MONTHS_SHORT = [
        '\u0e21\u002e\u0e04\u002e', '\u0e01\u002e\u0e1e\u002e', '\u0e21\u0e35\u002e\u0e04\u002e',
        '\u0e40\u0e21\u002e\u0e22\u002e', '\u0e1e\u002e\u0e04\u002e', '\u0e21\u0e34\u002e\u0e22\u002e',
        '\u0e01\u002e\u0e04\u002e', '\u0e2a\u002e\u0e04\u002e', '\u0e01\u002e\u0e22\u002e',
        '\u0e15\u002e\u0e04\u002e', '\u0e1e\u002e\u0e22\u002e', '\u0e18\u002e\u0e04\u002e'
    ];
    const TH_DAYS_NARROW = [
        '\u0e2d\u0e32', '\u0e08', '\u0e2d', '\u0e1e', '\u0e1e\u0e24', '\u0e28', '\u0e2a'
    ];

    const EN_MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    const EN_MONTHS_SHORT = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
    ];
    const EN_DAYS_NARROW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    function getDocLocale() {
        return ((document.documentElement && document.documentElement.lang) || 'th').toLowerCase();
    }

    // Ensure global Utils.date returns proper UTF-8 names to prevent mojibake
    window.Utils = window.Utils || {};
    window.Utils.date = window.Utils.date || {};
    window.Utils.date.getMonthNames = function(locale) {
        const loc = (locale || getDocLocale()).toLowerCase();
        return loc.startsWith('th') ? TH_MONTHS : EN_MONTHS;
    };
    window.Utils.date.getMonthNamesShort = function(locale) {
        const loc = (locale || getDocLocale()).toLowerCase();
        return loc.startsWith('th') ? TH_MONTHS_SHORT : EN_MONTHS_SHORT;
    };
    window.Utils.date.getDayNames = function(locale) {
        const loc = (locale || getDocLocale()).toLowerCase();
        return loc.startsWith('th') ? TH_DAYS_NARROW : EN_DAYS_NARROW;
    };
    window.Utils.date.getDayNamesNarrow = function(locale) {
        const loc = (locale || getDocLocale()).toLowerCase();
        return loc.startsWith('th') ? TH_DAYS_NARROW : EN_DAYS_NARROW;
    };
    window.Utils.date.getDayNamesShort = function(locale) {
        const loc = (locale || getDocLocale()).toLowerCase();
        return loc.startsWith('th') ? TH_DAYS_NARROW : EN_DAYS_NARROW;
    };

    function fixCalendarHeaderElements(instance) {
        if (!instance) return;
        // Navigation arrows: use HTML entities to guarantee no character encoding corruption
        if (instance.prevButton) instance.prevButton.innerHTML = '&lsaquo;';
        if (instance.nextButton) instance.nextButton.innerHTML = '&rsaquo;';

        const isThai = !instance.displayLocale || instance.displayLocale.startsWith('th');

        // Month title
        if (instance.monthDisplay && typeof instance.currentMonth === 'number') {
            instance.monthDisplay.textContent = isThai ? TH_MONTHS[instance.currentMonth] : EN_MONTHS[instance.currentMonth];
        }

        // Weekday header names
        if (instance.daysHeader) {
            const dayHeaders = instance.daysHeader.querySelectorAll('.day-name');
            const dayLabels = isThai ? TH_DAYS_NARROW : EN_DAYS_NARROW;
            dayHeaders.forEach((el, idx) => {
                if (dayLabels[idx]) el.textContent = dayLabels[idx];
            });
        }
    }

    function patchProto(proto) {
        if (!proto || proto._customDatePickerPatched) return;
        proto._customDatePickerPatched = true;

        const origRenderCalendar = proto._renderCalendar;
        if (typeof origRenderCalendar === 'function') {
            proto._renderCalendar = function() {
                origRenderCalendar.call(this);

                // Fix header texts & arrows immediately after rendering
                fixCalendarHeaderElements(this);

                // Prune other-month days to only show days belonging to this month
                if (this.calendarGrid) {
                    const days = Array.from(this.calendarGrid.children);
                    let foundCurrentMonth = false;

                    days.forEach((dayEl) => {
                        if (!dayEl.classList.contains('other-month')) {
                            foundCurrentMonth = true;
                        } else if (!foundCurrentMonth) {
                            // Leading days before Day 1: keep grid cell, blank & invisible
                            dayEl.textContent = '';
                            dayEl.className = 'calendar-day other-month empty';
                            dayEl.style.visibility = 'hidden';
                            dayEl.style.pointerEvents = 'none';
                            dayEl.style.border = 'none';
                            dayEl.style.background = 'transparent';
                        } else {
                            // Trailing days after last day of month: remove completely
                            dayEl.remove();
                        }
                    });
                }
            };
        }

        const origRenderMonthPicker = proto._renderMonthPicker;
        if (typeof origRenderMonthPicker === 'function') {
            proto._renderMonthPicker = function() {
                origRenderMonthPicker.call(this);
                if (this.prevButton) this.prevButton.innerHTML = '&lsaquo;';
                if (this.nextButton) this.nextButton.innerHTML = '&rsaquo;';
                const isThai = !this.displayLocale || this.displayLocale.startsWith('th');
                const monthLabels = isThai ? TH_MONTHS_SHORT : EN_MONTHS_SHORT;
                if (this.calendarGrid) {
                    const monthButtons = this.calendarGrid.querySelectorAll('.calendar-month');
                    monthButtons.forEach((btn, idx) => {
                        if (monthLabels[idx]) btn.textContent = monthLabels[idx];
                    });
                }
            };
        }

        const origRenderYearPicker = proto._renderYearPicker;
        if (typeof origRenderYearPicker === 'function') {
            proto._renderYearPicker = function() {
                origRenderYearPicker.call(this);
                if (this.prevButton) this.prevButton.innerHTML = '&lsaquo;';
                if (this.nextButton) this.nextButton.innerHTML = '&rsaquo;';
            };
        }

        const origOpen = proto.open;
        if (typeof origOpen === 'function') {
            proto.open = function() {
                const res = origOpen.apply(this, arguments);
                fixCalendarHeaderElements(this);
                return res;
            };
        }
    }

    if (window.DateElementFactory) {
        const origSetupElement = window.DateElementFactory.setupElement;
        if (origSetupElement) {
            window.DateElementFactory.setupElement = function(instance) {
                const res = origSetupElement.apply(this, arguments);
                if (instance && instance.gdatetime) {
                    patchProto(Object.getPrototypeOf(instance.gdatetime));
                    fixCalendarHeaderElements(instance.gdatetime);
                }
                return res;
            };
        }
    }
})();
