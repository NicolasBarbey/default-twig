import { Controller } from '@hotwired/stimulus';

/**
 * Reveals dependent field(s) only while a trigger checkbox is checked — used to
 * show the virtual-document selector only when "virtual product" is ticked.
 * State is synced on connect and on every change of the trigger.
 *
 * `mode="readonly"` makes the fields read-only instead of hiding them: a read-only field is still
 * sent with its form, a disabled one is not.
 */
export default class extends Controller {
    static targets = ['trigger', 'field'];

    static values = { mode: { type: String, default: 'hide' } };

    connect() {
        this.form = this.hasTriggerTarget ? this.triggerTarget.form : null;
        // `reset` fires before the browser gives the trigger back its first state.
        this.onReset = () => window.setTimeout(() => this.update());
        this.form?.addEventListener('reset', this.onReset);
        this.update();
    }

    disconnect() {
        this.form?.removeEventListener('reset', this.onReset);
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
