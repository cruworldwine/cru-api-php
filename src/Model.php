<?php

declare(strict_types=1);

namespace CruWorldWine;

/**
 * An object the API answered with.
 *
 * Its typed properties are the fields this version of the SDK knows. A field that is missing, or
 * whose value is not of the documented type, is null there. Every field the API sent, including
 * ones added after this SDK was released, is kept exactly as received: read it with
 * $model['name'], get('name') or toArray().
 *
 * Models are read-only.
 *
 * @phpstan-consistent-constructor
 * @implements \ArrayAccess<array-key, mixed>
 */
abstract class Model implements \ArrayAccess, \JsonSerializable
{
    /** @var array<array-key, mixed> */
    private array $raw;

    /**
     * @param array<array-key, mixed> $raw the object as the API sent it
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    /**
     * @param array<array-key, mixed> $raw the object as the API sent it
     */
    public static function fromArray(array $raw): static
    {
        return new static($raw);
    }

    /**
     * A field as the API sent it, or $default when it did not send it.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->raw) ? $this->raw[$key] : $default;
    }

    /**
     * Whether the API sent this field, even as null.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->raw);
    }

    /**
     * Every field as the API sent it, including fields this SDK does not know.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /**
     * isset($model['name']): true when the field was sent and is not null, as for an array.
     */
    public function offsetExists(mixed $offset): bool
    {
        return (is_string($offset) || is_int($offset)) && isset($this->raw[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset) && !is_int($offset)) {
            return null;
        }

        return $this->raw[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(static::class . ' is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(static::class . ' is read-only.');
    }
}
