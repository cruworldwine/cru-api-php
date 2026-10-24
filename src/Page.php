<?php

declare(strict_types=1);

namespace CruWorldWine;

use CruWorldWine\Internal\Coerce;

/**
 * One page of a list.
 *
 * Iterate it, or read $items, for this page's items; autoPagingIterator() walks every item of every
 * page from here on, fetching each next page as it gets there. $data is the whole data object, for
 * whatever else the list answers with (a wine search's currency, say).
 *
 * @template T of Model
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /** @var list<T> */
    public readonly array $items;

    /** meta.pagination.limit. */
    public readonly ?int $limit;

    /** meta.pagination.offset: how many items came before this page. */
    public readonly int $offset;

    /** meta.pagination.total; null when the list does not count its items. */
    public readonly ?int $total;

    /** meta.pagination.has_more: whether a next page has items. */
    public readonly bool $hasMore;

    /** @var array<array-key, mixed> */
    public readonly array $data;

    /** @var array<array-key, mixed> */
    public readonly array $meta;

    /** @var \Closure(int): Page<T> */
    private \Closure $fetch;

    private int $received;

    /**
     * @param list<T> $items
     * @param int $received how many items the API sent, counting any that were not objects
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $meta
     * @param \Closure(int): Page<T> $fetch fetches the page at an offset
     *
     * @internal
     */
    public function __construct(array $items, int $received, array $data, array $meta, \Closure $fetch)
    {
        $this->items = $items;
        $this->received = $received;
        $this->data = $data;
        $this->meta = $meta;
        $this->fetch = $fetch;

        $pagination = Coerce::map($meta['pagination'] ?? null) ?? [];
        $this->limit = Coerce::int($pagination['limit'] ?? null);
        $this->offset = Coerce::int($pagination['offset'] ?? null) ?? 0;
        $this->total = Coerce::int($pagination['total'] ?? null);
        $this->hasMore = Coerce::bool($pagination['has_more'] ?? null)
            ?? ($received > 0 && $received === $this->limit);
    }

    /**
     * The next page, or null when this is the last.
     *
     * @return Page<T>|null
     */
    public function nextPage(): ?Page
    {
        if (!$this->hasMore || $this->received === 0) {
            return null;
        }

        return ($this->fetch)($this->offset + $this->received);
    }

    /**
     * Every item of this page and of each page after it.
     *
     * @return \Generator<int, T>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->items as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
