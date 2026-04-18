import { Controller } from '@hotwired/stimulus';

// Auto-dismissing flash message.
// Usage: <div data-controller="flash" data-flash-timeout-value="5000">...</div>
export default class extends Controller {
    static values = { timeout: { type: Number, default: 5000 } };

    connect() {
        this.dismissTimer = setTimeout(() => this.dismiss(), this.timeoutValue);
    }

    disconnect() {
        if (this.dismissTimer) {
            clearTimeout(this.dismissTimer);
        }
    }

    dismiss() {
        this.element.classList.add('opacity-0', 'translate-x-4');
        setTimeout(() => this.element.remove(), 250);
    }
}
