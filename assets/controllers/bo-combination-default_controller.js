import { Controller } from '@hotwired/stimulus';

/**
 * The default combination of the combinations grid, saved with the grid: "Set as default" in the
 * menu of a combination checks its hidden `default_pse` radio, shows the badge on that combination
 * only, and disables the entry of the menu of the default one. Each target carries `data-pse-id`.
 */
export default class extends Controller {
    static targets = ['radio', 'badge', 'choice'];

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
