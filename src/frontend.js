import { createCalendar, createViewMonthAgenda, createViewMonthGrid, createViewWeek, createViewWeekAgenda } from "@schedule-x/calendar";
import "temporal-polyfill/global";
import "@schedule-x/theme-default/dist/index.css";

const initialized = new Set();
const WEEK_GRID_STEP = 60;
const MIN_WEEK_GRID_HEIGHT = 240;
const EVENT_POPOVER_MARGIN = 12;

let activeModal = null;
let lastFocusedElement = null;

function isCalendarSmall(container, breakpoint) {
    return container.clientWidth < breakpoint;
}

function mapResponsiveViewName(currentViewName, viewNames, small) {
    const viewPairs = new Map([
        [viewNames.week, { small: viewNames.weekAgenda, wide: viewNames.week }],
        [viewNames.weekAgenda, { small: viewNames.weekAgenda, wide: viewNames.week }],
        [viewNames.monthGrid, { small: viewNames.monthAgenda, wide: viewNames.monthGrid }],
        [viewNames.monthAgenda, { small: viewNames.monthAgenda, wide: viewNames.monthGrid }]
    ]);
    const viewPair = viewPairs.get(currentViewName);

    if (!viewPair) {
        return currentViewName;
    }

    return small ? viewPair.small : viewPair.wide;
}

function setupResponsiveViewPairing(container, $app, viewNames, breakpoint) {
    const applyViewPairing = (viewNameBeforeScheduleXResize, small) => {
        const targetViewName = mapResponsiveViewName(viewNameBeforeScheduleXResize, viewNames, small);

        if (targetViewName && targetViewName !== $app.calendarState.view.value) {
            $app.calendarState.setView(targetViewName, $app.datePickerState.selectedDate.value);
        }
    };
    const queueViewPairing = () => {
        const viewNameBeforeScheduleXResize = $app.calendarState.view.value;
        const small = isCalendarSmall(container, breakpoint);

        window.setTimeout(() => applyViewPairing(viewNameBeforeScheduleXResize, small), 0);
    };

    queueViewPairing();
    window.addEventListener("resize", queueViewPairing);
}

function normalizeViewLabels(week, weekAgenda, monthGrid, monthAgenda, labels) {
    week.label = labels.week;
    weekAgenda.label = labels.week;
    monthGrid.label = labels.month;
    monthAgenda.label = labels.month;
}

function boundaryHour(value) {
    const hour = parseInt(String(value).split(":")[0], 10);

    return Number.isFinite(hour) ? hour : 0;
}

function countGridSteps(dayBoundaries, gridStep) {
    const startHour = boundaryHour(dayBoundaries.start);
    const endHour = boundaryHour(dayBoundaries.end);
    let hours = endHour - startHour;

    if (hours <= 0) {
        hours += 24;
    }

    return Math.max(1, hours * (60 / gridStep));
}

function getAvailableWeekGridHeight(container) {
    const containerHeight = container.getBoundingClientRect().height || container.clientHeight;
    const calendarHeader = container.querySelector(".sx__calendar-header");
    const weekHeader = container.querySelector(".sx__week-header");
    const usedHeight = [calendarHeader, weekHeader].reduce((total, element) => {
        return total + (element ? element.getBoundingClientRect().height : 0);
    }, 0);
    const availableHeight = Math.floor(containerHeight - usedHeight);

    if (availableHeight > 0) {
        return Math.max(MIN_WEEK_GRID_HEIGHT, availableHeight);
    }

    return Math.max(MIN_WEEK_GRID_HEIGHT, Math.floor((containerHeight || 640) * 0.72));
}

function applyWeekGridSizing(container, dayBoundaries, gridStep) {
    const gridHeight = getAvailableWeekGridHeight(container);
    const gridStepHeight = gridHeight / countGridSteps(dayBoundaries, gridStep);

    container.style.setProperty("--sx-week-grid-height", gridHeight + "px");
    container.style.setProperty("--sx-week-grid-hour-height", gridStepHeight + "px");
}

function setupWeekGridSizing(container, dayBoundaries, gridStep) {
    let queued = false;
    const update = () => {
        if (queued) {
            return;
        }

        queued = true;
        window.requestAnimationFrame(() => {
            queued = false;
            applyWeekGridSizing(container, dayBoundaries, gridStep);
        });
    };

    update();
    window.setTimeout(update, 0);

    const resizeObserver = new ResizeObserver(update);
    resizeObserver.observe(container);

    const mutationObserver = new MutationObserver(update);
    mutationObserver.observe(container, { childList: true, subtree: true });
}

function eventToScheduleX(event) {
    const scheduleEvent = {
        id: event.id,
        title: event.title,
        location: event.location,
        calendarId: event.calendarId,
        moqboModal: event.modal
    };

    if (event.allDay) {
        scheduleEvent.start = Temporal.PlainDate.from(event.startDate);
        scheduleEvent.end = Temporal.PlainDate.from(event.endDate);
        return scheduleEvent;
    }

    scheduleEvent.start = Temporal.ZonedDateTime.from(event.startZoned);
    scheduleEvent.end = Temporal.ZonedDateTime.from(event.endZoned);

    return scheduleEvent;
}

function writeText(modal, key, value) {
    const element = modal.querySelector('[data-moqbo-modal-field="' + key + '"]');

    if (element) {
        element.textContent = value || "";
    }
}

function writeOptionalText(modal, key, value) {
    const element = modal.querySelector('[data-moqbo-modal-field="' + key + '"]');

    if (!element) {
        return;
    }

    const text = value ? String(value).trim() : "";
    element.textContent = text;
    element.hidden = !text;
}

function setCategorySwatch(modal, color) {
    const swatch = modal.querySelector('[data-moqbo-modal-field="categoryColor"]');

    if (swatch) {
        swatch.style.backgroundColor = color || "#2271b1";
    }
}

function formatEventTimeRange(eventModal) {
    return [eventModal.start, eventModal.end].filter(Boolean).join(" - ");
}

function getEventAnchorRect(uiEvent) {
    const target = uiEvent && uiEvent.target instanceof Element ? uiEvent.target : null;
    const eventElement = target ? target.closest(".sx__event") : null;

    if (eventElement) {
        return eventElement.getBoundingClientRect();
    }

    if (uiEvent && "clientX" in uiEvent && "clientY" in uiEvent) {
        return {
            top: uiEvent.clientY,
            right: uiEvent.clientX,
            bottom: uiEvent.clientY,
            left: uiEvent.clientX,
            width: 0,
            height: 0
        };
    }

    return {
        top: window.innerHeight / 2,
        right: window.innerWidth / 2,
        bottom: window.innerHeight / 2,
        left: window.innerWidth / 2,
        width: 0,
        height: 0
    };
}

function positionEventPopover(modal, anchorRect) {
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;
    const width = Math.min(400, viewportWidth - EVENT_POPOVER_MARGIN * 2);
    const maxLeft = Math.max(EVENT_POPOVER_MARGIN, viewportWidth - width - EVENT_POPOVER_MARGIN);
    let left = anchorRect.right + EVENT_POPOVER_MARGIN;
    let animationStart = "-16px";

    modal.style.width = width + "px";

    if (left + width > viewportWidth - EVENT_POPOVER_MARGIN) {
        left = anchorRect.left - width - EVENT_POPOVER_MARGIN;
        animationStart = "16px";
    }

    if (left < EVENT_POPOVER_MARGIN) {
        left = anchorRect.left;
        animationStart = "0";
    }

    left = Math.min(Math.max(left, EVENT_POPOVER_MARGIN), maxLeft);

    const modalHeight = modal.getBoundingClientRect().height || 250;
    const maxTop = Math.max(EVENT_POPOVER_MARGIN, viewportHeight - modalHeight - EVENT_POPOVER_MARGIN);
    const top = Math.min(Math.max(anchorRect.top, EVENT_POPOVER_MARGIN), maxTop);

    modal.style.setProperty("--sx-event-modal-top", Math.round(top) + "px");
    modal.style.setProperty("--sx-event-modal-left", Math.round(left) + "px");
    modal.style.setProperty("--sx-event-modal-animation-start", animationStart);
}

function openEventPopover(modal, eventModal, uiEvent) {
    if (!modal || !eventModal) {
        return;
    }

    writeText(modal, "categoryName", eventModal.categoryName);
    writeText(modal, "title", eventModal.title);
    writeText(modal, "time", formatEventTimeRange(eventModal));
    writeOptionalText(modal, "location", eventModal.location);
    writeOptionalText(modal, "description", eventModal.description);
    setCategorySwatch(modal, eventModal.categoryColor);

    lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    activeModal = modal;
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    modal.classList.add("is-open");
    positionEventPopover(modal, getEventAnchorRect(uiEvent));
}

function closeEventPopover(modal, restoreFocus = true) {
    if (!modal) {
        return;
    }

    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    modal.hidden = true;

    if (activeModal === modal) {
        activeModal = null;
    }

    if (restoreFocus && lastFocusedElement && document.contains(lastFocusedElement)) {
        lastFocusedElement.focus();
    }

    lastFocusedElement = null;
}

function renderError(container, message) {
    container.innerHTML = "";
    const error = document.createElement("div");
    error.className = "moqbo-calendar-error";
    error.textContent = message;
    container.appendChild(error);
}

function initInstance(instance) {
    if (!instance || initialized.has(instance.containerId)) {
        return;
    }

    const container = document.getElementById(instance.containerId);
    const modal = document.getElementById(instance.modalId);
    const dayBoundaries = instance.config.dayBoundaries;
    const responsiveBreakpoint = instance.config.responsiveBreakpoint;

    if (!container) {
        return;
    }

    initialized.add(instance.containerId);

    try {
        const week = createViewWeek();
        const weekAgenda = createViewWeekAgenda();
        const monthGrid = createViewMonthGrid();
        const monthAgenda = createViewMonthAgenda();
        normalizeViewLabels(week, weekAgenda, monthGrid, monthAgenda, instance.i18n);
        const viewNames = {
            week: week.name,
            weekAgenda: weekAgenda.name,
            monthGrid: monthGrid.name,
            monthAgenda: monthAgenda.name
        };
        let fetchSequence = 0;
        let latestEvents = [];
        let fetchController = null;

        const calendar = createCalendar({
            views: [week, weekAgenda, monthGrid, monthAgenda],
            events: [],
            calendars: instance.config.calendars,
            locale: instance.config.locale,
            timezone: instance.config.timezone,
            firstDayOfWeek: instance.config.firstDayOfWeek,
            defaultView: isCalendarSmall(container, responsiveBreakpoint) ? weekAgenda.name : week.name,
            dayBoundaries,
            weekOptions: {
                gridHeight: getAvailableWeekGridHeight(container),
                gridStep: WEEK_GRID_STEP,
                timeAxisFormatOptions: { hour: "2-digit", minute: "2-digit", hour12: false }
            },
            callbacks: {
                fetchEvents: async (range) => {
                    const sequence = ++fetchSequence;

                    if (fetchController) {
                        fetchController.abort();
                    }

                    fetchController = new AbortController();

                    try {
                        const url = new URL(instance.eventsUrl, window.location.href);
                        url.searchParams.set("start_date", range.start.toPlainDate().toString());
                        url.searchParams.set("end_date", range.end.toPlainDate().toString());
                        const response = await fetch(url.toString(), {
                            credentials: "same-origin",
                            signal: fetchController.signal
                        });

                        if (!response.ok) {
                            throw new Error("HTTP " + response.status);
                        }

                        const payload = await response.json();
                        const events = payload.events.map(eventToScheduleX);

                        if (sequence !== fetchSequence) {
                            return latestEvents;
                        }

                        latestEvents = events;

                        return events;
                    }
                    catch (error) {
                        if (error && "AbortError" === error.name) {
                            return latestEvents;
                        }

                        console.error("Moqbo events could not be loaded.", error);
                        return latestEvents;
                    }
                },
                isCalendarSmall: () => isCalendarSmall(container, responsiveBreakpoint),
                onEventClick: (event, uiEvent) => {
                    openEventPopover(modal, event.moqboModal, uiEvent);
                },
                onRender: ($app) => {
                    setupResponsiveViewPairing(container, $app, viewNames, responsiveBreakpoint);
                }
            }
        });

        calendar.render(container);
        setupWeekGridSizing(container, dayBoundaries, WEEK_GRID_STEP);
    }
    catch (error) {
        renderError(container, instance.i18n.loadError);
        console.error("Moqbo calendar failed to initialize.", error);
    }
}

function initCalendars() {
    document.querySelectorAll("[data-moqbo-instance]").forEach((wrapper) => {
        const config = wrapper.querySelector("[data-moqbo-config]");

        if (!config) {
            return;
        }

        try {
            initInstance(JSON.parse(config.textContent || "{}"));
        }
        catch (error) {
            const container = wrapper.querySelector(".moqbo-calendar");

            if (container) {
                renderError(container, wrapper.getAttribute("data-moqbo-error") || "");
            }

            console.error("Moqbo calendar configuration is invalid.", error);
        }
    });
}

document.addEventListener("keydown", (event) => {
    if ("Escape" === event.key && activeModal) {
        closeEventPopover(activeModal);
    }
});

document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target : null;

    if (!activeModal || !target || activeModal.contains(target) || target.closest(".sx__event")) {
        return;
    }

    closeEventPopover(activeModal);
});

document.addEventListener(
    "scroll",
    (event) => {
        if (!activeModal) {
            return;
        }

        if (event.target instanceof Node && activeModal.contains(event.target)) {
            return;
        }

        closeEventPopover(activeModal, false);
    },
    { capture: true, passive: true }
);

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCalendars);
}
else {
    initCalendars();
}
