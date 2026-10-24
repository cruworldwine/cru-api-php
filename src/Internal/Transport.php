<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

use CruWorldWine\Client;
use CruWorldWine\Exception\ApiConnectionException;
use CruWorldWine\Exception\ApiStatusException;
use CruWorldWine\Exception\ApiTimeoutException;
use CruWorldWine\Exception\AuthenticationException;
use CruWorldWine\Exception\CruException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a request with the credentials, retries what is safe to retry, refreshes an expired access
 * token, and turns every failure into a CruException.
 *
 * @internal
 */
final class Transport
{
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private const MAX_BACKOFF = 8.0;

    private const MAX_WAIT = 30.0;

    private const CURLE_OPERATION_TIMEDOUT = 28;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    /** @var (\Closure(array<array-key, mixed>): void)|null */
    private ?\Closure $onTokenRefresh;

    /**
     * @param (callable(array<array-key, mixed>): void)|null $onTokenRefresh
     * @param (callable(float): void)|null $sleep
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUrl,
        private readonly ?string $clientKey,
        private readonly ?string $clientSecret,
        private ?string $accessToken,
        private ?string $refreshToken,
        private readonly ?string $oauthClientId,
        private readonly ?string $oauthClientSecret,
        ?callable $onTokenRefresh,
        private readonly float $timeout,
        private readonly int $maxRetries,
        ?callable $sleep,
    ) {
        $this->onTokenRefresh = $onTokenRefresh === null ? null : $onTokenRefresh(...);
        $this->sleep = $sleep === null
            ? static function (float $seconds): void {
                usleep((int) round($seconds * 1_000_000));
            }
            : $sleep(...);
    }

    public function isPartner(): bool
    {
        return $this->clientKey !== null && $this->clientSecret !== null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed>|null $body
     *
     * @throws CruException
     */
    public function send(string $method, string $path, array $query, ?array $body, string $accept = 'application/json'): ResponseInterface
    {
        $method = strtoupper($method);
        $write = $method !== 'GET' && $method !== 'HEAD';
        // A partner's write needs a reference. Made once here and sent on every retry, it is what
        // makes the retries safe: the API never acts twice on one reference.
        if ($write && $this->isPartner()) {
            $body ??= [];
            if (($body['reference'] ?? null) === null) {
                $body['reference'] = self::reference();
            }
        }
        $queryString = Query::encode($query);
        $url = $this->baseUrl . $path . ($queryString === '' ? '' : '?' . $queryString);
        $payload = $body === null ? null : Json::encodeBody($body);

        // A connected app's write is staged without a reference, so it is never retried.
        $retryable = !$write || $this->isPartner();
        $refreshed = false;
        $attempt = 0;
        while (true) {
            $response = $this->attempt($method, $url, $this->options($payload, $accept));
            if (!$response instanceof ResponseInterface) {
                if ($retryable && $attempt < $this->maxRetries) {
                    $attempt++;
                    ($this->sleep)($this->backoff($attempt, null, []));
                    continue;
                }
                throw self::connectionError($response);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $response;
            }
            $raw = self::body($response);
            $envelope = Json::decodeObject($raw);
            if ($status === 401 && !$refreshed && $this->canRefresh()) {
                $this->refresh();
                $refreshed = true;
                continue;
            }
            $inProgress = $status === 409 && self::firstErrorCode($envelope) === 'REQUEST_IN_PROGRESS';
            if ($retryable && $attempt < $this->maxRetries && ($inProgress || in_array($status, self::RETRY_STATUSES, true))) {
                $attempt++;
                ($this->sleep)($this->backoff($attempt, $response, $envelope));
                continue;
            }

            throw ApiStatusException::create($status, $envelope, self::headers($response), $raw);
        }
    }

    /**
     * The response body, read in full.
     *
     * @throws ApiConnectionException
     */
    public static function body(ResponseInterface $response): string
    {
        try {
            return (string) $response->getBody();
        } catch (\RuntimeException $e) {
            throw new ApiConnectionException('The response could not be read: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, string> by lower-case name, repeated values comma-joined
     */
    public static function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'partner' => $this->isPartner(),
            'maxRetries' => $this->maxRetries,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return ResponseInterface|\Throwable the response, or the failure that prevented one
     */
    private function attempt(string $method, string $url, array $options): ResponseInterface|\Throwable
    {
        try {
            return $this->http->request($method, $url, $options);
        } catch (RequestException $e) {
            return $e->getResponse() ?? $e;
        } catch (\InvalidArgumentException $e) {
            // Guzzle's own, or PSR-7's for a malformed URI: a bad request, so not one to retry.
            throw new CruException('The request could not be built: ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            return $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(?string $payload, string $accept): array
    {
        $headers = ['Accept' => $accept, 'User-Agent' => Client::USER_AGENT];
        $key = $this->clientKey;
        $secret = $this->clientSecret;
        if ($key !== null && $secret !== null) {
            $headers['CLIENT-KEY'] = $key;
            $headers['CLIENT-SECRET'] = $secret;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }
        $options = [
            // Redirects are not followed: they would carry the credentials to another host.
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['body'] = $payload;
        }
        $options['headers'] = $headers;

        return $options;
    }

    private function canRefresh(): bool
    {
        return !$this->isPartner() && $this->refreshToken !== null && $this->oauthClientId !== null;
    }

    /**
     * Exchanges the refresh token for a new access token, once, and stores both: a refresh token
     * works once, so the new one replaces it.
     *
     * @throws CruException
     */
    private function refresh(): void
    {
        $form = [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $this->refreshToken,
            'client_id' => (string) $this->oauthClientId,
        ];
        if ($this->oauthClientSecret !== null) {
            $form['client_secret'] = $this->oauthClientSecret;
        }
        $response = $this->attempt('POST', self::tokenUrl($this->baseUrl), [
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'form_params' => $form,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => Client::USER_AGENT],
        ]);
        if (!$response instanceof ResponseInterface) {
            throw self::connectionError($response);
        }
        $tokens = Json::decodeObject(self::body($response));
        $accessToken = $tokens['access_token'] ?? null;
        if ($response->getStatusCode() !== 200 || !is_string($accessToken) || $accessToken === '') {
            $description = $tokens['error_description'] ?? null;
            $message = is_string($description) && $description !== '' ? $description : 'The access token could not be refreshed.';
            // The raw body is left out: a token response must never end up in a log.
            throw new AuthenticationException($response->getStatusCode(), ['message' => $message], self::headers($response));
        }
        $this->accessToken = $accessToken;
        $refreshToken = $tokens['refresh_token'] ?? null;
        if (is_string($refreshToken) && $refreshToken !== '') {
            $this->refreshToken = $refreshToken;
        }
        if ($this->onTokenRefresh !== null) {
            ($this->onTokenRefresh)($tokens);
        }
    }

    /**
     * {scheme}://{host}/api/oauth/token for the base URL's host.
     */
    public static function tokenUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . '/api/oauth/token';
    }

    /**
     * @param array<array-key, mixed> $envelope
     */
    private function backoff(int $attempt, ?ResponseInterface $response, array $envelope): float
    {
        $wait = null;
        if ($response !== null) {
            $header = trim($response->getHeaderLine('Retry-After'));
            if (preg_match('/^[0-9]+$/D', $header) === 1) {
                $wait = (float) $header;
            }
            $details = self::firstError($envelope)['details'] ?? null;
            $retryAfter = is_array($details) ? ($details['retry_after'] ?? null) : null;
            if (is_int($retryAfter) || is_float($retryAfter)) {
                $wait = (float) $retryAfter;
            }
        }
        if ($wait === null) {
            $jitter = 0.75 + mt_rand() / mt_getrandmax() / 2;
            $wait = min(self::MAX_BACKOFF, 0.5 * 2 ** ($attempt - 1)) * $jitter;
        }

        return max(0.0, min($wait, self::MAX_WAIT));
    }

    private static function reference(): string
    {
        return 'sdk_' . bin2hex(random_bytes(16));
    }

    /**
     * @param array<array-key, mixed> $envelope
     *
     * @return array<array-key, mixed>
     */
    private static function firstError(array $envelope): array
    {
        $errors = $envelope['errors'] ?? null;
        $first = is_array($errors) ? ($errors[0] ?? null) : null;

        return is_array($first) ? $first : [];
    }

    /**
     * @param array<array-key, mixed> $envelope
     */
    private static function firstErrorCode(array $envelope): ?string
    {
        $code = self::firstError($envelope)['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    private static function connectionError(\Throwable $e): ApiConnectionException
    {
        $context = $e instanceof ConnectException || $e instanceof RequestException ? $e->getHandlerContext() : [];
        $message = $e->getMessage();
        $timedOut = ($context['errno'] ?? null) === self::CURLE_OPERATION_TIMEDOUT
            || stripos($message, 'timed out') !== false
            || stripos($message, 'timeout') !== false;
        if ($timedOut) {
            return new ApiTimeoutException('The API did not answer in time: ' . $message, 0, $e);
        }

        return new ApiConnectionException('The API could not be reached: ' . $message, 0, $e);
    }
}
