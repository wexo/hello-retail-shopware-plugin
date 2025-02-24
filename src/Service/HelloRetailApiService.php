<?php

declare(strict_types=1);

namespace Helret\HelloRetail\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class HelloRetailApiService
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        protected HelloRetailClientService $client,
        protected EntityRepository $productRepository
    ) {
    }

    public function renderHierarchies(Entity $entity): array
    {
        $category = null;
        if ($entity::class == CategoryEntity::class) {
            $category = $entity;
        } else if ($entity::class == SalesChannelProductEntity::class) {
            $category = $entity->getSeoCategory();
        }

        if (!$category || !$category->getBreadcrumb()) {
            return [];
        }

        return $category->getBreadcrumb();
    }

    protected function renderUrls(SalesChannelContext $salesChannelContext = null): array
    {
        $urls = [];
        if ($salesChannelContext) {
            /** @var SalesChannelDomainEntity $domain */
            foreach ($salesChannelContext->getSalesChannel()->getDomains() as $domain) {
                $urls[] = $domain->getUrl();
            }
        }
        return $urls;
    }
}
