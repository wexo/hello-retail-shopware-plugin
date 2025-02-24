<?php declare(strict_types=1);

namespace Helret\HelloRetail\Content\Product\SalesChannel\Listing\Processor;

use Helret\HelloRetail\Models\CriteriaModel;
use Shopware\Core\Content\Product\SalesChannel\Listing\Processor\AbstractListingProcessor;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelloRetailListingProcessor extends AbstractListingProcessor
{
    public function getDecorated(): AbstractListingProcessor
    {
        throw new DecorationPatternException(self::class);
    }

    public function prepare(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$request->get('only-aggregations')) {
            $criteria->removeExtension(CriteriaModel::NAME);
            return;
        }

        $model = new CriteriaModel($criteria->getLimit(), $criteria->getOffset());
        $criteria->addExtension(CriteriaModel::NAME, $model);
    }
}
