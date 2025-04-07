<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service\Models\Requests;

use Helret\HelloRetail\Service\Models\PageFilters;

class PageRequest extends Request
{
    public function __construct(
        public ?PageFilters $params,
        public string $url,
        public array $products = ['start' => 0, 'count' => 100],
        public bool $firstLoad = true,
        public bool $layout = false,
        public ?string $websiteUuid = null,
        public ?string $trackingUserId = null
    ) {
        parent::__construct($websiteUuid, $trackingUserId);
    }

    public function getParams(): PageFilters
    {
        return $this->params;
    }

    public function setParams(PageFilters $params): void
    {
        $this->params = $params;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function isFirstLoad(): bool
    {
        return $this->firstLoad;
    }

    public function setFirstLoad(bool $firstLoad): void
    {
        $this->firstLoad = $firstLoad;
    }

    public function isLayout(): bool
    {
        return $this->layout;
    }

    public function setLayout(bool $layout): void
    {
        $this->layout = $layout;
    }
}
