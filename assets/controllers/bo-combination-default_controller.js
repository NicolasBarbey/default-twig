import { Controller } from '@hotwired/stimulus';

/**
 * Default combination of the combinations grid: "Set as default" checks its hidden `default_pse`
 * radio and moves the badge. Each target carries `data-pse-id`; hosted by the form, to follow its reset.
 */
export default class extends Controller {
    static targets = ['radio', 'badge', 'choice'];

    connect() {
        this.onReset = () => window.setTimeout(() => this.refresh());
        this.element.addEventListener('reset', this.onReset);
    }

    disconnect() {
        this.element.removeEventListener('reset', this.onReset);
    }

    choose(event) {
        const pseId = event.currentTarget.dataset.pseId;
        const radio = this.radioTargets.find((field) => field.dataset.pseId === pseId);
        if (!radio || radio.checked) {
            return;
        }
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        this.refresh(pseId);
    }

    refresh(chosen = this.radioTargets.find((field) => field.checked)?.dataset.pseId) {
        this.badgeTargets.forEach((badge) => { badge.hidden = badge.dataset.pseId !== chosen; });
        this.choiceTargets.forEach((choice) => { choice.disabled = choice.dataset.pseId === chosen; });
    }
}
