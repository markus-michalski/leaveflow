import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the <turbo-frame id="leave-preview"> in sync with the request form.
 *
 * On every change of start/end/dayType it rewrites the frame's src with the
 * current inputs; Turbo handles the fetch + swap. A tiny debounce coalesces
 * rapid changes (e.g. user tabbing through fields) into one request.
 */
export default class extends Controller {
    static targets = ["form", "frame"];
    static values = { previewUrl: String };

    connect() {
        this.debounceTimer = null;
        // Initial fetch if dates are already populated (e.g. validation error rerender).
        if (this.frameTarget.dataset.initialFetch !== "done") {
            this.frameTarget.dataset.initialFetch = "done";
            this.refresh();
        }
    }

    disconnect() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
    }

    refresh() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }
        this.debounceTimer = setTimeout(() => this.fetchPreview(), 150);
    }

    fetchPreview() {
        const form = this.hasFormTarget ? this.formTarget : this.element.querySelector("form");
        if (!form) {
            return;
        }

        const formData = new FormData(form);
        const params = new URLSearchParams();

        const startDate = this.#readDate(formData, "startDate");
        const endDate = this.#readDate(formData, "endDate");
        const dayType = this.#readDayType(formData);

        if (startDate) params.set("start_date", startDate);
        if (endDate) params.set("end_date", endDate);
        if (dayType) params.set("day_type", dayType);

        const url = `${this.previewUrlValue}?${params.toString()}`;
        if (this.frameTarget.src === url) {
            return;
        }
        this.frameTarget.src = url;
    }

    /**
     * Form field names include the parent form name (Symfony default:
     * "leave_request_form[startDate]"). Strip to just the date string.
     */
    #readDate(formData, fieldSuffix) {
        for (const [key, value] of formData.entries()) {
            if (key.endsWith(`[${fieldSuffix}]`) && typeof value === "string" && value.trim() !== "") {
                return value.trim();
            }
        }
        return null;
    }

    #readDayType(formData) {
        for (const [key, value] of formData.entries()) {
            if (key.endsWith("[dayType]") && typeof value === "string" && value !== "") {
                return value;
            }
        }
        return null;
    }
}
