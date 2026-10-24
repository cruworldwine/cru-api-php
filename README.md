<p align="center">
  <a href="https://developers.cruworldwine.com">
    <img src="https://uk.cruworldwine.com/skin/frontend/cru/default/images/cru-world-wine-logo.svg" alt="Cru World Wine" width="200">
  </a>
</p>

# Cru Trading API for PHP

[![CI](https://github.com/cruworldwine/cru-api-php/actions/workflows/ci.yml/badge.svg)](https://github.com/cruworldwine/cru-api-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/cruworldwine/cru-api-php.svg)](https://packagist.org/packages/cruworldwine/cru-api-php)
[![PHP version](https://img.shields.io/packagist/dependency-v/cruworldwine/cru-api-php/php.svg)](https://packagist.org/packages/cruworldwine/cru-api-php)
[![License](https://img.shields.io/packagist/l/cruworldwine/cru-api-php.svg)](https://github.com/cruworldwine/cru-api-php/blob/main/LICENSE)

The PHP SDK for the [Cru Trading API](https://developers.cruworldwine.com), Cru World Wine's
partner REST API. Read Cru's live bid and offer books for every wine, manage your own bids and
offers, see your portfolio, purchases and sales, and trade: buy an offer or sell into a bid.

## Installation

```bash
composer require cruworldwine/cru-api-php
```

PHP 8.1 or later, and Guzzle 7.5 or later.

## Connecting

There are two ways to connect. Each answers on one market's host,
`https://{market}.cruworldwine.com/api/v1`, where market is `uk` (the default), `us`, `hk`, `sg`,
`eu`, `ae` or `cn`. A credential works on its own market only.

### With a partner credential

Cru issues a partner credential: a key (`cru_live_...`) and a secret, which is shown once. Keep
both out of source control. A partner credential's writes run at once.

```php
use CruWorldWine\Client;

$cru = new Client(clientKey: 'cru_live_...', clientSecret: $secret, market: 'uk');
```

### As a connected app (OAuth 2.0)

An app that acts for a Cru customer gets their tokens with the OAuth 2.0 authorization code flow
with PKCE (S256), at `/api/oauth/authorize` and `/api/oauth/token` on the market's host. The SDK
uses the access token and, given the refresh token and your app's client id, refreshes it once when
it expires (see [Refreshing an access token](#refreshing-an-access-token)). A connected app's
writes are staged: nothing happens until the customer confirms them.

```php
$app = new Client(accessToken: $tokens['access_token']);
```

### From the environment

Pass the constructor's arguments by name. Anything not given comes from the environment:
`CRU_CLIENT_KEY`, `CRU_CLIENT_SECRET`, `CRU_ACCESS_TOKEN`, `CRU_MARKET` and `CRU_BASE_URL`, so
`new Client()` alone works when they are set.

### Check the connection

```php
$client = $cru->ping()->client;
echo $client?->organisation, ' ', $client?->market, ' ', $client?->currency, "\n";
echo implode(', ', $client?->scopes ?? []), "\n";
```

### Every option

| Argument | Default | |
|---|---|---|
| `clientKey`, `clientSecret` | | A partner credential, sent as `CLIENT-KEY` and `CLIENT-SECRET`. |
| `accessToken` | | A connected app's OAuth access token, sent as `Authorization: Bearer`. |
| `refreshToken`, `oauthClientId`, `oauthClientSecret`, `onTokenRefresh` | | Refresh an expired access token; see below. |
| `market` | `uk` | `https://{market}.cruworldwine.com/api/v1`: uk, us, hk, sg, eu, ae or cn. |
| `baseUrl` | | Use this base URL instead of the market's. |
| `timeout` | `30.0` | Seconds to wait to connect, and for an answer. |
| `maxRetries` | `2` | How many times a call that is safe to retry is retried. |
| `httpClient` | a new Guzzle client | Any `GuzzleHttp\ClientInterface`. |

## Quick start

```php
foreach ($cru->market->listBids(['lwin' => ['123456720150600750'], 'limit' => 50])->autoPagingIterator() as $wine) {
    foreach ($wine->bids ?? [] as $bid) {
        echo $wine->wine_name, ': ', $bid->quantity, ' at ', $bid->price, ' ', $wine->currency, "\n";
    }
}

$write = $cru->bids->place(['lwin18' => '123456720150600750', 'quantity' => 6, 'price' => 1200.50]);
echo $write->result?->bid?->code, "\n";
```

## Calls

Every endpoint is a method on a resource of the client, named as in the API reference:
`$cru->warehouses`, `inbondWarehouses`, `wines`, `market`, `photos`, `bids`, `offers`,
`portfolio`, `trades`, `inbondAddresses` and `confirmations`; `ping()` is on the client itself.

Path parameters come first, by position; every other parameter goes in one array, named as in the
API. A list parameter takes a PHP list or a comma-separated string; null values are left out.

```php
$bid   = $cru->bids->get('BID-...');
$bids  = $cru->bids->list(['status' => ['live', 'matched'], 'limit' => 100]);
$write = $cru->bids->update('BID-...', ['price' => 1250]);
$offer = $cru->market->buyOffer('OFFER-...', ['quantity' => 1, 'max_price' => 1300]);
```

A parameter this version of the SDK does not know is sent as given, so a new one works before the
SDK is updated. `$cru->request('GET', '/new/endpoint', $query, $body)` calls an endpoint it does
not know at all, and returns a `CruWorldWine\Response` with `statusCode`, `headers`, `body`,
`rawBody`, `data` and `meta`.

### Pages

A list that pages returns a `CruWorldWine\Page`: iterate it (or read `->items`) for this page,
with `->limit`, `->offset`, `->total` and `->hasMore` from `meta.pagination`, and `->data` for the
whole data object. `->nextPage()` fetches the next page, or gives null after the last;
`->autoPagingIterator()` walks every item of every page, fetching each page as it gets there.

### Writes

A write returns a `CruWorldWine\Write`:

- `result`: what it did, as a model (`BidResult`, `OfferResult`, ...); null when it was staged.
- `staged` and `confirmation`: a connected app's write is staged, with nothing done yet;
  `confirmation->code` is what to confirm, and `confirmation->preview->summary` what to show.
- `replayed`: true when the API answered with the stored result of an earlier request with the
  same reference.
- `requestId`, `statusCode`, and `resultClass`, the model `result` is or would have been.

A partner credential's write must send a `reference`, its idempotency key. When you give none the
SDK makes one (`sdk_` and 32 hex digits) and sends that same one on every retry, so a retry can
never act twice. A connected app's writes carry no reference and are never retried.

```php
$app = new Client(accessToken: $token);
$write = $app->bids->place(['lwin18' => '123456720150600750', 'quantity' => 6, 'price' => 1200.50]);
if ($write->staged) {
    // Show $write->confirmation->preview->summary, and only on the customer's yes:
    $done = $app->confirmations->confirm($write->confirmation->code);
    $bid = $done->result; // a BidResult, chosen by the confirmed action
}
```

## Models

Every answer is a model with a typed, read-only property per field, named as the field is in the
API (`$bid->quantity_open`). The API adds fields and values over time, and the SDK never fails on
them:

- Every field sent is kept as it came, including ones this SDK does not know: read it with
  `$model['field']`, `$model->get('field', $default)` or `$model->toArray()`; `json_encode($model)`
  gives the same.
- A missing field is null. So is a field whose value is not of its documented type, which is
  never an error; the raw value is still in `toArray()`. An int is read as a float for a number
  field, an integral float or a plain digit string as an int, and an int as a string.
- Fields with a documented set of values (`status`, `currency`, ...) are plain strings, never PHP
  enums, so a new value is just a new string.

`Model::fromArray($array)` builds one, for tests say.

## Errors

Everything the SDK throws extends `CruWorldWine\Exception\CruException`:

- `ApiConnectionException`: no answer, and `ApiTimeoutException`, which extends it: no answer in time.
- `ApiStatusException`: the API answered with an error. By status: `BadRequestException` (400),
  `AuthenticationException` (401), `PermissionDeniedException` (403), `NotFoundException` (404),
  `MethodNotAllowedException` (405), `ConflictException` (409; with `PriceChangedException` and
  `RequestInProgressException` by code), `GoneException` (410), `UnprocessableEntityException`
  (422), `RateLimitException` (429) and `InternalServerException` (5xx).

```php
use CruWorldWine\Exception\PriceChangedException;
use CruWorldWine\Exception\UnprocessableEntityException;

try {
    $cru->market->buyOffer('OFFER-...', ['quantity' => 1, 'max_price' => 1300]);
} catch (PriceChangedException $e) {
    // The price moved: look again.
} catch (UnprocessableEntityException $e) {
    echo $e->getErrorCode(), ' ', $e->getField(), ': ', $e->getApiMessage(), ' (', $e->getRequestId(), ")\n";
}
```

Branch on `getStatusCode()` and `getErrorCode()`; `getApiMessage()` is for people. `getErrors()`
gives every entry, `getDetails()` the first one's details, `getRequestId()` what Cru support needs,
and `getBody()` and `getRawBody()` the answer.

## Retries

Reads, and a partner credential's writes, are retried up to `maxRetries` times on a connection
error or timeout, on 429, 500, 502, 503 and 504, and on 409 `REQUEST_IN_PROGRESS`. The wait is
0.5 s, doubling to at most 8 s, give or take 25%, or what `Retry-After` or the error's
`details.retry_after` asks for, up to 30 s. Redirects are not followed, so the credentials never
go to another host.

## Refreshing an access token

Give the refresh token and the app's client id, and a 401 refreshes the access token once and
sends the call again. A refresh token works once: store the new one `onTokenRefresh` gets.

```php
$app = new Client(
    accessToken: $tokens['access_token'],
    refreshToken: $tokens['refresh_token'],
    oauthClientId: 'your-client-id',
    onTokenRefresh: function (array $tokens): void {
        saveTokens($tokens['access_token'], $tokens['refresh_token']);
    },
);
```

A refused refresh throws `AuthenticationException`.

## Testing your code

Hand the client a Guzzle client over a `MockHandler`:

```php
$mock = new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Psr7\Response(200, [], json_encode(['status' => 'OK', 'data' => ['bid' => ['code' => 'BID-1']]])),
]);
$cru = new Client(clientKey: 'k', clientSecret: 's',
    httpClient: new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]));
```

## Running the tests

The tests need no credentials and make no network calls: every call goes through Guzzle's
`MockHandler`.

```bash
composer install
vendor/bin/phpunit                    # unit tests; the replay suite is skipped
CRU_CAPTURES=/path/to/run vendor/bin/phpunit --testsuite replay   # replays captured answers
vendor/bin/phpstan analyse            # static analysis at level max, as PHP 8.1 to 8.4
```

- `tests/Unit/`: authentication and the environment, query, path and body encoding, references
  kept across retries, retries and back-off, the OAuth refresh, every exception, each kind of
  answer (models, lists, pages, staged writes, confirmations, no content, photos), paging, and
  models given fields, values and types this version does not know.
- `tests/Replay/`: replays real API answers through the method that asks for each one and checks
  every typed field, nested ones included, against what the API sent. It runs when `CRU_CAPTURES`
  names a capture run, and is skipped otherwise. Captures hold customer data, so keep them out of
  the repository.

## Development

`src/BaseClient.php`, `src/Internal/Endpoints.php`, `src/Models/` and `src/Resources/` are
generated from the OpenAPI spec (`spec/openapi.yaml` is the copy they were generated from) and start
with `DO NOT EDIT`. Cru regenerates them from the API's own repository after each API change:

```bash
uv run --quiet --with pyyaml==6.0.3 python docs/api/tools/sdkgen/generate.py --php <this checkout>
```

The rest of `src/` is written by hand.

## License

Apache License 2.0. See [LICENSE](LICENSE).
