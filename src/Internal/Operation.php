<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

/**
 * An endpoint, as the generated resources describe it.
 *
 * @internal
 */
final class Operation
{
    /**
     * @param string $method the HTTP method
     * @param string $path the path under the base URL, with {name} placeholders
     * @param list<string> $query a write's query parameters; every other parameter of a write goes in its body
     * @param bool $body whether the call sends a JSON body even when no parameter is given
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly bool $body = false,
    ) {
    }

    public function isRead(): bool
    {
        return $this->method === 'GET' || $this->method === 'HEAD';
    }
}
