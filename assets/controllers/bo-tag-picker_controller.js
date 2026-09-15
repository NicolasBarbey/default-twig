import { Controller } from '@hotwired/stimulus';

/**
 * Turns the tag multi-select of the customer screen into a chip input.
 *
 * A progressive enhancement and nothing more: the select and the free-text field
 * stay in the form and keep being posted, so the submission is byte-for-byte the
 * one the server already accepts. Without JavaScript the operator gets the plain
 * multi-select and its filter box, which work on their own.
 *
 * Picked tags live in the select, typed ones in the comma-separated field, and a
 * chip only ever writes back into whichever of the two owns it.
 */
export default class extends Controller {
    static targets = ['select', 'newTags', 'fallback', 'widget', 'chips', 'input', 'menu'];

    static values = {
        createLabel: String,
        emptyLabel: String,
        removeLabel: String,
    };

    connect() {
        if (!this.hasSelectTarget || !this.hasWidgetTarget) {
            return;
        }

        this.highlightedIndex = -1;
        this.fallbackTarget.classList.add('d-none');
        this.widgetTarget.classList.remove('d-none');
        this.render();
    }

    disconnect() {
        this.closeMenu();
    }

    // ---------- state ----------

    /** Labels held by the free-text field, which the select does not know. */
    get typedLabels() {
        return this.newTagsTarget.value
            .split(',')
            .map((label) => label.trim())
            .filter((label) => label !== '');
    }

    set typedLabels(labels) {
        this.newTagsTarget.value = labels.join(', ');
    }

    get pickedOptions() {
        return Array.from(this.selectTarget.options).filter((option) => option.selected);
    }

    /** Every label currently on the customer, whichever field carries it. */
    get chosenLabels() {
        return [...this.pickedOptions.map((option) => option.text), ...this.typedLabels];
    }

    // ---------- rendering ----------

    render() {
        this.renderChips();
        this.renderMenu();
    }

    renderChips() {
        this.chipsTarget.replaceChildren();

        this.pickedOptions.forEach((option) => {
            this.chipsTarget.append(this.buildChip(option.text, option.dataset.color || '', false));
        });

        this.typedLabels.forEach((label) => {
            // No colour: a tag being typed does not exist yet, so it has none
            // until it is saved.
            this.chipsTarget.append(this.buildChip(label, '', true));
        });
    }

    buildChip(label, color, isTyped) {
        const chip = document.createElement('span');
        chip.className = 'bo-tag-picker__chip';
        chip.dataset.testid = 'customer-tag-chip';

        if (color !== '') {
            const swatch = document.createElement('span');
            swatch.className = 'bo-tag-picker__swatch';
            // Assigned rather than interpolated into a style string, and the
            // value was already checked by TagSwatch server-side.
            swatch.style.backgroundColor = color;
            chip.append(swatch);
        }

        chip.append(document.createTextNode(label));

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'bo-tag-picker__remove';
        remove.setAttribute('aria-label', this.removeLabelValue.replace('%label%', label));
        remove.textContent = '×';
        remove.addEventListener('click', (event) => {
            event.stopPropagation();
            this.remove(label, isTyped);
        });
        chip.append(remove);

        return chip;
    }

    renderMenu() {
        const term = this.inputTarget.value.trim();
        const chosen = this.chosenLabels.map((label) => label.toLocaleLowerCase());

        const matches = Array.from(this.selectTarget.options).filter((option) => {
            if (option.selected) {
                return false;
            }
            const text = option.text.toLocaleLowerCase();

            return term === '' || text.includes(term.toLocaleLowerCase());
        });

        this.menuTarget.replaceChildren();

        matches.forEach((option) => {
            this.menuTarget.append(this.buildMenuItem(
                option.text,
                option.dataset.color || '',
                () => this.pick(option),
            ));
        });

        const isNew = term !== ''
            && !chosen.includes(term.toLocaleLowerCase())
            && !matches.some((option) => option.text.toLocaleLowerCase() === term.toLocaleLowerCase());

        if (isNew) {
            this.menuTarget.append(this.buildMenuItem(
                this.createLabelValue.replace('%label%', term),
                '',
                () => this.create(term),
                'bo-tag-picker__option--create',
            ));
        }

        if (this.menuTarget.childElementCount === 0) {
            const empty = document.createElement('li');
            empty.className = 'bo-tag-picker__empty';
            empty.textContent = this.emptyLabelValue;
            this.menuTarget.append(empty);
        }

        this.highlight(this.menuTarget.querySelectorAll('[data-selectable]').length === 0 ? -1 : 0);
    }

    buildMenuItem(text, color, onChoose, extraClass = '') {
        const item = document.createElement('li');
        item.className = `bo-tag-picker__option ${extraClass}`.trim();
        item.dataset.selectable = 'true';
        item.setAttribute('role', 'option');

        if (color !== '') {
            const swatch = document.createElement('span');
            swatch.className = 'bo-tag-picker__swatch';
            swatch.style.backgroundColor = color;
            item.append(swatch);
        }

        item.append(document.createTextNode(text));
        // mousedown rather than click: the input loses focus first on a click, and
        // the blur handler would have closed the menu before the choice landed.
        item.addEventListener('mousedown', (event) => {
            event.preventDefault();
            onChoose();
        });

        return item;
    }

    // ---------- actions ----------

    search() {
        this.openMenu();
        this.renderMenu();
    }

    focusInput() {
        this.inputTarget.focus();
    }

    openMenu() {
        this.menuTarget.classList.remove('d-none');
    }

    closeMenu() {
        this.menuTarget.classList.add('d-none');
    }

    open() {
        this.openMenu();
        this.renderMenu();
    }

    close() {
        this.closeMenu();
    }

    key(event) {
        const items = Array.from(this.menuTarget.querySelectorAll('[data-selectable]'));

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (items.length === 0) {
                return;
            }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.highlight((this.highlightedIndex + step + items.length) % items.length);

            return;
        }

        if (event.key === 'Enter') {
            // Always swallowed: a bare Enter in this input would otherwise submit
            // the whole customer form while the operator is still typing a tag.
            event.preventDefault();
            const item = items[this.highlightedIndex];
            if (item) {
                item.dispatchEvent(new MouseEvent('mousedown'));
            }

            return;
        }

        if (event.key === 'Escape') {
            this.closeMenu();

            return;
        }

        if (event.key === 'Backspace' && this.inputTarget.value === '') {
            const typed = this.typedLabels;
            if (typed.length > 0) {
                this.remove(typed[typed.length - 1], true);

                return;
            }
            const picked = this.pickedOptions;
            if (picked.length > 0) {
                this.remove(picked[picked.length - 1].text, false);
            }
        }
    }

    highlight(index) {
        const items = Array.from(this.menuTarget.querySelectorAll('[data-selectable]'));
        this.highlightedIndex = index;
        items.forEach((item, position) => {
            item.classList.toggle('bo-tag-picker__option--active', position === index);
        });
    }

    pick(option) {
        option.selected = true;
        this.afterChange();
    }

    create(label) {
        // A label the vocabulary already holds is picked, never typed twice: the
        // service would fold them into one tag anyway, and the chip would double.
        const known = Array.from(this.selectTarget.options)
            .find((option) => option.text.toLocaleLowerCase() === label.toLocaleLowerCase());

        if (known) {
            this.pick(known);

            return;
        }

        if (!this.typedLabels.some((typed) => typed.toLocaleLowerCase() === label.toLocaleLowerCase())) {
            this.typedLabels = [...this.typedLabels, label];
        }

        this.afterChange();
    }

    remove(label, isTyped) {
        if (isTyped) {
            this.typedLabels = this.typedLabels.filter((typed) => typed !== label);
        } else {
            const option = Array.from(this.selectTarget.options).find((candidate) => candidate.text === label);
            if (option) {
                option.selected = false;
            }
        }

        this.render();
    }

    afterChange() {
        this.inputTarget.value = '';
        this.render();
        this.focusInput();
    }
}
