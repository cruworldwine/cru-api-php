<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Client;
use CruWorldWine\Environment;
use CruWorldWine\Exception\CruException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\Attributes\DataProvider;

final class EnvironmentTest extends TestCase
{
    public function testLiveIsTheDefault(): void
    {
        $client = $this->oauth([]);

        self::assertSame('https://uk.cruworldwine.com/api/v1', $client->getBaseUrl());
    }

    public function testTheSandboxHasItsOwnHost(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])], ['environment' => Environment::Sandbox]);
        $client->ping();

        self::assertSame('https://uk.crusandbox.com/api/v1', $client->getBaseUrl());
        self::assertSame('https://uk.crusandbox.com/api/v1/ping', (string) $this->request()->getUri());
    }

    /**
     * @return iterable<string, array{Environment|string, string}>
     */
    public static function environments(): iterable
    {
        yield 'Environment::Live' => [Environment::Live, 'https://hk.cruworldwine.com/api/v1'];
        yield 'Environment::Sandbox' => [Environment::Sandbox, 'https://hk.crusandbox.com/api/v1'];
        yield 'live' => ['live', 'https://hk.cruworldwine.com/api/v1'];
        yield 'sandbox' => ['sandbox', 'https://hk.crusandbox.com/api/v1'];
        yield 'LIVE' => ['LIVE', 'https://hk.cruworldwine.com/api/v1'];
        yield 'Sandbox' => ['Sandbox', 'https://hk.crusandbox.com/api/v1'];
    }

    #[DataProvider('environments')]
    public function testTheEnvironmentAsAnEnumOrAStringInAnyCase(Environment|string $environment, string $baseUrl): void
    {
        $client = $this->oauth([], ['market' => 'HK', 'environment' => $environment]);

        self::assertSame($baseUrl, $client->getBaseUrl());
    }

    public function testTheEnvironmentComesFromTheEnvironment(): void
    {
        putenv('CRU_ENVIRONMENT=SANDBOX');
        putenv('CRU_MARKET=sg');
        $client = $this->oauth([]);

        self::assertSame('https://sg.crusandbox.com/api/v1', $client->getBaseUrl());
    }

    public function testAnArgumentWinsOverCruEnvironment(): void
    {
        putenv('CRU_ENVIRONMENT=sandbox');
        $client = $this->oauth([], ['environment' => 'live']);

        self::assertSame('https://uk.cruworldwine.com/api/v1', $client->getBaseUrl());
    }

    public function testAnEmptyEnvironmentCountsAsNotGiven(): void
    {
        putenv('CRU_ENVIRONMENT=sandbox');
        $client = $this->oauth([], ['environment' => '']);

        self::assertSame('https://uk.crusandbox.com/api/v1', $client->getBaseUrl());
    }

    public function testAnUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(CruException::class);
        $this->expectExceptionMessage('environment must be live or sandbox, not "staging".');
        $this->oauth([], ['environment' => 'staging']);
    }

    public function testAnUnknownCruEnvironmentIsRefused(): void
    {
        putenv('CRU_ENVIRONMENT=production');
        $this->expectException(CruException::class);
        $this->oauth([]);
    }

    public function testAnUnknownEnvironmentIsRefusedWithABaseUrlToo(): void
    {
        $this->expectException(CruException::class);
        $this->oauth([], ['environment' => 'test', 'baseUrl' => 'http://localhost:8080/api/v1']);
    }

    public function testABaseUrlWinsOverTheEnvironment(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])], ['environment' => 'sandbox', 'market' => 'us', 'baseUrl' => 'http://localhost:8080/api/v1']);
        $client->ping();

        self::assertSame('http://localhost:8080/api/v1', $client->getBaseUrl());
        self::assertSame('http://localhost:8080/api/v1/ping', (string) $this->request()->getUri());
    }

    public function testCruBaseUrlWinsOverCruEnvironment(): void
    {
        putenv('CRU_ENVIRONMENT=sandbox');
        putenv('CRU_BASE_URL=https://uk.cruworldwine.com/api/v1');
        $client = $this->oauth([]);

        self::assertSame('https://uk.cruworldwine.com/api/v1', $client->getBaseUrl());
    }

    public function testAnUnknownMarketCodeStillGetsAHostInTheSandbox(): void
    {
        $client = $this->oauth([], ['market' => 'fr', 'environment' => 'sandbox']);

        self::assertSame('https://fr.crusandbox.com/api/v1', $client->getBaseUrl());
        self::assertNull($client->getMarketInfo());
    }

    public function testAMarketThatIsNotAHostLabelIsRefusedInTheSandbox(): void
    {
        $this->expectException(CruException::class);
        $this->oauth([], ['market' => 'uk.evil.test/x', 'environment' => 'sandbox']);
    }

    public function testPositionalArgumentsKeepTheirPlaces(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::ok(['pong' => true])]));
        $stack->push(Middleware::history($history));
        $sleep = static function (float $seconds): void {
        };

        $client = new Client('ck_test', 'cs_test', null, null, null, null, null, 'hk', null, 12.5, 1, new GuzzleClient(['handler' => $stack]), $sleep);
        $client->ping();

        self::assertSame('https://hk.cruworldwine.com/api/v1', $client->getBaseUrl());
        self::assertTrue($client->isPartner());
        self::assertCount(1, $history);
        self::assertSame('ck_test', $history[0]['request']->getHeaderLine('CLIENT-KEY'));
        self::assertSame(12.5, $history[0]['options']['timeout']);

        $sandbox = new Client('ck_test', 'cs_test', null, null, null, null, null, 'hk', null, 12.5, 1, null, null, 'sandbox');
        self::assertSame('https://hk.crusandbox.com/api/v1', $sandbox->getBaseUrl());
    }

    public function testTheMarketInfoIsTheMarketBuiltFor(): void
    {
        self::assertSame('uk', $this->oauth([])->getMarketInfo()?->code);
        self::assertSame('us', $this->oauth([], ['market' => 'US'])->getMarketInfo()?->code);
        self::assertSame('hk', $this->oauth([], ['market' => 'hk', 'environment' => 'sandbox'])->getMarketInfo()?->code);

        putenv('CRU_MARKET=eu');
        self::assertSame('eu', $this->oauth([])->getMarketInfo()?->code);
    }

    /**
     * @return iterable<string, array{array<string, string>, string|null}>
     */
    public static function baseUrls(): iterable
    {
        yield 'a live host' => [['baseUrl' => 'https://sg.cruworldwine.com/api/v1'], 'sg'];
        yield 'a sandbox host' => [['baseUrl' => 'https://ae.crusandbox.com/api/v1'], 'ae'];
        yield 'a host in upper case' => [['baseUrl' => 'HTTPS://CN.CRUSANDBOX.COM/api/v1/'], 'cn'];
        yield 'a host with a port' => [['baseUrl' => 'http://uk.crusandbox.com:8443/api/v1'], 'uk'];
        yield 'the host over the market given' => [['baseUrl' => 'https://hk.crusandbox.com/api/v1', 'market' => 'us'], 'hk'];
        yield 'another host, with a market' => [['baseUrl' => 'http://localhost:8080/api/v1', 'market' => 'EU'], 'eu'];
        yield 'another host, without one' => [['baseUrl' => 'http://localhost:8080/api/v1'], null];
        yield 'another host, with an unknown market' => [['baseUrl' => 'http://localhost:8080/api/v1', 'market' => 'fr'], null];
        yield 'a host under a market host' => [['baseUrl' => 'https://api.uk.cruworldwine.com/api/v1'], null];
        yield 'a market host of an unknown market' => [['baseUrl' => 'https://fr.crusandbox.com/api/v1', 'market' => 'uk'], null];
    }

    /**
     * @param array<string, string> $args
     */
    #[DataProvider('baseUrls')]
    public function testTheMarketInfoWithABaseUrl(array $args, ?string $code): void
    {
        self::assertSame($code, $this->oauth([], $args)->getMarketInfo()?->code);
    }

    public function testTheMarketInfoWithABaseUrlAndCruMarket(): void
    {
        putenv('CRU_MARKET=ae');
        putenv('CRU_BASE_URL=http://localhost:8080/api/v1');

        self::assertSame('ae', $this->oauth([])->getMarketInfo()?->code);
    }
}
