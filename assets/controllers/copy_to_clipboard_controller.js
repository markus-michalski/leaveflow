import { Controller } from '@hotwired/stimulus';

/**
 * Copies the closest readonly input's value to the clipboard when the
 * action's button is clicked. Falls back to a manual select-and-prompt
 * if the Clipboard API is unavailable (older browsers, http context).
 *
 * The button shows a brief "Kopiert" confirmation by swapping its text
 * for 1.5 seconds — kept minimal, no toast library needed.
 */
export default class extends Controller {
    copy(event) {
        const button = event.currentTarget;
        const wrapper = button.closest('div');
        const input = wrapper ? wrapper.querySelector('input[type="text"]') : null;
        if (!input) {
            return;
        }

        const value = input.value;
        const successText = button.dataset.successText || 'Kopiert';
        const originalText = button.textContent;

        const writeToClipboard = (text) => {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            // Fallback for non-secure contexts: legacy execCommand.
            input.select();
            document.execCommand('copy');
            window.getSelection()?.removeAllRanges();
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
                input.select();
            });
    }
}
