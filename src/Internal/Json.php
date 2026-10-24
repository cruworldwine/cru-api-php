<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

use CruWorldWine\Exception\CruException;

/**
 * @internal
 */
final class Json
{
    /**
     * The decoded body, or null when it is empty or not JSON. Big integers stay strings, so no
     * digit is lost to a float.
     */
    public static function decode(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * The decoded body when it is a JSON object, else [].
     *
     * @return array<array-key, mixed>
     */
    public static function decodeObject(string $body): array
    {
        $decoded = self::decode($body);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A request body. An empty array is sent as {}, the empty object the API expects.
     *
     * @param array<array-key, mixed> $body
     */
    public static function encodeBody(array $body): string
    {
        if ($body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new CruException('The request body could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
