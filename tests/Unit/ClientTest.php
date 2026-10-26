<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Client;
use CruWorldWine\Exception\CruException;
use CruWorldWine\Models\Ping;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClientTest extends TestCase
{
    public function testPartnerSendsTheKeyPair(): void
    {
        $client = $this->partner([self::ok(['pong' => true, 'client' => ['market' => 'uk']])]);
        $client->ping();

        $request = $this->request();
        self::assertSame('ck_test', $request->getHeaderLine('CLIENT-KEY'));
        self::assertSame('cs_test', $request->getHeaderLine('CLIENT-SECRET'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('cruworldwine-php/0.2.0', $request->getHeaderLine('User-Agent'));
        self::assertTrue($client->isPartner());
    }

    public function testConnectedAppSendsABearerToken(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])]);
        $client->ping();

        $request = $this->request();
        self::assertSame('Bearer at_test', $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader('CLIENT-KEY'));
        self::assertFalse($request->hasHeader('CLIENT-SECRET'));
        self::assertFalse($client->isPartner());
    }

    public function testAKeyPairWinsOverAToken(): void
    {
        $client = $this->client([self::ok(['pong' => true])], ['clientKey' => 'k', 'clientSecret' => 's', 'accessToken' => 't']);
        $client->ping();

        self::assertTrue($client->isPartner());
        self::assertSame('k', $this->request()->getHeaderLine('CLIENT-KEY'));
        self::assertFalse($this->request()->hasHeader('Authorization'));
    }

    public function testAKeyWithoutItsSecretFallsBackToTheToken(): void
    {
        $client = $this->client([self::ok(['pong' => true])], ['clientKey' => 'k', 'accessToken' => 't']);
        $client->ping();

        self::assertFalse($client->isPartner());
        self::assertSame('Bearer t', $this->request()->getHeaderLine('Authorization'));
    }

    public function testNoCredentialsThrowsAtConstruction(): void
    {
        $this->expectException(CruException::class);
        new Client();
    }

    public function testAKeyAloneThrowsAtConstruction(): void
    {
        $this->expectException(CruException::class);
        new Client(clientKey: 'k');
    }

    public function testEmptyStringsCountAsNotGiven(): void
    {
        $this->expectException(CruException::class);
        new Client(clientKey: '', clientSecret: '', accessToken: '');
    }

    public function testThePartnerCredentialComesFromTheEnvironment(): void
    {
        putenv('CRU_CLIENT_KEY=env_key');
        putenv('CRU_CLIENT_SECRET=env_secret');
        $client = $this->client([self::ok(['pong' => true])], []);
        $client->ping();

        self::assertSame('env_key', $this->request()->getHeaderLine('CLIENT-KEY'));
        self::assertSame('env_secret', $this->request()->getHeaderLine('CLIENT-SECRET'));
    }

    public function testTheTokenComesFromTheEnvironment(): void
    {
        putenv('CRU_ACCESS_TOKEN=env_token');
        $client = $this->client([self::ok(['pong' => true])], []);
        $client->ping();

        self::assertSame('Bearer env_token', $this->request()->getHeaderLine('Authorization'));
    }

    public function testTheEnvironmentIsReadFromEnvSuperglobalToo(): void
    {
        $_ENV['CRU_ACCESS_TOKEN'] = 'superglobal_token';
        $client = $this->client([self::ok(['pong' => true])], []);
        $client->ping();

        self::assertSame('Bearer superglobal_token', $this->request()->getHeaderLine('Authorization'));
    }

    public function testAnArgumentWinsOverTheEnvironment(): void
    {
        putenv('CRU_ACCESS_TOKEN=env_token');
        $client = $this->client([self::ok(['pong' => true])], ['accessToken' => 'arg_token']);
        $client->ping();

        self::assertSame('Bearer arg_token', $this->request()->getHeaderLine('Authorization'));
    }

    public function testAnExplicitTokenIgnoresAKeyPairInTheEnvironment(): void
    {
        putenv('CRU_CLIENT_KEY=env_key');
        putenv('CRU_CLIENT_SECRET=env_secret');
        $client = $this->client([self::ok(['pong' => true])], ['accessToken' => 'arg_token']);
        $client->ping();

        self::assertSame('Bearer arg_token', $this->request()->getHeaderLine('Authorization'));
        self::assertFalse($this->request()->hasHeader('CLIENT-KEY'));
    }

    public function testAnExplicitKeyPairIgnoresATokenInTheEnvironment(): void
    {
        putenv('CRU_ACCESS_TOKEN=env_token');
        $client = $this->client([self::ok(['pong' => true])], ['clientKey' => 'arg_key', 'clientSecret' => 'arg_secret']);
        $client->ping();

        self::assertSame('arg_key', $this->request()->getHeaderLine('CLIENT-KEY'));
        self::assertFalse($this->request()->hasHeader('Authorization'));
    }

    public function testTheMarketDefaultsToUk(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])]);
        $client->ping();

        self::assertSame('https://uk.cruworldwine.com/api/v1', $client->getBaseUrl());
        self::assertSame('https://uk.cruworldwine.com/api/v1/ping', (string) $this->request()->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markets(): iterable
    {
        foreach (['uk', 'us', 'hk', 'sg', 'eu', 'ae', 'cn'] as $market) {
            yield $market => [$market];
        }
    }

    #[DataProvider('markets')]
    public function testEachMarketHasItsHost(string $market): void
    {
        $client = $this->oauth([self::ok(['pong' => true])], ['market' => $market]);
        $client->ping();

        self::assertSame('https://' . $market . '.cruworldwine.com/api/v1/ping', (string) $this->request()->getUri());
    }

    public function testTheMarketComesFromTheEnvironment(): void
    {
        putenv('CRU_MARKET=hk');
        $client = $this->oauth([]);

        self::assertSame('https://hk.cruworldwine.com/api/v1', $client->getBaseUrl());
    }

    public function testABaseUrlWinsOverTheMarket(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])], ['market' => 'us', 'baseUrl' => 'http://localhost:8080/api/v1/']);
        $client->ping();

        self::assertSame('http://localhost:8080/api/v1', $client->getBaseUrl());
        self::assertSame('http://localhost:8080/api/v1/ping', (string) $this->request()->getUri());
    }

    public function testTheBaseUrlComesFromTheEnvironment(): void
    {
        putenv('CRU_BASE_URL=https://sandbox.example.test/api/v1');
        $client = $this->oauth([]);

        self::assertSame('https://sandbox.example.test/api/v1', $client->getBaseUrl());
    }

    public function testAMarketThatIsNotAHostLabelIsRefused(): void
    {
        $this->expectException(CruException::class);
        $this->oauth([], ['market' => 'uk.evil.test/x']);
    }

    public function testABaseUrlWithoutAnHttpSchemeIsRefused(): void
    {
        $this->expectException(CruException::class);
        $this->oauth([], ['baseUrl' => 'ftp://example.test/api/v1']);
    }

    public function testANegativeRetryCountIsRefused(): void
    {
        $this->expectException(CruException::class);
        $this->oauth([], ['maxRetries' => -1]);
    }

    public function testTimeoutAndNoRedirectsAreSentWithEveryRequest(): void
    {
        $client = $this->oauth([self::ok(['pong' => true])], ['timeout' => 12.5]);
        $client->ping();

        $options = $this->history[0]['options'];
        self::assertSame(12.5, $options['timeout']);
        self::assertSame(12.5, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
    }

    public function testPingReturnsTheEnvelopesDataItself(): void
    {
        $client = $this->partner([self::ok([
            'pong' => true,
            'client' => ['environment' => 'sandbox', 'market' => 'uk', 'currency' => 'GBP', 'scopes' => ['meta:read', 'bids:read'], 'expires_at' => null],
        ])]);
        $ping = $client->ping();

        self::assertInstanceOf(Ping::class, $ping);
        self::assertTrue($ping->pong);
        self::assertNotNull($ping->client);
        self::assertSame('sandbox', $ping->client->environment);
        self::assertSame(['meta:read', 'bids:read'], $ping->client->scopes);
        self::assertNull($ping->client->expires_at);
    }

    public function testDebugOutputHidesTheCredentials(): void
    {
        $client = $this->partner([]);
        $dump = print_r($client, true);

        self::assertStringNotContainsString('cs_test', $dump);
        self::assertStringNotContainsString('ck_test', $dump);
    }
}
