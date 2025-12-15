import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';

export default class OffCanvasCartRecommendationsPlugin extends Plugin {
    static options = {
        recommendationsUrl: '/hello-retail/cart/recommendations',
        cartSelector: '.cart-offcanvas .offcanvas-body',
        recommendationsSelector: '.offcanvas-cart-recommendations',
        hrRecom: '.hr-recom'
    }

    init() {
        this._client = new HttpClient();
        this._registerEvents();
    }

    _registerEvents() {
        var pluginInstances = window.PluginManager.getPluginInstances('OffCanvasCart');

        pluginInstances[0].$emitter.subscribe('offCanvasOpened');

        this._onOffcanvasOpened()
    }

    _onOffcanvasOpened() {
        this.fetch();
    }

    fetch() {
        this._client.get(this.options.recommendationsUrl, (response) => {
            const offcanvasCart = DomAccess.querySelector(document, this.options.cartSelector);
            const recommendationsContainer = offcanvasCart.querySelector(this.options.recommendationsSelector);

            if (recommendationsContainer) {
                recommendationsContainer.innerHTML = response;
                DomAccess.querySelector(document, this.options.hrRecom).classList.remove('d-none');
                const PluginManager = window.PluginManager;
                PluginManager.initializePlugins();
            }
        }, (error) => {
            console.error('Error fetching recommendations:', error);
        });
    }
}