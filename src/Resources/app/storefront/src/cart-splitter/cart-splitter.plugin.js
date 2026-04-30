import Plugin from 'src/plugin-system/plugin.class';

// TMMS-Felder liegen in eigenen Formularen (ID-Schema productCustomerInputForm-{productId}-{count}) —
// dieses Plugin sammelt sie pro Produkt, leitet daraus eine deterministische LineItem-ID ab und
// injiziert die Werte als Hidden-Felder, damit der Cart sie ohne weiteren Round-Trip anzeigen kann.
// Suffix-Daten sind generisch: jedes Plugin schreibt seinen Wert in form.dataset.rc*Suffix und meldet
// die Aenderung ueber das gemeinsame CustomEvent rcSuffixChanged. Neue Suffix-Plugins
// brauchen keine Code-Aenderung in dieser Datei mehr.
// Erweiterungs-Howto: README, Abschnitt "Erweiterung: weitere Suffix-Plugins".
export default class CartSplitterPlugin extends Plugin {

    // Muss mit TmmsConstants::INPUT_COUNT (PHP) uebereinstimmen
    static TMMS_MAX_FIELDS = 5;

    // Generisches Suffix-Event aus dem Plugin-Interaktionsprotokoll. Bewusst neutraler Namespace —
    // kein Plugin owned den Namen, jedes Suffix-Plugin (RcColorPicker, RcDynamicPrice, ...) feuert ihn
    // nach jeder Wert-Aenderung.
    static SUFFIX_CHANGED_EVENT = 'rcSuffixChanged';

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

        const tmmsInputs = this._getTmmsInputs();
        if (tmmsInputs.length === 0) {
            return;
        }

        // Markiert dieses Form: andere Ruhrcoder-Plugins duerfen die LineItem-ID nicht mehr aendern
        this._form.dataset.rcIdController = 'true';

        this._payloadPrefix = 'lineItems[' + this._productId + '][payload]';

        this._boundUpdate = this._onInputChanged.bind(this);
        this._boundSuffixChanged = () => this._onInputChanged();
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

        if (this._boundSuffixChanged && this._form) {
            this._form.removeEventListener(CartSplitterPlugin.SUFFIX_CHANGED_EVENT, this._boundSuffixChanged);
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

        // Ein einziger Listener: jedes Suffix-Plugin signalisiert seine Aenderung ueber das generische Event.
        this._form.addEventListener(CartSplitterPlugin.SUFFIX_CHANGED_EVENT, this._boundSuffixChanged);

        // capture: true → feuert VOR Shopware-AddToCartPlugin (das auf bubble lauscht)
        this._form.addEventListener('submit', this._boundBeforeSubmit, true);
    }

    // TMMS-Forms sind nicht im Buy-Form geschachtelt — Zugriff nur ueber die feste ID-Konvention
    _getTmmsInputs() {
        const inputs = [];

        for (let i = 1; i <= CartSplitterPlugin.TMMS_MAX_FIELDS; i++) {
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

    _onInputChanged() {
        this._updateLineItemId();
    }

    _updateLineItemId() {
        const values = this._collectValues();
        const hasValues = values.some(v => v !== '');
        const allSuffixes = this._collectAllSuffixes();

        if (hasValues || allSuffixes) {
            this._idInput.value = this._computeId(values, allSuffixes);
        } else {
            this._idInput.value = this._productId;
        }
    }

    // Capture-Phase vor Shopware-AddToCartPlugin: Werte muessen Teil von FormData(form) sein
    _injectHiddenFields() {
        this._form.querySelectorAll('input[data-rc-tmms]').forEach(el => el.remove());

        let hasAnyValue = false;

        for (let i = 1; i <= CartSplitterPlugin.TMMS_MAX_FIELDS; i++) {
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

    // Platzhalter ist im TMMS-Backend die kuerzere, kundenfreundliche Variante des Labels
    _getTmmsFieldLabel(tmmsForm, count) {
        const placeholderInput = tmmsForm.querySelector('[name="tmms-customer-input-placeholder-' + count + '"]');
        const labelInput = tmmsForm.querySelector('[name="tmms-customer-input-label-' + count + '"]');

        const placeholder = placeholderInput ? placeholderInput.value.trim() : '';
        const rawLabel = labelInput ? labelInput.value.trim() : '';

        if (placeholder !== '' && placeholder !== rawLabel) {
            return this._cleanLabel(placeholder);
        }

        // " - " trennt im TMMS-Label oft den Anzeigetext vom internen Zusatz
        if (rawLabel.indexOf(' - ') !== -1) {
            return this._cleanLabel(rawLabel.split(' - ')[0].trim());
        }

        return this._cleanLabel(rawLabel);
    }

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

    // Generisches Suffix-Protokoll: andere Plugins schreiben rc*Suffix ans Form, hier ohne Sonderfaelle einbinden
    _collectAllSuffixes() {
        const parts = [];
        const dataset = this._form.dataset;

        for (const key in dataset) {
            if (key.startsWith('rc') && key.endsWith('Suffix') && dataset[key]) {
                parts.push(key + '=' + dataset[key]);
            }
        }

        return parts.sort().join('\x00');
    }

    _computeId(values, suffixes) {
        const hashSegments = [];

        if (suffixes) {
            hashSegments.push(suffixes);
        }

        values.forEach((v, i) => {
            if (v !== '') {
                hashSegments.push('f' + i + '=' + v);
            }
        });

        const hashInput = hashSegments.join('\x00');
        const valueHash = this._fnv32a(hashInput);
        const productScopedHash = this._fnv32a(this._productId + hashInput);

        const valueHashHex = valueHash.toString(16).padStart(8, '0');
        const productScopedHashHex = productScopedHash.toString(16).padStart(8, '0');

        const productSegment = this._productId.replace(/-/g, '');
        const combinedUuid = productSegment.substring(0, 16) + valueHashHex + productScopedHashHex;

        return [
            combinedUuid.substring(0, 8),
            combinedUuid.substring(8, 12),
            combinedUuid.substring(12, 16),
            combinedUuid.substring(16, 20),
            combinedUuid.substring(20, 32),
        ].join('-');
    }

    _fnv32a(str) {
        // FNV-1a 32-Bit: deterministisch und kollisionsarm bei kurzen Strings, ohne Crypto-API im Browser nutzbar.
        let hash = 0x811c9dc5;
        for (let i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash = Math.imul(hash, 0x01000193);
            hash >>>= 0;
        }
        return hash;
    }
}
