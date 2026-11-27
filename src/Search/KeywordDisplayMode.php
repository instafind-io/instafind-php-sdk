<?php

declare(strict_types=1);

namespace Instafind\Sdk\Search;

/**
 * How the widget shows popular keywords; it decides how many come back.
 */
enum KeywordDisplayMode: string
{
    case Modal = 'modal';
    case DropdownList = 'dropdown-list';
    case DropdownMega = 'dropdown-mega';
}
