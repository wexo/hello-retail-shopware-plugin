<?php declare(strict_types=1);

namespace Helret\HelloRetail\Content\Product\SalesChannel\Search;

use Helret\HelloRetail\Event\Search\HelretAggregationLoadedEvent;
use Helret\HelloRetail\Models\SearchResponse;
use Helret\HelloRetail\Service\HelloRetailSearchService;
use Helret\HelloRetail\Subscriber\SearchSubscriber;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Profiling\Profiler;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DecoratedProductSearchRoute extends AbstractProductSearchRoute
{
    /**
     * @param SalesChannelRepository<SalesChannelProductCollection> $productRepository
     * @param SalesChannelRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        protected AbstractProductSearchRoute $decorated,
        protected HelloRetailSearchService $searchService,
        protected SalesChannelRepository $productRepository,
        protected SalesChannelRepository $categoryRepository,
        protected Container $container,
        protected EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function getDecorated(): AbstractProductSearchRoute
    {
        return $this->decorated;
    }

    public function load(
        Request $request,
        SalesChannelContext $context,
        Criteria $criteria
    ): ProductSearchRouteResponse {
        if (!$context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            return $this->decorated->load($request, $context, $criteria);
        }

        $onlyAggregations = $request->request->get('only-aggregations');
        if ($onlyAggregations) {
            $response = $this->decorated->load(
                request: $request,
                context: $context,
                criteria: $criteria
            );
            $this->handleAggregations($response, $context, $criteria, $request);

            return $response;
        }

        $lazyAggregations = !$request->request->get('no-aggregations') &&
            !$request->request->get('reduce-aggregations');

        if (!$lazyAggregations) {
            $context->addState('hello-retail-force-return-filters');
            return $this->decorated->load(
                request: $request,
                context: $context,
                criteria: $criteria
            );
        }

        $request->request->set('hello-retail-type', 'search');
        $response = $this->decorated->load(
            request: $request,
            context: $context,
            criteria: $criteria
        );

        Profiler::trace(
            name: 'load-aggregation',
            closure: fn() => $this->handleAggregations(
                $response,
                $context,
                $criteria,
                $request
            ),
            category: 'hello-retail'
        );

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


    protected function handleAggregations(
        ProductSearchRouteResponse $response,
        SalesChannelContext $context,
        Criteria $criteria,
        Request $request
    ): void {
        if (!$context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            return;
        }

        /** @var SearchResponse|null $originalResponse */
        $originalResponse = $criteria->getExtensionOfType(SearchResponse::NAME, SearchResponse::class);

        $origin = clone $criteria;
        $origin->setLimit(0);

        $searchResponse = $originalResponse->getProducts()->filters?->getCollection() ?
            $originalResponse :
            $this->searchService->searchByRequest(
                request: $request,
                criteria: $origin,
                context: $context,
                isFilterRequest: true,
                originalResponse: $originalResponse
            );

        $event = $this->eventDispatcher->dispatch(
            new HelretAggregationLoadedEvent(
                response: $searchResponse,
                originalResponse: $originalResponse,
                aggregations: $searchResponse->getProducts()?->filters?->parseCollection(
                    $this->container,
                    $context->getContext()
                )->getCollection(),
                request: $request,
                criteria: $origin,
                context: $context
            )
        );

        $aggregations = $event->getAggregations();
        if (!$aggregations && $event->isFallbackAllowed()) {
            // Fallback.
            $origin->setLimit($searchResponse->getProducts()?->getTotalCount() ?: $criteria->getLimit());

            $searchResponse = $this->searchService->searchByRequest(
                request: $request,
                criteria: $origin,
                context: $context,
                isFilterRequest: true
            );
            if ($searchResponse->getProducts()) {
                $aggregations = $this->eventDispatcher->dispatch(
                    new HelretAggregationLoadedEvent(
                        response: $searchResponse,
                        originalResponse: $originalResponse,
                        aggregations: $this->productRepository->aggregate(
                            criteria: $origin,
                            salesChannelContext: $context
                        ),
                        request: $request,
                        criteria: $origin,
                        context: $context
                    )
                )->getAggregations();
            }
        }

        if ($aggregations) {
            $response->getListingResult()->assign([
                'aggregations' => $aggregations,
            ]);
        }
    }
}
