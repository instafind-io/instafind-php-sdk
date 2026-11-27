<?php

declare(strict_types=1);

namespace Instafind\Sdk\Indexing;

use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Integration\RefreshMode;
use Instafind\Sdk\IntegrationApi;

/**
 * Streams products into an index through the bulk endpoint, so no
 * integration has to relearn its rules:
 *
 * - Batches hold at most 500 products.
 * - Only the last batch waits until its products are searchable
 *   (`refresh=wait_for`): waiting per batch costs seconds each. Knowing which
 *   batch is last without a total means reading one product ahead.
 * - Rejected products come back inside an HTTP 200; {@see BulkResult} counts
 *   them with batches refused outright.
 * - Two products with the same id in one batch fail the whole batch.
 *
 * It takes any iterable, so a generator over a paged query streams through
 * without the catalogue ever being in memory. Get one from
 * {@see IntegrationApi::bulkLoader()}.
 */
final class BulkLoader
{
    public const MAX_BATCH_SIZE = IntegrationApi::MAX_BATCH;

    private readonly int $batchSize;

    /**
     * @internal use {@see IntegrationApi::bulkLoader()}
     */
    public function __construct(
        private readonly IntegrationApi $api,
        int $batchSize = self::MAX_BATCH_SIZE,
        private readonly bool $waitForFinalBatch = true,
    ) {
        $this->batchSize = max(1, min($batchSize, self::MAX_BATCH_SIZE));
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    /** The same loader, without waiting for the last batch to be searchable. */
    public function withoutWaitingForRefresh(): self
    {
        return new self($this->api, $this->batchSize, false);
    }

    /**
     * @param iterable<array<string, mixed>> $products
     *
     * @throws InstafindException the first refused batch, unless the listener carries on
     */
    public function load(string $indexId, iterable $products, ?BulkListener $listener = null): BulkResult
    {
        return $this->run($products, function (array $batch, bool $isFinal, BulkResult $result) use ($indexId, $listener): BulkResult {
            $refresh = $isFinal && $this->waitForFinalBatch ? RefreshMode::WaitFor : null;
            try {
                return $result->withWrite($this->api->upsertProducts($indexId, $batch, $refresh), count($batch));
            } catch (InstafindException $e) {
                if ($listener === null || !$listener->batchFailed(count($batch), $e)) {
                    throw $e;
                }

                return $result->withFailedBatch(count($batch), $e);
            }
        }, $listener);
    }

    /**
     * Walk and batch the products without sending anything: the dry run.
     *
     * @param iterable<array<string, mixed>> $products
     */
    public function preview(iterable $products, ?BulkListener $listener = null): BulkResult
    {
        return $this->run($products, static fn(array $batch, bool $isFinal, BulkResult $result): BulkResult => $result->withSkippedBatch(count($batch)), $listener);
    }

    /**
     * @param iterable<array<string, mixed>>                                          $products
     * @param callable(list<array<string, mixed>>, bool, BulkResult): BulkResult $flush
     */
    private function run(iterable $products, callable $flush, ?BulkListener $listener): BulkResult
    {
        $result = BulkResult::empty();
        $batch = [];
        foreach ($products as $product) {
            if ($product === []) {
                continue;
            }
            if (count($batch) === $this->batchSize) {
                $result = $flush($batch, false, $result);
                $listener?->batchLoaded(count($batch), $result);
                $batch = [];
            }
            $batch[] = $product;
        }
        if ($batch !== []) {
            $result = $flush($batch, true, $result);
            $listener?->batchLoaded(count($batch), $result);
        }

        return $result;
    }
}
