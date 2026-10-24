<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Exception\ApiConnectionException;
use CruWorldWine\Exception\ApiStatusException;
use CruWorldWine\Exception\AuthenticationException;
use CruWorldWine\Exception\BadRequestException;
use CruWorldWine\Exception\ConflictException;
use CruWorldWine\Exception\CruException;
use CruWorldWine\Exception\GoneException;
use CruWorldWine\Exception\InternalServerException;
use CruWorldWine\Exception\MethodNotAllowedException;
use CruWorldWine\Exception\NotFoundException;
use CruWorldWine\Exception\PermissionDeniedException;
use CruWorldWine\Exception\PriceChangedException;
use CruWorldWine\Exception\RateLimitException;
use CruWorldWine\Exception\RequestInProgressException;
use CruWorldWine\Exception\UnprocessableEntityException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, ?string, class-string<ApiStatusException>}>
     */
    public static function mapping(): iterable
    {
        yield '400' => [400, 'VALIDATION_UNKNOWN_PARAMETER', BadRequestException::class];
        yield '401' => [401, 'AUTH_INVALID_CREDENTIALS', AuthenticationException::class];
        yield '403' => [403, 'AUTH_INSUFFICIENT_SCOPE', PermissionDeniedException::class];
        yield '404' => [404, 'BID_NOT_FOUND', NotFoundException::class];
        yield '405' => [405, 'METHOD_NOT_ALLOWED', MethodNotAllowedException::class];
        yield '409' => [409, 'CONFIRMATION_LIMIT_REACHED', ConflictException::class];
        yield '409 PRICE_CHANGED' => [409, 'PRICE_CHANGED', PriceChangedException::class];
        yield '409 REQUEST_IN_PROGRESS' => [409, 'REQUEST_IN_PROGRESS', RequestInProgressException::class];
        yield '410' => [410, 'CONFIRMATION_EXPIRED', GoneException::class];
        yield '422' => [422, 'VALIDATION_INVALID_VALUE', UnprocessableEntityException::class];
        yield '422 TRADE_REFUSED' => [422, 'TRADE_REFUSED', UnprocessableEntityException::class];
        yield '422 PRICE_CHANGED' => [422, 'PRICE_CHANGED', UnprocessableEntityException::class];
        yield '429' => [429, 'RATE_LIMIT_EXCEEDED', RateLimitException::class];
        yield '500' => [500, 'INTERNAL_ERROR', InternalServerException::class];
        yield '501' => [501, null, InternalServerException::class];
        yield '503' => [503, 'SERVICE_UNAVAILABLE', InternalServerException::class];
        yield '599' => [599, null, InternalServerException::class];
        yield '418' => [418, null, ApiStatusException::class];
        yield '302' => [302, null, ApiStatusException::class];
    }

    /**
     * @param class-string<ApiStatusException> $class
     */
    #[DataProvider('mapping')]
    public function testEachStatusHasItsException(int $status, ?string $code, string $class): void
    {
        $client = $this->partner([self::error($status, $code, 'Something was refused.')], ['maxRetries' => 0]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (ApiStatusException $e) {
            self::assertSame($class, $e::class);
            self::assertInstanceOf(CruException::class, $e);
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($code, $e->getErrorCode());
        }
    }

    public function testTheErrorCarriesWhatTheApiSent(): void
    {
        $client = $this->partner([self::error(422, 'VALIDATION_INVALID_VALUE', 'price must be more than 0.', [
            'field' => 'price',
            'details' => ['minimum' => 0],
        ], ['X-Request-Id' => 'req_header_1'])]);
        try {
            $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 1, 'price' => 0]);
            self::fail('No exception');
        } catch (UnprocessableEntityException $e) {
            self::assertSame('VALIDATION_INVALID_VALUE', $e->getErrorCode());
            self::assertSame('price', $e->getField());
            self::assertSame(['minimum' => 0], $e->getDetails());
            self::assertSame('price must be more than 0.', $e->getApiMessage());
            self::assertSame('422 VALIDATION_INVALID_VALUE: price must be more than 0.', $e->getMessage());
            self::assertSame('req_err_1', $e->getRequestId());
            self::assertCount(1, $e->getErrors());
            self::assertSame('ERROR', $e->getBody()['status']);
            self::assertStringContainsString('VALIDATION_INVALID_VALUE', $e->getRawBody());
            self::assertSame('req_header_1', $e->getHeaders()['x-request-id']);
            self::assertSame(422, $e->getCode());
        }
    }

    public function testEveryErrorEntryIsKept(): void
    {
        $body = [
            'status' => 'ERROR',
            'message' => 'Two problems.',
            'meta' => ['request_id' => 'req_two'],
            'errors' => [
                ['code' => 'VALIDATION_REQUIRED_FIELD', 'message' => 'quantity is required.', 'field' => 'quantity'],
                ['code' => 'VALIDATION_INVALID_VALUE', 'message' => 'price is not a number.', 'field' => 'price'],
            ],
        ];
        $client = $this->partner([self::json(422, $body)]);
        try {
            $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 1, 'price' => 1]);
            self::fail('No exception');
        } catch (UnprocessableEntityException $e) {
            self::assertSame('VALIDATION_REQUIRED_FIELD', $e->getErrorCode());
            self::assertSame('quantity', $e->getField());
            self::assertCount(2, $e->getErrors());
            self::assertSame('price', $e->getErrors()[1]['field']);
        }
    }

    public function testTheRequestIdFallsBackToTheHeader(): void
    {
        $client = $this->partner([self::json(404, ['errors' => [['code' => 'NOT_FOUND', 'message' => 'No.']]], ['X-Request-Id' => 'req_hdr'])]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (NotFoundException $e) {
            self::assertSame('req_hdr', $e->getRequestId());
        }
    }

    public function testABodyThatIsNotJsonStillMapsByStatus(): void
    {
        $client = $this->partner([new Response(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>')], ['maxRetries' => 0]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (InternalServerException $e) {
            self::assertSame(502, $e->getStatusCode());
            self::assertNull($e->getErrorCode());
            self::assertSame('HTTP 502', $e->getApiMessage());
            self::assertSame([], $e->getBody());
            self::assertSame('<html>Bad gateway</html>', $e->getRawBody());
            self::assertSame([], $e->getErrors());
        }
    }

    public function testMalformedErrorEntriesDoNotThrow(): void
    {
        $client = $this->partner([self::json(400, ['message' => 'Bad.', 'errors' => ['not an object', ['code' => 42, 'field' => ['x'], 'details' => 'none']]])]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (BadRequestException $e) {
            self::assertNull($e->getErrorCode());
            self::assertNull($e->getField());
            self::assertSame([], $e->getDetails());
            self::assertSame('Bad.', $e->getApiMessage());
            self::assertCount(1, $e->getErrors());
        }
    }

    public function testAGuzzleExceptionNeverEscapes(): void
    {
        $client = $this->partner([new TransferException('Something odd in the handler')], ['maxRetries' => 0]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (ApiConnectionException $e) {
            self::assertInstanceOf(TransferException::class, $e->getPrevious());
        }
    }

    public function testARequestThatCannotBeBuiltIsACruErrorAndIsNotRetried(): void
    {
        $client = $this->partner([new \GuzzleHttp\Exception\InvalidArgumentException('bad option'), self::ok(['bid' => self::bid()])]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (CruException $e) {
            self::assertNotInstanceOf(ApiConnectionException::class, $e);
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
        self::assertCount(1, $this->history);
    }

    public function testAnErrorResponseFromAGuzzleExceptionIsMappedToo(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://uk.cruworldwine.com/api/v1/bids/x');
        $response = self::error(404, 'BID_NOT_FOUND');
        $client = $this->partner([new \GuzzleHttp\Exception\ClientException('404', $request, $response)]);
        $this->expectException(NotFoundException::class);
        $client->bids->get('x');
    }
}
