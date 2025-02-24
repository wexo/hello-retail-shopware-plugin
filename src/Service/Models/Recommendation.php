<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service\Models;

use Helret\HelloRetail\Service\Models\Requests\AbstractRequest;

class Recommendation extends AbstractRequest
{
    public array $fields = [];

    public function __construct(
        public string $key,
        array|string $fields,
        public ?RecommendationContext $context = null,
        public string $format = "json"
    ) {
        $this->fields = is_array($fields) ? $fields : [$fields];
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function setFields(array $fields): void
    {
        $this->fields = $fields;
    }

    public function getContext(): RecommendationContext
    {
        return $this->context;
    }

    public function setContext(RecommendationContext $context): void
    {
        $this->context = $context;
    }

    public function toArray(): array
    {
        return [];
    }
}
