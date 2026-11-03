import { Controller } from '@hotwired/stimulus';

/**
 * A price typed with or without taxes fills the other one through the calculate-price endpoint; the
 * form is held back until every conversion has answered. Prices pair up inside the nearest
 * `[data-bo-pse-price-with-tax-scope]` element (one per grid row), else the form.
 */
const SCOPE = '[data-bo-pse-price-with-tax-scope]';

const COUNTERPARTS = {
    priceExcl: ['untaxed_to_taxed', 'priceIncl'],
    priceIncl: ['taxed_to_untaxed', 'priceExcl'],
    salePriceExcl: ['untaxed_to_taxed', 'salePriceIncl'],
    salePriceIncl: ['taxed_to_untaxed', 'salePriceExcl'],
};

export default class extends Controller {
    static targets = ['priceExcl', 'priceIncl', 'salePriceExcl', 'salePriceIncl', 'taxRule', 'error'];

    static values = {
        calcUrl: String,
        productId: { type: Number, default: 0 },
    };

    connect() {
        this.pending = new Set();
        this.edited = new Set();
        this.failures = new Set();
        this.submitting = false;
        this.holding = false;
        this.boundInput = this.onInput.bind(this);
        this.boundSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('input', this.boundInput);
        this.element.addEventListener('submit', this.boundSubmit);
    }

    disconnect() {
        this.element.removeEventListener('input', this.boundInput);
        this.element.removeEventListener('submit', this.boundSubmit);
    }

    onPriceExclBlur(event) {
        this.convertTyped(event.currentTarget);
    }

    onPriceInclBlur(event) {
        this.convertTyped(event.currentTarget);
    }

    onSalePriceExclBlur(event) {
        this.convertTyped(event.currentTarget);
    }

    onSalePriceInclBlur(event) {
        this.convertTyped(event.currentTarget);
    }

    async onTaxRuleChange() {
        // A price with taxes typed just before is converted with the former rule first: the new rule applies to the prices without taxes it gives.
        await Promise.all(Array.from(this.pending));
        [...this.priceExclTargets, ...this.salePriceExclTargets].forEach((source) => this.convert(source));
    }

    /**
     * Looked up from the field itself, so that typing in a grid of hundreds of rows does not scan them all.
     *
     * @returns {[string, HTMLInputElement]|null}
     */
    pairOf(field) {
        const names = (field.getAttribute?.(`data-${this.identifier}-target`) ?? '').split(/\s+/);
        const kind = names.find((name) => name in COUNTERPARTS);
        if (!kind) {
            return null;
        }

        const [action, otherKind] = COUNTERPARTS[kind];
        const scope = field.closest(SCOPE) ?? this.element;
        const output = scope.querySelector(`[data-${this.identifier}-target~="${otherKind}"]`);

        return output ? [action, output] : null;
    }

    onInput(event) {
        if (this.pairOf(event.target)) {
            this.edited.add(event.target);
        }
    }

    /** Only a price typed in: tabbing through the grid would convert back a rounded price. */
    convertTyped(source) {
        if (this.edited.has(source)) {
            this.convert(source);
        }
    }

    convert(source) {
        const pair = this.pairOf(source);
        if (!pair) {
            return Promise.resolve(true);
        }
        this.edited.delete(source);

        const conversion = this.compute(source, pair[0], pair[1]);
        this.pending.add(conversion);
        conversion.then((converted) => {
            this.pending.delete(conversion);
            if (!converted) {
                this.edited.add(source);
            }
        });

        return conversion;
    }

    async onSubmit(event) {
        if (this.submitting) {
            return;
        }
        if (this.holding) {
            event.preventDefault();

            return;
        }
        if (this.edited.size === 0 && this.pending.size === 0) {
            return;
        }
        event.preventDefault();

        this.holding = true;
        let converted = [];
        try {
            Array.from(this.edited).forEach((source) => this.convert(source));
            converted = await Promise.all(Array.from(this.pending));
        } finally {
            this.holding = false;
        }
        if (converted.every(Boolean)) {
            // requestSubmit() fires `submit` at once: this.submitting lets it through this handler.
            this.submitting = true;
            this.element.requestSubmit(event.submitter ?? undefined);
            this.submitting = false;
        }
    }

    /** @returns {Promise<boolean>} false when the price could not be converted */
    async compute(source, action, output) {
        const price = source.value;
        const numeric = parseFloat(String(price).replace(',', '.'));
        const taxRuleId = parseInt(this.taxRuleTarget?.value || '0', 10);
        if (Number.isNaN(numeric) || !taxRuleId) {
            return true;
        }

        const url = new URL(this.calcUrlValue, window.location.origin);
        url.searchParams.set('price', numeric);
        url.searchParams.set('tax_rule_id', taxRuleId);
        url.searchParams.set('action', action);
        if (this.productIdValue > 0) {
            url.searchParams.set('product_id', this.productIdValue);
        }

        try {
            // A conversion that never answers would hold the form back for good.
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: AbortSignal.timeout(10000) });
            const result = Number((await response.json())?.result);
            if (!response.ok || Number.isNaN(result)) {
                return this.failed(source);
            }
            if (source.value !== price || this.edited.has(output)) {
                // Typed in again meanwhile: the conversion of what was typed then is the one that counts.
                return true;
            }
            output.value = String(Number(result.toFixed(6)));
            this.failures.delete(source);
            this.failures.delete(output);
            this.showError(this.failures.size > 0);

            return true;
        } catch {
            // A back office page instead of JSON (session expired), or no answer at all.
            return this.failed(source);
        }
    }

    failed(source) {
        this.failures.add(source);
        this.showError(true);

        return false;
    }

    showError(shown) {
        if (this.hasErrorTarget) {
            this.errorTarget.hidden = !shown;
        }
    }
}
