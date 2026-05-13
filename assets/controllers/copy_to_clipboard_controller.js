import { Controller } from '@hotwired/stimulus';

/**
 * Copies the controller-scoped `source` target's value to the
 * clipboard. The source can be any element with a `.value` property
 * (input, textarea) — textarea is the right choice when the payload
 * contains newlines (e.g. backup-codes export).
 *
 * Falls back to `document.execCommand('copy')` in non-secure contexts.
 * The button briefly swaps its text to "Kopiert" for visual feedback.
 */
export default class extends Controller {
    static targets = ['source'];

    copy(event) {
        const button = event.currentTarget;
        const source = this.hasSourceTarget ? this.sourceTarget : null;
        if (!source) {
            return;
        }

        const value = 'value' in source ? source.value : source.textContent;
        const successText = button.dataset.successText || 'Kopiert';
        const originalText = button.textContent;

        const writeToClipboard = (text) => {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            // execCommand-fallback works on input/textarea only, hence the guard.
            if (typeof source.select === 'function') {
                source.select();
                document.execCommand('copy');
                window.getSelection()?.removeAllRanges();
            }
            return Promise.resolve();
        };

        writeToClipboard(value)
            .then(() => {
                button.textContent = successText;
                setTimeout(() => {
                    button.textContent = originalText;
                }, 1500);
            })
            .catch(() => {
                if (typeof source.select === 'function') {
                    source.select();
                }
            });
    }
}
