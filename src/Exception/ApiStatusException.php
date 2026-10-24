<?php

declare(strict_types=1);

namespace CruWorldWine\Exception;

/**
 * The API answered with an error.
 *
 * Branch on getStatusCode() and getErrorCode(); the message is for people and may change.
 * getErrors() holds every entry the API sent, and getRequestId() is what Cru support needs to
 * find the request.
 */
class ApiStatusException extends CruException
{
    private const BY_STATUS = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        405 => MethodNotAllowedException::class,
        409 => ConflictException::class,
        410 => GoneException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitException::class,
    ];

    private const CONFLICT_BY_CODE = [
        'PRICE_CHANGED' => PriceChangedException::class,
        'REQUEST_IN_PROGRESS' => RequestInProgressException::class,
    ];

    private int $statusCode;

    /** @var array<array-key, mixed> */
    private array $body;

    /** @var array<string, string> */
    private array $headers;

    private string $rawBody;

    /** @var list<array<array-key, mixed>> */
    private array $errors;

    private ?string $errorCode;

    private ?string $field;

    /** @var array<array-key, mixed> */
    private array $details;

    private string $apiMessage;

    private ?string $requestId;

    /**
     * @param array<array-key, mixed> $body the decoded response body, or [] when it was not JSON
     * @param array<string, string> $headers the response headers, by lower-case name
     */
    final public function __construct(
        int $statusCode,
        array $body = [],
        array $headers = [],
        string $rawBody = '',
        ?\Throwable $previous = null,
    ) {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
        $this->rawBody = $rawBody;

        $errors = [];
        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $entry) {
                if (is_array($entry)) {
                    $errors[] = $entry;
                }
            }
        }
        $this->errors = $errors;
        $first = $errors[0] ?? [];

        $this->errorCode = self::stringOrNull($first['code'] ?? null);
        $this->field = self::stringOrNull($first['field'] ?? null);
        $details = $first['details'] ?? null;
        $this->details = is_array($details) ? $details : [];
        $this->apiMessage = self::stringOrNull($first['message'] ?? null)
            ?? self::stringOrNull($body['message'] ?? null)
            ?? 'HTTP ' . $statusCode;

        $meta = $body['meta'] ?? null;
        $this->requestId = (is_array($meta) ? self::stringOrNull($meta['request_id'] ?? null) : null)
            ?? self::stringOrNull($headers['x-request-id'] ?? null);

        $prefix = $this->errorCode === null ? (string) $statusCode : $statusCode . ' ' . $this->errorCode;
        parent::__construct($prefix . ': ' . $this->apiMessage, $statusCode, $previous);
    }

    /**
     * The exception for an error response: by status, and for a 409 by its code.
     *
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     */
    public static function create(int $statusCode, array $body = [], array $headers = [], string $rawBody = ''): self
    {
        $class = self::BY_STATUS[$statusCode] ?? ($statusCode >= 500 ? InternalServerException::class : self::class);
        if ($statusCode === 409) {
            $errors = $body['errors'] ?? null;
            $first = is_array($errors) ? ($errors[0] ?? null) : null;
            $code = is_array($first) ? ($first['code'] ?? null) : null;
            if (is_string($code) && isset(self::CONFLICT_BY_CODE[$code])) {
                $class = self::CONFLICT_BY_CODE[$code];
            }
        }

        return new $class($statusCode, $body, $headers, $rawBody);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * errors[0].code, such as VALIDATION_INVALID_VALUE: what to branch on.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * errors[0].message, or the envelope's message: for people, never to branch on.
     */
    public function getApiMessage(): string
    {
        return $this->apiMessage;
    }

    /**
     * errors[0].field: the parameter the error is about, when there is one.
     */
    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * errors[0].details: structured context such as required_scope or retry_after.
     *
     * @return array<array-key, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Every entry of errors[], as sent.
     *
     * @return list<array<array-key, mixed>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * meta.request_id, else the X-Request-Id header.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * The decoded response body, or [] when it was not JSON.
     *
     * @return array<array-key, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, string> by lower-case name
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
