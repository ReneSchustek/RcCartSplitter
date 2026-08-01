import CartSplitterPlugin from './cart-splitter/cart-splitter.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('CartSplitter', CartSplitterPlugin, 'form[action*="checkout/line-item/add"]');

// Re-Initialisierung nach Variantenwechsel — Shopware baut die Buybox neu auf.
// Gezielt nur CartSplitter neu binden, sonst werden alle Storefront-Plugins der Seite
// (Slider, Galerie, Reviews, Wishlist ...) unnoetig re-initialisiert.
document.$emitter.subscribe('onVariantChange', () => {
    window.PluginManager.initializePlugin('CartSplitter');
});
