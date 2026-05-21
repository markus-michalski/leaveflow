import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['employee', 'year', 'type'];
    static values = { baseUrl: String };

    reload() {
        const frame = document.getElementById('pro-rata-hint');
        if (!frame) return;

        const url = new URL(this.baseUrlValue, window.location.origin);
        url.searchParams.set('employee', this.employeeTarget.value);
        url.searchParams.set('year', this.yearTarget.value);
        if (this.hasTypeTarget) {
            url.searchParams.set('type', this.typeTarget.value);
        }

        frame.src = url.toString();
    }
}
