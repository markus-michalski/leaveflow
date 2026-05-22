import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';

// Inline German locale — avoids a separate importmap entry for the locale file.
const german = {
    firstDayOfWeek: 1,
    weekAbbreviation: 'KW',
    rangeSeparator: ' bis ',
    scrollTitle: 'Zum Blättern scrollen',
    toggleTitle: 'Zum Umschalten klicken',
    amPM: ['AM', 'PM'],
    yearAriaLabel: 'Jahr',
    monthAriaLabel: 'Monat',
    hourAriaLabel: 'Stunde',
    minuteAriaLabel: 'Minute',
    time_24hr: true,
    months: {
        shorthand: ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
        longhand: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
    },
    weekdays: {
        shorthand: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
        longhand: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
    },
};

export default class extends Controller {
    connect() {
        this._picker = flatpickr(this.element, {
            dateFormat: 'd.m.Y',
            allowInput: true,
            locale: german,
        });
    }

    disconnect() {
        this._picker?.destroy();
    }
}
