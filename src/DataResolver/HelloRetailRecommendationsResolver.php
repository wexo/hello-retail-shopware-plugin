<?php declare(strict_types=1);

namespace Helret\HelloRetail\DataResolver;

use Helret\HelloRetail\Service\HelloRetailRecommendationService;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\Struct\ProductSliderStruct;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class HelloRetailRecommendationsResolver extends AbstractCmsElementResolver
{
    private const STATIC_SEARCH_KEY = 'hello-retail-recommendations';

    public function __construct(
        private readonly HelloRetailRecommendationService $recommendationService,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getType(): string
    {
        return 'hello-retail-recommendations';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $config = $slot->getFieldConfig();
        $entity = null;

        if (method_exists($resolverContext, 'getEntity')) {
            $entity = $resolverContext->getEntity();
        }

        $key = $config->get('key');
        if ($key === null) {
            return null;
        }

        $salesChannelContext = $resolverContext->getSalesChannelContext();

        $collection = $this->recommendationService->getRecommendationsSearch(
            $key->getValue(),
            self::STATIC_SEARCH_KEY . '_' . $slot->getUniqueIdentifier(),
            $entity,
            $salesChannelContext
        );

        return $collection->all() ? $collection : null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $slider = new ProductSliderStruct();
        $slot->setData($slider);

        $this->enrichFromSearch(
            $slider,
            $result,
            self::STATIC_SEARCH_KEY . '_' . $slot->getUniqueIdentifier(),
            $resolverContext->getSalesChannelContext()
        );
    }

    private function enrichFromSearch(
        ProductSliderStruct $slider,
        ElementDataCollection $result,
        string $searchKey,
        SalesChannelContext $salesChannelContext
    ): void {
        $searchResult = $result->get($searchKey);
        if ($searchResult === null) {
            return;
        }

        /** @var ProductCollection|null $products */
        $products = $searchResult->getEntities();
        if ($products->first() === null) {
            return;
        }

        $hideOutOfStock = $this->systemConfigService->get(
            'core.listing.hideCloseoutProductsWhenOutOfStock',
            $salesChannelContext->getSalesChannelId()
        );
        if ($hideOutOfStock) {
            $products = $this->filterOutOutOfStockHiddenCloseoutProducts($products);
        }

        if (!$products->count()) {
            return;
        }

        $idsOrder = $searchResult->getCriteria()->getExtension('ids')[0];
        $products->sortByIdArray($idsOrder);

        $slider->setProducts($products);
    }

    private function filterOutOutOfStockHiddenCloseoutProducts(ProductCollection $products): ProductCollection
    {
        return $products->filter(function (ProductEntity $product) {
            if ($product->getIsCloseout() && $product->getAvailableStock() <= 0) {
                return false;
            }

            return true;
        });
    }
}
