<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Helret\HelloRetail\Enum\FilterType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FilteringModel
{
    protected AggregationResultCollection $collection;

    public function __construct(protected array $filters)
    {
        $this->collection = new AggregationResultCollection();
    }

    public function get(): iterable
    {
        yield from $this->filters;
    }

    public function parseCollection(ContainerInterface $container, Context $context): static
    {
        $collection = new CriteriaCollection();
        $eventDispatcher = $container->get('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        foreach ($this->getFormattedFilters() as $filter) {
            $aggregation = $filter->getAsAggregationResult($collection, $eventDispatcher);
            if ($aggregation) {
                $this->collection->add($aggregation);
            }
        }

        $definitionRegistry = $container->get(DefinitionInstanceRegistry::class);
        foreach ($collection as $entityName => $criteria) {
            /** @var ArrayStruct|null $struct */
            $struct = $criteria->getExtensionOfType('hello-retail-data', ArrayStruct::class);
            if (!$struct) {
                continue;
            }

            $repository = $definitionRegistry->getRepository($entityName);
            if ($struct->get('aggregation') === TermsResult::class) {
                $ids = $repository->searchIds($criteria, $context)->getIds();

                $buckets = [];
                foreach ($ids as $id) {
                    if ($struct->has($id)) {
                        $buckets[] = new Bucket(
                            key: $id,
                            count: $struct->get($id),
                            result: null
                        );
                    }
                }

                $this->collection->add(
                    new TermsResult(
                        name: $struct->get('aggregationName'),
                        buckets: $buckets
                    )
                );
            } elseif ($struct->get('aggregation') === EntityResult::class) {
                $this->collection->add(
                    new EntityResult(
                        name: $struct->get('aggregationName'),
                        entities: $repository->search($criteria, $context)->getEntities()
                    )
                );
            }
        }

        return $this;
    }

    public function getCollection(): AggregationResultCollection
    {
        return $this->collection;
    }

    /**
     * @return Filter[]
     */
    public function getFormattedFilters(): array
    {
        $filters = [];
        foreach ($this->filters as $filter) {
            $type = FilterType::tryFrom(strtolower($filter['settings']['type'] ?? ''));
            if (!$type) {
                continue;
            }

            $value = match ($type) {
                FilterType::RANGE => [
                    RangeFilter::GTE => $filter['min'],
                    RangeFilter::LTE => $filter['max'],
                ],
                FilterType::LIST => $filter['values'],
                default => null,
            };

            $filters[] = new Filter(
                $type,
                $filter['name'],
                $value,
                $filter['settings']['title'] ?? null
            );
        }

        return $filters;
    }
}
