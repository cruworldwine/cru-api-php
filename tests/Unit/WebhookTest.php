<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Unit;

use CruWorldWine\Exception\CruException;
use CruWorldWine\Exception\WebhookVerificationException;
use CruWorldWine\Models\WebhookEvent;
use CruWorldWine\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Webhook::verify(), against signatures made by the API's own signer (Cru_Api_Model_Webhook_Signer).
 * The secrets are invented samples, not keys of any webhook.
 */
final class WebhookTest extends \PHPUnit\Framework\TestCase
{
    private const BODY = '{"id":"evt_6f1c0d2e9a7b4c3d5e8f9a0b","type":"webhook.test","api_version":"1",'
        . '"created_at":"2026-10-19T10:11:01Z","data":{"webhook_id":"whs_3f9a1c7e5b2d4086a1e9c4b7",'
        . '"message":"A test event from Cru. Answer with any 2xx status to show your endpoint works."}}';
    private const OLD = 'whsec_c2FtcGxlLXNlY3JldC1ub3QtYS1yZWFsLWtleQ==';
    private const NEW = 'whsec_c2Vjb25kLXNhbXBsZS1zZWNyZXQtbm90LXJlYWw=';
    private const OLD_SIG = 'v1,fpcbXyd+iRNLUrvep/lKQ+/nbKRqmRkE/jbH+gndpZc=';
    private const NEW_SIG = 'v1,I8G3oULkwTjh5XwYduia9NQKJWTNYCaQjyZJMJ+fotc=';
    private const AT = 1792404661;

    /**
     * @return array<string, string>
     */
    private static function headers(string $signature = self::OLD_SIG): array
    {
        return [
            'webhook-id' => 'evt_6f1c0d2e9a7b4c3d5e8f9a0b',
            'webhook-timestamp' => (string) self::AT,
            'webhook-signature' => $signature,
        ];
    }

    public function testAGenuineDeliveryReturnsItsEvent(): void
    {
        $event = Webhook::verify(self::BODY, self::headers(), self::OLD, self::AT);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('evt_6f1c0d2e9a7b4c3d5e8f9a0b', $event->id);
        self::assertSame('webhook.test', $event->type);
        self::assertSame('whs_3f9a1c7e5b2d4086a1e9c4b7', Webhook::testData($event)->webhook_id);
    }

    public function testHeadersInAnyCaseAndAsPsr7Lists(): void
    {
        $headers = [
            'Webhook-Id' => ['evt_6f1c0d2e9a7b4c3d5e8f9a0b'],
            'WEBHOOK-TIMESTAMP' => [(string) self::AT],
            'webhook-signature' => [self::OLD_SIG],
        ];

        self::assertSame('webhook.test', Webhook::verify(self::BODY, $headers, self::OLD, self::AT)->type);
    }

    public function testDuringARotationEitherSecretVerifies(): void
    {
        $headers = self::headers(self::NEW_SIG . ' ' . self::OLD_SIG);

        self::assertSame('webhook.test', Webhook::verify(self::BODY, $headers, self::NEW, self::AT)->type);
        self::assertSame('webhook.test', Webhook::verify(self::BODY, $headers, self::OLD, self::AT)->type);
    }

    public function testTheNewSecretAloneDoesNotVerifyAnOldSignature(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('No signature matches');
        Webhook::verify(self::BODY, self::headers(), self::NEW, self::AT);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a changed body' => [str_replace('webhook.test', 'sale.created', self::BODY), self::headers(), self::OLD, self::AT, 'No signature matches'];
        yield 'too old' => [self::BODY, self::headers(), self::OLD, self::AT + 301, 'too far from now'];
        yield 'too far ahead' => [self::BODY, self::headers(), self::OLD, self::AT - 301, 'too far from now'];
        yield 'no signature' => [self::BODY, self::headers(''), self::OLD, self::AT, 'required'];
        yield 'no id' => [self::BODY, ['webhook-id' => ''] + self::headers(), self::OLD, self::AT, 'required'];
        yield 'another version' => [self::BODY, self::headers(str_replace('v1,', 'v2,', self::OLD_SIG)), self::OLD, self::AT, 'No signature matches'];
        yield 'a signature of no shape' => [self::BODY, self::headers('fpcbXyd'), self::OLD, self::AT, 'No signature matches'];
        yield 'a timestamp that is not a number' => [self::BODY, ['webhook-timestamp' => 'soon'] + self::headers(), self::OLD, self::AT, 'not a Unix time'];
        yield 'a negative timestamp' => [self::BODY, ['webhook-timestamp' => '-1792404661'] + self::headers(), self::OLD, self::AT, 'not a Unix time'];
        yield 'a secret that is not base64' => [self::BODY, self::headers(), 'whsec_not base64!', self::AT, 'not whsec_ and a base64 key'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('refusals')]
    public function testRefuses(string $body, array $headers, string $secret, int $now, string $message): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage($message);
        Webhook::verify($body, $headers, $secret, $now);
    }

    public function testTheEdgesOfTheToleranceAndAWiderOne(): void
    {
        self::assertNotNull(Webhook::verify(self::BODY, self::headers(), self::OLD, self::AT + 300)->id);
        self::assertNotNull(Webhook::verify(self::BODY, self::headers(), self::OLD, self::AT - 300)->id);
        self::assertNotNull(Webhook::verify(self::BODY, self::headers(), self::OLD, self::AT + 3600, 7200)->id);
    }

    public function testItChecksAgainstTheCurrentTimeByDefault(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::BODY, self::headers(), self::OLD);
    }

    public function testASignedBodyThatIsNotAJsonObjectIsRefused(): void
    {
        $key = (string) base64_decode(substr(self::OLD, 6), true);
        foreach (['not json' => 'not JSON', '[1]' => 'not a JSON object', '"text"' => 'not a JSON object'] as $body => $message) {
            $signature = 'v1,' . base64_encode(hash_hmac('sha256', 'evt_6f1c0d2e9a7b4c3d5e8f9a0b.' . self::AT . '.' . $body, $key, true));
            try {
                Webhook::verify((string) $body, self::headers($signature), self::OLD, self::AT);
                self::fail("$body verified");
            } catch (WebhookVerificationException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testEventDataByType(): void
    {
        $sale = WebhookEvent::fromArray(['type' => 'sale.created', 'data' => ['sale_ref' => 'stpo-5531', 'sales' => [['sale_ref' => 'stpo-5531', 'trade_id' => '49780']]]]);
        $purchase = WebhookEvent::fromArray(['type' => 'purchase.created', 'data' => ['purchase_ref' => 'ord-90211', 'purchases' => [['purchase_ref' => 'ord-90211', 'quantity' => 6]]]]);

        $saleData = Webhook::saleCreated($sale);
        self::assertSame('stpo-5531', $saleData->sale_ref);
        self::assertSame('49780', $saleData->sales[0]->trade_id ?? null);

        $purchaseData = Webhook::purchaseCreated($purchase);
        self::assertSame('ord-90211', $purchaseData->purchase_ref);
        self::assertSame(6, $purchaseData->purchases[0]->quantity ?? null);

        $this->expectException(\InvalidArgumentException::class);
        Webhook::testData($purchase);
    }

    public function testTheExceptionIsACruException(): void
    {
        self::assertTrue(is_subclass_of(WebhookVerificationException::class, CruException::class));
    }
}
