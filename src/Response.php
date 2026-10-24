<?php

declare(strict_types=1);

namespace CruWorldWine;

/**
 * The answer to a raw call made with $client->request().
 */
final class Response
{
    /** The envelope's data, or null when there is none. */
    public readonly mixed $data;

    /** @var array<array-key, mixed> the envelope's meta */
    public readonly array $meta;

    /**
     * @param int $statusCode the HTTP status
     * @param array<string, string> $headers by lower-case name, repeated values comma-joined
     * @param string $rawBody the body as received
     * @param mixed $body the decoded JSON body, or null when it is empty or not JSON
     *
     * @internal
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly mixed $body,
    ) {
        $envelope = is_array($body) ? $body : [];
        $this->data = $envelope['data'] ?? null;
        $this->meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
    }

    /**
     * A header's value, by name in any case.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
