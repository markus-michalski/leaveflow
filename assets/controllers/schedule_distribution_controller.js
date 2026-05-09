import { Controller } from '@hotwired/stimulus';

/**
 * Toggles between the auto- and manual-distribution blocks on the
 * employee form (Phase 9), and provides a live sum indicator in
 * manual mode so admins see immediately whether the per-day hours
 * match weeklyHours.
 *
 * The indicator is purely advisory; the authoritative validation is
 * still in WorkSchedule::manual on the server (sum-epsilon, negatives,
 * empty distribution).
 */
export default class extends Controller {
    static targets = [
        'autoBlock',
        'manualBlock',
        'modeLabel',
        'weeklyHours',
        'hoursInput',
        'sumIndicator',
        'sumText',
    ];

    static values = {
        mode: { type: String, default: 'auto' },
    };

    connect() {
        this.applyMode(this.modeValue);
        this.updateSum();
    }

    switchMode(event) {
        this.modeValue = event.target.value;
        this.applyMode(this.modeValue);
        this.updateSum();
    }

    applyMode(mode) {
        const isManual = mode === 'manual';
        this.autoBlockTarget.classList.toggle('hidden', isManual);
        this.manualBlockTarget.classList.toggle('hidden', !isManual);

        this.modeLabelTargets.forEach((label) => {
            const active = label.dataset.mode === mode;
            label.classList.toggle('bg-blue-50', active);
            label.classList.toggle('text-blue-700', active);
        });
    }

    updateSum() {
        if (!this.hasSumIndicatorTarget) {
            return;
        }

        const sum = this.hoursInputTargets
            .map((input) => parseFloat(input.value || '0'))
            .filter((n) => !Number.isNaN(n))
            .reduce((acc, n) => acc + n, 0);

        const target = parseFloat(this.weeklyHoursTarget.value || '0') || 0;
        const matches = Math.abs(sum - target) <= 0.01;

        const formatted = sum.toFixed(2).replace('.', ',');
        const targetFormatted = target.toFixed(2).replace('.', ',');
        // Translation strings live on the form root via data attributes
        // when more languages land — for now German+English share the
        // same arithmetic glyph "/" so the bare numbers carry the message.
        this.sumTextTarget.textContent = `${formatted} h / ${targetFormatted} h`;

        this.sumIndicatorTarget.classList.toggle('text-green-700', matches);
        this.sumIndicatorTarget.classList.toggle('text-rose-700', !matches);
    }
}
