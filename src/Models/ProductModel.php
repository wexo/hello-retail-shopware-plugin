<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Generator;

class ProductModel
{
    public ?FilteringModel $filters = null;
    public ?SortingsModel $sortings = null;

    public function __construct(protected array $data)
    {
        if (isset($this->data['filters']) && is_array($this->data['filters'])) {
            $this->filters = new FilteringModel($this->data['filters']);
        }
        if (isset($this->data['sorting']) && is_array($this->data['sorting'])) {
            $this->sortings = new SortingsModel($this->data['sorting']);
        }
    }

    public function getStart(): int
    {
        return $this->data['start'] ?? 0;
    }

    public function getTotalCount(): int
    {
        return $this->data['totalCount'] ?? 0;
    }

    public function getIds(): array
    {
        $ids = [];
        foreach ($this->getResults() as $result) {
            $ids[] = $result['extraData']['id'];
        }

        return $ids;
    }

    public function getResults(): iterable
    {
        if (!isset($this->data['results'])) {
            return;
        }

        yield from $this->data['results'];
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return Generator<string, Filter>
     */
    public function getFilters(): Generator
    {
        if (!$this->filters) {
            return;
        }

        foreach ($this->filters->iterator as $filter) {
            yield $filter->getName() => $filter;
        }
    }

    public function hasData(): bool
    {
        if ($this->getIds()) {
            return true;
        }

        // Current?
        if ($this->filters?->iterator->valid()) {
            return true;
        }

        return false;
    }
}
