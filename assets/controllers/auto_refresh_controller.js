import { Controller } from '@hotwired/stimulus';

// Periodically reloads the wrapping turbo-frame.
//
// Used by the notification bell so the unread count stays current without a
// page reload. Default interval is 30 seconds; override via
// `data-auto-refresh-interval-value="60000"` (milliseconds).
//
// Usage:
//   <turbo-frame id="..." src="..." data-controller="auto-refresh"
//                data-auto-refresh-interval-value="30000"></turbo-frame>
export default class extends Controller {
    static values = { interval: { type: Number, default: 30000 } };

    connect() {
        // Avoid scheduling on detached frames (lazy-loaded but never visible).
        if (this.intervalValue <= 0) {
            return;
        }
        this.timer = setInterval(() => this.reload(), this.intervalValue);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    reload() {
        // The element IS a turbo-frame; reload() refetches its src.
        if (typeof this.element.reload === 'function') {
            this.element.reload();
        }
    }
}
