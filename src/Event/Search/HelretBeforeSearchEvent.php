<?php

declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\SearchResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelretBeforeSearchEvent extends AbstractSearchEvent
{
    public function __construct(
        protected Request $request,
        protected SalesChannelContext $context,
        protected Criteria $criteria,
        protected array $postData,
        protected bool $isFilterRequest = false,
        protected ?SearchResponse $originalResponse = null,
        protected bool $forceReturnFilters = false
    ) {
        parent::__construct(
            request: $this->request,
            criteria: $this->criteria,
            context: $this->context
        );
    }

    public function getPostData(): array
    {
        return $this->postData;
    }

    public function setPostData(array $postData): void
    {
        $this->postData = $postData;
    }

    public function changePostData(array $postData): void
    {
        $this->postData = array_replace($this->postData ?? [], $postData);
    }

    public function isFilterRequest(): bool
    {
        return $this->isFilterRequest;
    }

    public function getOriginalResponse(): ?SearchResponse
    {
        return $this->originalResponse;
    }

    public function shouldForceReturnFilters(): bool
    {
        return $this->forceReturnFilters;
    }

    public function setForceReturnFilters(bool $forceReturnFilters): void
    {
        $this->isFilterRequest = $forceReturnFilters;
    }
}
