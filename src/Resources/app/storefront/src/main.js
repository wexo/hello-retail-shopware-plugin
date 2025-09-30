import CartTrackerPlugin from "./plugins/cart-tracker.plugin";
import OffCanvasCartRecommendationsPlugin from "./plugins/offcanvas-cart-recommendations.plugin";
import ProductListingPlugin from "./plugins/product-listing.plugin";

const PluginManager = window.PluginManager;

PluginManager.register('HelretCartTracker', CartTrackerPlugin, '[data-helret-cart-tracker]');
PluginManager.register('OffCanvasCartRecommendations', OffCanvasCartRecommendationsPlugin, '[data-offcanvas-cart-recommendations]');
PluginManager.register('ProductListingPlugin', ProductListingPlugin, '[data-helret-listing]');

window.PluginManager.register(
  'aw-click-tracking',
  () => import('./plugins/ClickTracking.plugin'),
  '[data-aw-source]'
);
window.PluginManager.register(
  'aw-search-widget',
  () => import('./plugins/DecoratedSearchWidgetPlugin.plugin'),
  '[data-search-widget]'
);
