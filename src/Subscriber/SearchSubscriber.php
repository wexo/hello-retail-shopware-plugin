<?php declare(strict_types=1);

namespace Helret\HelloRetail\Subscriber;

use Shopware\Core\Content\Product\Events\ProductSearchRouteCacheKeyEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestRouteCacheKeyEvent;
use Shopware\Core\Framework\Adapter\Cache\StoreApiRouteCacheKeyEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SearchSubscriber implements EventSubscriberInterface
{
    public const SEARCH_AWARE = 'helret/search';

    public function __construct(
        protected SystemConfigService $configService
    ) {
    }


    public static function getSubscribedEvents(): array
    {
        // Add Pre- & Post-event to allow overrides.
        return [
            ProductSearchRouteCacheKeyEvent::class => [
                ['onPreProductSearchRouteCacheKey', 9999],
                ['onPostCacheKeyEvent', -9999],
            ],
            ProductSuggestRouteCacheKeyEvent::class => [
                ['onPreProductSuggestRouteCacheKeyEvent', 9999],
                ['onPostCacheKeyEvent', -9999],
            ]
        ];
    }

    public function onPreProductSearchRouteCacheKey(ProductSearchRouteCacheKeyEvent $event): void
    {
        if ($this->isHelloRetailRoute($event->getSalesChannelId(), 'searchPage')) {
            $event->getContext()->addState(self::SEARCH_AWARE);
        }
    }

    public function onPreProductSuggestRouteCacheKeyEvent(ProductSuggestRouteCacheKeyEvent $event): void
    {
        if ($this->isHelloRetailRoute($event->getSalesChannelId(), 'suggest')) {
            $event->getContext()->addState(self::SEARCH_AWARE);
        }
    }

    public function onPostCacheKeyEvent(StoreApiRouteCacheKeyEvent $event): void
    {
        if ($event->getContext()->hasState(self::SEARCH_AWARE)) {
            $event->disableCaching();
        }
    }

    protected function isHelloRetailRoute(string $salesChannelId, string $key): bool
    {
        if (!$this->configService->getBool('HelretHelloRetail.config.searchConfigKey', $salesChannelId)) {
            return false;
        }

        return $this->configService->getBool("HelretHelloRetail.config.$key", $salesChannelId);
    }
}
