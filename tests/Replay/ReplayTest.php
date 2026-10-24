<?php

declare(strict_types=1);

namespace CruWorldWine\Tests\Replay;

use CruWorldWine\Client;
use CruWorldWine\Exception\ApiStatusException;
use CruWorldWine\Exception\AuthenticationException;
use CruWorldWine\Exception\BadRequestException;
use CruWorldWine\Exception\ConflictException;
use CruWorldWine\Exception\GoneException;
use CruWorldWine\Exception\InternalServerException;
use CruWorldWine\Exception\MethodNotAllowedException;
use CruWorldWine\Exception\NotFoundException;
use CruWorldWine\Exception\PermissionDeniedException;
use CruWorldWine\Exception\PriceChangedException;
use CruWorldWine\Exception\RateLimitException;
use CruWorldWine\Exception\RequestInProgressException;
use CruWorldWine\Exception\UnprocessableEntityException;
use CruWorldWine\Internal\Endpoints;
use CruWorldWine\Model;
use CruWorldWine\Models\InstructionResult;
use CruWorldWine\Models\StagedConfirmation;
use CruWorldWine\Page;
use CruWorldWine\Write;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Replays answers captured from the real API through the SDK, endpoint by endpoint.
 *
 * CRU_CAPTURES names a capture run directory: each captures/*.json in it is
 * {request: {method, path, query, body, grant, store}, status, body}. The test is skipped when
 * CRU_CAPTURES is unset.
 *
 * The captures hold real customer data, so nothing of them is kept in this repository, and the
 * messages here name fields and types, never values.
 */
final class ReplayTest extends \PHPUnit\Framework\TestCase
{
    /** @var list<string> fields whose typed value is not what was sent */
    private array $drift = [];

    private int $compared = 0;

    /**
     * @return iterable<string, array{?string}>
     */
    public static function captures(): iterable
    {
        $dir = getenv('CRU_CAPTURES');
        if (!is_string($dir) || $dir === '') {
            yield 'CRU_CAPTURES is unset' => [null];

            return;
        }
        $files = glob(rtrim($dir, '/') . '/captures/*.json') ?: [];
        sort($files);
        if ($files === []) {
            yield 'CRU_CAPTURES has no captures' => [''];

            return;
        }
        foreach ($files as $i => $file) {
            $capture = self::load($file);
            $request = self::arr($capture['request'] ?? null);
            $method = strtoupper((string) self::str($request['method'] ?? ''));
            $match = self::match($method, (string) self::str($request['path'] ?? ''));
            yield sprintf('#%03d %s %s', $i + 1, $method, $match === null ? '(raw call, no spec operation)' : $match[0]) => [$file];
        }
    }

    #[DataProvider('captures')]
    public function testCapture(?string $file): void
    {
        if ($file === null) {
            self::markTestSkipped('Set CRU_CAPTURES to a capture run directory to replay real answers.');
        }
        if ($file === '') {
            self::fail('CRU_CAPTURES is set but its captures/ directory has no *.json.');
        }

        $capture = self::load($file);
        $request = self::arr($capture['request'] ?? null);
        $status = is_int($capture['status'] ?? null) ? $capture['status'] : 0;
        $body = $capture['body'] ?? null;
        $method = strtoupper((string) self::str($request['method'] ?? ''));
        $path = (string) self::str($request['path'] ?? '');

        /** @var list<array{request: RequestInterface}> $history */
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response($status, $body, self::rawBody($file))]));
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);
        $client = ($request['grant'] ?? null) === 'oauth'
            ? new Client(accessToken: 'replay-token', httpClient: $http, maxRetries: 0)
            : new Client(clientKey: 'replay-key', clientSecret: 'replay-secret', httpClient: $http, maxRetries: 0);
        $query = self::arr($request['query'] ?? null);
        $sentBody = self::arr($request['body'] ?? null);

        $match = self::match($method, $path);
        if ($match === null) {
            // No spec operation: an error the API gives for a route it does not have, replayed
            // through the raw call.
            self::assertGreaterThanOrEqual(400, $status, 'only an error may be captured for a route outside the spec');
            $id = "$method (raw)";
            $call = static fn (): mixed => $client->request($method, $path, $query, $method === 'GET' ? null : $sentBody);
        } else {
            [$id, $endpoint, $pathValues] = $match;
            $params = $method === 'GET' ? $query : $query + $sentBody;
            $target = $endpoint['resource'] === null ? $client : $client->{$endpoint['resource']};
            self::assertIsObject($target);
            $args = array_merge(array_values($pathValues), [$params]);
            $call = static fn (): mixed => $target->{$endpoint['name']}(...$args);
        }

        if ($status >= 200 && $status < 300 && isset($endpoint)) {
            $result = $call();
            self::assertCount(1, $history);
            $this->assertSent($history[0]['request'], $method, $path, $query, $sentBody, $method === 'GET' || ($request['grant'] ?? null) === 'oauth');
            $this->assertResult($id, $endpoint, $status, $body, $result);
            self::assertSame([], $this->drift, "$id: typed values differ from what was sent:\n" . implode("\n", $this->drift));
            if (!in_array($endpoint['kind'], ['empty', 'binary'], true) && self::hasItems($endpoint, $body)) {
                self::assertGreaterThan(0, $this->compared, "$id: no typed field was compared");
            }

            return;
        }

        $envelope = self::arr($body);
        $errors = self::arr($envelope['errors'] ?? null);
        $code = self::str(self::arr($errors[0] ?? null)['code'] ?? null);
        try {
            $call();
            self::fail("$id: HTTP $status did not throw");
        } catch (ApiStatusException $e) {
            self::assertSame(self::expectedException($status, $code), $e::class, "$id: HTTP $status " . ($code ?? ''));
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($code, $e->getErrorCode());
        }
    }

    /**
     * @param array{method: string, path: string, resource: string|null, name: string, kind: string, model: class-string<Model>|null, itemsKey: string|null} $endpoint
     */
    private function assertResult(string $id, array $endpoint, int $status, mixed $body, mixed $result): void
    {
        $kind = $endpoint['kind'];
        if ($kind === 'empty') {
            self::assertNull($result);

            return;
        }
        if ($kind === 'binary') {
            self::assertIsString($result);

            return;
        }
        $envelope = self::arr($body);
        $data = self::arr($envelope['data'] ?? null);
        $key = $endpoint['itemsKey'];

        if ($kind === 'get') {
            self::assertInstanceOf((string) $endpoint['model'], $result);
            $this->assertModel($result, $key === null ? $data : ($data[$key] ?? null), $id);

            return;
        }
        if ($kind === 'list' || $kind === 'page') {
            $items = $kind === 'page' ? ($result instanceof Page ? $result->items : null) : $result;
            self::assertIsArray($items);
            $sent = $data[(string) $key] ?? null;
            self::assertIsArray($sent, "$id: data.$key is a list");
            self::assertCount(count($sent), $items, "$id: every item is parsed");
            foreach ($items as $i => $item) {
                self::assertInstanceOf((string) $endpoint['model'], $item);
                $this->assertModel($item, $sent[$i], "{$id}.{$key}[{$i}]");
            }
            if ($result instanceof Page) {
                $pagination = self::arr(self::arr($envelope['meta'] ?? null)['pagination'] ?? null);
                self::assertSame($pagination['limit'] ?? null, $result->limit, "$id: limit");
                self::assertSame($pagination['offset'] ?? 0, $result->offset, "$id: offset");
                self::assertSame($pagination['total'] ?? null, $result->total, "$id: total");
                if (isset($pagination['has_more'])) {
                    self::assertSame($pagination['has_more'], $result->hasMore, "$id: has_more");
                }
                self::assertSame($data, $result->data);
            }

            return;
        }

        self::assertInstanceOf(Write::class, $result);
        $requestId = self::str(self::arr($envelope['meta'] ?? null)['request_id'] ?? null);
        self::assertSame($requestId, $result->requestId, "$id: request id");
        self::assertSame($status, $result->statusCode);
        if ($status === 202) {
            self::assertTrue($result->staged);
            self::assertNull($result->result);
            self::assertInstanceOf(StagedConfirmation::class, $result->confirmation);
            $this->assertModel($result->confirmation, $data['confirmation'] ?? null, "$id.confirmation");

            return;
        }
        self::assertFalse($result->staged);
        $expected = (string) $endpoint['model'];
        if ($kind === 'confirm') {
            $action = self::str($data['action'] ?? null);
            $expected = $action !== null && isset(Endpoints::RESULTS[$action]) ? Endpoints::RESULTS[$action] : InstructionResult::class;
        }
        self::assertInstanceOf($expected, $result->result);
        $this->assertModel($result->result, $data, $id);
    }

    /**
     * Every typed property holds what was sent (an int sent for a number counts), and nested
     * models do too.
     */
    private function assertModel(Model $model, mixed $sent, string $where): void
    {
        self::assertIsArray($sent, "$where is an object");
        self::assertTrue($sent === $model->toArray(), "$where: the raw array is what was sent");
        foreach ((new \ReflectionObject($model))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $typed = $model->{$name};
            $value = $sent[$name] ?? null;
            $field = "$where.$name";
            if ($typed instanceof Model) {
                $this->assertModel($typed, $value, $field);
                continue;
            }
            if (is_array($typed) && $typed !== [] && array_is_list($typed) && $typed[0] instanceof Model) {
                self::assertIsArray($value, "$field is a list");
                self::assertCount(count($value), $typed, "$field: every item is parsed");
                foreach ($typed as $i => $item) {
                    self::assertInstanceOf(Model::class, $item);
                    $this->assertModel($item, $value[$i], "{$field}[$i]");
                }
                continue;
            }
            $this->compared++;
            if ($typed === null) {
                if ($value !== null) {
                    $this->drift[] = "$field: sent " . get_debug_type($value) . ', typed property is null';
                }
                continue;
            }
            if (!self::same($typed, $value)) {
                $this->drift[] = "$field: sent " . get_debug_type($value) . ', typed ' . get_debug_type($typed);
            }
        }
    }

    /**
     * Whether the answer has anything to compare: a list answer may be empty.
     *
     * @param array{method: string, path: string, resource: string|null, name: string, kind: string, model: class-string<Model>|null, itemsKey: string|null} $endpoint
     */
    private static function hasItems(array $endpoint, mixed $body): bool
    {
        if ($endpoint['kind'] !== 'list' && $endpoint['kind'] !== 'page') {
            return true;
        }
        $data = self::arr(self::arr($body)['data'] ?? null);

        return self::arr($data[(string) $endpoint['itemsKey']] ?? null) !== [];
    }

    private static function same(mixed $typed, mixed $sent): bool
    {
        if (is_float($typed) && is_int($sent)) {
            return (float) $sent === $typed;
        }
        if (is_array($typed) && is_array($sent) && array_is_list($typed) && array_is_list($sent) && count($typed) === count($sent)) {
            foreach ($typed as $i => $item) {
                if (!self::same($item, $sent[$i])) {
                    return false;
                }
            }

            return true;
        }

        return $typed === $sent;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     */
    private function assertSent(RequestInterface $sent, string $method, string $path, array $query, array $body, bool $exactBody): void
    {
        self::assertSame($method, $sent->getMethod());
        self::assertSame('/api/v1' . $path, $sent->getUri()->getPath());
        parse_str($sent->getUri()->getQuery(), $sentQuery);
        $expected = [];
        foreach ($query as $name => $value) {
            $expected[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : $value);
        }
        self::assertTrue($expected === $sentQuery, 'the query is what was captured');
        if ($method === 'GET') {
            return;
        }
        $raw = (string) $sent->getBody();
        if ($raw === '') {
            self::assertTrue($exactBody && $body === [], 'only a connected app may send a write without a body, and only when none was captured');

            return;
        }
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'a write sends a JSON object');
        if (!$exactBody) {
            self::assertIsString($decoded['reference'] ?? null, 'a partner write carries a reference');
            if (!array_key_exists('reference', $body)) {
                unset($decoded['reference']);
            }
        }
        self::assertTrue($body === $decoded, 'the body is what was captured');
    }

    /**
     * @return class-string<ApiStatusException>
     */
    private static function expectedException(int $status, ?string $code): string
    {
        if ($status === 409 && $code === 'PRICE_CHANGED') {
            return PriceChangedException::class;
        }
        if ($status === 409 && $code === 'REQUEST_IN_PROGRESS') {
            return RequestInProgressException::class;
        }

        return [
            400 => BadRequestException::class,
            401 => AuthenticationException::class,
            403 => PermissionDeniedException::class,
            404 => NotFoundException::class,
            405 => MethodNotAllowedException::class,
            409 => ConflictException::class,
            410 => GoneException::class,
            422 => UnprocessableEntityException::class,
            429 => RateLimitException::class,
        ][$status] ?? ($status >= 500 ? InternalServerException::class : ApiStatusException::class);
    }

    /**
     * The operation for a method and path: literal segments beat templated ones.
     *
     * @return array{string, array{method: string, path: string, resource: string|null, name: string, kind: string, model: class-string<Model>|null, itemsKey: string|null}, array<string, string>}|null
     */
    private static function match(string $method, string $path): ?array
    {
        $segments = explode('/', trim($path, '/'));
        $best = null;
        $bestScore = -1;
        foreach (Endpoints::ALL as $id => $endpoint) {
            if ($endpoint['method'] !== $method) {
                continue;
            }
            $template = explode('/', trim($endpoint['path'], '/'));
            if (count($template) !== count($segments)) {
                continue;
            }
            $values = [];
            $score = 0;
            foreach ($template as $i => $part) {
                if (preg_match('/^\{(\w+)\}$/', $part, $m) === 1) {
                    if ($segments[$i] === '') {
                        continue 2;
                    }
                    $values[$m[1]] = rawurldecode($segments[$i]);
                } elseif ($part === $segments[$i]) {
                    $score++;
                } else {
                    continue 2;
                }
            }
            if ($score > $bestScore) {
                $best = [$id, $endpoint, $values];
                $bestScore = $score;
            }
        }

        return $best;
    }

    private static function response(int $status, mixed $body, string $raw): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        $meta = self::arr(self::arr($body)['meta'] ?? null);
        $requestId = self::str($meta['request_id'] ?? null);
        if ($requestId !== null) {
            $headers['X-Request-Id'] = $requestId;
        }

        return new Response($status, $headers, $raw);
    }

    /**
     * The captured answer as JSON again, decoded as objects first so that {} stays {}.
     */
    private static function rawBody(string $file): string
    {
        $raw = file_get_contents($file);
        self::assertIsString($raw);
        $capture = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $capture);
        $body = $capture->body ?? null;

        return $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function load(string $file): array
    {
        $raw = file_get_contents($file);
        self::assertIsString($raw);

        return self::arr(json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
