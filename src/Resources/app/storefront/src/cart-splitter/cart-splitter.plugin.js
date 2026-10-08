import Plugin from 'src/plugin-system/plugin.class';

/**
 * Trennt Warenkorbpositionen desselben Artikels nach ihren TMMS-Eingaben.
 *
 * Shopware fasst Positionen mit gleicher Kennung zusammen. Das Plugin leitet die Kennung deshalb
 * aus Produkt, TMMS-Werten und den Suffixen anderer Plugins ab: gleiche Eingaben ergeben dieselbe
 * Position, abweichende eine eigene. Die TMMS-Felder liegen in eigenen Formularen außerhalb des
 * Kaufformulars (ID-Schema productCustomerInputForm-{productId}-{count}); ihre Werte gehen beim
 * Absenden als Hidden-Felder in den Payload der Position.
 *
 * Suffix-Plugins schreiben ihren Wert in form.dataset.rc*Suffix und melden die Änderung über das
 * Ereignis rcSuffixChanged; diese Datei kennt sie nicht einzeln. Anleitung: README, Abschnitt
 * „Erweiterung: weitere Suffix-Plugins".
 */
export default class CartSplitterPlugin extends Plugin {

    // TMMS bietet fünf Felder je Produkt; muss mit TmmsConstants::INPUT_COUNT (PHP) übereinstimmen.
    static TMMS_MAX_FIELDS = 5;

    // Der Name gehört keinem einzelnen Plugin. Jedes Suffix-Plugin (RcColorPicker, RcDynamicPrice, …)
    // feuert ihn nach jeder Wertänderung am Kaufformular.
    static SUFFIX_CHANGED_EVENT = 'rcSuffixChanged';

    init() {
        this._form = this.el;

        this._idInput = this._form.querySelector('input[name$="[id]"][name^="lineItems["]');
        if (!this._idInput) {
            return;
        }

        // Der Schlüssel in lineItems[…] ist die Produktkennung und bleibt fest; umgeschrieben wird
        // nur der Wert des id-Felds.
        const match = this._idInput.name.match(/lineItems\[([^\]]+)]\[id]/);
        this._productId = match ? match[1] : null;
        if (!this._productId) {
            return;
        }

        const tmmsInputs = this._getTmmsInputs();
        if (tmmsInputs.length === 0) {
            return;
        }

        // Andere Ruhrcoder-Plugins lesen diese Markierung und lassen die Positionskennung dann in Ruhe.
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

        // Ein Listener für alle Suffix-Plugins, weil sie dasselbe Ereignis feuern.
        this._form.addEventListener(CartSplitterPlugin.SUFFIX_CHANGED_EVENT, this._boundSuffixChanged);

        // Capture-Phase: Das AddToCartPlugin des Kerns lauscht ohne capture am selben Formular und
        // liest FormData beim Absenden. Die Hidden-Felder müssen vorher stehen.
        this._form.addEventListener('submit', this._boundBeforeSubmit, true);
    }

    // TMMS-Forms sind nicht im Buy-Form geschachtelt — Zugriff nur über die feste ID-Konvention
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
            // Ohne Eingaben bleibt die Produktkennung, damit die Position mit einer gewöhnlichen
            // Position desselben Artikels zusammenfällt.
            this._idInput.value = this._productId;
        }
    }

    // Läuft in der Capture-Phase vor dem AddToCartPlugin, damit die Werte Teil von FormData(form) sind.
    _injectHiddenFields() {
        // Die Kennung wird beim Absenden noch einmal berechnet. input und change feuern bei
        // Auswahllisten, Datumswählern und per Skript gesetzten Werten nicht zuverlässig; ohne ein
        // Suffix-Plugin, das zwischendurch neu rechnen lässt, bliebe die Kennung sonst auf dem Startwert.
        this._updateLineItemId();

        // Ein zweites Absenden ohne Neuladen fände sonst die Felder vom ersten Mal doppelt vor.
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

        // Der Marker nur bei mindestens einem Wert: Ohne ihn greift serverseitig der Session-Weg.
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

    // Platzhalter ist im TMMS-Backend die kürzere, kundenfreundliche Variante des Labels
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

    // Andere Plugins schreiben rc*Suffix ans Formular; jedes solche Attribut zählt, ohne Sonderfälle.
    // Sortiert, damit die Reihenfolge, in der die Plugins ihre Werte setzen, die Kennung nicht ändert.
    // NUL kommt in Formularwerten praktisch nicht vor und trennt die Teile deshalb eindeutig.
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

        // 16 Zeichen Produktkennung plus zwei 32-Bit-Hashes ergeben 32 Hex-Zeichen im UUID-Format.
        // Der Produktanteil macht die Position beim Nachsehen im Warenkorb zuordenbar; der zweite
        // Hash bezieht die volle Produktkennung ein, damit zwei Produkte mit gleichem Präfix und
        // gleichen Eingaben nicht zusammenfallen.
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
        // 0x811c9dc5 und 0x01000193 sind Startwert und Primzahl dieser Variante; Math.imul und >>> 0
        // halten die Rechnung in vorzeichenlosen 32 Bit.
        let hash = 0x811c9dc5;
        for (let i = 0; i < str.length; i++) {
            hash ^= str.charCodeAt(i);
            hash = Math.imul(hash, 0x01000193);
            hash >>>= 0;
        }
        return hash;
    }
}
