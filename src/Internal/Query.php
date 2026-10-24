<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

use CruWorldWine\Exception\CruException;

/**
 * @internal
 */
final class Query
{
    /**
     * The query string: null values dropped, booleans as true or false, lists comma-joined.
     *
     * @param array<array-key, mixed> $params
     */
    public static function encode(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    if ($item !== null) {
                        $items[] = self::scalar((string) $name, $item);
                    }
                }
                $value = implode(',', $items);
            } else {
                $value = self::scalar((string) $name, $value);
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    public static function scalar(string $name, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new CruException(sprintf('%s: a %s cannot be sent in a URL', $name, get_debug_type($value)));
    }
}
