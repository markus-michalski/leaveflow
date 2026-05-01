import { Controller } from '@hotwired/stimulus';

/**
 * Team Calendar Stimulus controller.
 *
 * Mounts a FullCalendar instance into the [data-team-calendar-target="calendar"]
 * element. FullCalendar is loaded via the global script tag (window.FullCalendar)
 * in templates/team/calendar/index.html.twig — the split-package importmap
 * approach trips a Preact dual-instance bug in FC v6.1.x, the global bundle
 * sidesteps it entirely.
 *
 * Filters (team, absence type) live in the surrounding form; this controller
 * listens to their changes and refetches events from the JSON feed.
 */
export default class extends Controller {
    static targets = ['calendar', 'team', 'type'];
    static values = {
        feedUrl: String,
        defaultTeam: String,
    };

    async connect() {
        const FC = await this.waitForFullCalendar();
        if (!FC) {
            console.error('FullCalendar global bundle not loaded after timeout — check the <script> tag in the calendar template.');
            return;
        }

        const { Calendar } = FC;

        this.calendar = new Calendar(this.calendarTarget, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek',
            },
            // Locale registers via a separate <script> with `defer`, so it may
            // not be present when this controller mounts. Explicit buttonText
            // overrides guarantee German labels regardless of locale-load order.
            locale: document.documentElement.lang || 'de',
            buttonText: {
                today: 'Heute',
                month: 'Monat',
                week: 'Woche',
                day: 'Tag',
                list: 'Liste',
            },
            firstDay: 1,
            height: 'auto',
            events: (info, success, failure) => this.fetchEvents(info, success, failure),
            eventDidMount: (info) => this.applyEventStyling(info),
        });

        this.calendar.render();
    }

    disconnect() {
        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
    }

    refresh() {
        if (this.calendar) {
            this.calendar.refetchEvents();
        }
    }

    async fetchEvents(info, success, failure) {
        const params = new URLSearchParams({
            start: info.startStr.slice(0, 10),
            end: info.endStr.slice(0, 10),
        });
        const team = this.currentTeam();
        if (team) {
            params.set('team', team);
        }
        const type = this.currentType();
        if (type) {
            params.set('type', type);
        }

        try {
            const response = await fetch(`${this.feedUrlValue}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Calendar feed returned ${response.status}`);
            }
            const events = await response.json();
            success(events);
        } catch (error) {
            failure(error);
        }
    }

    currentTeam() {
        if (this.hasTeamTarget && this.teamTarget.value !== '') {
            return this.teamTarget.value;
        }
        return this.defaultTeamValue || '';
    }

    currentType() {
        if (this.hasTypeTarget && this.typeTarget.value !== '') {
            return this.typeTarget.value;
        }
        return '';
    }

    applyEventStyling(info) {
        if (info.event.extendedProps.kind === 'blackout') {
            info.el.classList.add('opacity-70', 'border-2', 'border-rose-400');
        }
    }

    /**
     * Wait until window.FullCalendar is populated by the deferred script.
     * Resolves to the global FullCalendar object, or null on timeout.
     */
    waitForFullCalendar() {
        return new Promise((resolve) => {
            if (window.FullCalendar) {
                resolve(window.FullCalendar);
                return;
            }
            const startedAt = Date.now();
            const interval = setInterval(() => {
                if (window.FullCalendar) {
                    clearInterval(interval);
                    resolve(window.FullCalendar);
                } else if (Date.now() - startedAt > 5000) {
                    clearInterval(interval);
                    resolve(null);
                }
            }, 50);
        });
    }
}
