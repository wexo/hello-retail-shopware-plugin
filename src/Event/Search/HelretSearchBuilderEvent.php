<?php declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\SearchResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelretSearchBuilderEvent extends AbstractSearchEvent
{
    public function __construct(
        protected SearchResponse $response,
        protected Request $request,
        protected Criteria $criteria,
        protected SalesChannelContext $context
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
}
