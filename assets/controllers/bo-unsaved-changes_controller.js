import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Warns before leaving an edit screen through a navigation link while a watched
 * form holds unsaved changes. Without it, the prev/next chevrons silently drop
 * everything the user typed.
 *
 * Usage:
 *   <div data-controller="bo-unsaved-changes"
 *        data-bo-unsaved-changes-modal-value="#product-unsaved-changes-modal">
 *       <form data-bo-unsaved-changes-target="form">…</form>
 *       <a href="…" data-bo-unsaved-changes-target="link">next</a>
 *   </div>
 *
 * The modal drives the outcome through three actions: #save submits the dirty
 * form (the server brings the user back to the same screen), #leave follows the
 * link, and dismissing keeps editing. One page can only send one form: when
 * several are changed, the modal hides its `saveButton` and shows its
 * `severalFormsMessage` instead of its `oneFormMessage`, so that each one is
 * saved on its own rather than all but one dropped.
 *
 * Each watched form is followed on its own: the modal saves the one that was
 * changed, and a changed form carries `data-bo-unsaved-changes-dirty`, which a
 * screen can show (a button to cancel the changes, for one). A form reset (a
 * `<button type="reset">`) is clean again. A submit that another controller holds
 * back (`preventDefault`) leaves the form changed until it is really sent. With
 * `warnOnUnload`, the browser also asks before the page is left any other way:
 * reloaded, closed, or left through a link that is not a target.
 */
export default class extends Controller {
    static targets = ['form', 'link', 'saveButton', 'oneFormMessage', 'severalFormsMessage'];

    static values = {
        modal: { type: String, default: '' },
        warnOnUnload: { type: Boolean, default: false },
    };

    initialize() {
        // The form targets connect before connect() runs.
        this.dirtyForms = new Set();
        this.pendingUrl = null;
        this.onFieldChange = (event) => this.markDirty(event.currentTarget);
        this.onSubmit = (event) => this.submitted(event);
        this.onReset = (event) => this.markClean(event.currentTarget);
        this.onLinkClick = (event) => this.guard(event);
        this.onBeforeUnload = (event) => this.beforeUnload(event);
    }

    connect() {
        this.linkTargets.forEach((link) => link.addEventListener('click', this.onLinkClick));
        if (this.warnOnUnloadValue) {
            window.addEventListener('beforeunload', this.onBeforeUnload);
        }
        // Heard once the submit has gone through the listeners of the form, which may hold it back.
        document.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.linkTargets.forEach((link) => link.removeEventListener('click', this.onLinkClick));
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        document.removeEventListener('submit', this.onSubmit);
    }

    formTargetConnected(form) {
        form.addEventListener('input', this.onFieldChange);
        form.addEventListener('change', this.onFieldChange);
        form.addEventListener('reset', this.onReset);
    }

    formTargetDisconnected(form) {
        form.removeEventListener('input', this.onFieldChange);
        form.removeEventListener('change', this.onFieldChange);
        form.removeEventListener('reset', this.onReset);
        this.markClean(form);
    }

    get dirty() {
        return this.dirtyForms.size > 0;
    }

    markDirty(form) {
        this.dirtyForms.add(form);
        form.setAttribute('data-bo-unsaved-changes-dirty', '');
    }

    markClean(form) {
        this.dirtyForms.delete(form);
        form.removeAttribute('data-bo-unsaved-changes-dirty');
    }

    /** Read on the document, not in a microtask: the browser runs microtasks between two listeners of a click, before the one that holds the form back. */
    submitted(event) {
        const form = event.target;
        if (this.formTargets.includes(form) && !event.defaultPrevented) {
            this.markClean(form);
        }
    }

    beforeUnload(event) {
        if (!this.dirty) {
            return;
        }

        event.preventDefault();
        // Older browsers ask only when a return value is set.
        event.returnValue = '';
    }

    guard(event) {
        if (!this.dirty) {
            return;
        }

        const url = event.currentTarget.getAttribute('href');
        const modalEl = this.modalElement;
        if (!url || !modalEl) {
            return;
        }

        event.preventDefault();
        this.pendingUrl = url;
        this.offerToSave();
        (Modal.getInstance(modalEl) ?? new Modal(modalEl)).show();
    }

    offerToSave() {
        const several = this.dirtyForms.size > 1;
        this.saveButtonTargets.forEach((button) => { button.hidden = several; });
        this.oneFormMessageTargets.forEach((message) => { message.hidden = several; });
        this.severalFormsMessageTargets.forEach((message) => { message.hidden = !several; });
    }

    /** Saves the dirty form; the server redirect decides where the user lands. */
    save() {
        if (this.dirtyForms.size > 1) {
            // Sending one would reload the page and drop the others.
            return;
        }
        const form = this.dirtyForm;
        if (!form) {
            this.leave();

            return;
        }

        this.hideModal();
        form.requestSubmit();
    }

    /** Follows the link that was intercepted, dropping the pending changes. */
    leave() {
        const url = this.pendingUrl;
        Array.from(this.dirtyForms).forEach((form) => this.markClean(form));
        this.pendingUrl = null;
        this.hideModal();

        if (url) {
            window.location.assign(url);
        }
    }

    get dirtyForm() {
        return this.formTargets.find((form) => this.dirtyForms.has(form)) ?? null;
    }

    get modalElement() {
        return this.modalValue ? document.querySelector(this.modalValue) : null;
    }

    hideModal() {
        const modalEl = this.modalElement;
        if (modalEl) {
            Modal.getInstance(modalEl)?.hide();
        }
    }
}
