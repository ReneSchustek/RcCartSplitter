import CartSplitterPlugin from './cart-splitter/cart-splitter.plugin';

// Das Plugin hängt an jedem Kaufformular; ohne TMMS-Felder beendet es sich in init() sofort.
const PluginManager = window.PluginManager;
PluginManager.register('CartSplitter', CartSplitterPlugin, 'form[action*="checkout/line-item/add"]');

// Soll CartSplitter nach einem Variantenwechsel an die neu aufgebaute Buybox binden, und zwar nur
// dieses Plugin statt aller Storefront-Plugins der Seite. Der Kern sendet `onVariantChange` nicht;
// nach dem Austausch der Buybox ruft er selbst `PluginManager.initializePlugins()` auf.
document.$emitter.subscribe('onVariantChange', () => {
    window.PluginManager.initializePlugin('CartSplitter');
});
