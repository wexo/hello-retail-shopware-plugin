<?php declare(strict_types=1);

namespace Helret\HelloRetail\Content\Product\SalesChannel\Suggest;

use Helret\HelloRetail\Models\SearchResponse;
use Helret\HelloRetail\Service\HelloRetailSearchService;
use Helret\HelloRetail\Subscriber\SearchSubscriber;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Product\SalesChannel\Suggest\AbstractProductSuggestRoute;
use Shopware\Core\Content\Product\SalesChannel\Suggest\ProductSuggestRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class DecoratedProductSuggestRoute extends AbstractProductSuggestRoute
{
    /**
     * @param SalesChannelRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        protected AbstractProductSuggestRoute $decorated,
        protected HelloRetailSearchService $searchService,
        protected SystemConfigService $configService,
        protected SalesChannelRepository $categoryRepository
    ) {
    }

    public function getDecorated(): AbstractProductSuggestRoute
    {
        return $this->decorated;
    }

    public function load(
        Request $request,
        SalesChannelContext $context,
        Criteria $criteria
    ): ProductSuggestRouteResponse {
        if ($context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            $limit = $this->configService->getInt(
                'HelretHelloRetail.config.suggestProductLimit',
                $context->getSalesChannelId()
            );
            if ($limit > 0) {
                $criteria->setLimit($limit);
            }

            $request->request->set('hello-retail-type', 'suggest');
            $response = $this->searchService->searchByRequest(
                request: $request,
                criteria: $criteria,
                context: $context
            );
            if ($criteria->hasExtensionOfType($response::NAME, $response::class)) {
                $criteria->resetFilters();
                $criteria->resetQueries();
            } else {
                $context->removeState(SearchSubscriber::SEARCH_AWARE);
            }
        }

        $response = $this->decorated->load($request, $context, $criteria);

        /** @var SearchResponse|null $searchResponse */
        $searchResponse = $criteria->getExtensionOfType(SearchResponse::NAME, SearchResponse::class);
        $categoryIds = $searchResponse?->getCategories()?->getIds();
        if ($categoryIds) {
            $response->getListingResult()->addExtension(
                'hello-retail-content',
                $this->categoryRepository->search(
                    (new Criteria($categoryIds)),
                    $context
                )->getEntities()
            );
        }

        return $response;
    }
}
