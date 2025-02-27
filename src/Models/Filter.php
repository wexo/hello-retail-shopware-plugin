<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Helret\HelloRetail\Enum\FilterType;
use Helret\HelloRetail\Event\Search\HelretUnmappedAggregationData;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\StatsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;

class Filter
{
    public function __construct(
        protected FilterType $type,
        protected string $name,
        protected mixed $value,
        protected ?string $title = null
    ) {
    }

    public function getType(): FilterType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAsAggregationResult(
        CriteriaCollection $collection,
        ?EventDispatcherInterface $dispatcher = null
    ): ?AggregationResult {
        if (str_starts_with($this->name, 'extraDataList.propertyGroup_')) {
            $groupId = substr($this->name, strlen('extraDataList.propertyGroup_'));
            if (!Uuid::isValid($groupId)) {
                return null;
            }

            /** @var Criteria|null $criteria */
            $criteria = $collection->get(PropertyGroupOptionDefinition::ENTITY_NAME);
            if ($criteria) {
                $struct = $criteria->getExtensionOfType('hello-retail-data', ArrayStruct::class);

                $criteria->setIds(
                    array_merge(
                        $criteria->getIds(),
                        $this->getValueSet()
                    )
                );

                foreach ($this->value as $item) {
                    $id = str_replace("$this->name:", '', $item['query']);
                    if (!$struct->has($id)) {
                        $struct->set($id, $item['count'] ?? 0);
                    }
                }
            } else {
                $criteria = (new Criteria($this->getValueSet()))
                    ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE)
                    ->addFields(['id']);

                $buckets = [
                    'aggregation' => TermsResult::class,
                    'aggregationName' => 'properties',
                ];
                foreach ($this->value as $item) {
                    $id = str_replace("$this->name:", '', $item['query']);
                    $buckets[$id] = $item['count'] ?? 0;
                }
                $criteria->addArrayExtension('hello-retail-data', $buckets);

                $collection->set(PropertyGroupOptionDefinition::ENTITY_NAME, $criteria);
            }

            return null;
        } elseif ($this->name === 'extraData.manufacturerId') {
            /** @var Criteria|null $criteria */
            $criteria = $collection->get(ProductManufacturerDefinition::ENTITY_NAME);
            if ($criteria) {
                $criteria->setIds(
                    array_merge(
                        $criteria->getIds(),
                        $this->getValueSet()
                    )
                );
            } else {
                $criteria = (new Criteria($this->getValueSet()))
                    ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
                $criteria->addArrayExtension('hello-retail-data', [
                    'aggregation' => EntityResult::class,
                    'aggregationName' => 'manufacturer',
                ]);

                $collection->set(
                    ProductManufacturerDefinition::ENTITY_NAME,
                    $criteria
                );
            }

            return null;
        } elseif ($this->name === 'price') {
            return new StatsResult(
                name: 'price',
                min: $this->value[RangeFilter::GTE],
                max: $this->value[RangeFilter::LTE],
                avg: null,
                sum: null
            );
        }

        return $dispatcher?->dispatch(
            new HelretUnmappedAggregationData(
                filter: $this,
                collection: $collection,
            )
        )->getResult();
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getValueSet(): mixed
    {
        if ($this->type !== FilterType::LIST || !is_array($this->value)) {
            return $this->getValue();
        }

        return array_map(
            fn(string $query) => str_replace("$this->name:", '', $query),
            array_column($this->value, 'query')
        );
    }
}
