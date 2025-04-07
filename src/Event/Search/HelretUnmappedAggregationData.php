<?php declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\CriteriaCollection;
use Helret\HelloRetail\Models\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResult;

class HelretUnmappedAggregationData
{
    public function __construct(
        protected Filter $filter,
        protected CriteriaCollection $collection,
        protected ?AggregationResult $result = null
    ) {
    }

    public function getFilter(): Filter
    {
        return $this->filter;
    }

    public function getResult(): ?AggregationResult
    {
        return $this->result;
    }

    public function setResult(?AggregationResult $result): void
    {
        $this->result = $result;
    }

    public function getCollection(): CriteriaCollection
    {
        return $this->collection;
    }
}
