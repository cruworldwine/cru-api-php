<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Internal\Coerce;
use CruWorldWine\Internal\Json;
use CruWorldWine\Models\LastTrade;
use PHPUnit\Framework\Attributes\DataProvider;

final class CoerceTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function ints(): iterable
    {
        yield 'int' => [42, 42];
        yield 'negative' => [-7, -7];
        yield 'integral float' => [12.0, 12];
        yield 'negative zero float' => [-0.0, 0];
        yield 'float at 2^53' => [9007199254740992.0, 9007199254740992];
        yield 'float past 2^53' => [9007199254740994.0, null];
        yield 'fractional float' => [0.5, null];
        yield 'digit string' => ['2015', 2015];
        yield 'negative digit string' => ['-3', -3];
        yield 'zero string' => ['0', 0];
        yield 'eighteen digits' => ['999999999999999999', 999999999999999999];
        yield 'nineteen digits' => ['9999999999999999999', null];
        yield 'leading zero' => ['0750', null];
        yield 'plus sign' => ['+5', null];
        yield 'spaces' => [' 5', null];
        yield 'trailing newline' => ["5\n", null];
        yield 'decimal string' => ['5.0', null];
        yield 'exponent' => ['1e3', null];
        yield 'empty' => ['', null];
        yield 'bool' => [true, null];
        yield 'null' => [null, null];
        yield 'array' => [[1], null];
    }

    #[DataProvider('ints')]
    public function testInt(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, Coerce::int($value));
    }

    /**
     * @return iterable<string, array{mixed, ?float}>
     */
    public static function floats(): iterable
    {
        yield 'float' => [1.25, 1.25];
        yield 'int' => [1200, 1200.0];
        yield 'int at 2^53' => [9007199254740992, 9007199254740992.0];
        yield 'int past 2^53' => [9007199254740993, null];
        yield 'decimal string' => ['1200.50', 1200.5];
        yield 'integer string' => ['30780', 30780.0];
        yield 'negative string' => ['-0.5', -0.5];
        yield 'fifteen digits' => ['123456789.012345', 123456789.012345];
        yield 'sixteen digits' => ['1234567890.123456', null];
        yield 'no leading digit' => ['.5', null];
        yield 'trailing dot' => ['5.', null];
        yield 'comma decimal' => ['1,5', null];
        yield 'exponent' => ['1e3', null];
        yield 'word' => ['NaN', null];
        yield 'infinite float' => [INF, null];
        yield 'nan float' => [NAN, null];
        yield 'bool' => [false, null];
        yield 'null' => [null, null];
    }

    #[DataProvider('floats')]
    public function testFloat(mixed $value, ?float $expected): void
    {
        self::assertSame($expected, Coerce::float($value));
    }

    public function testString(): void
    {
        self::assertSame('a', Coerce::string('a'));
        self::assertSame('', Coerce::string(''));
        self::assertSame('-12', Coerce::string(-12));
        self::assertNull(Coerce::string(1.5));
        self::assertNull(Coerce::string(false));
        self::assertNull(Coerce::string(['a']));
        self::assertNull(Coerce::string(null));
    }

    public function testBool(): void
    {
        self::assertTrue(Coerce::bool(true));
        self::assertFalse(Coerce::bool(false));
        self::assertNull(Coerce::bool(1));
        self::assertNull(Coerce::bool('false'));
        self::assertNull(Coerce::bool(null));
    }

    public function testMapAndModel(): void
    {
        self::assertSame([], Coerce::map([]));
        self::assertSame(['a' => 1], Coerce::map(['a' => 1]));
        self::assertNull(Coerce::map([1, 2]));
        self::assertNull(Coerce::map('a'));
        self::assertInstanceOf(LastTrade::class, Coerce::model(['price' => 1], LastTrade::class));
        self::assertNull(Coerce::model([1], LastTrade::class));
        self::assertNull(Coerce::model(null, LastTrade::class));
    }

    public function testLists(): void
    {
        self::assertSame([], Coerce::listOf([], Coerce::string(...)));
        self::assertSame(['a', '1'], Coerce::listOf(['a', 1], Coerce::string(...)));
        self::assertNull(Coerce::listOf(['a', 1.5], Coerce::string(...)));
        self::assertNull(Coerce::listOf([1 => 'a'], Coerce::string(...)));
        self::assertNull(Coerce::listOf('a', Coerce::string(...)));
        self::assertSame([1, null, 'x'], Coerce::rawList([1, null, 'x']));
        self::assertNull(Coerce::rawList(['k' => 1]));
        self::assertNull(Coerce::modelList([['price' => 1], 'x'], LastTrade::class));
        self::assertCount(2, Coerce::modelList([['price' => 1], []], LastTrade::class) ?? []);
    }

    public function testBigIntegersInJsonStayStrings(): void
    {
        $decoded = Json::decode('{"id": 123456789012345678901234567890, "n": 5}');
        self::assertIsArray($decoded);
        self::assertSame('123456789012345678901234567890', $decoded['id']);
        self::assertNull(Coerce::int($decoded['id']));
        self::assertSame(5, $decoded['n']);
    }

    public function testJsonThatIsNotJsonDecodesToNull(): void
    {
        self::assertNull(Json::decode(''));
        self::assertNull(Json::decode('  '));
        self::assertNull(Json::decode('{"a":'));
        self::assertSame([], Json::decodeObject('"a string"'));
    }
}
