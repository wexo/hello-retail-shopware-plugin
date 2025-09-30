<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service;

use Helret\HelloRetail\Models\ProductModel;
use Helret\HelloRetail\Service\Models\RecommendationContext;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class HelloRetailRecommendationService
{
    private const EXTRA_DATA = "extraData";
    private const TRACKING_CODE = "trackingCode";
    private const ENDPOINT = "recoms";

    /**
     * @param SalesChannelRepository<SalesChannelProductCollection> $salesChannelRepository
     */
    public function __construct(
        protected readonly HelloRetailClientService $client,
        protected readonly SalesChannelRepository $salesChannelRepository
    ) {
    }

    public function getRecommendationsSearch(
        string $key,
        string $searchKey,
        Entity $entity,
        SalesChannelContext $salesChannelContext
    ): ?CriteriaCollection {
        $collection = new CriteriaCollection();
        $hierarchies = [];
        $urls = [];
        $category = null;
        if ($entity::class == CategoryEntity::class) {
            $category = $entity;
        } elseif ($entity::class == SalesChannelProductEntity::class) {
            $category = $entity->getSeoCategory();
        }

        if ($category && $category->getBreadcrumb()) {
            $hierarchies = $category->getBreadcrumb();
        }

        /** @var SalesChannelDomainEntity $domain */
        foreach ($salesChannelContext->getSalesChannel()->getDomains() as $domain) {
            $urls[] = $domain->getUrl();
        }

        $productData = $this->fetchRecommendations(
            $key,
            $salesChannelContext->getSalesChannelId(),
            [$hierarchies],
            $urls
        );

        $ids = $this->getIds($productData);
        $productModel = new ProductModel(['results' =>$productData]);
        if (!$ids) {
            return $collection;
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociation('cover');
        $criteria->addAssociation('options.group');
        $criteria->addAssociation('manufacturer');
        $criteria->addExtension('ids', new ArrayEntity([$ids]));
        $criteria->addExtension('hrData', $productModel);
        $collection->add($searchKey, ProductDefinition::class, $criteria);

        return $collection;
    }

    public function getRecommendations(string $key, SalesChannelContext $context): ?EntityCollection
    {
        $productData = $this->fetchRecommendations($key, $context->getSalesChannelId());
        return $this->getProducts($productData, $context);
    }

    private function fetchRecommendations(
        string $key,
        string $salesChannelId,
        array $hierarchies = [],
               $urls = []
    ): array {
        if ($key) {
            $productData = [];
            $context = new RecommendationContext($hierarchies, "", $urls);
            $request = new Models\Recommendation($key, [self::EXTRA_DATA, self::TRACKING_CODE], $context);
            $callback = $this->client->callApi(
                endpoint: self::ENDPOINT,
                request: $request,
                type: 'recommendations',
                salesChannelId: $salesChannelId
            );

            foreach ($callback['responses'] ?? [] as $response) {
                if (!$response['success']) {
                    continue;
                }
                $productData = array_merge($productData, $response['products']);
            }

            return $productData;
        }

        return [];
    }

    private function getProducts(array $productData, SalesChannelContext $context): ?ProductCollection
    {
        $ids = $this->getIds($productData);

        if (!$ids) {
            return null;
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('seoUrls');
        return $this->salesChannelRepository->search($criteria, $context)->getEntities();
    }

    private function getIds(array $productData): array
    {
        $ids = [];
        foreach ($productData as $data) {
            $id = $data[self::EXTRA_DATA]['id'] ?? $data[self::EXTRA_DATA]['productId'] ?? null;

            if ($id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
