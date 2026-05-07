import { Controller } from '@hotwired/stimulus';

// Submits the form after the user stops typing for {delay} ms.
// Usage:
//   <form data-controller="debounced-submit" data-debounced-submit-delay-value="300">
//       <input data-action="input->debounced-submit#schedule">
//       <select data-action="change->debounced-submit#submit">
//   </form>
//
// `submit` fires immediately (used for select changes); `schedule` debounces
// (used for free-text inputs).
export default class extends Controller {
    static values = { delay: { type: Number, default: 300 } };

    connect() {
        this.timer = null;
    }

    disconnect() {
        this.cancel();
    }

    schedule() {
        this.cancel();
        this.timer = setTimeout(() => this.submit(), this.delayValue);
    }

    submit() {
        this.cancel();
        // requestSubmit() respects the form's data-turbo-* attributes so a
        // turbo-frame target stays scoped to the frame.
        if (typeof this.element.requestSubmit === 'function') {
            this.element.requestSubmit();
        } else {
            this.element.submit();
        }
    }

    cancel() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }
}
