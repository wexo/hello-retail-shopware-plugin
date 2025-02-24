<?php declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\SearchResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelretAggregationLoadedEvent extends AbstractSearchEvent
{
    public function __construct(
        protected SearchResponse $response,
        protected ?SearchResponse $originalResponse,
        protected ?AggregationResultCollection $aggregations,
        protected Request $request,
        protected Criteria $criteria,
        protected SalesChannelContext $context,
        protected bool $fallbackAllowed = true
    ) {
        parent::__construct(
            request: $this->request,
            criteria: $this->criteria,
            context: $this->context
        );
    }

    public function getResponse(): SearchResponse
    {
        return $this->response;
    }

    public function getOriginalResponse(): SearchResponse
    {
        return $this->originalResponse;
    }

    public function getAggregations(): ?AggregationResultCollection
    {
        return $this->aggregations;
    }

    public function setAggregations(?AggregationResultCollection $aggregations): void
    {
        $this->aggregations = $aggregations;
    }

    public function isFallbackAllowed(): bool
    {
        return $this->fallbackAllowed;
    }

    public function setFallbackAllowed(bool $fallbackAllowed): void
    {
        $this->fallbackAllowed = $fallbackAllowed;
    }
}
