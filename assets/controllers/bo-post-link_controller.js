import { Controller } from '@hotwired/stimulus';
import { submitAsPost } from '../lib/post-request.js';

/**
 * Turns a click on a link marked `data-bo-post` into a POST of the same URL,
 * with the back-office token in the request body. Mounted once on <body>, it
 * also covers links injected later (modals, ajax tabs).
 *
 *   <a href="{{ path('admin.brand.toggle-online', { brand_id: 1 }) }}" data-bo-post>…</a>
 *
 * A link may ask for a confirmation first with `data-bo-post-confirm="…"`.
 */
export default class extends Controller {
    connect() {
        this.onClick = this.submit.bind(this);
        this.element.addEventListener('click', this.onClick);
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
    }

    submit(event) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }

        const link = event.target.closest('[data-bo-post]');
        if (!link || !this.element.contains(link)) {
            return;
        }

        const url = link.getAttribute('href') || link.dataset.boPostUrl || '';
        if (url === '') {
            return;
        }

        event.preventDefault();

        const question = link.dataset.boPostConfirm;
        if (question && !window.confirm(question)) {
            return;
        }

        submitAsPost(url);
    }
}
