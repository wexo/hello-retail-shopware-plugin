<?php declare(strict_types=1);

namespace Helret\HelloRetail\Subscriber;

use Helret\HelloRetail\Service\HelloRetailPageService;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected HelloRetailPageService $pageService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingResultEvent::class => 'onProductListingResult'
        ];
    }

    public function onProductListingResult(ProductListingResultEvent $event): void
    {
        $request = $event->getRequest();
        $pageKey = $request->get('helloRetailPageKey');

        if (!$pageKey) {
            return;
        }

        $hierarchies = $request->get('helloRetailHierarchies');

        $pageProductsResult = $this->pageService->getPage($pageKey, $hierarchies, $event->getSalesChannelContext());

        if ($pageProductsResult && $pageProductsResult['products']['total'] > 0) {
            $pageKey = [$pageKey];

            $event->getResult()->addExtension('helloRetailPageData', new ArrayEntity($pageProductsResult));
            $event->getResult()->addExtension('helloRetailHierarchies', new ArrayEntity($hierarchies));
            $event->getResult()->addExtension('pageKey', new ArrayEntity($pageKey));
        }
    }
}
