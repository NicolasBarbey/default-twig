import { Controller } from '@hotwired/stimulus';
import { Dropdown } from 'bootstrap';

/**
 * Keeps the tabs of a .bo-tab-bar on one row: the tabs that do not fit leave the row for a
 * "More" dropdown appended at its end, and come back as soon as the bar is wide enough again.
 * The open tab never leaves the row, so the bar always shows where the user is.
 *
 * A tab moved to the menu is also marked disabled while it is there: Bootstrap's arrow, Home
 * and End keys then skip it instead of opening a tab nobody can see. Each entry of the menu
 * lifts that mark and clicks its tab, so a Bootstrap tab and a tab that loads its pane behave
 * as they do in the row.
 *
 * The stylesheet holds the tabs on one clipped row until this controller marks the bar ready;
 * the mark comes first, so the tabs wrap again whatever fails afterwards.
 */
export default class extends Controller {
    static values = { moreLabel: { type: String, default: 'More' } };

    connect() {
        this.element.classList.add('bo-tab-bar--ready');

        this.nav = this.element.querySelector(':scope > .nav-tabs');
        if (!this.nav) {
            return;
        }

        this.more = document.createElement('li');
        this.more.className = 'nav-item dropdown bo-tab-overflow__more';
        this.more.dataset.testid = 'bo-tab-overflow-more';
        this.more.innerHTML = '<button type="button" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"></button>'
            + '<ul class="dropdown-menu dropdown-menu-end shadow-sm"></ul>';
        this.toggle = this.more.querySelector('.dropdown-toggle');
        this.toggle.textContent = this.moreLabelValue;
        this.menu = this.more.querySelector('.dropdown-menu');
        this.nav.append(this.more);

        this.boundLayout = this.layout.bind(this);
        this.observer = new ResizeObserver(this.boundLayout);
        this.observer.observe(this.element);
        this.element.addEventListener('shown.bs.tab', this.boundLayout);
        // A web font that arrives late widens the labels without resizing the bar.
        document.fonts?.ready.then(() => {
            if (this.nav) {
                this.layout();
            }
        });
        this.layout();
    }

    disconnect() {
        this.element.classList.remove('bo-tab-bar--ready');
        if (!this.nav) {
            return;
        }

        this.observer.disconnect();
        this.element.removeEventListener('shown.bs.tab', this.boundLayout);
        this.tabs().forEach(item => this.show(item));
        Dropdown.getInstance(this.toggle)?.dispose();
        this.more.remove();
        this.nav = null;
    }

    tabs() {
        return [...this.nav.children].filter(item => item !== this.more);
    }

    layout() {
        const tabs = this.tabs();
        tabs.forEach(item => this.show(item));
        this.more.hidden = true;

        if (this.wraps(tabs)) {
            this.more.hidden = false;
            const openIndex = tabs.findIndex(item => item.querySelector(':scope > .nav-link.active'));
            const kept = openIndex === -1 ? 0 : openIndex;
            for (let index = tabs.length - 1; index >= 0 && this.wraps(tabs); index--) {
                if (index !== kept) {
                    this.hide(tabs[index]);
                }
            }
        }

        this.fillMenu(tabs.filter(item => item.hidden));
    }

    show(item) {
        item.hidden = false;
        const tab = item.querySelector(':scope > .nav-link');
        if (tab?.dataset.boTabOverflowDisabled) {
            tab.classList.remove('disabled');
            delete tab.dataset.boTabOverflowDisabled;
        }
    }

    hide(item) {
        item.hidden = true;
        const tab = item.querySelector(':scope > .nav-link');
        if (tab && !tab.classList.contains('disabled')) {
            tab.classList.add('disabled');
            tab.dataset.boTabOverflowDisabled = '1';
        }
    }

    wraps(tabs) {
        const visible = [...tabs.filter(item => !item.hidden), ...(this.more.hidden ? [] : [this.more])];
        const firstRow = visible[0]?.offsetTop ?? 0;

        return visible.some(item => item.offsetTop > firstRow);
    }

    fillMenu(hiddenTabs) {
        this.menu.replaceChildren();

        hiddenTabs.forEach(item => {
            const tab = item.querySelector(':scope > .nav-link');
            if (!tab) {
                return;
            }

            const entry = document.createElement('button');
            entry.type = 'button';
            entry.className = 'dropdown-item d-flex align-items-center gap-2';
            if (tab.dataset.testid) {
                entry.dataset.testid = 'bo-tab-overflow-' + tab.dataset.testid;
            }
            // A copy of the label, not its text: a count badge stays a badge in the menu. The copy
            // drops the ids and test ids of the label, which must stay unique in the page.
            const label = [...tab.childNodes].map(node => node.cloneNode(true));
            label.filter(node => node instanceof Element).forEach(node => {
                [node, ...node.querySelectorAll('[id], [data-testid]')].forEach(element => {
                    element.removeAttribute('id');
                    element.removeAttribute('data-testid');
                });
            });
            entry.append(...label);
            entry.addEventListener('click', () => {
                // Bootstrap marks the tab active at once (shown.bs.tab only follows the fade):
                // laying out right away brings it back to the row without waiting.
                tab.classList.remove('disabled');
                tab.click();
                this.layout();
            });

            const line = document.createElement('li');
            line.append(entry);
            this.menu.append(line);
        });
    }
}
