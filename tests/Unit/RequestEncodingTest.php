<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Exception\CruException;

final class RequestEncodingTest extends TestCase
{
    private static function marketPage(): \GuzzleHttp\Psr7\Response
    {
        return self::ok(['markets' => []], 200, [], ['pagination' => ['limit' => 20, 'offset' => 0, 'has_more' => false]]);
    }

    public function testQueryDropsNullsSendsBooleansAsWordsAndJoinsLists(): void
    {
        $client = $this->partner([self::marketPage()]);
        $client->market->listBids([
            'lwin' => [self::LWIN, '765432120100601500'],
            'deliverable_warehouse' => ['UK_DRAK', 'HK_KERRY'],
            'landed_stock_only' => true,
            'include_market_price' => false,
            'min_price' => 100,
            'max_price' => null,
            'wine_name' => 'Chateau & Fils',
        ]);

        self::assertSame([
            'lwin' => self::LWIN . ',765432120100601500',
            'deliverable_warehouse' => 'UK_DRAK,HK_KERRY',
            'landed_stock_only' => 'true',
            'include_market_price' => 'false',
            'min_price' => '100',
            'wine_name' => 'Chateau & Fils',
        ], $this->query());
        self::assertStringNotContainsString('max_price', $this->request()->getUri()->getQuery());
        self::assertSame('/api/v1/market/bids', $this->request()->getUri()->getPath());
        self::assertSame('GET', $this->request()->getMethod());
    }

    public function testAListMayAlsoBeGivenAsACommaSeparatedString(): void
    {
        $client = $this->partner([self::marketPage()]);
        $client->market->list(['lwin' => self::LWIN . ',765432120100601500']);

        self::assertSame(['lwin' => self::LWIN . ',765432120100601500'], $this->query());
    }

    public function testAReadSendsNoBody(): void
    {
        $client = $this->partner([self::marketPage()]);
        $client->market->list();

        self::assertSame('', (string) $this->request()->getBody());
        self::assertFalse($this->request()->hasHeader('Content-Type'));
        self::assertSame('', $this->request()->getUri()->getQuery());
    }

    public function testUnknownQueryParametersArePassedThrough(): void
    {
        $client = $this->partner([self::marketPage()]);
        $client->market->list(['brand_new_filter' => 'x', 'limit' => 5]);

        self::assertSame(['brand_new_filter' => 'x', 'limit' => '5'], $this->query());
    }

    public function testPathParametersAreEncodedAsOneSegment(): void
    {
        $client = $this->partner([self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-a/b?c#d e%');

        self::assertSame('/api/v1/bids/BID-a%2Fb%3Fc%23d%20e%25', $this->request()->getUri()->getPath());
    }

    public function testIntegerPathParameters(): void
    {
        $client = $this->partner([self::ok(['inbond_warehouse' => ['inbond_address_id' => 7, 'name' => 'Example Bond']])]);
        $warehouse = $client->inbondWarehouses->get(7);

        self::assertSame('/api/v1/warehouses/inbond/7', $this->request()->getUri()->getPath());
        self::assertSame(7, $warehouse->inbond_address_id);
    }

    public function testAnEmptyPathParameterThrowsBeforeSending(): void
    {
        $client = $this->partner([]);
        try {
            $client->bids->get('');
            self::fail('No exception');
        } catch (CruException $e) {
            self::assertStringContainsString('code', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testLiteralPathsAreNotTemplated(): void
    {
        $client = $this->partner([self::ok(['inbond_warehouses' => []])]);
        $client->inbondWarehouses->list();

        self::assertSame('/api/v1/warehouses/inbond', $this->request()->getUri()->getPath());
    }

    public function testBodyDropsNullsAndKeepsTheRest(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $client->bids->place([
            'lwin18' => self::LWIN,
            'quantity' => 3,
            'price' => 1200.0,
            'local_only' => false,
            'auto_transfer' => null,
            'client_ref' => null,
        ]);

        self::assertSame('POST', $this->request()->getMethod());
        self::assertSame('application/json', $this->request()->getHeaderLine('Content-Type'));
        self::assertSame(['lwin18' => self::LWIN, 'quantity' => 3, 'price' => 1200.0, 'local_only' => false], $this->body());
        self::assertStringContainsString('"price":1200.0', (string) $this->request()->getBody());
    }

    public function testAnEmptyWriteBodyIsSentAsAnObject(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $client->bids->cancel('BID-test-0001');

        self::assertSame('DELETE', $this->request()->getMethod());
        self::assertSame('{}', (string) $this->request()->getBody());
    }

    public function testAWriteBodyOfOnlyNullsIsSentAsAnObject(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $client->bids->update('BID-test-0001', ['price' => null, 'quantity' => null]);

        self::assertSame('{}', (string) $this->request()->getBody());
    }

    public function testUnknownBodyParametersArePassedThrough(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $client->bids->update('BID-test-0001', ['price' => 10, 'brand_new_option' => true]);

        self::assertSame(['price' => 10, 'brand_new_option' => true], $this->body());
    }

    public function testAPartnerWriteGetsAReference(): void
    {
        $client = $this->partner([self::ok(['action' => 'bid.cancel', 'status' => 'completed', 'bid' => self::bid()])]);
        $client->bids->cancel('BID-test-0001');

        $body = $this->body();
        self::assertIsString($body['reference']);
        self::assertMatchesRegularExpression('/^sdk_[0-9a-f]{32}$/', $body['reference']);
    }

    public function testEachPartnerWriteGetsItsOwnReference(): void
    {
        $result = ['action' => 'bid.cancel', 'status' => 'completed', 'bid' => self::bid()];
        $client = $this->partner([self::ok($result), self::ok($result)]);
        $client->bids->cancel('BID-test-0001');
        $client->bids->cancel('BID-test-0001');

        self::assertNotSame($this->body(0)['reference'], $this->body(1)['reference']);
    }

    public function testAGivenReferenceIsKept(): void
    {
        $client = $this->partner([self::ok(['action' => 'bid.place', 'status' => 'completed', 'bid' => self::bid()], 201)]);
        $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 1, 'price' => 10, 'reference' => 'my-ref-42']);

        self::assertSame('my-ref-42', $this->body()['reference']);
    }

    public function testAConnectedAppWriteCarriesNoReference(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $client->bids->renew('BID-test-0001');

        self::assertArrayNotHasKey('reference', $this->body());
    }

    public function testDiscardingAConfirmationSendsNoBodyForAConnectedApp(): void
    {
        $client = $this->oauth([new \GuzzleHttp\Psr7\Response(204)]);
        $client->confirmations->cancel('cnf_test_1');

        self::assertSame('DELETE', $this->request()->getMethod());
        self::assertSame('', (string) $this->request()->getBody());
    }

    public function testRawRequestKeepsNullsInTheBodyAndDropsThemFromTheQuery(): void
    {
        $client = $this->oauth([self::ok(['ok' => true])]);
        $client->request('POST', 'something/new', ['a' => null, 'b' => 1], ['keep' => null]);

        self::assertSame('/api/v1/something/new', $this->request()->getUri()->getPath());
        self::assertSame('b=1', $this->request()->getUri()->getQuery());
        self::assertSame('{"keep":null}', (string) $this->request()->getBody());
    }

    public function testARawPartnerWriteGetsAReference(): void
    {
        $client = $this->partner([self::ok(['ok' => true])]);
        $client->request('DELETE', '/something/1');

        self::assertMatchesRegularExpression('/^sdk_[0-9a-f]{32}$/', (string) $this->body()['reference']);
    }

    public function testAValueThatCannotGoInAUrlThrows(): void
    {
        $client = $this->partner([]);
        $this->expectException(CruException::class);
        $client->market->list(['lwin' => [new \stdClass()]]);
    }
}
