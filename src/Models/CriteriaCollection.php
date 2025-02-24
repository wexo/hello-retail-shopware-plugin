<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Collection;

class CriteriaCollection extends Collection
{
    protected function getExpectedClass(): ?string
    {
        return Criteria::class;
    }
}
