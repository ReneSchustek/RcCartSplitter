import CartSplitterPlugin from './cart-splitter/cart-splitter.plugin';

// Das Plugin hängt an jedem Kaufformular; ohne TMMS-Felder beendet es sich in init() sofort.
const PluginManager = window.PluginManager;
PluginManager.register('CartSplitter', CartSplitterPlugin, 'form[action*="checkout/line-item/add"]');

// Nach einem Variantenwechsel tauscht der Kern die Buybox aus und ruft selbst
// `PluginManager.initializePlugins()` auf; das Plugin bindet sich dabei neu an das Kaufformular.
