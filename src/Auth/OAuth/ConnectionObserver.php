<?php

declare(strict_types=1);

namespace Instafind\Sdk\Auth\OAuth;

use Instafind\Sdk\Exception\InstafindException;

/**
 * Told when {@see OAuthConnection} could not renew its access token, for the
 * host's log and admin notice.
 */
interface ConnectionObserver
{
    /**
     * @param bool $connectionLost true when Instafind ended the connection for good
     *                             (connect again), false for a temporary failure
     */
    public function renewalFailed(InstafindException $failure, bool $connectionLost): void;
}
