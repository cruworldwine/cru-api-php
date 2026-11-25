<?php

declare(strict_types=1);

namespace CruWorldWine;

use CruWorldWine\Exception\WebhookVerificationException;
use CruWorldWine\Models\PurchaseCreatedData;
use CruWorldWine\Models\SaleCreatedData;
use CruWorldWine\Models\WebhookEvent;
use CruWorldWine\Models\WebhookTestData;

/**
 * Checking that a webhook delivery came from Cru, as Standard Webhooks v1 signs it.
 *
 * Cru signs every delivery: webhook-signature holds v1, and the base64 HMAC-SHA256 of
 * "{webhook-id}.{webhook-timestamp}.{body}", keyed with the base64-decoded part of the signing
 * secret after whsec_. For 24 hours after the secret is rotated there are two signatures, separated
 * by a space, and either is accepted.
 *
 * ```php
 * $body = file_get_contents('php://input'); // the raw bytes, before any JSON decoding
 * try {
 *     $event = Webhook::verify($body, getallheaders(), getenv('CRU_WEBHOOK_SECRET'));
 * } catch (WebhookVerificationException) {
 *     http_response_code(401);
 *     exit;
 * }
 * // Store $event->id and answer at once; do the work afterwards.
 * if ($event->type === 'sale.created') {
 *     $sale = Webhook::saleCreated($event);
 * }
 * ```
 */
final class Webhook
{
    /** How far a delivery's webhook-timestamp may be from now, either way, in seconds. */
    public const TOLERANCE = 300;

    private const SECRET_PREFIX = 'whsec_';

    private function __construct()
    {
    }

    /**
     * Check a webhook delivery's signature and return its event. Deduplicate on the event's id.
     *
     * @param string $body the request body exactly as it arrived, before any JSON decoding
     * @param array<string, string|list<string>> $headers the request's headers, in any case, as getallheaders() or a PSR-7 request's getHeaders() gives them
     * @param string $secret the webhook's signing_secret, whsec_ and a base64 key
     * @param int|null $now the Unix time to check the timestamp against, for tests; null is now
     * @param int $tolerance how old, or how far ahead, webhook-timestamp may be, in seconds
     *
     * @throws WebhookVerificationException when a header is missing, the timestamp is outside the tolerance, no signature matches, or the body is not a JSON object
     */
    public static function verify(string $body, array $headers, string $secret, ?int $now = null, int $tolerance = self::TOLERANCE): WebhookEvent
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $id = $lower['webhook-id'] ?? '';
        $timestamp = $lower['webhook-timestamp'] ?? '';
        $signature = $lower['webhook-signature'] ?? '';

        if ($id === '' || $signature === '') {
            throw new WebhookVerificationException('The webhook-id and webhook-signature headers are required');
        }
        if (!ctype_digit($timestamp)) {
            throw new WebhookVerificationException('The webhook-timestamp header is not a Unix time');
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('The webhook-timestamp is too far from now: an old or replayed delivery');
        }

        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, strlen(self::SECRET_PREFIX)) : $secret;
        $key = base64_decode($encoded, true);
        if ($key === false) {
            throw new WebhookVerificationException('The secret is not whsec_ and a base64 key');
        }
        $expected = base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));

        $matched = false;
        foreach (explode(' ', $signature) as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) === 2 && $parts[0] === 'v1' && hash_equals($expected, $parts[1])) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('No signature matches: not signed by Cru with this secret');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('The body is not JSON', 0, $e);
        }
        if (!is_array($payload) || array_is_list($payload) && $payload !== []) {
            throw new WebhookVerificationException('The body is not a JSON object');
        }

        /** @var array<string, mixed> $payload */
        return WebhookEvent::fromArray($payload);
    }

    /**
     * A sale.created event's data: the sale_ref and its units, as GET /trades/sales gives them.
     *
     * @throws \InvalidArgumentException for an event of another type
     */
    public static function saleCreated(WebhookEvent $event): SaleCreatedData
    {
        return SaleCreatedData::fromArray(self::data($event, 'sale.created'));
    }

    /**
     * A purchase.created event's data: the purchase_ref and its lines, as GET /trades/purchases gives them.
     *
     * @throws \InvalidArgumentException for an event of another type
     */
    public static function purchaseCreated(WebhookEvent $event): PurchaseCreatedData
    {
        return PurchaseCreatedData::fromArray(self::data($event, 'purchase.created'));
    }

    /**
     * A webhook.test event's data.
     *
     * @throws \InvalidArgumentException for an event of another type
     */
    public static function testData(WebhookEvent $event): WebhookTestData
    {
        return WebhookTestData::fromArray(self::data($event, 'webhook.test'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(WebhookEvent $event, string $type): array
    {
        if ($event->type !== $type) {
            throw new \InvalidArgumentException(sprintf('The event is %s, not %s', $event->type ?? 'untyped', $type));
        }
        $data = [];
        foreach ($event->data ?? [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return $data;
    }
}
