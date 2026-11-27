<?php

declare(strict_types=1);

namespace Instafind\Sdk\Integration;

/**
 * When written products become searchable. Without one, the call returns as
 * soon as the write is durable and search catches up within seconds.
 */
enum RefreshMode: string
{
    /** Wait until the products are searchable: for the last batch of a load. */
    case WaitFor = 'wait_for';
    /** Force a refresh now. Costly; keep it rare. */
    case Immediate = 'immediate';
}
