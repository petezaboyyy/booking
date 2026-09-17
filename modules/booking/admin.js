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

const ROOMLY_ROLE_UI_VERSION = '20260915-occurrences-5';

function roleTemplateGuard(routePath, memberTemplate, adminTemplate, memberTitle, adminTitle = memberTitle) {
    return (_params, _from, authManager) => {
        const user = authManager?.getUser?.() || authManager?.state?.user;
        const isAdmin = typeof window.BookingUiRole?.usesAdminUi === 'function'
            ? window.BookingUiRole.usesAdminUi(user)
            : Number(user?.status) === 1;
        const route = RouterManager.routes?.get(routePath);

        if (route) {
            const selectedTemplate = isAdmin ? adminTemplate : memberTemplate;
            route.template = window.location.origin
                + '/templates/' + selectedTemplate
                + '?v=' + ROOMLY_ROLE_UI_VERSION;
            route.title = isAdmin ? adminTitle : memberTitle;
        }

        document.documentElement.dataset.uiRole = isAdmin ? 'admin' : 'member';
        return true;
    };
}

EventManager.on('router:initialized', () => {
    RouterManager.register('/rooms', {
        template: window.location.origin + '/templates/booking/catalog.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_All rooms}',
        beforeEnter: roleTemplateGuard(
            '/rooms',
            'booking/catalog.html',
            'booking/catalog-admin.html',
            '{LNG_All rooms}'
        ),
        requireAuth: true
    });

    RouterManager.register('/my-bookings', {
        template: window.location.origin + '/templates/booking/my-bookings.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_My bookings}',
        beforeEnter: roleTemplateGuard(
            '/my-bookings',
            'booking/my-bookings.html',
            'booking/my-bookings-admin.html',
            '{LNG_My bookings}'
        ),
        requireAuth: true
    });

    RouterManager.register('/booking', {
        template: window.location.origin + '/templates/booking/booking-recurring.html?v=' + ROOMLY_ROLE_UI_VERSION,
        title: '{LNG_Book a room}',
        beforeEnter: roleTemplateGuard(
            '/booking',
            'booking/booking-recurring.html',
            'booking/booking-recurring-admin.html',
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
        template: window.location.origin + '/templates/index.html?v=' + ROOMLY_ROLE_UI_VERSION,
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
function formatTime(time) {
    if (!time) return '';
    const str = String(time).trim();
    if (!str) return '';

    // e.g. "12" or "9" -> "12:00", "09:00"
    if (/^\d{1,2}$/.test(str)) {
        const num = parseInt(str, 10);
        if (num >= 0 && num <= 23) {
            return str.padStart(2, '0') + ':00';
        }
        return str;
    }

    // e.g. "12:" -> "12:00"
    if (/^\d{1,2}:$/.test(str)) {
        const num = parseInt(str.slice(0, -1), 10);
        if (num >= 0 && num <= 23) {
            return str.slice(0, -1).padStart(2, '0') + ':00';
        }
        return str;
    }

    // e.g. "12:0" -> "12:00", "12:3" -> "12:30", "9:30" -> "09:30"
    const match = /^(\d{1,2}):(\d{0,2})$/.exec(str);
    if (match) {
        const h = parseInt(match[1], 10);
        if (h >= 0 && h <= 23) {
            const hStr = match[1].padStart(2, '0');
            const mStr = (match[2] || '00').padEnd(2, '0').slice(0, 2);
            return `${hStr}:${mStr}`;
        }
    }

    return str;
}

function formatSummaryDate(isoDate) {
    if (!isoDate) return '';
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(isoDate).trim());
    if (!match) return isoDate;
    const y = parseInt(match[1], 10);
    const m = match[2];
    const d = match[3];
    const currentLang = (document.documentElement.lang || (window.I18nManager?.getCurrentLocale ? window.I18nManager.getCurrentLocale() : 'th')).toLowerCase();
    const isThai = currentLang.startsWith('th');
    const displayYear = isThai ? (y + 543) : y;
    return `${d}/${m}/${displayYear}`;
}

function initBookingForm(element, data) {
    const scheduleType = element.querySelector('#schedule_type');
    const recurrenceDays = element.querySelector('#recurrence_days');
    const recurrenceGroup = recurrenceDays ? recurrenceDays.closest('.roomly-recurrence-days-group, .width50') : null;

    const roomIdInput = element.querySelector('#room_id');
    const attendeesInput = element.querySelector('#attendees');
    const topicInput = element.querySelector('#topic');
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
        const useInput = element.querySelector('#use');
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
        if (topicInput && topicInput.tagName === 'SELECT') {
            if (topicInput.options && topicInput.options.length > 0) {
                const firstOpt = topicInput.options[0];
                if (!firstOpt.value) {
                    firstOpt.textContent = '';
                    firstOpt.removeAttribute('data-i18n');
                    delete firstOpt.dataset.i18n;
                    firstOpt.dataset.placeholder = 'true';
                }
            }
            if (!topicInput.value || topicInput.value === '0' || topicInput.selectedIndex <= 0) {
                topicInput.value = '';
                if (topicInput.options && topicInput.options.length > 0) {
                    topicInput.selectedIndex = 0;
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

    const updateLiveSummary = () => {
        ensureSelectPlaceholders();
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
                    summaryRoomImg.onerror = () => {
                        summaryRoomImg.style.display = 'none';
                        summaryRoomIcon.style.display = '';
                    };
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
            const selectedOpt = topicInput.selectedOptions?.[0];
            const topicText = selectedOpt && selectedOpt.value ? selectedOpt.textContent.trim() : topicInput.value.trim();
            summaryTopic.textContent = topicText || '—';
        }

        // 3. Attendees
        const attTarget = summaryAttendees || element.querySelector('#summary-attendees') || document.querySelector('#summary-attendees');
        const attSrc = attendeesInput || element.querySelector('#attendees') || document.querySelector('#attendees');
        if (attTarget && attSrc) {
            const rawVal = (attSrc.value !== undefined && attSrc.value !== null) ? String(attSrc.value).trim() : '';
            const n = parseInt(rawVal, 10);
            attTarget.textContent = (!isNaN(n) && n > 0) ? String(n) : '—';
        }

        // 4. Dates (Thai format: วัน/เดือน/ปี)
        if (summaryDates && beginDateInput) {
            const bDate = beginDateInput.value;
            const eDate = endDateInput ? endDateInput.value : '';
            if (bDate && eDate && bDate !== eDate) {
                summaryDates.textContent = `${formatSummaryDate(bDate)} → ${formatSummaryDate(eDate)}`;
            } else if (bDate) {
                summaryDates.textContent = formatSummaryDate(bDate);
            } else {
                summaryDates.textContent = '—';
            }
        }

        // 5. Times
        if (summaryTimes && beginTimeInput) {
            const bTime = formatTime(beginTimeInput.value);
            const eTime = formatTime(endTimeInput ? endTimeInput.value : '');
            if (bTime && eTime) {
                summaryTimes.textContent = `${bTime} - ${eTime}`;
            } else if (bTime) {
                summaryTimes.textContent = bTime;
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

    // Staff screens render templates/booking/booking-recurring.html, which
    // carries the per-date picker this UI has no markup for.
    const occurrencePickerCleanup = initOccurrencePicker(element, data);

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
        ['input', 'change', 'keyup', 'paste', 'blur'].forEach(evt => {
            attendeesInput.addEventListener(evt, updateLiveSummary);
        });
    }
    if (topicInput) {
        topicInput.addEventListener('input', updateLiveSummary);
        topicInput.addEventListener('change', updateLiveSummary);
    }
    const updateTimePlaceholders = () => {
        const curLang = (document.documentElement.getAttribute('lang') || (window.I18nManager?.getCurrentLocale ? window.I18nManager.getCurrentLocale() : null) || window.Now?.getLocale?.() || 'th').toLowerCase();
        const isThai = curLang.startsWith('th');
        if (beginTimeInput) {
            beginTimeInput.placeholder = isThai ? 'ชช:นน (เช่น 08:30)' : 'HH:MM (e.g. 08:30)';
        }
        if (endTimeInput) {
            endTimeInput.placeholder = isThai ? 'ชช:นน (เช่น 12:00)' : 'HH:MM (e.g. 12:00)';
        }
        if (beginDateInput) {
            beginDateInput.placeholder = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
        }
        if (endDateInput) {
            endDateInput.placeholder = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
        }
    };
    updateTimePlaceholders();
    const onLocaleChange = () => {
        updateTimePlaceholders();
        updateLiveSummary();
    };
    window.addEventListener('locale:changed', onLocaleChange);
    window.EventManager?.on?.('locale:changed', onLocaleChange);
    window.EventManager?.on?.('i18n:updated', onLocaleChange);
    if (beginDateInput) {
        beginDateInput.addEventListener('change', updateLiveSummary);
        beginDateInput.addEventListener('input', updateLiveSummary);
    }
    if (beginTimeInput) {
        beginTimeInput.addEventListener('input', updateLiveSummary);
        beginTimeInput.addEventListener('change', updateLiveSummary);
        beginTimeInput.addEventListener('blur', () => {
            const normalized = formatTime(beginTimeInput.value);
            if (normalized && normalized !== beginTimeInput.value) {
                beginTimeInput.value = normalized;
                beginTimeInput.dispatchEvent(new Event('input'));
                beginTimeInput.dispatchEvent(new Event('change'));
            }
        });
    }
    if (endDateInput) {
        endDateInput.addEventListener('change', updateLiveSummary);
        endDateInput.addEventListener('input', updateLiveSummary);
    }
    if (endTimeInput) {
        endTimeInput.addEventListener('input', updateLiveSummary);
        endTimeInput.addEventListener('change', updateLiveSummary);
        endTimeInput.addEventListener('blur', () => {
            const normalized = formatTime(endTimeInput.value);
            if (normalized && normalized !== endTimeInput.value) {
                endTimeInput.value = normalized;
                endTimeInput.dispatchEvent(new Event('input'));
                endTimeInput.dispatchEvent(new Event('change'));
            }
        });
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
        topicInput,
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

        const onFocusOut = () => {
            setTimeout(() => {
                if (wrap && wrap.contains(document.activeElement)) return;
                const fc = wrap ? wrap.querySelector('.form-control') : null;
                if (fc && fc.classList.contains('is-open')) return;
                if (input._userClicked) {
                    input._touched = true;
                    checkFieldValidity(input);
                }
            }, 150);
        };

        input.addEventListener('focus', onUserFocus);
        input.addEventListener('pointerdown', onUserFocus);
        if (wrap) {
            wrap.addEventListener('pointerdown', onUserFocus);
            wrap.addEventListener('focusin', onUserFocus);
            wrap.addEventListener('focusout', onFocusOut);
        }
        input.addEventListener('blur', onBlur);
        input.addEventListener('input', onInput);
        input.addEventListener('change', onChange);

        validationCleanups.push(
            () => input.removeEventListener('focus', onUserFocus),
            () => input.removeEventListener('pointerdown', onUserFocus),
            () => {
                if (wrap) {
                    wrap.removeEventListener('pointerdown', onUserFocus);
                    wrap.removeEventListener('focusin', onUserFocus);
                    wrap.removeEventListener('focusout', onFocusOut);
                }
            },
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
            if (firstInvalid.id === 'room_id') {
                const searchEl = element.querySelector('#room_id_search');
                if (searchEl) searchEl.focus();
                else firstInvalid.focus();
            } else if (firstInvalid.id === 'topic') {
                const searchEl = element.querySelector('#topic_search');
                if (searchEl) searchEl.focus();
                else firstInvalid.focus();
            } else {
                firstInvalid.focus();
            }
            return false;
        }
    };
    element.addEventListener('submit', onSubmit, true);

    // =========================================================================
    // Searchable & Animated Dropdown Combobox for Form Select Elements
    // =========================================================================
    function createRoomlyCombobox(selectEl, config = {}) {
        if (!selectEl || selectEl._roomlyComboboxInitialized) return null;
        const container = selectEl.closest('.roomly-select-btn');
        if (!container) return null;

        container.classList.add('roomly-combobox');
        const isSearchable = config.searchable !== false;

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'roomly-combobox-input';
        searchInput.id = `${selectEl.id || 'select'}_search`;
        searchInput.autocomplete = 'off';
        searchInput.spellcheck = false;
        searchInput.placeholder = typeof config.placeholder === 'function'
            ? config.placeholder()
            : (config.placeholder || '');
        searchInput.setAttribute('role', 'combobox');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.setAttribute('aria-autocomplete', isSearchable ? 'list' : 'none');
        if (!isSearchable) {
            searchInput.readOnly = true;
            searchInput.style.cursor = 'pointer';
            searchInput.style.userSelect = 'none';
            searchInput.style.webkitUserSelect = 'none';
        }

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'roomly-combobox-clear';
        clearBtn.setAttribute('aria-label', 'Clear selection');
        clearBtn.innerHTML = '&times;';
        clearBtn.style.display = 'none';

        const dropdown = document.createElement('div');
        dropdown.className = 'roomly-combobox-dropdown';
        dropdown.setAttribute('role', 'listbox');
        dropdown.style.display = 'none';
        document.body.appendChild(dropdown);

        container.insertBefore(searchInput, selectEl);
        if (isSearchable && config.clearable !== false) {
            container.insertBefore(clearBtn, selectEl);
        }

        let activeIndex = -1;
        let isOpen = false;
        let justFocused = false;

        const updateClearBtn = () => {
            if (!clearBtn.parentElement) return;
            const hasValue = Boolean((searchInput.value && searchInput.value.trim()) || (selectEl.value && selectEl.value !== '0'));
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
            dropdown.style.minWidth = '240px';
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

        const getAvailableOptions = () => {
            let opts = Array.from(selectEl.options || []).filter(opt => opt.value && opt.value !== '' && opt.value !== '0' && !opt.disabled);
            if (opts.length === 0 && config.getFallbackOptions) {
                const fallback = config.getFallbackOptions();
                if (Array.isArray(fallback)) {
                    fallback.forEach(item => {
                        if (item && item.value && item.value !== '' && item.value !== '0') {
                            const opt = document.createElement('option');
                            opt.value = String(item.value);
                            opt.textContent = item.label || item.text || item.name || String(item.value);
                            if (item.image) opt.dataset.image = item.image;
                            selectEl.appendChild(opt);
                        }
                    });
                    opts = Array.from(selectEl.options || []).filter(opt => opt.value && opt.value !== '' && opt.value !== '0' && !opt.disabled);
                }
            }
            return opts;
        };

        const renderItems = (filterQuery = '') => {
            const query = isSearchable ? filterQuery.trim().toLowerCase() : '';
            dropdown.innerHTML = '';
            const options = getAvailableOptions();

            let matchCount = 0;
            options.forEach(opt => {
                const text = opt.textContent.trim();
                const val = String(opt.value);
                const isMatch = !query || text.toLowerCase().includes(query);

                if (!isMatch) return;
                matchCount++;

                const item = document.createElement('div');
                item.className = 'roomly-combobox-item' + (String(selectEl.value) === val ? ' is-selected' : '');
                item.dataset.value = val;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(selectEl.value) === val ? 'true' : 'false');

                const iconWrap = document.createElement('span');
                iconWrap.className = 'roomly-combobox-item-icon';
                if (typeof config.renderItemIcon === 'function') {
                    config.renderItemIcon(iconWrap, opt, val, text);
                } else {
                    iconWrap.innerHTML = '<span class="icon-bookmark" aria-hidden="true"></span>';
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


                const handleSelect = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    selectOption(val, text);
                };
                item.addEventListener('pointerdown', handleSelect);
                item.addEventListener('click', handleSelect);

                dropdown.appendChild(item);
            });

            if (matchCount === 0) {
                const emptyEl = document.createElement('div');
                emptyEl.className = 'roomly-combobox-empty';
                const emptyText = typeof config.getEmptyText === 'function'
                    ? config.getEmptyText()
                    : (window.Now && typeof Now.translate === 'function'
                        ? Now.translate('No options found')
                        : 'ไม่พบรายการที่ตรงกับการค้นหา');
                emptyEl.textContent = emptyText;
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
            if (selectEl._userClicked) {
                selectEl._touched = true;
                checkFieldValidity(selectEl);
            }
        };

        const syncInputWithSelect = () => {
            const selectedOpt = selectEl.selectedOptions?.[0];
            if (selectedOpt && selectedOpt.value && selectedOpt.value !== '0') {
                searchInput.value = selectedOpt.textContent.trim();
            } else if (!isOpen) {
                searchInput.value = '';
            }
            updateClearBtn();
        };

        const selectOption = (val, text) => {
            selectEl.value = val;
            Array.from(selectEl.options).forEach((opt, idx) => {
                opt.selected = (opt.value === val);
                if (opt.value === val) selectEl.selectedIndex = idx;
            });
            searchInput.value = text;
            updateClearBtn();
            closeDropdown();
            const wrap = selectEl.closest('.roomly-field-wrap');
            if (wrap) wrap.classList.remove('has-error');
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            selectEl.dispatchEvent(new Event('input', { bubbles: true }));
            if (typeof config.onSelect === 'function') {
                config.onSelect(val, text);
            }
        };

        searchInput.addEventListener('focus', () => {
            selectEl._userClicked = true;
            if (!isOpen) {
                openDropdown('');
                justFocused = true;
                setTimeout(() => {
                    justFocused = false;
                }, 250);
            }
            if (isSearchable) {
                setTimeout(() => {
                    searchInput.select();
                }, 20);
            }
        });

        searchInput.addEventListener('click', (e) => {
            selectEl._userClicked = true;
            if (justFocused) {
                justFocused = false;
                return;
            }
            if (isOpen) {
                closeDropdown();
            } else {
                openDropdown('');
            }
        });

        searchInput.addEventListener('blur', () => {
            setTimeout(() => {
                if (isOpen) return;
                if (selectEl._userClicked) {
                    selectEl._touched = true;
                    checkFieldValidity(selectEl);
                }
            }, 150);
        });

        if (isSearchable) {
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
        }

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
                if (items.length === 0) return;
                activeIndex = (activeIndex + 1) % items.length;
                highlightItem(items, activeIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length === 0) return;
                activeIndex = (activeIndex - 1 + items.length) % items.length;
                highlightItem(items, activeIndex);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && items[activeIndex]) {
                    const item = items[activeIndex];
                    selectOption(item.dataset.value, item.querySelector('.roomly-combobox-item-text')?.textContent || item.textContent);
                } else if (items.length === 1) {
                    selectOption(items[0].dataset.value, items[0].querySelector('.roomly-combobox-item-text')?.textContent || items[0].textContent);
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
            selectEl._userClicked = true;
            selectEl.value = '';
            selectEl.selectedIndex = 0;
            Array.from(selectEl.options).forEach((opt, idx) => {
                opt.selected = (idx === 0);
            });
            searchInput.value = '';
            updateClearBtn();
            closeDropdown();
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            selectEl.dispatchEvent(new Event('input', { bubbles: true }));
            if (typeof config.onSelect === 'function') {
                config.onSelect('', '');
            }
        });

        const arrow = container.querySelector('.roomly-select-arrow');
        if (arrow) {
            arrow.style.cursor = 'pointer';
            arrow.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                selectEl._userClicked = true;
                if (isOpen) {
                    closeDropdown();
                } else {
                    searchInput.focus();
                    openDropdown('');
                }
            });
        }

        container.addEventListener('pointerdown', () => {
            selectEl._userClicked = true;
        });

        container.addEventListener('click', (e) => {
            selectEl._userClicked = true;
            if (e.target === clearBtn || clearBtn.contains(e.target) || dropdown.contains(e.target) || e.target === searchInput || (arrow && arrow.contains(e.target))) return;
            if (!isOpen) {
                searchInput.focus();
                openDropdown('');
            } else {
                closeDropdown();
            }
        });

        // Stop pointerdown inside dropdown from closing it
        dropdown.addEventListener('pointerdown', (e) => {
            e.stopPropagation();
        });

        const onDocClick = (e) => {
            if (!container.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
                if (selectEl._userClicked) {
                    selectEl._touched = true;
                    checkFieldValidity(selectEl);
                }
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

        const observer = new MutationObserver(() => {
            syncInputWithSelect();
            if (isOpen) renderItems(searchInput.value || '');
        });
        observer.observe(selectEl, { childList: true, subtree: true, attributes: true, attributeFilter: ['value', 'selected'] });

        selectEl.addEventListener('change', syncInputWithSelect);

        selectEl._roomlyComboboxInitialized = true;
        syncInputWithSelect();

        return {
            open: openDropdown,
            close: closeDropdown,
            sync: () => {
                syncInputWithSelect();
                if (isOpen) renderItems(searchInput.value || '');
            },
            destroy: () => {
                document.removeEventListener('pointerdown', onDocClick);
                window.removeEventListener('scroll', onViewportChange, true);
                window.removeEventListener('resize', onViewportChange);
                observer.disconnect();
                selectEl.removeEventListener('change', syncInputWithSelect);
                searchInput.remove();
                clearBtn.remove();
                dropdown.remove();
                container.classList.remove('roomly-combobox');
                selectEl._roomlyComboboxInitialized = false;
            }
        };
    }

    // 1. Room Combobox
    let roomCombobox = null;
    if (roomIdInput) {
        roomCombobox = createRoomlyCombobox(roomIdInput, {
            searchable: true,
            placeholder: () => (window.Now && typeof Now.translate === 'function')
                ? Now.translate('Select or search room...')
                : 'พิมพ์ค้นหา หรือเลือกห้อง...',
            getEmptyText: () => (window.Now && typeof Now.translate === 'function')
                ? Now.translate('No rooms found')
                : 'ไม่พบห้องที่ตรงกับการค้นหา',
            getFallbackOptions: () => data?.options?.room_id,
            renderItemIcon: (iconWrap, opt, val, text) => {
                const img = (opt.dataset && opt.dataset.image) || roomImageMap.get(val) || '';
                if (img) {
                    const imgEl = document.createElement('img');
                    imgEl.src = img;
                    imgEl.alt = text;
                    iconWrap.appendChild(imgEl);
                } else {
                    iconWrap.innerHTML = '<span class="icon-office" aria-hidden="true"></span>';
                }
            },
            onSelect: () => {
                updateLiveSummary();
            }
        });
    }

    // 2. Topic Combobox
    let topicCombobox = null;
    if (topicInput) {
        topicCombobox = createRoomlyCombobox(topicInput, {
            searchable: true,
            placeholder: () => (window.Now && typeof Now.translate === 'function')
                ? Now.translate('Select or search topic...')
                : 'พิมพ์ค้นหา หรือเลือกหัวข้อ...',
            getEmptyText: () => (window.Now && typeof Now.translate === 'function')
                ? Now.translate('No topics found')
                : 'ไม่พบหัวข้อที่ตรงกับการค้นหา',
            getFallbackOptions: () => data?.options?.topic,
            renderItemIcon: (iconWrap) => {
                iconWrap.innerHTML = '<span class="icon-bookmark" aria-hidden="true"></span>';
            },
            onSelect: () => {
                updateLiveSummary();
            }
        });
    }

    // 3. Repeat (Schedule Type) Selector
    let scheduleTypeCombobox = null;
    if (scheduleType) {
        scheduleTypeCombobox = createRoomlyCombobox(scheduleType, {
            searchable: false,
            clearable: false,
            placeholder: () => (window.Now && typeof Now.translate === 'function')
                ? Now.translate('Select repeat option...')
                : 'เลือกรูปแบบการเกิดซ้ำ...',
            renderItemIcon: (iconWrap, opt, val) => {
                if (val === 'weekly-slot') {
                    iconWrap.innerHTML = '<span class="icon-shuffle" aria-hidden="true"></span>';
                } else {
                    iconWrap.innerHTML = '<span class="icon-calendar" aria-hidden="true"></span>';
                }
            },
            onSelect: () => {
                if (typeof scheduleChange === 'function') scheduleChange();
                updateLiveSummary();
            }
        });
    }

    // 4. Global sync for MultiSelect & DatePicker dropdown active state & rotating arrow
    const onDropdownShow = (e) => {
        const target = e?.detail?.target;
        if (target) {
            const fc = target.closest('.form-control') || (target.classList.contains('form-control') ? target : null);
            if (fc) fc.classList.add('is-open');
            const btn = target.querySelector('.dropdown-button') || (target.classList.contains('dropdown-button') ? target : null);
            if (btn) btn.setAttribute('aria-expanded', 'true');
        }
    };

    const onDropdownHide = (e) => {
        const target = e?.detail?.target;
        if (target) {
            const fc = target.closest('.form-control') || (target.classList.contains('form-control') ? target : null);
            if (fc) fc.classList.remove('is-open');
            const btn = target.querySelector('.dropdown-button') || (target.classList.contains('dropdown-button') ? target : null);
            if (btn) btn.setAttribute('aria-expanded', 'false');
            const wrap = target.closest?.('.roomly-field-wrap');
            const dateInput = target.querySelector?.('input') || wrap?.querySelector?.('input');
            if (dateInput && (dateInput.id === 'begin_date' || dateInput.id === 'end_date') && dateInput._userClicked) {
                dateInput._touched = true;
                checkFieldValidity(dateInput);
            }
        }
    };

    const onDateTimeOpen = (e) => {
        const target = e?.target;
        if (target) {
            const fc = target.closest('.form-control') || (target.classList.contains('form-control') ? target : null);
            if (fc) fc.classList.add('is-open');
            const btn = target.querySelector?.('.dropdown-button');
            if (btn) btn.setAttribute('aria-expanded', 'true');
            const wrap = target.closest?.('.roomly-field-wrap');
            const dateInput = target.querySelector?.('input') || wrap?.querySelector?.('input');
            if (dateInput) {
                dateInput._userClicked = true;
            }
        }
    };

    const onDateTimeClose = (e) => {
        const target = e?.target;
        if (target) {
            const fc = target.closest('.form-control') || (target.classList.contains('form-control') ? target : null);
            if (fc) fc.classList.remove('is-open');
            const btn = target.querySelector?.('.dropdown-button');
            if (btn) btn.setAttribute('aria-expanded', 'false');
            const wrap = target.closest?.('.roomly-field-wrap');
            const dateInput = target.querySelector?.('input') || wrap?.querySelector?.('input');
            if (dateInput && dateInput._userClicked) {
                dateInput._touched = true;
                checkFieldValidity(dateInput);
            }
        }
    };

    document.addEventListener('dropdown:show', onDropdownShow);
    document.addEventListener('dropdown:hide', onDropdownHide);
    document.addEventListener('gdatetime:open', onDateTimeOpen, true);
    document.addEventListener('gdatetime:close', onDateTimeClose, true);

    const onOptionsChanged = (e) => {
        const optsList = e?.detail?.options || data?.options?.room_id;
        if (Array.isArray(optsList)) {
            optsList.forEach(opt => {
                if (opt && opt.value && opt.image) {
                    roomImageMap.set(String(opt.value), opt.image);
                }
            });
        }
        if (roomIdInput && roomIdInput.options) {
            Array.from(roomIdInput.options).forEach(opt => {
                if (opt.value && opt.dataset && opt.dataset.image) {
                    roomImageMap.set(String(opt.value), opt.dataset.image);
                } else if (opt.value && roomImageMap.has(String(opt.value))) {
                    opt.dataset.image = roomImageMap.get(String(opt.value));
                }
            });
        }
        if (roomCombobox) {
            roomCombobox.sync();
        }
        if (topicCombobox) {
            topicCombobox.sync();
        }
        if (scheduleTypeCombobox) {
            scheduleTypeCombobox.sync();
        }
        ensureSelectPlaceholders();
        clearInitialErrors();
        updateLiveSummary();
    };
    if (roomIdInput) {
        roomIdInput.addEventListener('optionschanged', onOptionsChanged);
    }
    if (topicInput) {
        topicInput.addEventListener('optionschanged', onOptionsChanged);
    }
    const useSelect = element.querySelector('#use');
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
        if (topicCombobox) {
            topicCombobox.destroy();
        }
        if (scheduleTypeCombobox) {
            scheduleTypeCombobox.destroy();
        }
        document.removeEventListener('dropdown:show', onDropdownShow);
        document.removeEventListener('dropdown:hide', onDropdownHide);
        document.removeEventListener('gdatetime:open', onDateTimeOpen, true);
        document.removeEventListener('gdatetime:close', onDateTimeClose, true);
        if (roomIdInput) {
            roomIdInput.removeEventListener('optionschanged', onOptionsChanged);
        }
        if (topicInput) {
            topicInput.removeEventListener('optionschanged', onOptionsChanged);
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
        occurrencePickerCleanup();
        if (scheduleType) scheduleType.removeEventListener('change', scheduleChange);
        if (recurrenceDays) recurrenceDays.removeEventListener('change', updateLiveSummary);
        if (roomIdInput) roomIdInput.removeEventListener('change', updateLiveSummary);
        if (attendeesInput) {
            ['input', 'change', 'keyup', 'paste', 'blur'].forEach(evt => {
                attendeesInput.removeEventListener(evt, updateLiveSummary);
            });
        }
        if (topicInput) topicInput.removeEventListener('input', updateLiveSummary);
        if (beginDateInput) {
            beginDateInput.removeEventListener('change', updateLiveSummary);
            beginDateInput.removeEventListener('input', updateLiveSummary);
        }
        if (beginTimeInput) {
            beginTimeInput.removeEventListener('input', updateLiveSummary);
            beginTimeInput.removeEventListener('change', updateLiveSummary);
        }
        if (endDateInput) {
            endDateInput.removeEventListener('change', updateLiveSummary);
            endDateInput.removeEventListener('input', updateLiveSummary);
        }
        if (endTimeInput) {
            endTimeInput.removeEventListener('input', updateLiveSummary);
            endTimeInput.removeEventListener('change', updateLiveSummary);
        }
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

        // Placeholder text when empty
        if (!instance.selectedDate && instance.displayButton && instance.displayButton.firstChild) {
            const currentText = instance.displayButton.firstChild.textContent?.trim();
            if (currentText === 'วว/ดด/ปปปป' || currentText === 'DD/MM/YYYY' || (instance._original && /วว\/ดด\/ปปปป|DD\/MM\/YYYY/.test(instance._original.getAttribute('placeholder') || ''))) {
                instance.displayButton.firstChild.textContent = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
            }
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

        const origUpdateLocale = proto._updateLocale;
        if (typeof origUpdateLocale === 'function') {
            proto._updateLocale = function() {
                origUpdateLocale.call(this);
                const isThai = !this.displayLocale || this.displayLocale.startsWith('th');
                if (!this.selectedDate && this.displayButton && this.displayButton.firstChild) {
                    const currentText = this.displayButton.firstChild.textContent?.trim();
                    if (currentText === 'วว/ดด/ปปปป' || currentText === 'DD/MM/YYYY' || (this._original && /วว\/ดด\/ปปปป|DD\/MM\/YYYY/.test(this._original.getAttribute('placeholder') || ''))) {
                        this.displayButton.firstChild.textContent = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
                    }
                }
            };
        }

        const origSetValue = proto.setValue;
        if (typeof origSetValue === 'function') {
            proto.setValue = function(value) {
                origSetValue.apply(this, arguments);
                if (!value && this.displayButton && this.displayButton.firstChild) {
                    const isThai = !this.displayLocale || this.displayLocale.startsWith('th');
                    const currentText = this.displayButton.firstChild.textContent?.trim();
                    if (currentText === 'วว/ดด/ปปปป' || currentText === 'DD/MM/YYYY' || (this._original && /วว\/ดด\/ปปปป|DD\/MM\/YYYY/.test(this._original.getAttribute('placeholder') || ''))) {
                        this.displayButton.firstChild.textContent = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
                    }
                }
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
                    const g = instance.gdatetime;
                    if (!g.selectedDate && g.displayButton && g.displayButton.firstChild) {
                        const isThai = !g.displayLocale || g.displayLocale.startsWith('th');
                        const currentText = g.displayButton.firstChild.textContent?.trim();
                        if (currentText === 'วว/ดด/ปปปป' || currentText === 'DD/MM/YYYY' || (g._original && /วว\/ดด\/ปปปป|DD\/MM\/YYYY/.test(g._original.getAttribute('placeholder') || ''))) {
                            g.displayButton.firstChild.textContent = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
                        }
                    }
                }
                return res;
            };
        }
    }

    const updateAllDatePlaceholders = (locale) => {
        const docLang = locale || (document.documentElement && document.documentElement.lang) || 'th';
        const isThai = docLang.toLowerCase().startsWith('th');
        document.querySelectorAll('.custom-datepicker').forEach(picker => {
            const display = picker.querySelector('.dropdown-display');
            const hidden = picker.querySelector('input[type="hidden"], input[type="date"]');
            if (display && (!hidden || !hidden.value)) {
                const txt = display.textContent?.trim();
                if (txt === 'วว/ดด/ปปปป' || txt === 'DD/MM/YYYY') {
                    display.textContent = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
                }
            }
        });
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.setAttribute('lang', isThai ? 'th' : 'en-GB');
            if (input.placeholder === 'วว/ดด/ปปปป' || input.placeholder === 'DD/MM/YYYY') {
                input.placeholder = isThai ? 'วว/ดด/ปปปป' : 'DD/MM/YYYY';
            }
        });
    };

    if (window.EventManager?.on) {
        EventManager.on('locale:changed', ({locale}) => updateAllDatePlaceholders(locale));
        EventManager.on('page:loaded', () => updateAllDatePlaceholders());
        EventManager.on('i18n:updated', ({locale}) => updateAllDatePlaceholders(locale));
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => updateAllDatePlaceholders());
    } else {
        updateAllDatePlaceholders();
    }
})();

/**
 * Per-date controls for a recurring request: the "uncheck dates you do not
 * want" picker on the booking form. Ported from modules/booking/admin.js,
 * which index.html no longer loads — the two files share top-level const
 * names, so only one booking script can run.
 *
 * Returns a no-op when the template has no picker (the member UI), so it is
 * safe to call from initBookingForm unconditionally.
 */
function initOccurrencePicker(element, data) {
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

    const parseExcluded = value => new Set(
        (Array.isArray(value) ? value : String(value || '').split(','))
            .map(item => String(item).trim())
            .filter(item => /^\d{4}-\d{2}-\d{2}$/.test(item))
    );
    const excluded = parseExcluded(data?.excluded_dates ?? excludedInput.value);

    // The staff template calls this through data-on-load, which hands over the loaded
    // booking. The member template uses data-script, which fires with route params only
    // and gets its values from data-binding afterwards — so pick up whatever landed in
    // the hidden input since the last pass. Union, never remove: the checkbox handler
    // keeps the input and this set in step, so the only new values are the bound ones.
    const adoptBoundExclusions = () => {
        parseExcluded(excludedInput.value).forEach(date => excluded.add(date));
    };
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
        adoptBoundExclusions();
        const first = parseDate(beginDate.value);
        const last = parseDate(endDate.value);
        preview.replaceChildren();
        if (!first || !last || first > last) {
            // Leave the hidden input alone: on an edit this runs once before the dates
            // are bound, and clearing here would drop the saved exclusions. The server
            // ignores excluded dates that fall outside the schedule anyway.
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

        const currentLang = (document.documentElement.lang || (window.I18nManager?.getCurrentLocale ? window.I18nManager.getCurrentLocale() : 'th')).toLowerCase();
        const isThai = currentLang.startsWith('th');
        const localeTag = isThai ? 'th-TH' : 'en-GB';

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
                ? dateObject.toLocaleDateString(localeTag, {
                    weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
                })
                : date;
            const bTime = formatTime(beginTime?.value);
            const eTime = formatTime(endTime?.value);
            const hasTime = bTime && eTime;
            const timeRange = hasTime ? ` (${bTime} – ${eTime})` : '';
            label.textContent = `${dateLabel}${timeRange}`;
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) excluded.delete(date);
                else excluded.add(date);
                item.classList.toggle('is-excluded', !checkbox.checked);
                excludedInput.value = Array.from(excluded).sort().join(',');
                updateSummary(availableDates.length);
            });
            item.addEventListener('click', (e) => {
                if (e.target !== checkbox && e.target !== label) {
                    checkbox.checked = !checkbox.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
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

    // The surrounding initBookingForm owns showing/hiding the weekday group,
    // so this only needs to redraw the date list.
    const onScheduleChange = () => renderOccurrences();

    scheduleType.addEventListener('change', onScheduleChange);
    [beginDate, endDate, beginTime, endTime, recurrenceDays].filter(Boolean).forEach(input => {
        input.addEventListener('change', renderOccurrences);
        input.addEventListener('input', renderOccurrences);
    });
    // Data-binding writes the date fields without firing input events, so redraw once the
    // page reports it finished loading or language changes.
    EventManager.on?.('page:loaded', renderOccurrences);
    EventManager.on?.('locale:changed', renderOccurrences);
    EventManager.on?.('i18n:updated', renderOccurrences);
    renderOccurrences();

    return () => {
        scheduleType.removeEventListener('change', onScheduleChange);
        [beginDate, endDate, beginTime, endTime, recurrenceDays].filter(Boolean).forEach(input => {
            input.removeEventListener('change', renderOccurrences);
            input.removeEventListener('input', renderOccurrences);
        });
        EventManager.off?.('page:loaded', renderOccurrences);
        EventManager.off?.('locale:changed', renderOccurrences);
        EventManager.off?.('i18n:updated', renderOccurrences);
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

