import Plugin from 'src/plugin-system/plugin.class';

export default class ProductListingPlugin extends Plugin {
    init() {
        let pageKey = window.hrListingData.pageKey
        let hrScript = window.hrListingData.helloRetailData.products.javascript
        let productFilters =  window.hrListingData.hierarchies

        window.hrq = window.hrq || [];

        window.hrq.push(function () {
            (function (_, container, data, page) {
                data.params = data.params || {};

                // pass shopware category name or id to hello retail script
                let existingFilters = {};
                if (data.params.filters) {
                    existingFilters = JSON.parse(data.params.filters);
                }
                if (productFilters["extraDataList.categoryIds"]) {
                    existingFilters["extraDataList.categoryIds"] = productFilters["extraDataList.categoryIds"];
                } else {
                    existingFilters = productFilters;
                }

                data.params.filters = JSON.stringify(existingFilters);
                eval(hrScript);
            })(
                ADDWISH_PARTNER_NS,
                document.getElementById('hr-category-page'),
                window.hrListingData.helloRetailData,
                { key: pageKey }
            );
        });
    }
}