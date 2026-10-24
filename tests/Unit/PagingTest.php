<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Models\OwnBid;
use CruWorldWine\Models\Wine;
use CruWorldWine\Page;

final class PagingTest extends TestCase
{
    /**
     * @param list<string> $codes
     * @param array<string, mixed> $pagination
     */
    private static function bidsPage(array $codes, array $pagination): \GuzzleHttp\Psr7\Response
    {
        $bids = array_map(static fn (string $code): array => self::bid(['code' => $code]), $codes);

        return self::ok(['bids' => $bids], 200, [], ['pagination' => $pagination]);
    }

    public function testAPageHasItsItemsAndPagination(): void
    {
        $client = $this->partner([self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 0, 'total' => 5, 'has_more' => true])]);
        $page = $client->bids->list(['limit' => 2, 'status' => ['live', 'matched']]);

        self::assertInstanceOf(Page::class, $page);
        self::assertCount(2, $page);
        self::assertContainsOnlyInstancesOf(OwnBid::class, $page->items);
        self::assertSame(2, $page->limit);
        self::assertSame(0, $page->offset);
        self::assertSame(5, $page->total);
        self::assertTrue($page->hasMore);
        self::assertSame(['B1', 'B2'], array_map(static fn (OwnBid $b): ?string => $b->code, iterator_to_array($page)));
        self::assertSame(['limit' => '2', 'status' => 'live,matched'], $this->query());
    }

    public function testNextPageAsksFromTheOffsetPlusTheItemsReceived(): void
    {
        $client = $this->partner([
            self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 4, 'has_more' => true]),
            self::bidsPage(['B3'], ['limit' => 2, 'offset' => 6, 'has_more' => false]),
        ]);
        $page = $client->bids->list(['limit' => 2, 'offset' => 4, 'status' => 'live']);
        $next = $page->nextPage();

        self::assertNotNull($next);
        self::assertSame(['limit' => '2', 'offset' => '6', 'status' => 'live'], $this->query(1));
        self::assertSame('B3', $next->items[0]->code);
        self::assertFalse($next->hasMore);
        self::assertNull($next->nextPage());
        self::assertCount(2, $this->history);
    }

    public function testAutoPagingWalksEveryPage(): void
    {
        $client = $this->partner([
            self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 0, 'has_more' => true]),
            self::bidsPage(['B3', 'B4'], ['limit' => 2, 'offset' => 2, 'has_more' => true]),
            self::bidsPage(['B5'], ['limit' => 2, 'offset' => 4, 'has_more' => false]),
        ]);
        $page = $client->bids->list(['limit' => 2]);

        $codes = [];
        foreach ($page->autoPagingIterator() as $i => $bid) {
            $codes[$i] = $bid->code;
        }

        self::assertSame(['B1', 'B2', 'B3', 'B4', 'B5'], $codes);
        self::assertCount(3, $this->history);
        self::assertSame(['limit' => '2'], $this->query(0));
        self::assertSame(['limit' => '2', 'offset' => '2'], $this->query(1));
        self::assertSame(['limit' => '2', 'offset' => '4'], $this->query(2));
    }

    public function testAutoPagingKeysDoNotRepeatAcrossPages(): void
    {
        $client = $this->partner([
            self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 0, 'has_more' => true]),
            self::bidsPage(['B3'], ['limit' => 2, 'offset' => 2, 'has_more' => false]),
        ]);

        self::assertCount(3, iterator_to_array($client->bids->list(['limit' => 2])->autoPagingIterator()));
    }

    public function testAutoPagingIsLazy(): void
    {
        $client = $this->partner([
            self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 0, 'has_more' => true]),
            self::bidsPage(['B3', 'B4'], ['limit' => 2, 'offset' => 2, 'has_more' => true]),
        ]);
        foreach ($client->bids->list(['limit' => 2])->autoPagingIterator() as $bid) {
            if ($bid->code === 'B2') {
                break;
            }
        }

        self::assertCount(1, $this->history);
    }

    public function testWithoutHasMoreAFullPageMeansMore(): void
    {
        $client = $this->partner([
            self::bidsPage(['B1', 'B2'], ['limit' => 2, 'offset' => 0]),
            self::bidsPage(['B3'], ['limit' => 2, 'offset' => 2]),
        ]);
        $page = $client->bids->list(['limit' => 2]);

        self::assertTrue($page->hasMore);
        $next = $page->nextPage();
        self::assertNotNull($next);
        self::assertFalse($next->hasMore);
    }

    public function testAnEmptyPageHasNoNextPage(): void
    {
        $client = $this->partner([self::bidsPage([], ['limit' => 2, 'offset' => 0, 'has_more' => true])]);
        $page = $client->bids->list();

        self::assertCount(0, $page);
        self::assertNull($page->nextPage());
        self::assertSame([], iterator_to_array($page->autoPagingIterator()));
    }

    public function testMissingPaginationDoesNotThrow(): void
    {
        $client = $this->partner([self::ok(['bids' => [self::bid()]])]);
        $page = $client->bids->list();

        self::assertNull($page->limit);
        self::assertSame(0, $page->offset);
        self::assertNull($page->total);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextPage());
    }

    public function testThePageKeepsTheWholeDataObject(): void
    {
        $wine = [
            'lwin18' => self::LWIN, 'wine_name' => 'Chateau Exemple 2015', 'vintage' => 2015, 'pack_size' => '6x75cl',
            'producer' => 'Chateau Exemple', 'region' => 'Bordeaux', 'colour' => 'Red', 'product_url' => null,
            'market_price' => 1500, 'bids' => [], 'offers' => [],
        ];
        $client = $this->partner([self::ok(['currency' => 'GBP', 'wines' => [$wine]], 200, [], ['pagination' => ['limit' => 20, 'offset' => 0, 'has_more' => false]])]);
        $page = $client->wines->search(['q' => 'Chateau Exemple']);

        self::assertSame('GBP', $page->data['currency']);
        self::assertInstanceOf(Wine::class, $page->items[0]);
        self::assertSame(1500.0, $page->items[0]->market_price);
        self::assertSame(['q' => 'Chateau Exemple'], $this->query());
    }
}
