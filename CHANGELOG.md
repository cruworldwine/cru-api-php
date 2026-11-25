# Changelog

Every notable change to `cruworldwine/cru-api-php` is listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html): while it is 0.x, a minor release may
change the public API, and each such change is listed under **Changed** or **Removed**.

## [Unreleased]

## [0.3.0] - 2026-11-25

Every change is an addition: code written for 0.2.0 works as before.

### Added

- Webhooks. `$client->webhooks`: `list()`, `create(['url' => ..., 'event_types' => [...]])`, whose
  answer carries the signing secret once, `get($id)`, `update($id, [...])`, `remove($id)`,
  `test($id)`, `rotateSecret($id)` and `retry($id)`. `$client->webhookDeliveries`: `list([...])`,
  paged, `get($id)`, with every attempt and the body sent, and `resend($id)`.
  `$client->webhookEvents`: `list(['after' => ..., 'type' => ..., 'limit' => ...])`, to catch up
  from the last event handled, and `resend($id)`. Each needs `webhooks:manage`, on a client key and
  secret.
- `Webhook::verify($body, $headers, $secret)`, which checks a delivery's Standard Webhooks
  signature (either one, during the day after a rotation) and its timestamp (within
  `Webhook::TOLERANCE`, five minutes), and returns its `Models\WebhookEvent`, or throws
  `Exception\WebhookVerificationException`. `Webhook::saleCreated()`, `purchaseCreated()` and
  `testData()` read its data by type.
- `$client->account->get()`, `GET /account`: the account's contact details and company users, its
  membership, and the figures on Cru's Account Summary page. Needs `account:read`, which a
  connected app gets only by asking for it by name.
- `image_url`, the wine's bottle photo, on every wine: market wines and their bid and offer details,
  your bids, portfolio and offers, the wine catalogue, purchases and sales.
- `purchase_ref` on each purchase and `sale_ref` and `trade_id` on each sale, and the
  `purchase_ref` and `sale_ref` filters on `trades->listPurchases()` and `trades->listSales()`,
  which take up to 50.
- `kind` (partner or customer) and `account_number` on `ping()`'s client, and `import` on a
  portfolio line: the wine list a line held outside Cru was uploaded from.

## [0.2.0] - 2026-10-26

### Added

- The sandbox, `https://{market}.crusandbox.com/api/v1`, for building and testing where no real
  money or wine changes hands: `new Client(environment: Environment::Sandbox)`, or `'sandbox'` in
  any case, or `CRU_ENVIRONMENT=sandbox`. Live stays the default, and a `baseUrl` still wins.
- The markets, so a program knows a market's currency without a call: `MarketInfo::all()`,
  `MarketInfo::lookup('hk')` and `$client->getMarketInfo()`, each with the market's code, name,
  currency, symbol, and live and sandbox URLs.

### Changed

- Requires `guzzlehttp/guzzle` 7.15.2 or later, up from 7.5: every earlier 7.x release has 2026
  security advisories, among them proxy credentials leaking and HTTPS proxies being downgraded.
- A `CRU_ENVIRONMENT` set to anything other than live or sandbox now throws `CruException` when a
  client is made.
- The package Composer installs holds only `src/`, `composer.json`, the README, this changelog and
  the licence: the tests, the copy of the API spec and the PHPStan and PHPUnit configurations stay
  in the repository.

## [0.1.0] - 2026-10-24

The first release: a client for every endpoint of the Cru Trading API v1.

- A partner credential, whose writes run at once, or a connected app's OAuth access token, whose
  writes are staged for the customer to confirm; an expired access token is refreshed once, and
  `onTokenRefresh` receives the new tokens.
- Typed models that keep fields and values this version does not know, pages, staged writes and
  confirmations.
- Partner writes carry an idempotency `reference`, made by the SDK when not given and kept across
  its retries. Reads and partner writes are retried on 429, 500, 502, 503 and 504, honouring
  `Retry-After`.
- An exception for each status and error code, such as `NotFoundException` and
  `PriceChangedException`.

[Unreleased]: https://github.com/cruworldwine/cru-api-php/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/cruworldwine/cru-api-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/cruworldwine/cru-api-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/cruworldwine/cru-api-php/releases/tag/v0.1.0
