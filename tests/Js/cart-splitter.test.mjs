// Unit-Tests fuer cart-splitter.plugin.js. Zero-Dependency: Node-Standardbibliothek (node:test).
// Die Storefront-Quelle wird zur Testzeit eingelesen, `import`/`export` rausgestrippt
// und mit einer Stub-Plugin-Basisklasse evaluiert — so testen wir den echten Source ohne Webpack-Bundler.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
    __dirname,
    '..',
    '..',
    'src',
    'Resources',
    'app',
    'storefront',
    'src',
    'cart-splitter',
    'cart-splitter.plugin.js',
);

const rawSource = readFileSync(sourcePath, 'utf8');
const stripped = rawSource
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const wrapped = `
    class Plugin {
        init() {}
        destroy() {}
    }
    ${stripped}
    return CartSplitterPlugin;
`;

const CartSplitterPlugin = new Function(wrapped)();

const PRODUCT_ID = '0123456789abcdef0123456789abcdef';

function makePlugin(productId = PRODUCT_ID, dataset = {}) {
    const instance = Object.create(CartSplitterPlugin.prototype);
    instance._productId = productId;
    instance._form = { dataset };
    return instance;
}

function makeTmmsForm(fields) {
    return {
        querySelector(selector) {
            const match = selector.match(/name="tmms-customer-input-(placeholder|label)-\d+"/);
            if (!match) {
                return null;
            }
            const value = fields[match[1]];
            return value === undefined ? null : { value };
        },
    };
}

describe('_fnv32a — FNV-1a-Determinismus', () => {
    test('liefert fuer denselben Input denselben Hash (Reproduzierbarkeit)', () => {
        const plugin = makePlugin();
        assert.strictEqual(plugin._fnv32a('rcMeterLengthSuffix=500cm'), plugin._fnv32a('rcMeterLengthSuffix=500cm'));
    });

    test('trennt verschiedene Inputs zuverlaessig (Kollisionsfreiheit auf Stichproben)', () => {
        const plugin = makePlugin();
        assert.notStrictEqual(plugin._fnv32a('Laenge=200'), plugin._fnv32a('Laenge=300'));
    });

    test('liefert fuer den leeren String den FNV-1a-Offset-Basis-Wert 0x811c9dc5', () => {
        const plugin = makePlugin();
        assert.strictEqual(plugin._fnv32a(''), 0x811c9dc5);
    });

    test('liefert fuer "a" den oeffentlichen FNV-1a-Referenzwert 0xe40c292c', () => {
        const plugin = makePlugin();
        assert.strictEqual(plugin._fnv32a('a'), 0xe40c292c);
    });

    test('liefert fuer "foobar" den oeffentlichen FNV-1a-Referenzwert 0xbf9cf968', () => {
        const plugin = makePlugin();
        assert.strictEqual(plugin._fnv32a('foobar'), 0xbf9cf968);
    });
});

describe('_cleanLabel', () => {
    test('entfernt trailing Doppelpunkt', () => {
        assert.strictEqual(makePlugin()._cleanLabel('Laenge:'), 'Laenge');
    });

    test('entfernt gemischte trailing Whitespaces und Doppelpunkte', () => {
        assert.strictEqual(makePlugin()._cleanLabel('Material  :  '), 'Material');
    });

    test('laesst Label ohne trailing Trennzeichen unveraendert', () => {
        assert.strictEqual(makePlugin()._cleanLabel('Farbe'), 'Farbe');
    });
});

describe('_collectAllSuffixes', () => {
    test('liefert leeren String wenn keine rc*Suffix-Attribute am Form gesetzt sind', () => {
        assert.strictEqual(makePlugin(PRODUCT_ID, {})._collectAllSuffixes(), '');
    });

    test('sortiert rc*Suffix-Attribute deterministisch unabhaengig von der Insertion-Order', () => {
        const plugin = makePlugin(PRODUCT_ID, {
            rcMeterLengthSuffix: '500cm',
            rcColorPickerSuffix: 'eiche',
        });
        assert.strictEqual(
            plugin._collectAllSuffixes(),
            'rcColorPickerSuffix=eiche\x00rcMeterLengthSuffix=500cm',
        );
    });

    test('ignoriert Attribute, die nicht mit "rc" beginnen oder nicht auf "Suffix" enden', () => {
        const plugin = makePlugin(PRODUCT_ID, {
            rcMeterLengthSuffix: '300cm',
            otherAttr: 'X',
            rcSomethingElse: 'Y',
        });
        assert.strictEqual(plugin._collectAllSuffixes(), 'rcMeterLengthSuffix=300cm');
    });
});

describe('_computeId', () => {
    test('liefert UUID-Format (8-4-4-4-12 Hex)', () => {
        const id = makePlugin()._computeId(['100'], '');
        assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
    });

    test('ist deterministisch — gleiche Eingaben ergeben dieselbe ID', () => {
        const id1 = makePlugin()._computeId(['100', '200'], 'rcMeterLengthSuffix=500cm');
        const id2 = makePlugin()._computeId(['100', '200'], 'rcMeterLengthSuffix=500cm');
        assert.strictEqual(id1, id2);
    });

    test('trennt verschiedene Werte in verschiedene IDs', () => {
        const plugin = makePlugin();
        assert.notStrictEqual(
            plugin._computeId(['100'], ''),
            plugin._computeId(['200'], ''),
        );
    });

    test('trennt verschiedene Suffixe in verschiedene IDs', () => {
        const plugin = makePlugin();
        assert.notStrictEqual(
            plugin._computeId([], 'rcSuffix=A'),
            plugin._computeId([], 'rcSuffix=B'),
        );
    });

    test('beginnt mit den ersten 16 Hex-Zeichen der Produkt-ID (Lesbarkeit im Cart-Debugging)', () => {
        const id = makePlugin()._computeId(['100'], '');
        assert.strictEqual(id.replace(/-/g, '').substring(0, 16), PRODUCT_ID.substring(0, 16));
    });
});

describe('_getTmmsFieldLabel', () => {
    test('verwendet den Placeholder, wenn er vom Roh-Label abweicht', () => {
        const tmmsForm = makeTmmsForm({ placeholder: 'Laenge', label: 'Laenge - intern' });
        assert.strictEqual(makePlugin()._getTmmsFieldLabel(tmmsForm, 1), 'Laenge');
    });

    test('schneidet bei fehlendem Placeholder den " - "-Suffix aus dem Roh-Label ab', () => {
        const tmmsForm = makeTmmsForm({ placeholder: '', label: 'Material - eiche' });
        assert.strictEqual(makePlugin()._getTmmsFieldLabel(tmmsForm, 1), 'Material');
    });

    test('liefert das bereinigte Roh-Label, wenn weder Placeholder noch " - "-Trenner vorhanden sind', () => {
        const tmmsForm = makeTmmsForm({ placeholder: '', label: 'Farbe:' });
        assert.strictEqual(makePlugin()._getTmmsFieldLabel(tmmsForm, 1), 'Farbe');
    });
});
