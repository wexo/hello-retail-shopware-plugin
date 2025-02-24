<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service\Models;

class PageFilters
{
    public array $hierarchies = [];

    public function __construct(array $hierarchies)
    {
        $this->hierarchies = $hierarchies;
    }

    public function getHierarchies(): array
    {
        return $this->hierarchies;
    }

    public function setHierarchies(array $hierarchies): void
    {
        $this->hierarchies = $hierarchies;
    }
}
