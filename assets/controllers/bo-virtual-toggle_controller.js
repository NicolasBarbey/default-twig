import { Controller } from '@hotwired/stimulus';

/**
 * Reveals dependent field(s) only while a trigger checkbox is checked — used to
 * show the virtual-document selector only when "virtual product" is ticked.
 * State is synced on connect and on every change of the trigger.
 *
 * With `mode` set to `readonly`, the fields stay in place and are only made read-only while the
 * trigger is unchecked: a read-only field is still sent with its form, a disabled one would not be.
 */
export default class extends Controller {
    static targets = ['trigger', 'field'];

    static values = { mode: { type: String, default: 'hide' } };

    connect() {
        this.update();
    }

    update() {
        const on = this.hasTriggerTarget && this.triggerTarget.checked;
        if (this.modeValue === 'readonly') {
            this.fieldTargets.forEach((el) => { el.readOnly = !on; });

            return;
        }
        this.fieldTargets.forEach((el) => el.classList.toggle('d-none', !on));
    }
}
