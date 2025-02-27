<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service\Models\Requests;

use Helret\HelloRetail\Service\Models\RecommendationContext;

class RecommendationRequest extends AbstractRequest
{
    public function __construct(protected array $requests = [])
    {
    }

    public function getRequests(): array
    {
        return $this->requests;
    }

    public function setRequests(array $requests): void
    {
        $this->requests = $requests;
    }

    public function addManagedRecommendation(
        string $key,
        ?RecommendationContext $recommendationContext,
        array $fields = [],
        $hideAdditionalVariants = true
    ): static {
        $request = [
            'key' => $key,
            'hideAdditionalVariants' => $hideAdditionalVariants,
        ];

        if ($fields) {
            $request['fields'] = $fields;
        }

        if ($recommendationContext) {
            $request['context'] = [
                'hierachies' => $recommendationContext->getHierarchies(),
                'brand' => $recommendationContext->getBrand(),
                'urls' => $recommendationContext->getUrls(),
            ];
        }

        $this->requests[] = $request;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
        ];
    }
}
