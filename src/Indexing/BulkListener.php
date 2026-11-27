<?php

declare(strict_types=1);

namespace Instafind\Sdk\Indexing;

use Instafind\Sdk\Exception\InstafindException;

/**
 * Watches a {@see BulkLoader} run: for progress output, and to decide what a
 * refused batch means. Without one, the loader throws the first refusal
 * (what an incremental write in a queue wants); a long catalogue load
 * reports the batch and carries on.
 */
interface BulkListener
{
    /**
     * @param int        $documents the batch's size
     * @param BulkResult $running   the tally so far
     */
    public function batchLoaded(int $documents, BulkResult $running): void;

    /**
     * @return bool true to carry on with the next batch, false to throw `$failure`
     */
    public function batchFailed(int $documents, InstafindException $failure): bool;
}
