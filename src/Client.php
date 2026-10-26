<?php

declare(strict_types=1);

namespace CruWorldWine;

use CruWorldWine\Exception\CruException;
use CruWorldWine\Internal\Caller;
use CruWorldWine\Internal\Markets;
use CruWorldWine\Internal\Transport;
use GuzzleHttp\ClientInterface;

/**
 * A client for the Cru Trading API.
 *
 * Authenticate with a partner credential (clientKey and clientSecret), whose writes run at once,
 * or as a connected app with an OAuth access token, whose writes are staged for the customer to
 * confirm. Give refreshToken and oauthClientId as well and an expired access token is refreshed
 * once per call, automatically; onTokenRefresh is then called with the token response, and its
 * new refresh token must be stored, since a refresh token works once.
 *
 * What is not given comes from the environment: CRU_CLIENT_KEY, CRU_CLIENT_SECRET,
 * CRU_ACCESS_TOKEN, CRU_MARKET, CRU_ENVIRONMENT and CRU_BASE_URL.
 *
 * Pass the constructor's arguments by name: their order is not part of the API.
 */
class Client extends BaseClient
{
    public const VERSION = '0.1.0';

    public const USER_AGENT = 'cruworldwine-php/' . self::VERSION;

    private readonly Transport $transport;

    private readonly ?string $marketCode;

    /**
     * @param string|null $clientKey a partner credential's key, sent as CLIENT-KEY
     * @param string|null $clientSecret a partner credential's secret, sent as CLIENT-SECRET
     * @param string|null $accessToken a connected app's OAuth access token, sent as a bearer token
     * @param string|null $refreshToken the refresh token that came with the access token
     * @param string|null $oauthClientId the connected app's OAuth client id, needed to refresh
     * @param string|null $oauthClientSecret the connected app's OAuth client secret, for a confidential client
     * @param (callable(array<array-key, mixed>): void)|null $onTokenRefresh called with the token
     *     response after a refresh; store its refresh_token
     * @param string|null $market uk, us, hk, sg, eu, ae or cn: https://{market}.cruworldwine.com/api/v1. Default uk.
     * @param Environment|string|null $environment live (the default) or sandbox, in any case: the sandbox
     *     is https://{market}.crusandbox.com/api/v1, for building and testing with sandbox credentials
     * @param string|null $baseUrl the API's base URL, in place of the market's in the environment
     * @param float $timeout seconds to wait for a connection, and for an answer
     * @param int $maxRetries how many times a failed call that is safe to retry is retried
     * @param ClientInterface|null $httpClient the Guzzle client to send with
     * @param (callable(float): void)|null $sleep waits that many seconds between retries; usleep by default
     *
     * @throws CruException when neither a key pair nor an access token is given, or an option is invalid
     */
    public function __construct(
        ?string $clientKey = null,
        ?string $clientSecret = null,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?string $oauthClientId = null,
        ?string $oauthClientSecret = null,
        ?callable $onTokenRefresh = null,
        ?string $market = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?ClientInterface $httpClient = null,
        ?callable $sleep = null,
        Environment|string|null $environment = null,
    ) {
        $clientKey = self::option($clientKey, null);
        $clientSecret = self::option($clientSecret, null);
        $accessToken = self::option($accessToken, null);
        // The environment fills in only when neither kind of credential is given, so an explicit
        // access token is never turned into a partner credential by keys left in the environment.
        if ($clientKey === null && $clientSecret === null && $accessToken === null) {
            $clientKey = self::option(null, 'CRU_CLIENT_KEY');
            $clientSecret = self::option(null, 'CRU_CLIENT_SECRET');
            $accessToken = self::option(null, 'CRU_ACCESS_TOKEN');
        }
        if (($clientKey === null || $clientSecret === null) && $accessToken === null) {
            throw new CruException('Give clientKey and clientSecret, or accessToken (or set CRU_CLIENT_KEY and CRU_CLIENT_SECRET, or CRU_ACCESS_TOKEN).');
        }
        if ($maxRetries < 0) {
            throw new CruException('maxRetries cannot be negative.');
        }
        if (!($timeout > 0)) {
            throw new CruException('timeout must be more than 0 seconds.');
        }

        $environment = self::environment($environment);
        $market = self::option($market, 'CRU_MARKET');
        $baseUrl = self::option($baseUrl, 'CRU_BASE_URL');
        if ($baseUrl === null) {
            $market ??= 'uk';
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $market) !== 1) {
                throw new CruException('market must be a market code such as uk, not "' . $market . '".');
            }
            $baseUrl = str_replace('{market}', strtolower($market), Markets::ENVIRONMENTS[$environment->value]);
        }
        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new CruException('baseUrl must be an http or https URL, such as https://uk.cruworldwine.com/api/v1.');
        }
        $this->marketCode = self::marketOfHost($parts['host']) ?? $market;

        $this->transport = new Transport(
            $httpClient ?? new \GuzzleHttp\Client(),
            $baseUrl,
            $clientKey,
            $clientSecret,
            $accessToken,
            self::option($refreshToken, null),
            self::option($oauthClientId, null),
            self::option($oauthClientSecret, null),
            $onTokenRefresh,
            $timeout,
            $maxRetries,
            $sleep,
        );
        parent::__construct(new Caller($this->transport));
    }

    /**
     * True for a partner credential, whose writes run at once; false for a connected app.
     */
    public function isPartner(): bool
    {
        return $this->transport->isPartner();
    }

    public function getBaseUrl(): string
    {
        return $this->transport->getBaseUrl();
    }

    /**
     * The market the client calls: the one it was built for or, given a base URL, the market whose
     * host that is (in either environment), else the market given. Null when there is none, or it
     * is not a market this version of the SDK knows.
     */
    public function getMarketInfo(): ?MarketInfo
    {
        return $this->marketCode === null ? null : MarketInfo::lookup($this->marketCode);
    }

    /**
     * The access token in use: after a refresh, the new one.
     */
    public function getAccessToken(): ?string
    {
        return $this->transport->getAccessToken();
    }

    /**
     * The refresh token in use: after a refresh, the new one.
     */
    public function getRefreshToken(): ?string
    {
        return $this->transport->getRefreshToken();
    }

    /**
     * Calls any endpoint, even one this SDK does not know yet, and returns the answer as it came.
     *
     * Errors throw and retries happen as for every other call, and a partner's write gets a
     * reference when it has none. Null values are left out of the query; the body is sent as given.
     *
     * @param string $method such as GET or POST
     * @param string $path the path under the base URL, such as /bids
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed>|null $body
     *
     * @throws CruException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        return $this->caller->raw($method, $path, $query, $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport];
    }

    private static function environment(Environment|string|null $value): Environment
    {
        if ($value instanceof Environment) {
            return $value;
        }
        $value = self::option($value, 'CRU_ENVIRONMENT') ?? Environment::Live->value;

        return Environment::tryFrom(strtolower($value))
            ?? throw new CruException('environment must be live or sandbox, not "' . $value . '".');
    }

    /**
     * The host's first label when the host is that market's in one of the environments.
     */
    private static function marketOfHost(string $host): ?string
    {
        $host = strtolower($host);
        $label = strstr($host, '.', true);
        if ($label === false || $label === '') {
            return null;
        }
        foreach (Markets::ENVIRONMENTS as $template) {
            if (parse_url(str_replace('{market}', $label, $template), PHP_URL_HOST) === $host) {
                return $label;
            }
        }

        return null;
    }

    private static function option(?string $value, ?string $env): ?string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        if ($env === null) {
            return null;
        }
        foreach ([getenv($env), $_ENV[$env] ?? null, $_SERVER[$env] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
