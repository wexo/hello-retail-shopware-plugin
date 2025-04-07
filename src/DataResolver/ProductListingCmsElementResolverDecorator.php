<?php declare(strict_types=1);

namespace Helret\HelloRetail\DataResolver;

use Helret\HelloRetail\Service\HelloRetailPageService;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;

class ProductListingCmsElementResolverDecorator extends AbstractCmsElementResolver
{
    public function __construct(
        protected readonly AbstractCmsElementResolver $decorated,
        private readonly HelloRetailPageService $pageService
    ) {
    }

    public function getType(): string
    {
        return $this->decorated->getType();
    }


    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return $this->decorated->collect($slot, $resolverContext);
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $config = $slot->getFieldConfig();
        $hierarchies = [];

        $key = $config->get('helloRetailKey');
        if ($key) {
            if (method_exists($resolverContext, 'getEntity')) {
                $entity = $resolverContext->getEntity();
                $hierarchies = $this->pageService->renderHierarchies($entity);
            }

            $resolverContext->getRequest()->request->set('helloRetailPageKey', $key->getValue());
            $resolverContext->getRequest()->request->set('helloRetailHierarchies', $hierarchies);
        }

        $this->decorated->enrich($slot, $resolverContext, $result);
    }
}
