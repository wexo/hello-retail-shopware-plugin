<?php declare(strict_types=1);

namespace Helret\HelloRetail\Service\Models\Requests;

abstract class AbstractRequest
{
    abstract public function toArray(): array;
}
