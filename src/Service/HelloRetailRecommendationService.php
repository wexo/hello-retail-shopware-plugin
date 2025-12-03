<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service;

use Helret\HelloRetail\Models\ProductModel;
use Helret\HelloRetail\Service\Models\Recommendation;
use Helret\HelloRetail\Service\Models\RecommendationContext;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Symfony\Component\HttpFoundation\RequestStack;

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
        protected readonly SalesChannelRepository $salesChannelRepository,
        protected readonly entityRepository $productRepository,
        protected readonly CartService $cartService,
        protected RequestStack $requestStack
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
        if ($entity::class == CategoryEntity::class || $entity::class == SalesChannelCategoryEntity::class) {
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
            $salesChannelContext,
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
        $productData = $this->fetchRecommendations($key, $context);
        return $this->getProducts($productData, $context);
    }

    protected function fetchRecommendations(
        string $key,
        SalesChannelContext $salesChannelContext,
        array $hierarchies = [],
               $urls = []
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $currentRequest = $this->requestStack->getCurrentRequest();
        $route = $currentRequest->attributes->get('_route');
        $requestUri = $currentRequest->attributes->get('sw-original-request-uri');

        if (!empty($hierarchies[0])) {
            $brand = $hierarchies[0][count($hierarchies[0]) - 1];
        } else {
            $brand = "";
        }

        $fullUrls = [];
        foreach ($urls as $url) {
            $fullUrls[] = $url . $requestUri;
        }

        if ($key) {
            $productData = [];
            $context = new RecommendationContext();
            switch ($route) {
                case 'frontend.home.page': // Home page
                    break;
                case 'frontend.navigation.page': // Category page
                    $context->setHierarchies($hierarchies);
                    $context->setBrand($brand);
                    break;
                case 'frontend.detail.page': // Product page
                    $context->setUrls($fullUrls);
                    break;
                case 'hello-retail.cart.recommendations': // Cart offcanvas
                case 'frontend.cart.offcanvas': // Cart offcanvas
                case 'frontend.checkout.cart.page': // Cart page
                foreach ($salesChannelContext->getSalesChannel()->getDomains() as $domain) {
                    $urls[] = $domain->getUrl();
                }
                $cartUrls = $this->getCartUrls($salesChannelContext, $urls);
                if($cartUrls){
                    $context->setUrls($cartUrls);
                }

                $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
                $cartPrice = $cart->getPrice()->getPositionPrice();
                $context->setPrice($cartPrice);

                    break;
                default:
                    break;
            }

            $request = new Recommendation($key, [self::EXTRA_DATA, self::TRACKING_CODE], $context);
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

    protected function getProducts(array $productData, SalesChannelContext $context): ?ProductCollection
    {
        $ids = $this->getIds($productData);

        if (!$ids) {
            return null;
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociation('cover');
        $criteria->addAssociation('media');
        $criteria->addAssociation('seoUrls');
        $products = $this->salesChannelRepository->search($criteria, $context)->getEntities();

        $hrData = new ProductModel(['results' => $productData]);
        $hrStructs = $hrData->getStructs();

        foreach ($products as $product) {
            $productId = $product->getId();
            if (isset($hrStructs[$productId])) {
                    $product->addExtension('hello-retail', $hrStructs[$productId]);
                }
        }

        return $products;
    }

    protected function getIds(array $productData): array
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

    protected function getCartUrls(SalesChannelContext $salesChannelContext, array $urls) : array
    {
        $cartUrls = [];
        $productIds = [];
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        $lineItems = $cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE);
        foreach ($lineItems as $lineItem) {
            $productIds[] = $lineItem->getReferencedId();
        }

        $productSeo =$this->getProductSEO($productIds, $salesChannelContext->getContext());

        foreach ($urls as $baseUrl) {
            foreach ($productSeo as $productId => $seoUrl) {
                $cartUrls[] = $baseUrl . '/' . $seoUrl;
            }
        }
        return $cartUrls;
    }

    protected function getProductSEO(array $productIds, $context): array
    {
        $productSeo = [];

        if (empty($productIds)) {
            return $productSeo;
        }
        $criteria = new Criteria($productIds);
        $criteria->addAssociation('seoUrls');

        /** @var ProductCollection|null $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        foreach ($products as $product) {
            $productSeo[$product->getId()] = $product->getSeoUrls()->first()->getSeoPathInfo();
        }

        return $productSeo;
    }
}
