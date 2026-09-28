import { Controller } from '@hotwired/stimulus';
import qrcode from 'qrcode-generator';

export default class extends Controller {
    static values = { uri: String, label: String };

    connect() {
        const code = qrcode(0, 'M');
        code.addData(this.uriValue);
        code.make();

        this.element.innerHTML = code.createSvgTag({ cellSize: 4, margin: 4, scalable: true, alt: this.labelValue });
        this.element.querySelector('svg')?.setAttribute('role', 'img');
    }
}
