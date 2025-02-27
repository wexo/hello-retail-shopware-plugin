<?php

declare(strict_types=1);

namespace Helret\HelloRetail\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Helret\HelloRetail\Event\Search\HelretBeforeSearchEvent;
use Helret\HelloRetail\Event\Search\HelretSearchResponseEvent;
use Helret\HelloRetail\Event\Search\HelretSearchSortingEvent;
use Helret\HelloRetail\Models\CriteriaModel;
use Helret\HelloRetail\Models\SearchResponse;
use Helret\HelloRetail\Subscriber\SearchSubscriber;
use RuntimeException;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Saleschannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class HelloRetailSearchService
{
    public function __construct(
        public readonly HelloRetailClientService $client,
        protected readonly SystemConfigService $configService,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly Connection $connection
    ) {
    }

    public function searchByRequest(
        Request $request,
        Criteria $criteria,
        SalesChannelContext $context,
        bool $isFilterRequest = false,
        ?SearchResponse $originalResponse = null
    ): SearchResponse {
        if (!$context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            throw new RuntimeException('Context has to have a search state');
        }

        $response = $this->search(
            request: $request,
            context: $context,
            criteria: $criteria,
            isFilterRequest: $isFilterRequest,
            originalResponse: $originalResponse
        );

        if ($response->getProducts()) {
            $criteria->addExtension($response::NAME, $response);

            // Ensure that we actually find products as we might paginate
            $criteria->setOffset(0);

            $productIds = $response->getProducts()->getIds();
            if ($productIds) {
                // Set ids from HelloRetail and reset sorting to allow "IdSorting"
                $criteria->setIds($productIds);
                $criteria->setTerm(null);
                $criteria->resetSorting();

                /**
                 * If filters aren't group by AndFilter, we need to reset PostFilters -
                 *  to ensure correct content between Shopware & HelloRetail when filtering by properties etc.
                 *
                 * If filters aren't reset the search page can give empty result while the response contains X products
                 */
                if (!$request->request->get('only-aggregations')) {
                    $criteria->resetPostFilters();
                }
            }

            // Bail on caching.
            if ($request->attributes->get(PlatformRequest::ATTRIBUTE_HTTP_CACHE)) {
                $request->attributes->set(PlatformRequest::ATTRIBUTE_HTTP_CACHE, false);
            }
        }

        return $response;
    }

    protected function search(
        Request $request,
        SalesChannelContext $context,
        Criteria $criteria,
        bool $isFilterRequest = false,
        ?SearchResponse $originalResponse = null
    ): SearchResponse {
        $query = trim((string)$request->get('search'));
        if (!$query) {
            throw RoutingException::missingRequestParameter('search');
        }

        $limit = $criteria->getLimit();
        $offset = $criteria->getOffset();

        /** @var CriteriaModel|null $criteriaModel */
        $criteriaModel = $criteria->getExtensionOfType(CriteriaModel::NAME, CriteriaModel::class);
        if ($criteriaModel) {
            $limit = $criteriaModel->getLimit();
            $offset = $criteriaModel->getOffset() ?: 0;
        }

        $postData = $this->getDefaultPostData($query, $context);
        $postData['products']['start'] = $offset ?: 0;
        $postData['products']['count'] = $limit;

        // Setup aggregated data.
        if ($isFilterRequest) {
            $postData['products']['start'] = 0;
            $postData['products']['count'] = 0;
            $postData['products']['returnFilters'] = true; // Allows use of filtering via HR (Partial support)
            // Limit response data when searching for filter ids.
            $postData['products']['fields'] = ['extraData.id'];

            if ($originalResponse && !$originalResponse->getProducts()->filters?->getFormattedFilters()) {
                $postData['products']['returnFilters'] = false;
                $postData['products']['count'] = $originalResponse->getProducts()?->getTotalCount() ?: 5000;
            }
        }


        // Add filters
        if (!$isFilterRequest || $request->request->get('only-aggregations')) {
            $postData['products']['filters'] = [];
            foreach ($this->mapFilters($criteria->getPostFilters()) as $field => $map) {
                if ($field === 'product.manufacturerId') {
                    $postData['products']['filters'] = array_merge(
                        $postData['products']['filters'],
                        array_map(
                            fn(string $id) => "extraData.manufacturerId:$id",
                            $map
                        )
                    );
                } elseif (in_array($field, ['product.propertyIds', 'product.optionIds'], true)) {
                    /** @var array<int, array{groupId: string, optionIds: string}> $propertyFilters */
                    try {
                        $propertyFilters = $this->connection->createQueryBuilder()
                            ->from(PropertyGroupOptionDefinition::ENTITY_NAME)
                            ->select(
                                'LOWER(HEX(property_group_id)) groupId',
                                'GROUP_CONCAT(LOWER(HEX(id))) as optionIds'
                            )
                            ->where('id IN(:ids)')
                            ->groupBy('property_group_id')
                            ->setParameter('ids', Uuid::fromHexToBytesList($map), ArrayParameterType::STRING)
                            ->fetchAllAssociative();
                    } catch (Exception) {
                        $propertyFilters = [];
                    }

                    foreach ($propertyFilters as $propertyFilter) {
                        $groupId = $propertyFilter['groupId'];
                        $optionIds = explode(',', $propertyFilter['optionIds']);

                        $postData['products']['filters'] = array_merge(
                            $postData['products']['filters'],
                            array_map(
                                fn(string $id) => "extraDataList.propertyGroup_$groupId:$id",
                                $optionIds
                            )
                        );
                    }
                } elseif ($field === 'product.cheapestPrice') {
                    $postData['products']['filters'][] = "price:$map";
                }
            }

            $postData['products']['filters'] = array_unique($postData['products']['filters']);
        }

        $sorting = $this->eventDispatcher->dispatch(
            new HelretSearchSortingEvent(
                request: $request,
                criteria: $criteria,
                context: $context,
                postData: $postData,
                isFilterRequest: $isFilterRequest,
                originalResponse: $originalResponse
            )
        )->getSortings();
        if ($sorting) {
            $postData['products']['sorting'] = $sorting;
        }

        $type = $request->request->get('hello-retail-type', 'search');

        $contentLimit = $this->configService->getInt(
            "HelretHelloRetail.config.{$type}CategoryLimit",
            $context->getSalesChannelId()
        );

        if ($contentLimit > 0) {
            $postData['categories'] = [
                'start' => 0,
                'count' => $contentLimit,
                'fields' => ['extraData.id'],
            ];
        }


        // Allow request post data tampering
        $event = $this->eventDispatcher->dispatch(
            new HelretBeforeSearchEvent(
                request: $request,
                context: $context,
                criteria: $criteria,
                postData: $postData,
                isFilterRequest: $isFilterRequest,
                originalResponse: $originalResponse,
                forceReturnFilters: ($isFilterRequest &&
                    ($postData['products']['returnFilters'] ?? false) !== true &&
                    $originalResponse &&
                    !$originalResponse->getProducts()->sortings) ||
                $context->hasState('hello-retail-force-return-filters')
            )
        );

        $postData = $event->getPostData();
        if ($event->shouldForceReturnFilters()) {
            $postData['products']['returnFilters'] = true;
        }

        $isCloseoutAware = $this->configService->getBool(
            'core.listing.hideCloseoutProductsWhenOutOfStock',
            $context->getSalesChannelId()
        );
        if ($isCloseoutAware) {
            // To ensure correct filtering/product count add this as post event, to ensure it's not removed by accident
            $postData['products']['filters'][] = 'extraData.isCloseoutAvailable:1';
        }

        $response = $this->client->callApi(
            endpoint: 'search',
            request: $postData,
            type: $type,
            salesChannelId: $context->getSalesChannelId()
        );

        $searchResponse = new SearchResponse($response);
        $this->eventDispatcher->dispatch(
            new HelretSearchResponseEvent(
                response: $searchResponse,
                request: $request,
                context: $context,
                criteria: $criteria,
                postData: $postData,
                isFilterRequest: $isFilterRequest,
                originalResponse: $originalResponse
            )
        );

        // Post tampering, set sorting
        if ($isFilterRequest && $originalResponse) {
            if ($searchResponse->getProducts()?->sortings && !$originalResponse->getProducts()->sortings) {
                $originalResponse->getProducts()->sortings = $searchResponse->getProducts()->sortings;
            }

            if ($originalResponse->getProducts()?->sortings && !$searchResponse->getProducts()?->sortings) {
                $searchResponse->getProducts()->sortings = $originalResponse->getProducts()->sortings;
            }

            if ($searchResponse->getProducts()?->filters && !$originalResponse->getProducts()->filters) {
                $originalResponse->getProducts()->filters = $searchResponse->getProducts()->filters;
            }

            if ($originalResponse->getProducts()?->filters && !$searchResponse->getProducts()?->filters) {
                $searchResponse->getProducts()->filters = $originalResponse->getProducts()->filters;
            }
        }

        return $searchResponse;
    }

    protected function getDefaultPostData(
        string $query,
        SalesChannelContext $context
    ): array {
        return [
            'query' => $query,
            'key' => $this->configService->getString(
                'HelretHelloRetail.config.searchConfigKey',
                $context->getSalesChannelId()
            ),
            'products' => [
                'returnFilters' => false,
                'start' => 0,
                'fields' => [
                    'extraData.id',
                ],
            ],
            'format' => 'json',
        ];
    }

    /**
     * @param array<Filter> $filters
     * @return array<string, array|string|mixed>
     */
    protected function mapFilters(array $filters, &$map = []): array
    {
        foreach ($filters as $filter) {
            if ($filter instanceof EqualsAnyFilter) {
                $map[$filter->getField()] = array_merge(
                    isset($map[$filter->getField()]) && is_array($map[$filter->getField()]) ?
                        $map[$filter->getField()] :
                        [],
                    $filter->getValue()
                );
            } elseif ($filter instanceof EqualsFilter) {
                $map[$filter->getField()] = $filter->getValue();
            } elseif ($filter instanceof RangeFilter) {
                $params = array_merge([
                    'gte' => null,
                    'lte' => null,
                ], $filter->getParameters());

                $map[$filter->getField()] = implode(',', $params);
            } elseif ($filter instanceof MultiFilter) {
                $this->mapFilters($filter->getQueries(), $map);
            }
        }

        return $map;
    }
}
