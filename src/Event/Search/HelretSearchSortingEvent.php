<?php declare(strict_types=1);

namespace Helret\HelloRetail\Event\Search;

use Helret\HelloRetail\Models\SearchResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class HelretSearchSortingEvent extends AbstractSearchEvent
{
    protected array $fieldMapping;

    public function __construct(
        protected Request $request,
        protected Criteria $criteria,
        protected SalesChannelContext $context,
        protected array $postData,
        protected bool $isFilterRequest = false,
        protected bool $useMappedSorting = false,
        protected ?SearchResponse $originalResponse = null
    ) {
        parent::__construct(
            request: $this->request,
            criteria: $this->criteria,
            context: $this->context
        );

        $this->fieldMapping = [
            'product.name' => 'title',
            'product.cheapestPrice' => 'price',
            'id' => 'extraData.id',
            'product.releaseDate' => 'extraData.createdDate',
        ];
    }

    public function getSortings(): array
    {
        if ($this->useMappedSorting) {
            return $this->getSortings();
        }

        return $this->formatHelretOrder();
    }

    public function formatHelretOrder(): array
    {
        $sortings = [];

        if ($this->request->get('order')) {
            $order = explode('+', $this->request->get('order'));

            $sorting = strtolower($order[1] ?? '');
            if ($sorting &&
                in_array(strtoupper($sorting), [FieldSorting::ASCENDING, FieldSorting::DESCENDING], true)
            ) {
                $key = $order[0];
                $sortings[] = "$key $sorting";
            }
        }

        return $sortings;
    }

    protected function getMappedSorting(): array
    {
        $sortings = [];
        if (!$this->criteria->getSorting()) {
            return $sortings;
        }

        foreach ($this->criteria->getSorting() as $sorting) {
            $key = $this->fieldMapping[$sorting->getField()] ?? $sorting->getField();
            $sort = strtolower($sorting->getDirection());
            $sortings[] = "$key $sort";
        }

        return $sortings;
    }

    public function getFieldMapping(): array
    {
        return $this->fieldMapping;
    }

    public function setFieldMapping(array $fieldMapping): void
    {
        $this->fieldMapping = $fieldMapping;
    }

    public function changeFieldMapping(array $fieldMapping): void
    {
        $this->fieldMapping = array_replace($this->fieldMapping ?? [], $fieldMapping);
    }

    public function addFieldMapping(string $shopwareField, string $helretField): void
    {
        $this->fieldMapping[$shopwareField] = $helretField;
    }


    public function useMappedSorting(): bool
    {
        return $this->useMappedSorting;
    }

    public function setUseMappedSorting(bool $useMappedSorting): void
    {
        $this->useMappedSorting = $useMappedSorting;
    }

    public function getPostData(): array
    {
        return $this->postData;
    }

    public function isFilterRequest(): bool
    {
        return $this->isFilterRequest;
    }

    public function getOriginalResponse(): ?SearchResponse
    {
        return $this->originalResponse;
    }
}
