import { Controller } from '@hotwired/stimulus';

const PRICE_PAIRS = {
    price: ['price-incl', 'untaxed_to_taxed'],
    'price-incl': ['price', 'taxed_to_untaxed'],
    'sale-price': ['sale-price-incl', 'untaxed_to_taxed'],
    'sale-price-incl': ['sale-price', 'taxed_to_untaxed'],
};

/**
 * Bulk edit of the combinations grid: a side panel fills the fields of the ticked combinations, then
 * closes. Nothing is saved, the grid's save bar does that. Row fields get a `change` event, never
 * `input`, which would have the price controller convert the row again on submit.
 *
 * Usage:
 *   <div data-controller="bo-bulk-select bo-combinations-bulk-edit"
 *        data-bo-combinations-bulk-edit-bo-pse-price-with-tax-outlet="form.grid">
 *       <form class="grid" data-controller="bo-pse-price-with-tax">
 *           <table data-controller="bo-sortable" data-bo-combinations-bulk-edit-target="sortable">
 *               <input type="checkbox" form="bulk" data-bo-bulk-select-target="row"> (outside the grid form: not sent nor reset with it)
 *               <input name="quantity[]" data-bulk-field="quantity">
 *       <dialog data-bo-combinations-bulk-edit-target="panel" data-action="close->bo-combinations-bulk-edit#closed">
 *           <form id="bulk" data-action="submit->bo-combinations-bulk-edit#apply">
 *               <input type="number" data-bulk-field="quantity" data-bo-combinations-bulk-edit-target="field">
 *   </div>
 */
export default class extends Controller {
    static targets = ['field', 'panel', 'toggle', 'sortable', 'apply', 'emptyHint', 'notch'];

    static outlets = ['bo-pse-price-with-tax'];

    static classes = ['active', 'folded'];

    initialize() {
        this.conversions = new Set();
        this.typedPrices = new Set();
    }

    connect() {
        this.refresh();
    }

    toggle() {
        if (this.panelTarget.open) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.element.classList.add(...this.activeClasses);
        this.toggleTargets.forEach((button) => button.setAttribute('aria-expanded', 'true'));
        if (this.hasSortableTarget) {
            this.sortableTarget.setAttribute('data-bo-sortable-disabled-value', 'true');
        }
        this.panelTarget.show();
        // Into its first field: on its own, the dialog gives the focus to the notch.
        this.fieldTargets[0]?.focus();
        this.refresh();
    }

    /** Escape and the close button: a panel that is not modal does not close itself on Escape. */
    close() {
        this.panelTarget.close();
    }

    fold() {
        this.setFolded(!this.panelTarget.classList.contains(this.foldedClass));
    }

    setFolded(folded) {
        this.panelTarget.classList.toggle(this.foldedClass, folded);
        this.notchTargets.forEach((notch) => notch.setAttribute('aria-expanded', String(!folded)));
        // Aside, out of sight: out of reach of the keyboard and of screen readers too. The notch stays.
        this.panelTarget.querySelectorAll(':scope > form').forEach((form) => { form.inert = folded; });
    }

    closed() {
        const focusWasInside = this.panelTarget.contains(document.activeElement) || document.activeElement === document.body;

        this.element.classList.remove(...this.activeClasses);
        this.toggleTargets.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        if (this.hasSortableTarget) {
            this.sortableTarget.setAttribute('data-bo-sortable-disabled-value', 'false');
        }
        this.setFolded(false);
        this.unselectAll();
        this.fieldTargets.forEach((field) => { field.value = ''; });
        this.typedPrices.clear();

        if (focusWasInside && this.hasToggleTarget) {
            this.toggleTarget.focus();
        }
    }

    /** Through the "select all" box, so that the selection controller counts again. */
    unselectAll() {
        const all = this.element.querySelector('input[data-bo-bulk-select-target~="all"]');
        if (!all) {
            return;
        }
        all.checked = false;
        all.indeterminate = false;
        all.dispatchEvent(new Event('change', { bubbles: true }));
    }

    refresh() {
        const none = this.selectedRows().length === 0;
        this.applyTargets.forEach((button) => { button.disabled = none; });
        this.emptyHintTargets.forEach((hint) => { hint.hidden = !none; });
    }

    onPriceInput(event) {
        this.typedPrices.add(event.currentTarget);
    }

    onPriceBlur(event) {
        this.convert(event.currentTarget);
    }

    /** @returns {Promise<boolean>} false when the price could not be converted */
    convert(source) {
        this.typedPrices.delete(source);
        const [otherName, action] = PRICE_PAIRS[source.dataset.bulkField] ?? [];
        const output = otherName ? this.field(otherName) : null;
        if (!output || !this.hasBoPsePriceWithTaxOutlet) {
            return Promise.resolve(true);
        }
        if (source.value.trim() === '') {
            output.value = '';

            return Promise.resolve(true);
        }

        const conversion = this.boPsePriceWithTaxOutlet.compute(source, action, output);
        this.conversions.add(conversion);
        conversion.then((converted) => {
            this.conversions.delete(conversion);
            if (!converted) {
                // Clear the stale paired price: a second click would otherwise apply both as they are.
                output.value = '';
                this.typedPrices.add(source);
            }
        });

        return conversion;
    }

    async apply(event) {
        event.preventDefault();

        // A price typed then applied with Enter has not left its field: it is converted first.
        Array.from(this.typedPrices).forEach((source) => this.convert(source));
        const converted = await Promise.all(Array.from(this.conversions));
        if (!converted.every(Boolean)) {
            return;
        }

        const values = this.fieldTargets
            .filter((field) => field.value.trim() !== '')
            .map((field) => [field.dataset.bulkField, field.value.trim()]);
        if (values.length === 0) {
            return;
        }

        const rows = this.selectedRows();
        if (rows.length === 0) {
            return;
        }

        rows.forEach((row) => {
            values.forEach(([name, value]) => this.fill(row, name, value));
        });
        this.close();
    }

    fill(row, name, value) {
        const field = row.querySelector(`[data-bulk-field="${name}"]`);
        if (!field) {
            return;
        }

        if (field.type === 'checkbox') {
            const checked = value === '1';
            if (field.checked === checked) {
                return;
            }
            field.checked = checked;
        } else {
            if (field.value === value) {
                return;
            }
            field.value = value;
        }

        const [otherName] = PRICE_PAIRS[name] ?? [];
        if (otherName && !this.field(otherName)?.value.trim()) {
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    field(name) {
        return this.fieldTargets.find((field) => field.dataset.bulkField === name) ?? null;
    }

    selectedRows() {
        return Array.from(this.element.querySelectorAll('input[data-bo-bulk-select-target~="row"]:checked'))
            .map((box) => box.closest('tbody[data-row-id]'))
            .filter(Boolean);
    }
}
