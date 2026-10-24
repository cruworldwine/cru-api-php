<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

use CruWorldWine\Model;

/**
 * Reads a decoded JSON value into a typed property without ever throwing.
 *
 * A value of the documented type is kept. A value that converts without loss is converted (an int
 * to a float, an integral float to an int, a plain decimal string to a number, an int to a
 * string). Anything else gives null; the model's raw array still holds what was sent.
 *
 * @internal
 */
final class Coerce
{
    // 2^53: beyond it a float no longer holds every integer exactly.
    private const MAX_EXACT = 9007199254740992;

    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (is_finite($value) && floor($value) === $value && $value >= -self::MAX_EXACT && $value <= self::MAX_EXACT) {
                return (int) $value;
            }

            return null;
        }
        // At most 18 digits, so it always fits a 64-bit int; no leading zeros, so it is not a code.
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]{0,17})$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public static function float(mixed $value): ?float
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_int($value)) {
            return $value >= -self::MAX_EXACT && $value <= self::MAX_EXACT ? (float) $value : null;
        }
        // At most 15 significant digits, which a float always holds exactly as written.
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $value) === 1) {
            $digits = strlen($value) - (str_starts_with($value, '-') ? 1 : 0) - (str_contains($value, '.') ? 1 : 0);

            return $digits <= 15 ? (float) $value : null;
        }

        return null;
    }

    public static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * A JSON object. An empty array counts, since PHP decodes {} and [] alike.
     *
     * @return array<array-key, mixed>|null
     */
    public static function map(mixed $value): ?array
    {
        return self::isObject($value) ? $value : null;
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function model(mixed $value, string $class): ?Model
    {
        return self::isObject($value) ? $class::fromArray($value) : null;
    }

    /**
     * A list of models; null when it is not a list or any item is not an object.
     *
     * @template T of Model
     *
     * @param class-string<T> $class
     *
     * @return list<T>|null
     */
    public static function modelList(mixed $value, string $class): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (!self::isObject($item)) {
                return null;
            }
            $out[] = $class::fromArray($item);
        }

        return $out;
    }

    /**
     * A list whose items each go through $item; null when it is not a list or any item gives null.
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return list<T>|null
     */
    public static function listOf(mixed $value, callable $item): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $raw) {
            $converted = $item($raw);
            if ($converted === null) {
                return null;
            }
            $out[] = $converted;
        }

        return $out;
    }

    /**
     * A list of anything, kept as sent.
     *
     * @return list<mixed>|null
     */
    public static function rawList(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
