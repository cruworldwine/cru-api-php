<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Model;
use CruWorldWine\Models\MarketWine;
use CruWorldWine\Models\OwnBid;
use CruWorldWine\Models\PortfolioWine;
use CruWorldWine\Models\Sale;
use CruWorldWine\Models\WarehouseAddress;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModelTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function portfolioWine(): array
    {
        return [
            'lwin18' => self::LWIN,
            'wine_name' => 'Domaine Fictif 2015',
            'vintage' => 2015,
            'pack_size' => '6x75cl',
            'product_url' => null,
            'currency' => 'GBP',
            'total_quantity' => 3,
            'market_price' => 900,
            'lowest_offer_price' => 950.25,
            'highest_bid_price' => null,
            'portfolio' => [[
                'portfolio_id' => 'PF-test-1',
                'stock_type' => 'cru',
                'stock_status' => 'in_stock',
                'duty_paid' => false,
                'condition_status' => 'verified',
                'condition_text' => null,
                'is_listed' => true,
                'pricing' => ['cost' => 800, 'listing_price' => 1000, 'commission_percent' => 10, 'commission' => 100, 'settlement_fee' => 25, 'net_proceeds' => 875],
                'photo_urls' => [],
                'quantity' => 3,
                'warehouse' => ['location_type' => 'cru', 'current_location' => 'Example Bond', 'current_location_code' => 'UK_DRAK', 'deliverable_warehouse_code' => 'UK_DRAK'],
            ]],
        ];
    }

    public function testNestedModelsAndListsAreHydrated(): void
    {
        $wine = PortfolioWine::fromArray(self::portfolioWine());

        self::assertSame(900.0, $wine->market_price);
        self::assertSame(950.25, $wine->lowest_offer_price);
        self::assertNull($wine->highest_bid_price);
        self::assertNotNull($wine->portfolio);
        $line = $wine->portfolio[0];
        self::assertSame('PF-test-1', $line->portfolio_id);
        self::assertTrue($line->is_listed);
        self::assertSame(875.0, $line->pricing?->net_proceeds);
        self::assertSame('UK_DRAK', $line->warehouse?->current_location_code);
        self::assertSame([], $line->photo_urls);
    }

    public function testUnknownFieldsAreKept(): void
    {
        $raw = self::bid(['brand_new_field' => ['nested' => [1, 2]], 'another' => null]);
        $bid = OwnBid::fromArray($raw);

        self::assertSame(['nested' => [1, 2]], $bid['brand_new_field']);
        self::assertSame(['nested' => [1, 2]], $bid->get('brand_new_field'));
        self::assertTrue($bid->has('brand_new_field'));
        self::assertTrue($bid->has('another'));
        self::assertFalse(isset($bid['another']));
        self::assertTrue(isset($bid['brand_new_field']));
        self::assertSame('fallback', $bid->get('never_sent', 'fallback'));
        self::assertNull($bid->get('another', 'fallback'));
        self::assertNull($bid['never_sent']);
        self::assertSame($raw, $bid->toArray());
    }

    public function testUnknownEnumValuesAreKeptAsStrings(): void
    {
        $bid = OwnBid::fromArray(self::bid(['status' => 'paused_by_cru', 'currency' => 'CHF', 'market' => 'ch']));

        self::assertSame('paused_by_cru', $bid->status);
        self::assertSame('CHF', $bid->currency);
        self::assertSame('ch', $bid->market);
    }

    public function testMissingFieldsAreNull(): void
    {
        $bid = OwnBid::fromArray(['code' => 'BID-test-0001']);

        self::assertSame('BID-test-0001', $bid->code);
        self::assertNull($bid->price);
        self::assertNull($bid->order_numbers);
        self::assertNull($bid->renewable);
    }

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function coercions(): iterable
    {
        yield 'int price becomes a float' => ['price', 1200, 1200.0];
        yield 'decimal string price becomes a float' => ['price', '1200.50', 1200.5];
        yield 'word price is null' => ['price', 'twelve', null];
        yield 'exponent string price is null' => ['price', '1e3', null];
        yield 'bool price is null' => ['price', true, null];
        yield 'list price is null' => ['price', [1200], null];
        yield 'integral float quantity becomes an int' => ['quantity', 3.0, 3];
        yield 'fractional quantity is null' => ['quantity', 3.5, null];
        yield 'digit string quantity becomes an int' => ['quantity', '3', 3];
        yield 'zero-padded quantity is null' => ['quantity', '03', null];
        yield 'bool quantity is null' => ['quantity', false, null];
        yield 'int code becomes a string' => ['code', 12345, '12345'];
        yield 'float code is null' => ['code', 1.5, null];
        yield 'bool code is null' => ['code', true, null];
        yield 'object code is null' => ['code', ['a' => 1], null];
        yield 'int boolean is null' => ['renewable', 1, null];
        yield 'string boolean is null' => ['renewable', 'true', null];
        yield 'string list is null' => ['order_numbers', 'ORD-1', null];
        yield 'object instead of list is null' => ['order_numbers', ['a' => 'ORD-1'], null];
        yield 'list of ints becomes strings' => ['order_numbers', [100001, 'ORD-2'], ['100001', 'ORD-2']];
        yield 'list with a null item is null' => ['order_numbers', ['ORD-1', null], null];
        yield 'list with an object item is null' => ['order_numbers', [['x' => 1]], null];
    }

    #[DataProvider('coercions')]
    public function testValuesOfTheWrongTypeNeverThrow(string $field, mixed $sent, mixed $expected): void
    {
        $raw = self::bid([$field => $sent]);
        $bid = OwnBid::fromArray($raw);

        self::assertSame($expected, $bid->{$field});
        self::assertSame($sent, $bid[$field]);
        self::assertSame($raw, $bid->toArray());
    }

    public function testWrongTypedNestedValuesNeverThrow(): void
    {
        $wine = MarketWine::fromArray([
            'lwin18' => self::LWIN,
            'bids' => [['code' => 'B1', 'requirements' => 'none'], 'junk'],
            'offers' => ['code' => 'not a list'],
            'last_trade' => [1, 2, 3],
        ]);

        self::assertNull($wine->bids);
        self::assertNull($wine->offers);
        self::assertNull($wine->last_trade);
        self::assertSame('junk', $wine['bids'][1]);

        $wine = MarketWine::fromArray(['bids' => [['code' => 'B1', 'requirements' => 'none', 'price' => '1.5']]]);
        self::assertNotNull($wine->bids);
        self::assertSame('B1', $wine->bids[0]->code);
        self::assertNull($wine->bids[0]->requirements);
        self::assertSame(1.5, $wine->bids[0]->price);
    }

    public function testAnEmptyArrayIsAnEmptyObject(): void
    {
        $wine = MarketWine::fromArray(['last_trade' => []]);

        self::assertNotNull($wine->last_trade);
        self::assertNull($wine->last_trade->price);
    }

    public function testNullableStringsTakeOnlyStringsAndKeepTheRawValue(): void
    {
        $sale = Sale::fromArray(['reference' => ['po' => 7], 'quantity' => 1]);
        $address = WarehouseAddress::fromArray(['region' => 42, 'city' => null]);

        self::assertNull($sale->reference);
        self::assertSame(['po' => 7], $sale['reference']);
        self::assertSame('42', $address->region);
        self::assertNull($address->city);
    }

    public function testModelsRoundTripThroughToArray(): void
    {
        $raw = self::portfolioWine() + ['brand_new' => ['x' => true]];
        $wine = PortfolioWine::fromArray($raw);
        $again = PortfolioWine::fromArray($wine->toArray());

        self::assertSame($raw, $again->toArray());
        self::assertEquals($wine, $again);
        self::assertNotNull($wine->portfolio);
        self::assertSame($raw['portfolio'][0], $wine->portfolio[0]->toArray());
    }

    public function testModelsRoundTripThroughJson(): void
    {
        $raw = self::portfolioWine() + ['brand_new' => 'kept'];
        $wine = PortfolioWine::fromArray($raw);

        self::assertSame($raw, $wine->jsonSerialize());
        self::assertSame($raw, json_decode(json_encode($wine, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testModelsAreReadOnly(): void
    {
        $bid = OwnBid::fromArray(self::bid());
        try {
            $bid['code'] = 'other';
            self::fail('No exception');
        } catch (\LogicException) {
        }
        try {
            unset($bid['code']);
            self::fail('No exception');
        } catch (\LogicException) {
        }
        $this->expectException(\Error::class);
        $bid->code = 'other';
    }

    /**
     * @return iterable<string, array{class-string<Model>}>
     */
    public static function models(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Models/*.php') ?: [] as $file) {
            $class = 'CruWorldWine\\Models\\' . basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * @param class-string<Model> $class
     */
    #[DataProvider('models')]
    public function testEveryModelSurvivesAnyValue(string $class): void
    {
        $fields = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        self::assertNotEmpty($fields);
        $samples = [null, 'text', '', '12', '1.5', 7, -0, 1.25, 3.0, true, false, [], [1, 'a', null], ['k' => 'v'], [['k' => 'v']], [[]]];
        foreach ($samples as $sample) {
            $raw = array_fill_keys($fields, $sample) + ['unknown_' . count($fields) => $sample];
            $model = $class::fromArray($raw);
            self::assertSame($raw, $model->toArray());
            foreach ($fields as $field) {
                $value = $model->{$field};
                self::assertTrue($value === null || $sample !== null, "$class::\$$field from null");
            }
        }
        self::assertSame([], $class::fromArray([])->toArray());
    }

    /**
     * @param class-string<Model> $class
     */
    #[DataProvider('models')]
    public function testNoModelAllowsDynamicProperties(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        while ($reflection !== false) {
            self::assertSame([], $reflection->getAttributes(\AllowDynamicProperties::class), $reflection->getName());
            $reflection = $reflection->getParentClass();
        }
    }
}
