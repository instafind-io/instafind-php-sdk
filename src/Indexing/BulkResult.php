<?php

declare(strict_types=1);

namespace Instafind\Sdk\Indexing;

use Instafind\Sdk\Exception\InstafindException;
use Instafind\Sdk\Integration\BulkWriteResult;
use Instafind\Sdk\Integration\ProductWriteResult;

/**
 * The tally of a bulk load. `failed` counts products, not requests: a batch
 * refused outright counts every product in it, so the number means the same
 * whether the refusal came as an error status or inside a 200.
 */
final class BulkResult
{
    /**
     * @param list<ProductWriteResult>  $errors
     * @param list<InstafindException> $failures
     */
    private function __construct(
        private readonly int $successful = 0,
        private readonly int $filtered = 0,
        private readonly int $pending = 0,
        private readonly int $failed = 0,
        private readonly int $sent = 0,
        private readonly int $batches = 0,
        private readonly array $errors = [],
        private readonly array $failures = [],
    ) {}

    /** @internal */
    public static function empty(): self
    {
        return new self();
    }

    /** @internal */
    public function withWrite(BulkWriteResult $write, int $sent): self
    {
        return new self(
            $this->successful + $write->successful(),
            $this->filtered + $write->filtered(),
            $this->pending + $write->pending(),
            $this->failed + $write->failed(),
            $this->sent + $sent,
            $this->batches + 1,
            [...$this->errors, ...$write->errors()],
            $this->failures,
        );
    }

    /** @internal */
    public function withFailedBatch(int $sent, InstafindException $failure): self
    {
        return new self($this->successful, $this->filtered, $this->pending, $this->failed + $sent, $this->sent + $sent, $this->batches + 1, $this->errors, [...$this->failures, $failure]);
    }

    /** @internal a dry-run batch: counted as successful, never sent */
    public function withSkippedBatch(int $sent): self
    {
        return new self($this->successful + $sent, $this->filtered, $this->pending, $this->failed, $this->sent + $sent, $this->batches + 1, $this->errors, $this->failures);
    }

    public function successful(): int
    {
        return $this->successful;
    }

    public function filtered(): int
    {
        return $this->filtered;
    }

    public function pending(): int
    {
        return $this->pending;
    }

    /** Products rejected, alone or with their whole batch. */
    public function failed(): int
    {
        return $this->failed;
    }

    /** Products handed to the loader (empty documents left out). */
    public function sent(): int
    {
        return $this->sent;
    }

    public function batches(): int
    {
        return $this->batches;
    }

    /**
     * Products rejected one by one inside a 200.
     *
     * @return list<ProductWriteResult>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Batches refused outright, when a listener chose to carry on.
     *
     * @return list<InstafindException>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function isClean(): bool
    {
        return $this->failed === 0 && $this->failures === [];
    }
}
