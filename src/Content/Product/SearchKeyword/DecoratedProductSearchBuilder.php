<?php

declare(strict_types=1);

namespace Helret\HelloRetail\Content\Product\SearchKeyword;

use Helret\HelloRetail\Event\Search\HelretSearchBuilderEvent;
use Helret\HelloRetail\Service\HelloRetailSearchService;
use Helret\HelloRetail\Subscriber\SearchSubscriber;
use Shopware\Core\Content\Product\SearchKeyword\ProductSearchBuilderInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class DecoratedProductSearchBuilder implements ProductSearchBuilderInterface
{
    public function __construct(
        protected readonly ProductSearchBuilderInterface $decorated,
        protected readonly HelloRetailSearchService $searchService,
        protected readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * @see ProductSearchBuilder::build()
     */
    public function build(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if ($context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            $response = $this->searchService->searchByRequest(
                request: $request,
                criteria: $criteria,
                context: $context
            );

            if ($criteria->hasExtensionOfType($response::NAME, $response::class)) {
                $this->eventDispatcher->dispatch(
                    new HelretSearchBuilderEvent(
                        response: $response,
                        request: $request,
                        criteria: $criteria,
                        context: $context
                    )
                );

                return;
            }

            $context->removeState(SearchSubscriber::SEARCH_AWARE);
        }

        $this->decorated->build($request, $criteria, $context);
    }
}
