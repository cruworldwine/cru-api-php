<?php

declare(strict_types=1);

namespace CruWorldWine\Internal;

use CruWorldWine\Exception\CruException;
use CruWorldWine\Model;
use CruWorldWine\Models\StagedConfirmation;
use CruWorldWine\Page;
use CruWorldWine\Response;
use CruWorldWine\Write;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds a request from an operation and its parameters, and unwraps the envelope it answers with.
 *
 * @internal
 */
final class Caller
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * One object: data[$itemsKey], or data itself when $itemsKey is null.
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     *
     * @return T
     */
    public function get(Operation $op, string $model, ?string $itemsKey, array $path, array $params): Model
    {
        $data = self::data(Json::decodeObject(Transport::body($this->send($op, $path, $params))));
        $value = $itemsKey === null ? $data : ($data[$itemsKey] ?? null);

        return $model::fromArray(Coerce::map($value) ?? []);
    }

    /**
     * Every object of data[$itemsKey].
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     *
     * @return list<T>
     */
    public function list(Operation $op, string $model, string $itemsKey, array $path, array $params): array
    {
        $data = self::data(Json::decodeObject(Transport::body($this->send($op, $path, $params))));

        return self::items($data[$itemsKey] ?? null, $model)[0];
    }

    /**
     * One page of data[$itemsKey], with meta.pagination and a way to the next page.
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     *
     * @return Page<T>
     */
    public function page(Operation $op, string $model, string $itemsKey, array $path, array $params): Page
    {
        $envelope = Json::decodeObject(Transport::body($this->send($op, $path, $params)));
        $data = self::data($envelope);
        [$items, $received] = self::items($data[$itemsKey] ?? null, $model);
        $meta = Coerce::map($envelope['meta'] ?? null) ?? [];
        $fetch = function (int $offset) use ($op, $model, $itemsKey, $path, $params): Page {
            return $this->page($op, $model, $itemsKey, $path, array_replace($params, ['offset' => $offset]));
        };

        return new Page($items, $received, $data, $meta, $fetch);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     *
     * @return Write<T>
     */
    public function write(Operation $op, string $model, array $path, array $params): Write
    {
        return self::toWrite($this->send($op, $path, $params), $model, []);
    }

    /**
     * A confirmed instruction's result: the model of its action, else $model.
     *
     * @param class-string<Model> $model
     * @param array<string, class-string<Model>> $results the result model of each action
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     *
     * @return Write<Model>
     */
    public function confirm(Operation $op, string $model, array $results, array $path, array $params): Write
    {
        return self::toWrite($this->send($op, $path, $params), $model, $results);
    }

    /**
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     */
    public function noContent(Operation $op, array $path, array $params): void
    {
        $this->send($op, $path, $params);
    }

    /**
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     */
    public function binary(Operation $op, array $path, array $params): string
    {
        return Transport::body($this->send($op, $path, $params, '*/*'));
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed>|null $body
     */
    public function raw(string $method, string $path, array $query, ?array $body): Response
    {
        $response = $this->transport->send($method, '/' . ltrim($path, '/'), $query, $body);
        $raw = Transport::body($response);

        return new Response($response->getStatusCode(), Transport::headers($response), $raw, Json::decode($raw));
    }

    /**
     * @param array<string, int|string> $path
     * @param array<array-key, mixed> $params
     */
    private function send(Operation $op, array $path, array $params, string $accept = 'application/json'): ResponseInterface
    {
        $url = self::path($op->path, $path);
        if ($op->isRead()) {
            return $this->transport->send($op->method, $url, $params, null, $accept);
        }
        // Parameters this SDK does not know go in the body, so a new one works before regenerating.
        $query = [];
        $body = [];
        foreach ($params as $name => $value) {
            if (in_array($name, $op->query, true)) {
                $query[$name] = $value;
            } elseif ($value !== null) {
                $body[$name] = $value;
            }
        }

        return $this->transport->send($op->method, $url, $query, $body === [] && !$op->body ? null : $body, $accept);
    }

    /**
     * @param array<string, int|string> $values
     */
    private static function path(string $template, array $values): string
    {
        $replace = [];
        foreach ($values as $name => $value) {
            $value = (string) $value;
            if ($value === '') {
                throw new CruException($name . ' is required and cannot be empty.');
            }
            $replace['{' . $name . '}'] = rawurlencode($value);
        }
        $path = strtr($template, $replace);
        if (preg_match('/\{[^}]*\}/', $path) === 1) {
            throw new CruException('A path parameter of ' . $template . ' is missing.');
        }

        return $path;
    }

    /**
     * @param array<array-key, mixed> $envelope
     *
     * @return array<array-key, mixed>
     */
    private static function data(array $envelope): array
    {
        return Coerce::map($envelope['data'] ?? null) ?? [];
    }

    /**
     * The objects of a list, and how many items the list had.
     *
     * @template T of Model
     *
     * @param class-string<T> $model
     *
     * @return array{list<T>, int}
     */
    private static function items(mixed $value, string $model): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [[], 0];
        }
        $items = [];
        foreach ($value as $item) {
            if (Coerce::isObject($item)) {
                $items[] = $model::fromArray($item);
            }
        }

        return [$items, count($value)];
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $model
     * @param array<string, class-string<T>> $results
     *
     * @return Write<T>
     */
    private static function toWrite(ResponseInterface $response, string $model, array $results): Write
    {
        $envelope = Json::decodeObject(Transport::body($response));
        $data = self::data($envelope);
        $status = $response->getStatusCode();
        $requestId = self::requestId($response, $envelope);
        if ($status === 202) {
            $confirmation = StagedConfirmation::fromArray(Coerce::map($data['confirmation'] ?? null) ?? []);

            return new Write($model, null, $confirmation, false, $requestId, $status);
        }
        $action = $data['action'] ?? null;
        if (is_string($action) && isset($results[$action])) {
            $model = $results[$action];
        }
        $replayed = strtolower(trim($response->getHeaderLine('Idempotent-Replayed'))) === 'true';

        return new Write($model, $model::fromArray($data), null, $replayed, $requestId, $status);
    }

    /**
     * @param array<array-key, mixed> $envelope
     */
    private static function requestId(ResponseInterface $response, array $envelope): ?string
    {
        $header = trim($response->getHeaderLine('X-Request-Id'));
        if ($header !== '') {
            return $header;
        }
        $meta = $envelope['meta'] ?? null;
        $id = is_array($meta) ? ($meta['request_id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
