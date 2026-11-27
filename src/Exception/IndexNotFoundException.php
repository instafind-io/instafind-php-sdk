<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

/**
 * Instafind has no such index for this caller: a deleted index, one being
 * created or deleted, and one the credential may not see all get this answer:
 * 404 with the error code `index_not_found`.
 */
class IndexNotFoundException extends ApiException
{
    /**
     * The 404 of a route where a plain `not_found` means the index.
     *
     * @internal
     */
    public static function from(ApiException $e, string $indexId): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self("Instafind has no index $indexId for this caller: " . $e->getMessage(), $e->status(), 'index_not_found', $e->retryAfter(), $e->body(), $e);
    }
}
