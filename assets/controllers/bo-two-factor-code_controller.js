import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'label', 'toggle'];
    static values = {
        totpLabel: String,
        backupLabel: String,
        totpToggle: String,
        backupToggle: String,
    };

    connect() {
        this.backup = false;
        this.render();
    }

    switch(event) {
        event.preventDefault();
        this.backup = !this.backup;
        this.inputTarget.value = '';
        this.render();
        this.inputTarget.focus();
    }

    clean() {
        const value = this.inputTarget.value.replace(/[\s-]/g, '');

        if (value !== this.inputTarget.value) {
            this.inputTarget.value = value;
        }
    }

    render() {
        this.labelTarget.textContent = this.backup ? this.backupLabelValue : this.totpLabelValue;
        this.toggleTarget.textContent = this.backup ? this.totpToggleValue : this.backupToggleValue;
        this.inputTarget.setAttribute('inputmode', this.backup ? 'text' : 'numeric');
        this.inputTarget.setAttribute('maxlength', this.backup ? '12' : '7');
        this.inputTarget.setAttribute('autocomplete', this.backup ? 'off' : 'one-time-code');
    }
}
