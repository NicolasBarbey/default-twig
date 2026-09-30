import { Controller } from '@hotwired/stimulus';
import { submitAsPost } from '../lib/post-request.js';

/**
 * Exclusive "set as default" radio for DataTable rows. Selecting a radio
 * posts to its toggle URL, which flips the chosen row to default and lets the
 * server reset the others - the page reloads with a single default checked,
 * matching the legacy back-office behaviour.
 */
export default class extends Controller {
    static values = { url: String };

    select() {
        if (this.urlValue) {
            submitAsPost(this.urlValue);
        }
    }
}
