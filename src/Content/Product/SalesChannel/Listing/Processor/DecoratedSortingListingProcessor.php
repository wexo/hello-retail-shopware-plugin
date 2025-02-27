<?php declare(strict_types=1);

namespace Helret\HelloRetail\Content\Product\SalesChannel\Listing\Processor;

use Helret\HelloRetail\Models\SearchResponse;
use Helret\HelloRetail\Subscriber\SearchSubscriber;
use Shopware\Core\Content\Product\SalesChannel\Listing\Processor\AbstractListingProcessor;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class DecoratedSortingListingProcessor extends AbstractListingProcessor
{
    public function __construct(protected AbstractListingProcessor $decorated)
    {
    }

    public function getDecorated(): AbstractListingProcessor
    {
        return $this->decorated;
    }

    public function prepare(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            $this->decorated->prepare($request, $criteria, $context);
        }
    }

    public function process(Request $request, ProductListingResult $result, SalesChannelContext $context): void
    {
        if (!$context->hasState(SearchSubscriber::SEARCH_AWARE)) {
            $this->decorated->process($request, $result, $context);
            return;
        }

        /** @var SearchResponse|null $searchResult */
        $searchResult = $result->getCriteria()->getExtensionOfType(
            SearchResponse::NAME,
            SearchResponse::class
        );
        if (!$searchResult) {
            return;
        }

        $result->setAvailableSortings(
            $searchResult->getProducts()?->sortings?->getCollection() ?: new ProductSortingCollection()
        );
    }
}
