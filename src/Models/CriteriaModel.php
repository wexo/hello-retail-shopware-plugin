<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Shopware\Core\Framework\Struct\Struct;

class CriteriaModel extends Struct
{
    public const NAME = 'hello-retail-criteria-model';

    public function __construct(protected int $limit, protected ?int $offset)
    {
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
