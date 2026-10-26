<?php

declare(strict_types=1);

namespace CruWorldWine;

use CruWorldWine\Exception\CruException;
use CruWorldWine\Internal\Markets;

/**
 * A market of the Cru Trading API: its code, name and currency, and its base URL in each
 * environment. Every amount on a market's host is in its currency.
 */
final class MarketInfo
{
    /** @var array<string, MarketInfo>|null */
    private static ?array $all = null;

    /**
     * @param string $code the market's code, such as uk: the first label of its host
     * @param string $name such as United Kingdom
     * @param string $currency the ISO 4217 code of its currency, such as GBP
     * @param string $symbol the currency's symbol, as Cru's website shows it, such as £
     * @param string $liveUrl its base URL in the live environment
     * @param string $sandboxUrl its base URL in the sandbox
     */
    private function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $currency,
        public readonly string $symbol,
        public readonly string $liveUrl,
        public readonly string $sandboxUrl,
    ) {
    }

    /**
     * Every market this version of the SDK knows, by code, in the API's order.
     *
     * @return array<string, MarketInfo>
     */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (Markets::ALL as $code => $market) {
                self::$all[$code] = new self(
                    $market['code'],
                    $market['name'],
                    $market['currency'],
                    $market['symbol'],
                    $market['liveUrl'],
                    $market['sandboxUrl'],
                );
            }
        }

        return self::$all;
    }

    /**
     * The market with this code, in any case; null for a code this version of the SDK does not know.
     */
    public static function lookup(string $code): ?self
    {
        return self::all()[strtolower($code)] ?? null;
    }

    /**
     * The market's base URL in an environment.
     *
     * @param Environment|string $environment live or sandbox, in any case
     *
     * @throws CruException for another environment
     */
    public function url(Environment|string $environment): string
    {
        if (is_string($environment)) {
            $environment = Environment::tryFrom(strtolower($environment))
                ?? throw new CruException('environment must be live or sandbox, not "' . $environment . '".');
        }

        return match ($environment) {
            Environment::Live => $this->liveUrl,
            Environment::Sandbox => $this->sandboxUrl,
        };
    }
}
