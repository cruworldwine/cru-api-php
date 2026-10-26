<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Environment;
use CruWorldWine\Exception\CruException;
use CruWorldWine\Internal\Markets;
use CruWorldWine\MarketInfo;

final class MarketInfoTest extends TestCase
{
    public function testEveryMarketByCodeInTheApisOrder(): void
    {
        $all = MarketInfo::all();

        self::assertSame(['uk', 'us', 'hk', 'sg', 'eu', 'ae', 'cn'], array_keys($all));
        foreach ($all as $code => $market) {
            self::assertSame($code, $market->code);
            self::assertSame('https://' . $code . '.cruworldwine.com/api/v1', $market->liveUrl);
            self::assertSame('https://' . $code . '.crusandbox.com/api/v1', $market->sandboxUrl);
        }
    }

    public function testAMarketsFields(): void
    {
        $uk = MarketInfo::lookup('uk');

        self::assertNotNull($uk);
        self::assertSame('uk', $uk->code);
        self::assertSame('United Kingdom', $uk->name);
        self::assertSame('GBP', $uk->currency);
        self::assertSame('£', $uk->symbol);
        self::assertSame('https://uk.cruworldwine.com/api/v1', $uk->liveUrl);
        self::assertSame('https://uk.crusandbox.com/api/v1', $uk->sandboxUrl);
    }

    public function testLookupIgnoresCase(): void
    {
        self::assertSame('HKD', MarketInfo::lookup('HK')?->currency);
        self::assertSame(MarketInfo::lookup('hk'), MarketInfo::lookup('Hk'));
    }

    public function testLookupOfAnUnknownCodeIsNull(): void
    {
        self::assertNull(MarketInfo::lookup('fr'));
        self::assertNull(MarketInfo::lookup(''));
    }

    public function testTheUrlInEachEnvironment(): void
    {
        $hk = MarketInfo::lookup('hk');

        self::assertNotNull($hk);
        self::assertSame('https://hk.cruworldwine.com/api/v1', $hk->url(Environment::Live));
        self::assertSame('https://hk.crusandbox.com/api/v1', $hk->url(Environment::Sandbox));
        self::assertSame('https://hk.cruworldwine.com/api/v1', $hk->url('live'));
        self::assertSame('https://hk.crusandbox.com/api/v1', $hk->url('SANDBOX'));
    }

    public function testTheUrlOfAnUnknownEnvironmentIsRefused(): void
    {
        $this->expectException(CruException::class);
        MarketInfo::lookup('uk')?->url('staging');
    }

    public function testEachEnvironmentHasItsTemplate(): void
    {
        self::assertSame(array_column(Environment::cases(), 'value'), array_keys(Markets::ENVIRONMENTS));
        foreach (MarketInfo::all() as $market) {
            foreach (Environment::cases() as $environment) {
                self::assertSame(str_replace('{market}', $market->code, Markets::ENVIRONMENTS[$environment->value]), $market->url($environment));
            }
        }
    }

    public function testTheClientBuildsTheSameUrlAsTheTable(): void
    {
        foreach (MarketInfo::all() as $market) {
            foreach (Environment::cases() as $environment) {
                $client = $this->oauth([], ['market' => $market->code, 'environment' => $environment]);
                self::assertSame($market->url($environment), $client->getBaseUrl());
                self::assertSame($market, $client->getMarketInfo());
            }
        }
    }
}
