<?php

declare(strict_types=1);

namespace CruWorldWine;

use CruWorldWine\Models\StagedConfirmation;

/**
 * What a write did.
 *
 * A partner credential's write runs at once: $staged is false and $result holds what it did. A
 * connected app's write is staged: $staged is true, nothing has happened yet, and $confirmation
 * holds the code to confirm once the customer agrees ($client->confirmations->confirm($code)).
 * $replayed is true when this is the stored answer to an earlier request with the same reference.
 *
 * @template T of Model
 */
final class Write
{
    public readonly bool $staged;

    /**
     * @param class-string<T> $resultClass the model $result is, or would have been had it run
     * @param T|null $result what the write did; null when it was staged
     * @param StagedConfirmation|null $confirmation what to confirm; null unless it was staged
     * @param bool $replayed whether the Idempotent-Replayed header said true
     * @param string|null $requestId the X-Request-Id, what Cru support needs to find the request
     * @param int $statusCode 200 or 201 when it ran, 202 when it was staged
     *
     * @internal
     */
    public function __construct(
        public readonly string $resultClass,
        public readonly ?Model $result,
        public readonly ?StagedConfirmation $confirmation,
        public readonly bool $replayed,
        public readonly ?string $requestId,
        public readonly int $statusCode,
    ) {
        $this->staged = $confirmation !== null;
    }
}
