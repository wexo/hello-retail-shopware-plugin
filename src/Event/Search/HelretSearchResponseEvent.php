<?php declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\SearchResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelretSearchResponseEvent extends AbstractSearchEvent
{
    public function __construct(
        protected SearchResponse $response,
        protected Request $request,
        protected SalesChannelContext $context,
        protected Criteria $criteria,
        protected array $postData,
        protected bool $isFilterRequest = false,
        protected ?SearchResponse $originalResponse = null
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

    public function getPostData(): array
    {
        return $this->postData;
    }

    public function isFilterRequest(): bool
    {
        return $this->isFilterRequest;
    }

    public function getOriginalResponse(): ?SearchResponse
    {
        return $this->originalResponse;
    }
}
