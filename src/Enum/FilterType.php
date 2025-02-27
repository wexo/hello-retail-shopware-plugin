<?php declare(strict_types=1);

namespace Helret\HelloRetail\Enum;

enum FilterType: string
{
    case RANGE = 'range';
    case LIST = 'list';
}
