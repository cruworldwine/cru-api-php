<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Client;
use CruWorldWine\Exception\ApiConnectionException;
use CruWorldWine\Exception\AuthenticationException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

final class OAuthRefreshTest extends TestCase
{
    /** @var list<array<array-key, mixed>> */
    private array $refreshed = [];

    /**
     * @param list<mixed> $responses
     * @param array<string, mixed> $args
     */
    private function app(array $responses, array $args = []): Client
    {
        return $this->oauth($responses, $args + [
            'accessToken' => 'at_old',
            'refreshToken' => 'rt_old',
            'oauthClientId' => 'app_123',
            'onTokenRefresh' => function (array $tokens): void {
                $this->refreshed[] = $tokens;
            },
        ]);
    }

    private static function expired(): \GuzzleHttp\Psr7\Response
    {
        return self::error(401, 'AUTH_INVALID_CREDENTIALS', 'The access token has expired.');
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private static function tokens(array $tokens = []): \GuzzleHttp\Psr7\Response
    {
        return self::json(200, $tokens + ['access_token' => 'at_new', 'refresh_token' => 'rt_new', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }

    /**
     * @return array<string, string>
     */
    private function form(int $index): array
    {
        parse_str((string) $this->request($index)->getBody(), $form);

        /** @var array<string, string> $form */
        return $form;
    }

    public function testAnExpiredTokenIsRefreshedAndTheCallRetried(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::ok(['bid' => self::bid()])]);
        $bid = $client->bids->get('BID-test-0001');

        self::assertSame('BID-test-0001', $bid->code);
        self::assertCount(3, $this->history);

        $refresh = $this->request(1);
        self::assertSame('POST', $refresh->getMethod());
        self::assertSame('https://uk.cruworldwine.com/api/oauth/token', (string) $refresh->getUri());
        self::assertStringStartsWith('application/x-www-form-urlencoded', $refresh->getHeaderLine('Content-Type'));
        self::assertFalse($refresh->hasHeader('Authorization'));
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'rt_old', 'client_id' => 'app_123'], $this->form(1));

        self::assertSame('Bearer at_old', $this->request(0)->getHeaderLine('Authorization'));
        self::assertSame('Bearer at_new', $this->request(2)->getHeaderLine('Authorization'));
        self::assertSame('at_new', $client->getAccessToken());
        self::assertSame('rt_new', $client->getRefreshToken());
        self::assertSame([], $this->sleeps);
    }

    public function testTheCallbackGetsTheTokenResponse(): void
    {
        $client = $this->app([self::expired(), self::tokens(['scope' => 'bids:read']), self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertCount(1, $this->refreshed);
        self::assertSame('at_new', $this->refreshed[0]['access_token']);
        self::assertSame('rt_new', $this->refreshed[0]['refresh_token']);
        self::assertSame('bids:read', $this->refreshed[0]['scope']);
    }

    public function testTheClientSecretIsSentWhenGiven(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::ok(['bid' => self::bid()])], ['oauthClientSecret' => 'app_secret']);
        $client->bids->get('BID-test-0001');

        self::assertSame('app_secret', $this->form(1)['client_secret']);
    }

    public function testTheRotatedRefreshTokenIsUsedNextTime(): void
    {
        $client = $this->app([
            self::expired(), self::tokens(), self::ok(['bid' => self::bid()]),
            self::expired(), self::tokens(['access_token' => 'at_newer', 'refresh_token' => 'rt_newer']), self::ok(['bid' => self::bid()]),
        ]);
        $client->bids->get('BID-test-0001');
        $client->bids->get('BID-test-0001');

        self::assertSame('rt_new', $this->form(4)['refresh_token']);
        self::assertSame('Bearer at_new', $this->request(3)->getHeaderLine('Authorization'));
        self::assertSame('Bearer at_newer', $this->request(5)->getHeaderLine('Authorization'));
        self::assertSame('rt_newer', $client->getRefreshToken());
        self::assertCount(2, $this->refreshed);
    }

    public function testARefreshTokenIsKeptWhenNoNewOneComes(): void
    {
        $client = $this->app([self::expired(), self::json(200, ['access_token' => 'at_new', 'token_type' => 'Bearer']), self::ok(['bid' => self::bid()])]);
        $client->bids->get('BID-test-0001');

        self::assertSame('at_new', $client->getAccessToken());
        self::assertSame('rt_old', $client->getRefreshToken());
    }

    public function testItRefreshesAtMostOncePerCall(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::expired(), self::tokens()]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->getStatusCode());
            self::assertSame('AUTH_INVALID_CREDENTIALS', $e->getErrorCode());
        }
        self::assertCount(3, $this->history);
        self::assertCount(1, $this->refreshed);
    }

    public function testARefusedRefreshThrowsAnAuthenticationError(): void
    {
        $client = $this->app([self::expired(), self::json(400, ['error' => 'invalid_grant', 'error_description' => 'The refresh token was already used.'])]);
        try {
            $client->bids->get('BID-test-0001');
            self::fail('No exception');
        } catch (AuthenticationException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('The refresh token was already used.', $e->getApiMessage());
            self::assertSame('', $e->getRawBody());
        }
        self::assertCount(2, $this->history);
        self::assertSame([], $this->refreshed);
        self::assertSame('at_old', $client->getAccessToken());
    }

    public function testATokenResponseWithoutAnAccessTokenIsAFailedRefresh(): void
    {
        $client = $this->app([self::expired(), self::json(200, ['token_type' => 'Bearer'])]);
        $this->expectException(AuthenticationException::class);
        $client->bids->get('BID-test-0001');
    }

    public function testARefreshThatCannotConnectIsAConnectionError(): void
    {
        $client = $this->app([self::expired(), new ConnectException('Could not resolve host', new Request('POST', 'https://uk.cruworldwine.com/api/oauth/token'))]);
        $this->expectException(ApiConnectionException::class);
        $client->bids->get('BID-test-0001');
    }

    public function testNoRefreshWithoutARefreshToken(): void
    {
        $client = $this->oauth([self::expired()], ['oauthClientId' => 'app_123']);
        $this->expectException(AuthenticationException::class);
        try {
            $client->bids->get('BID-test-0001');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testNoRefreshWithoutAClientId(): void
    {
        $client = $this->oauth([self::expired()], ['refreshToken' => 'rt_old']);
        $this->expectException(AuthenticationException::class);
        try {
            $client->bids->get('BID-test-0001');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testAPartnerNeverRefreshes(): void
    {
        $client = $this->partner([self::expired()], ['refreshToken' => 'rt_old', 'oauthClientId' => 'app_123']);
        $this->expectException(AuthenticationException::class);
        try {
            $client->bids->get('BID-test-0001');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testAConnectedAppWriteIsSentAgainAfterARefresh(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::ok(['confirmation' => ['code' => 'cnf_test_1']], 202)]);
        $write = $client->bids->update('BID-test-0001', ['price' => 99]);

        self::assertTrue($write->staged);
        self::assertSame(['price' => 99], $this->body(0));
        self::assertSame(['price' => 99], $this->body(2));
        self::assertSame('Bearer at_new', $this->request(2)->getHeaderLine('Authorization'));
    }

    public function testTheTokenUrlKeepsTheBaseUrlsHostAndPort(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::ok(['bid' => self::bid()])], ['baseUrl' => 'http://localhost:8080/api/v1']);
        $client->bids->get('BID-test-0001');

        self::assertSame('http://localhost:8080/api/oauth/token', (string) $this->request(1)->getUri());
    }

    public function testASandboxClientRefreshesOnTheSandboxHost(): void
    {
        $client = $this->app([self::expired(), self::tokens(), self::ok(['bid' => self::bid()])], ['market' => 'hk', 'environment' => 'sandbox']);
        $client->bids->get('BID-test-0001');

        self::assertSame('https://hk.crusandbox.com/api/v1/bids/BID-test-0001', (string) $this->request(0)->getUri());
        self::assertSame('https://hk.crusandbox.com/api/oauth/token', (string) $this->request(1)->getUri());
    }
}
