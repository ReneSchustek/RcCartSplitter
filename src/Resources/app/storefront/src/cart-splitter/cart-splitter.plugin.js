import Plugin from 'src/plugin-system/plugin.class';

/**
 * RcCartSplitter — ändert die LineItem-ID basierend auf TMMS-Kundeneingaben.
 *
 * TMMS rendert seine Eingabefelder in eigenen Formularen mit ID-Schema:
 * productCustomerInputForm-{productId}-{count}
 *
 * Dieses Plugin findet nur die Felder des zugehörigen Produkts, berechnet
 * einen Hash und setzt die LineItem-ID im Buy-Form entsprechend.
 * Zusätzlich werden die Kundeneingaben als Hidden-Felder ins Buy-Form
 * injiziert, damit sie im Warenkorb angezeigt werden können.
 */
export default class CartSplitterPlugin extends Plugin {

    init() {
        this._form = this.el;

        this._idInput = this._form.querySelector('input[name$="[id]"][name^="lineItems["]');
        if (!this._idInput) {
            return;
        }

        const match = this._idInput.name.match(/lineItems\[([^\]]+)]\[id]/);
        this._productId = match ? match[1] : null;
        if (!this._productId) {
            return;
        }

        // Nur initialisieren wenn TMMS-Felder für dieses Produkt existieren
        const tmmsInputs = this._getTmmsInputs();
        if (tmmsInputs.length === 0) {
            return;
        }

        this._meterSuffix = '';
        this._payloadPrefix = 'lineItems[' + this._productId + '][payload]';

        this._boundUpdate = this._onInputChanged.bind(this);
        this._boundMeterChanged = (e) => {
            this._meterSuffix = (e.detail && e.detail.suffix) ? e.detail.suffix : '';
            this._onInputChanged();
        };
        this._boundBeforeSubmit = this._injectHiddenFields.bind(this);

        this._registerEvents();
    }

    destroy() {
        if (this._boundUpdate) {
            this._getTmmsInputs().forEach(input => {
                input.removeEventListener('change', this._boundUpdate);
                input.removeEventListener('input', this._boundUpdate);
            });
        }

        if (this._boundMeterChanged && this._form) {
            this._form.removeEventListener('rcMeterLengthChanged', this._boundMeterChanged);
        }

        if (this._boundBeforeSubmit && this._form) {
            this._form.removeEventListener('submit', this._boundBeforeSubmit, true);
        }

        super.destroy();
    }

    _registerEvents() {
        this._getTmmsInputs().forEach(input => {
            input.addEventListener('change', this._boundUpdate);
            input.addEventListener('input', this._boundUpdate);
        });

        this._form.addEventListener('rcMeterLengthChanged', this._boundMeterChanged);
        // capture: true → feuert VOR Shopware's AddToCartPlugin (das auf bubble lauscht)
        this._form.addEventListener('submit', this._boundBeforeSubmit, true);
        this._meterSuffix = this._form.dataset.rcMeterSuffix || '';
    }

    /**
     * Findet TMMS-Eingabefelder die zu DIESEM Produkt gehören.
     * TMMS-Forms haben ID: productCustomerInputForm-{productId}-{count}
     */
    _getTmmsInputs() {
        const inputs = [];

        for (let i = 1; i <= 5; i++) {
            const tmmsForm = document.getElementById(
                'productCustomerInputForm-' + this._productId + '-' + i
            );

            if (!tmmsForm) {
                continue;
            }

            const input = tmmsForm.querySelector('[name^="tmms-customer-input-value-"]');
            if (input) {
                inputs.push(input);
            }
        }

        return inputs;
    }

    /**
     * Wird bei jeder Eingabeänderung aufgerufen.
     */
    _onInputChanged() {
        this._updateLineItemId();
    }

    _updateLineItemId() {
        const values = this._collectValues();
        const hasValues = values.some(v => v !== '');

        if (hasValues || this._meterSuffix) {
            this._idInput.value = this._computeId(values);
        } else {
            this._idInput.value = this._productId;
        }
    }

    /**
     * Wird im submit-Event (capture-Phase) aufgerufen — VOR Shopware's AddToCartPlugin.
     * Injiziert Hidden-Felder mit den aktuellen TMMS-Werten ins Formular,
     * damit sie bei new FormData(form) erfasst werden.
     */
    _injectHiddenFields() {
        // Alte Hidden-Felder entfernen
        this._form.querySelectorAll('input[data-rc-tmms]').forEach(el => el.remove());

        let hasAnyValue = false;

        for (let i = 1; i <= 5; i++) {
            const tmmsForm = document.getElementById(
                'productCustomerInputForm-' + this._productId + '-' + i
            );

            if (!tmmsForm) {
                continue;
            }

            const valueInput = tmmsForm.querySelector('[name^="tmms-customer-input-value-"]');
            if (!valueInput) {
                continue;
            }

            const value = valueInput.value.trim();
            if (value === '') {
                continue;
            }

            const label = this._getTmmsFieldLabel(tmmsForm, i);

            this._addHidden(this._payloadPrefix + '[rcTmmsField' + i + 'Value]', value);
            this._addHidden(this._payloadPrefix + '[rcTmmsField' + i + 'Label]', label);
            hasAnyValue = true;
        }

        if (hasAnyValue) {
            this._addHidden(this._payloadPrefix + '[rcTmmsActive]', '1');
        }
    }

    _addHidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        input.setAttribute('data-rc-tmms', '');
        this._form.appendChild(input);
    }

    /**
     * Liest die Bezeichnung für ein TMMS-Feld.
     * Prüft ob ein Platzhalter konfiguriert ist → verwendet diesen.
     * Wenn nicht → verwendet die Beschriftung (Label).
     */
    _getTmmsFieldLabel(tmmsForm, count) {
        const placeholderInput = tmmsForm.querySelector('[name="tmms-customer-input-placeholder-' + count + '"]');
        const labelInput = tmmsForm.querySelector('[name="tmms-customer-input-label-' + count + '"]');

        const placeholder = placeholderInput ? placeholderInput.value.trim() : '';
        const rawLabel = labelInput ? labelInput.value.trim() : '';

        // Platzhalter verwenden, wenn er sich vom Label unterscheidet
        // (TMMS setzt einen Default-Platzhalter wenn keiner konfiguriert ist —
        // dieser unterscheidet sich immer vom Label)
        if (placeholder !== '' && placeholder !== rawLabel) {
            return this._cleanLabel(placeholder);
        }

        // Fallback: Beschriftung (Label)
        // Kurzname: Text vor " - ", sonst vollständiges Label
        if (rawLabel.indexOf(' - ') !== -1) {
            return this._cleanLabel(rawLabel.split(' - ')[0].trim());
        }

        return this._cleanLabel(rawLabel);
    }

    /**
     * Entfernt abschließende Doppelpunkte und Leerzeichen.
     */
    _cleanLabel(label) {
        return label.replace(/[\s:]+$/, '');
    }

    _collectValues() {
        const values = [];
        this._getTmmsInputs().forEach(input => {
            values.push(input.value.trim());
        });
        return values;
    }

    _computeId(values) {
        const parts = [];
        if (this._meterSuffix) {
            parts.push(this._meterSuffix);
        }
        values.forEach((v, i) => {
            if (v !== '') {
                parts.push('f' + i + '=' + v);
            }
        });

        const hashInput = parts.join('\x00');
        const h1 = this._fnv32a(hashInput);
        const h2 = this._fnv32a(this._productId + hashInput);

        const h1Hex = h1.toString(16).padStart(8, '0');
        const h2Hex = h2.toString(16).padStart(8, '0');

        const productHex = this._productId.replace(/-/g, '');
        const newHex = productHex.substring(0, 16) + h1Hex + h2Hex;

        return [
            newHex.substring(0, 8),
            newHex.substring(8, 12),
            newHex.substring(12, 16),
            newHex.substring(16, 20),
            newHex.substring(20, 32),
        ].join('-');
    }

    _fnv32a(str) {
        let hash = 0x811c9dc5;
        for (let i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash = Math.imul(hash, 0x01000193);
            hash >>>= 0;
        }
        return hash;
    }
}
