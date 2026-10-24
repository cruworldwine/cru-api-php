<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Models\BidResult;
use CruWorldWine\Models\Confirmation;
use CruWorldWine\Models\InstructionResult;
use CruWorldWine\Models\OfferRemoveResult;
use CruWorldWine\Models\OwnBid;
use CruWorldWine\Models\StagedConfirmation;
use CruWorldWine\Models\Warehouse;
use GuzzleHttp\Psr7\Response;

final class ResponseKindsTest extends TestCase
{
    private const STAGED = [
        'confirmation' => [
            'code' => 'cnf_test_0001',
            'action' => 'bid.place',
            'status' => 'pending',
            'expires_at' => '2026-01-02T03:14:05Z',
            'preview' => ['summary' => 'Bid 1,200.50 GBP each for 2 units of Chateau Exemple 2015.'],
        ],
    ];

    public function testGetUnwrapsItsKey(): void
    {
        $client = $this->partner([self::ok(['bid' => self::bid()])]);
        $bid = $client->bids->get('BID-test-0001');

        self::assertInstanceOf(OwnBid::class, $bid);
        self::assertSame('BID-test-0001', $bid->code);
        self::assertSame(1200.5, $bid->price);
        self::assertSame(2, $bid->quantity);
        self::assertSame([], $bid->order_numbers);
        self::assertTrue($bid->auto_transfer);
        self::assertNull($bid->updated_at);
    }

    public function testGetWithoutItsKeyGivesAnEmptyModel(): void
    {
        $client = $this->partner([self::ok(['something_else' => true])]);
        $bid = $client->bids->get('BID-test-0001');

        self::assertNull($bid->code);
        self::assertSame([], $bid->toArray());
    }

    public function testListUnwrapsItsKey(): void
    {
        $warehouse = [
            'code' => 'UK_DRAK', 'name' => 'Example Bonded Store', 'market' => 'uk',
            'address' => ['street' => '1 Test Lane', 'city' => 'Testford', 'region' => null, 'postcode' => 'TE1 1ST', 'country' => 'GB'],
            'phone' => null, 'bond_references' => ['GBWKA000000000'], 'delivery_instructions' => null,
            'sells_your_inbond_wine' => true, 'settlement_fee' => 25, 'currency' => 'GBP',
        ];
        $client = $this->partner([self::ok(['warehouses' => [$warehouse, $warehouse + ['code' => 'HK_KERRY']]])]);
        $warehouses = $client->warehouses->list();

        self::assertCount(2, $warehouses);
        self::assertContainsOnlyInstancesOf(Warehouse::class, $warehouses);
        self::assertSame('UK_DRAK', $warehouses[0]->code);
        self::assertSame('Testford', $warehouses[0]->address?->city);
        self::assertSame(25.0, $warehouses[0]->settlement_fee);
        self::assertSame(['GBWKA000000000'], $warehouses[1]->bond_references);
    }

    public function testAListSkipsItemsThatAreNotObjects(): void
    {
        $client = $this->partner([self::ok(['warehouses' => [['code' => 'UK_DRAK'], 'junk', 42, null]])]);
        $warehouses = $client->warehouses->list();

        self::assertCount(1, $warehouses);
    }

    public function testAListThatIsNotAListIsEmpty(): void
    {
        $client = $this->partner([self::ok(['warehouses' => 'none'])]);

        self::assertSame([], $client->warehouses->list());
    }

    public function testAPartnerWriteReturnsItsResult(): void
    {
        $client = $this->partner([self::ok(['action' => 'bid.place', 'status' => 'completed', 'bid' => self::bid()], 201, ['X-Request-Id' => 'req_write_1'])]);
        $write = $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 2, 'price' => 1200.5]);

        self::assertFalse($write->staged);
        self::assertFalse($write->replayed);
        self::assertNull($write->confirmation);
        self::assertSame(201, $write->statusCode);
        self::assertSame('req_write_1', $write->requestId);
        self::assertSame(BidResult::class, $write->resultClass);
        self::assertInstanceOf(BidResult::class, $write->result);
        self::assertSame('bid.place', $write->result->action);
        self::assertSame('BID-test-0001', $write->result->bid?->code);
    }

    public function testAReplayedWriteSaysSo(): void
    {
        $client = $this->partner([self::ok(['action' => 'bid.cancel', 'status' => 'completed', 'bid' => self::bid()], 200, ['Idempotent-Replayed' => 'true'])]);
        $write = $client->bids->cancel('BID-test-0001', ['reference' => 'ref-1']);

        self::assertTrue($write->replayed);
    }

    public function testTheRequestIdFallsBackToMeta(): void
    {
        $client = $this->partner([self::ok(['action' => 'bid.cancel', 'status' => 'completed', 'bid' => self::bid()])]);
        $write = $client->bids->cancel('BID-test-0001');

        self::assertSame('req_test_1', $write->requestId);
    }

    public function testA202IsStaged(): void
    {
        $client = $this->oauth([self::ok(self::STAGED, 202, ['X-Request-Id' => 'req_staged'])]);
        $write = $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 2, 'price' => 1200.5]);

        self::assertTrue($write->staged);
        self::assertNull($write->result);
        self::assertFalse($write->replayed);
        self::assertSame(202, $write->statusCode);
        self::assertSame('req_staged', $write->requestId);
        self::assertSame(BidResult::class, $write->resultClass);
        self::assertInstanceOf(StagedConfirmation::class, $write->confirmation);
        self::assertSame('cnf_test_0001', $write->confirmation->code);
        self::assertSame('bid.place', $write->confirmation->action);
        self::assertSame('pending', $write->confirmation->status);
        self::assertStringStartsWith('Bid 1,200.50', (string) $write->confirmation->preview?->summary);
    }

    public function testA202WithoutAConfirmationIsStillStaged(): void
    {
        $client = $this->oauth([self::ok([], 202)]);
        $write = $client->bids->renew('BID-test-0001');

        self::assertTrue($write->staged);
        self::assertNotNull($write->confirmation);
        self::assertNull($write->confirmation->code);
    }

    public function testConfirmIsTypedByItsAction(): void
    {
        $client = $this->oauth([self::ok(['action' => 'bid.place', 'status' => 'completed', 'bid' => self::bid()], 201)]);
        $write = $client->confirmations->confirm('cnf_test_0001');

        self::assertSame('POST', $this->request()->getMethod());
        self::assertSame('/api/v1/confirmations/cnf_test_0001', $this->request()->getUri()->getPath());
        self::assertSame('{}', (string) $this->request()->getBody());
        self::assertInstanceOf(BidResult::class, $write->result);
        self::assertSame(BidResult::class, $write->resultClass);
        self::assertSame(1200.5, $write->result->bid?->price);
    }

    public function testConfirmOfARemovalIsAnOfferRemoveResult(): void
    {
        $client = $this->oauth([self::ok(['action' => 'offer.remove', 'status' => 'completed', 'offer' => null, 'removed' => 3, 'deleted' => true])]);
        $write = $client->confirmations->confirm('cnf_test_0002');

        self::assertInstanceOf(OfferRemoveResult::class, $write->result);
        self::assertSame(3, $write->result->removed);
        self::assertNull($write->result->offer);
    }

    public function testConfirmOfAnUnknownActionFallsBackToInstructionResult(): void
    {
        $client = $this->oauth([self::ok(['action' => 'cellar.move', 'status' => 'completed', 'moved' => 6])]);
        $write = $client->confirmations->confirm('cnf_test_0003');

        self::assertSame(InstructionResult::class, $write->result::class);
        self::assertInstanceOf(InstructionResult::class, $write->result);
        self::assertSame('cellar.move', $write->result->action);
        self::assertSame(6, $write->result['moved']);
    }

    public function testConfirmWithoutAnActionFallsBackToInstructionResult(): void
    {
        $client = $this->oauth([self::ok(['status' => 'completed'])]);
        $write = $client->confirmations->confirm('cnf_test_0004');

        self::assertSame(InstructionResult::class, $write->result::class);
    }

    public function testAConfirmationCanBeRead(): void
    {
        $client = $this->oauth([self::ok(['confirmation' => [
            'action' => 'bid.place', 'status' => 'completed', 'preview' => ['summary' => 'Bid.'],
            'expires_at' => null, 'result' => ['action' => 'bid.place', 'bid' => self::bid()],
        ]])]);
        $confirmation = $client->confirmations->get('cnf_test_0001');

        self::assertInstanceOf(Confirmation::class, $confirmation);
        self::assertSame('completed', $confirmation->status);
        self::assertIsArray($confirmation->result);
        self::assertSame('bid.place', $confirmation->result['action']);
        self::assertNull($confirmation->error);
    }

    public function testA204ReturnsNothing(): void
    {
        $client = $this->oauth([new Response(204)]);
        $client->confirmations->cancel('cnf_test_0001');

        self::assertSame('DELETE', $this->request()->getMethod());
        self::assertSame('/api/v1/confirmations/cnf_test_0001', $this->request()->getUri()->getPath());
    }

    public function testABinaryReturnsTheRawBody(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n\x00\x00binary\xff";
        $client = $this->partner([new Response(200, ['Content-Type' => 'image/png'], $bytes)]);
        $photo = $client->photos->get('PHOTO-test-0001');

        self::assertSame($bytes, $photo);
        self::assertSame('*/*', $this->request()->getHeaderLine('Accept'));
        self::assertSame('/api/v1/photos/PHOTO-test-0001', $this->request()->getUri()->getPath());
    }

    public function testABinaryErrorThrowsAsUsual(): void
    {
        $client = $this->partner([self::error(404, 'NOT_FOUND')]);
        $this->expectException(\CruWorldWine\Exception\NotFoundException::class);
        $client->photos->get('PHOTO-gone');
    }

    public function testRawRequestReturnsTheEnvelope(): void
    {
        $client = $this->partner([self::ok(['anything' => ['new' => 1]], 200, ['X-Request-Id' => 'req_raw'])]);
        $response = $client->request('GET', '/brand/new', ['x' => true]);

        self::assertSame('/api/v1/brand/new', $this->request()->getUri()->getPath());
        self::assertSame('x=true', $this->request()->getUri()->getQuery());
        self::assertSame(200, $response->statusCode);
        self::assertSame(['anything' => ['new' => 1]], $response->data);
        self::assertSame('req_test_1', $response->meta['request_id']);
        self::assertSame('req_raw', $response->header('x-request-id'));
        self::assertSame('req_raw', $response->header('X-Request-Id'));
        self::assertIsArray($response->body);
        self::assertSame('OK', $response->body['status']);
        self::assertStringContainsString('"anything"', $response->rawBody);
    }

    public function testRawRequestThrowsOnErrors(): void
    {
        $client = $this->partner([self::error(403, 'AUTH_INSUFFICIENT_SCOPE')]);
        $this->expectException(\CruWorldWine\Exception\PermissionDeniedException::class);
        $client->request('GET', '/bids');
    }

    public function testRawRequestWithANonJsonBody(): void
    {
        $client = $this->partner([new Response(200, [], 'plain text')]);
        $response = $client->request('GET', '/text');

        self::assertNull($response->body);
        self::assertNull($response->data);
        self::assertSame([], $response->meta);
        self::assertSame('plain text', $response->rawBody);
    }
}
