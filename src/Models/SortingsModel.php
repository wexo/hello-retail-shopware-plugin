<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class SortingsModel
{
    protected ProductSortingCollection $collection;

    public function __construct(protected array $sortings)
    {
        $this->collection = $this->createCollection();
    }

    public function get(): iterable
    {
        yield from $this->sortings;
    }

    public function getCollection(): ProductSortingCollection
    {
        return $this->collection;
    }

    protected function createCollection(): ProductSortingCollection
    {
        $sortings = new ProductSortingCollection();
        foreach ($this->sortings as $sorting) {
            foreach ([FieldSorting::ASCENDING, FieldSorting::DESCENDING] as $order) {
                $sortingEntity = new ProductSortingEntity();
                $sortingEntity->setActive(true);
                $sortingEntity->setPriority(1);
                $sortingEntity->setKey($sorting['name'] . '+' . $order);
                $sortingEntity->setFields([
                    [
                        'field' => $sorting['name'],
                        'order' => $order,
                        'priority' => 1,
                        'naturalSorting' => 0,
                    ]
                ]);

                $labelKey = $order === 'ASC' ? 'ascendingText' : 'descendingText';
                $label = $sorting['settings'][$labelKey];

                $sortingEntity->setLabel($label);
                $sortingEntity->setTranslated(['label' => $label]);
                $sortingEntity->setId($sortingEntity->getKey());

                $sortings->add($sortingEntity);
            }
        }

        return $sortings;
    }
}
