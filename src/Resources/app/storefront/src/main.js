import CartSplitterPlugin from './cart-splitter/cart-splitter.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('CartSplitter', CartSplitterPlugin, 'form[action*="checkout/line-item/add"]');

// Re-Initialisierung nach Variantenwechsel — Shopware baut die Buybox neu auf
document.$emitter.subscribe('onVariantChange', () => {
    window.PluginManager.initializePlugins();
});
