import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['priceExcl', 'priceIncl', 'salePriceExcl', 'salePriceIncl', 'taxRule'];

    static values = {
        calcUrl: String,
    };

    onPriceExclBlur() {
        this.compute(this.priceExclTarget.value, 'untaxed_to_taxed', this.priceInclTarget);
    }

    onPriceInclBlur() {
        this.compute(this.priceInclTarget.value, 'taxed_to_untaxed', this.priceExclTarget);
    }

    onSalePriceExclBlur() {
        this.compute(this.salePriceExclTarget.value, 'untaxed_to_taxed', this.salePriceInclTarget);
    }

    onSalePriceInclBlur() {
        this.compute(this.salePriceInclTarget.value, 'taxed_to_untaxed', this.salePriceExclTarget);
    }

    async compute(price, action, output) {
        const numeric = parseFloat(price);
        const taxRuleId = parseInt(this.taxRuleTarget?.value || '0', 10);
        if (Number.isNaN(numeric) || !taxRuleId) {
            return;
        }

        const url = new URL(this.calcUrlValue, window.location.origin);
        url.searchParams.set('price', numeric);
        url.searchParams.set('tax_rule_id', taxRuleId);
        url.searchParams.set('action', action);

        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) return;
            const payload = await response.json();
            const result = Number(payload?.result);
            if (!Number.isNaN(result) && output) {
                output.value = result.toFixed(6);
            }
        } catch {
            // silently ignore — user keeps editing manually
        }
    }
}
