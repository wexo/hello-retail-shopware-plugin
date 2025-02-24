<?php declare(strict_types=1);

namespace Helret\HelloRetail\Models;

use Shopware\Core\Framework\Struct\Struct;

class SearchResponse extends Struct
{
    public const NAME = 'hello-retail-shop-response';

    protected ?ProductModel $products = null;
    protected ?CategoryModel $categories = null;

    public function __construct(protected array $result)
    {
        if (isset($this->result['products']) && is_array($this->result['products'])) {
            $this->products = new ProductModel($this->result['products']);
        }
        if (isset($this->result['categories']) && is_array($this->result['categories'])) {
            $this->categories = new CategoryModel($this->result['categories']);
        }
    }

    public function getProducts(): ?ProductModel
    {
        return $this->products;
    }

    public function getCategories(): ?CategoryModel
    {
        return $this->categories;
    }

    public function getResult(): array
    {
        return $this->result;
    }
}
