<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Builds clients over a Guzzle MockHandler and records what they sent. Every fixture is invented.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const LWIN = '123456720150600750';

    protected const ENV = ['CRU_CLIENT_KEY', 'CRU_CLIENT_SECRET', 'CRU_ACCESS_TOKEN', 'CRU_MARKET', 'CRU_BASE_URL'];

    /** @var list<array{request: RequestInterface, response: mixed, error: mixed, options: array<string, mixed>}> */
    protected array $history = [];

    /** @var list<float> */
    protected array $sleeps = [];

    protected MockHandler $mock;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->history = [];
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * @param list<mixed> $responses
     * @param array<string, mixed> $args
     */
    protected function partner(array $responses, array $args = []): Client
    {
        return $this->client($responses, $args + ['clientKey' => 'ck_test', 'clientSecret' => 'cs_test']);
    }

    /**
     * @param list<mixed> $responses
     * @param array<string, mixed> $args
     */
    protected function oauth(array $responses, array $args = []): Client
    {
        return $this->client($responses, $args + ['accessToken' => 'at_test']);
    }

    /**
     * @param list<mixed> $responses
     * @param array<string, mixed> $args
     */
    protected function client(array $responses, array $args): Client
    {
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $args += [
            'httpClient' => new GuzzleClient(['handler' => $stack]),
            'sleep' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ];

        return new Client(...$args);
    }

    /**
     * @param array<array-key, mixed> $meta
     *
     * @return array<string, mixed>
     */
    protected static function envelope(mixed $data, array $meta = []): array
    {
        return [
            'status' => 'OK',
            'message' => 'OK',
            'meta' => $meta + ['version' => 'v1', 'timestamp' => '2026-01-02T03:04:05Z', 'request_id' => 'req_test_1'],
            'data' => $data,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $meta
     */
    protected static function ok(mixed $data, int $status = 200, array $headers = [], array $meta = []): Response
    {
        return self::json($status, self::envelope($data, $meta), $headers);
    }

    /**
     * @param array<string, mixed> $entry more of errors[0]
     * @param array<string, string> $headers
     */
    protected static function error(int $status, ?string $code, string $message = 'Refused.', array $entry = [], array $headers = []): Response
    {
        $errors = $code === null ? [] : [['code' => $code, 'message' => $message] + $entry];

        return self::json($status, [
            'status' => 'ERROR',
            'message' => $message,
            'meta' => ['version' => 'v1', 'timestamp' => '2026-01-02T03:04:05Z', 'request_id' => 'req_err_1'],
            'data' => null,
            'errors' => $errors,
        ], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function request(int $index = -1): RequestInterface
    {
        $entry = $index < 0 ? $this->history[count($this->history) + $index] : $this->history[$index];

        return $entry['request'];
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function body(int $index = -1): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    protected function query(int $index = -1): array
    {
        parse_str($this->request($index)->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function bid(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'BID-test-0001',
            'client_ref' => null,
            'lwin18' => self::LWIN,
            'wine_name' => 'Chateau Exemple 2015',
            'vintage' => 2015,
            'pack_size' => '6x75cl',
            'product_url' => 'https://example.test/wine',
            'price' => 1200.5,
            'currency' => 'GBP',
            'bid_currency' => 'GBP',
            'quantity' => 2,
            'quantity_matched' => 0,
            'quantity_open' => 2,
            'order_numbers' => [],
            'order_urls' => [],
            'status' => 'live',
            'renewable' => false,
            'market' => 'uk',
            'local_only' => false,
            'auto_transfer' => true,
            'expires_on' => '2026-04-01',
            'created_at' => '2026-01-01T10:00:00Z',
            'updated_at' => null,
        ];
    }
}
