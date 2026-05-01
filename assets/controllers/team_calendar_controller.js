import { Controller } from '@hotwired/stimulus';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';

/**
 * Team Calendar Stimulus controller.
 *
 * Mounts a FullCalendar instance into the [data-team-calendar-target="calendar"]
 * element. Filters (team, absence type) live in the surrounding form; this
 * controller listens to their changes and refetches events from the JSON feed.
 */
export default class extends Controller {
    static targets = ['calendar', 'team', 'type'];
    static values = {
        feedUrl: String,
        defaultTeam: String,
    };

    connect() {
        this.calendar = new Calendar(this.calendarTarget, {
            plugins: [dayGridPlugin, interactionPlugin],
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek',
            },
            locale: document.documentElement.lang || 'de',
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
}
