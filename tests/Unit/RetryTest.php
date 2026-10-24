<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Exception\ApiConnectionException;
use CruWorldWine\Exception\ApiTimeoutException;
use CruWorldWine\Exception\InternalServerException;
use CruWorldWine\Exception\NotFoundException;
use CruWorldWine\Exception\PriceChangedException;
use CruWorldWine\Exception\RateLimitException;
use CruWorldWine\Exception\RequestInProgressException;
use CruWorldWine\Exception\UnprocessableEntityException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryTest extends TestCase
{
    private static function bidResult(): \GuzzleHttp\Psr7\Response
    {
        return self::ok(['action' => 'bid.cancel', 'status' => 'completed', 'bid' => self::bid()]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function connectError(string $message = 'Could not resolve host', array $context = []): ConnectException
    {
        return new ConnectException($message, new Request('GET', 'https://uk.cruworldwine.com/api/v1/ping'), null, $context);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryStatuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retryStatuses')]
    public function testAReadIsRetried(int $status): void
    {
        $client = $this->partner([self::error($status, 'SERVICE_UNAVAILABLE'), self::ok(['bid' => self::bid()])]);
        $bid = $client->bids->get('BID-test-0001');

        self::assertSame('BID-test-0001', $bid->code);
        self::assertCount(2, $this->history);
        self::assertCount(1, $this->sleeps);
    }

    public function testAReadIsRetriedAfterAConnectionError(): void
    {
        $client = $this->partner([self::connectError(), self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertCount(2, $this->history);
    }

    public function testAReadIsRetriedAfterANetworkErrorWithoutAResponse(): void
    {
        $failure = new RequestException('Connection reset by peer', new Request('GET', 'https://uk.cruworldwine.com/api/v1/bids'));
        $client = $this->oauth([$failure, self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertCount(2, $this->history);
    }

    public function testAPartnerWriteIsRetriedWithTheSameReference(): void
    {
        $client = $this->partner([
            self::error(503, 'SERVICE_UNAVAILABLE'),
            self::connectError(),
            self::bidResult(),
        ]);
        $write = $client->bids->cancel('BID-test-0001');

        self::assertNotNull($write->result);
        self::assertCount(3, $this->history);
        $references = array_map(fn (int $i): mixed => $this->body($i)['reference'], [0, 1, 2]);
        self::assertMatchesRegularExpression('/^sdk_[0-9a-f]{32}$/', (string) $references[0]);
        self::assertSame($references[0], $references[1]);
        self::assertSame($references[0], $references[2]);
    }

    public function testAGivenReferenceIsSentOnEveryRetry(): void
    {
        $client = $this->partner([self::error(502, null), self::ok(['action' => 'bid.place', 'status' => 'completed', 'bid' => self::bid()], 201)]);
        $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 1, 'price' => 10, 'reference' => 'mine-1']);

        self::assertSame('mine-1', $this->body(0)['reference']);
        self::assertSame('mine-1', $this->body(1)['reference']);
    }

    public function testAPartnerWriteIsRetriedWhileTheRequestIsInProgress(): void
    {
        $client = $this->partner([self::error(409, 'REQUEST_IN_PROGRESS'), self::bidResult()]);
        $client->bids->cancel('BID-test-0001');

        self::assertCount(2, $this->history);
        self::assertSame($this->body(0)['reference'], $this->body(1)['reference']);
    }

    public function testRequestInProgressIsThrownOnceRetriesRunOut(): void
    {
        $client = $this->partner([self::error(409, 'REQUEST_IN_PROGRESS'), self::error(409, 'REQUEST_IN_PROGRESS'), self::error(409, 'REQUEST_IN_PROGRESS')]);
        $this->expectException(RequestInProgressException::class);
        try {
            $client->bids->cancel('BID-test-0001');
        } finally {
            self::assertCount(3, $this->history);
        }
    }

    public function testAnotherConflictIsNotRetried(): void
    {
        $client = $this->partner([self::error(409, 'PRICE_CHANGED')]);
        $this->expectException(PriceChangedException::class);
        $client->market->buyOffer('OFFER-test-0001', ['quantity' => 1]);
    }

    public function testAConnectedAppWriteIsNeverRetriedOnAnError(): void
    {
        $client = $this->oauth([self::error(503, 'SERVICE_UNAVAILABLE'), self::ok(['confirmation' => ['code' => 'cnf_x']], 202)]);
        try {
            $client->bids->place(['lwin18' => self::LWIN, 'quantity' => 1, 'price' => 10]);
            self::fail('No exception');
        } catch (InternalServerException $e) {
            self::assertSame(503, $e->getStatusCode());
        }
        self::assertCount(1, $this->history);
        self::assertSame([], $this->sleeps);
    }

    public function testAConnectedAppWriteIsNeverRetriedOnAConnectionError(): void
    {
        $client = $this->oauth([self::connectError(), self::ok(['confirmation' => ['code' => 'cnf_x']], 202)]);
        try {
            $client->bids->cancel('BID-test-0001');
            self::fail('No exception');
        } catch (ApiConnectionException $e) {
            self::assertNotInstanceOf(ApiTimeoutException::class, $e);
        }
        self::assertCount(1, $this->history);
    }

    public function testAConnectedAppWriteIsNeverRetriedWhileInProgress(): void
    {
        $client = $this->oauth([self::error(409, 'REQUEST_IN_PROGRESS')]);
        $this->expectException(RequestInProgressException::class);
        $client->bids->cancel('BID-test-0001');
    }

    public function testAConnectedAppReadIsRetried(): void
    {
        $client = $this->oauth([self::error(500, 'INTERNAL_ERROR'), self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertCount(2, $this->history);
    }

    /**
     * @return iterable<string, array{int, string, class-string}>
     */
    public static function finalErrors(): iterable
    {
        yield '404' => [404, 'BID_NOT_FOUND', NotFoundException::class];
        yield '422' => [422, 'VALIDATION_INVALID_VALUE', UnprocessableEntityException::class];
    }

    /**
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('finalErrors')]
    public function testAClientErrorIsNotRetried(int $status, string $code, string $class): void
    {
        $client = $this->partner([self::error($status, $code)]);
        $this->expectException($class);
        try {
            $client->bids->get('BID-test-0001');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testTheLastErrorIsThrownOnceRetriesRunOut(): void
    {
        $client = $this->partner([self::error(429, 'RATE_LIMIT_EXCEEDED'), self::error(429, 'RATE_LIMIT_EXCEEDED'), self::error(429, 'RATE_LIMIT_EXCEEDED')]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (RateLimitException $e) {
            self::assertSame('RATE_LIMIT_EXCEEDED', $e->getErrorCode());
        }
        self::assertCount(3, $this->history);
        self::assertCount(2, $this->sleeps);
    }

    public function testMaxRetriesIsHonoured(): void
    {
        $client = $this->partner(array_fill(0, 6, self::error(503, 'SERVICE_UNAVAILABLE')), ['maxRetries' => 4]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (InternalServerException) {
        }
        self::assertCount(5, $this->history);
    }

    public function testNoRetriesWithMaxRetriesZero(): void
    {
        $client = $this->partner([self::error(503, 'SERVICE_UNAVAILABLE'), self::ok(['bid' => self::bid()])], ['maxRetries' => 0]);
        $this->expectException(InternalServerException::class);
        $client->bids->get('BID-test-0001');
    }

    public function testATimeoutIsRetriedThenThrownAsATimeout(): void
    {
        $timeouts = array_fill(0, 3, self::connectError('cURL error 28: Operation timed out', ['errno' => 28]));
        $client = $this->partner($timeouts);
        $this->expectException(ApiTimeoutException::class);
        try {
            $client->bids->get('BID-test-0001');
        } finally {
            self::assertCount(3, $this->history);
        }
    }

    public function testBackoffDoublesWithJitter(): void
    {
        $client = $this->partner(array_fill(0, 7, self::error(503, null)), ['maxRetries' => 6]);
        try {
            $client->bids->get('BID-test-0001');
        } catch (InternalServerException) {
        }

        self::assertCount(6, $this->sleeps);
        $bases = [0.5, 1.0, 2.0, 4.0, 8.0, 8.0];
        foreach ($bases as $i => $base) {
            self::assertGreaterThanOrEqual($base * 0.75, $this->sleeps[$i], "attempt $i");
            self::assertLessThanOrEqual($base * 1.25, $this->sleeps[$i], "attempt $i");
        }
    }

    public function testRetryAfterHeaderIsHonoured(): void
    {
        $client = $this->partner([self::error(429, 'RATE_LIMIT_EXCEEDED', 'Slow down.', [], ['Retry-After' => '3']), self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertSame([3.0], $this->sleeps);
    }

    public function testRetryAfterDetailIsHonoured(): void
    {
        $client = $this->partner([
            self::error(429, 'RATE_LIMIT_EXCEEDED', 'Slow down.', ['details' => ['retry_after' => 1.5]], ['Retry-After' => '3']),
            self::ok(['bid' => self::bid()]),
        ]);
        $client->bids->get('BID-test-0001');

        self::assertSame([1.5], $this->sleeps);
    }

    public function testRetryAfterIsCappedAtThirtySeconds(): void
    {
        $client = $this->partner([
            self::error(503, 'SERVICE_UNAVAILABLE', 'Down.', [], ['Retry-After' => '3600']),
            self::error(429, 'RATE_LIMIT_EXCEEDED', 'Slow down.', ['details' => ['retry_after' => 90]]),
            self::ok(['bid' => self::bid()]),
        ]);
        $client->bids->get('BID-test-0001');

        self::assertSame([30.0, 30.0], $this->sleeps);
    }

    public function testAnUnreadableRetryAfterFallsBackToBackoff(): void
    {
        $client = $this->partner([
            self::error(503, 'SERVICE_UNAVAILABLE', 'Down.', ['details' => ['retry_after' => 'soon']], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']),
            self::ok(['bid' => self::bid()]),
        ]);
        $client->bids->get('BID-test-0001');

        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(0.375, $this->sleeps[0]);
        self::assertLessThanOrEqual(0.625, $this->sleeps[0]);
    }
}
