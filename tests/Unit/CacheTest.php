<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Tests\Unit;

use Kipchak\Middleware\ResponseCache\Config\Settings;
use Kipchak\Middleware\ResponseCache\Handlers\Cache;
use Kipchak\Middleware\ResponseCache\Library\ResponseStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class CacheTest extends TestCase
{
    private ResponseStore $store;
    private \DateTimeImmutable $now;

    /** @var list<string> Paths the route served. */
    private array $served = [];

    protected function setUp(): void
    {
        $this->store = new ResponseStore(new TagAwareAdapter(new ArrayAdapter()));
        $this->now = new \DateTimeImmutable('2026-10-29 12:00:00 UTC');
        $this->served = [];
    }

    private function cache(array $config = [], ?int $ttl = null): Cache
    {
        $clock = new class ($this->now) implements ClockInterface {
            public function __construct(private \DateTimeImmutable &$now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        return new Cache(
            ttl: $ttl,
            settings: Settings::fromArray($config + ['consumer' => [['source' => 'header', 'name' => 'x-api-key']]]),
            store: $this->store,
            clock: $clock,
            logger: new NullLogger(),
        );
    }

    private static function request(string $method = 'GET', string $uri = '/v1/countries', array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://api.example.com' . $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * A route answering with the given status and headers, and a body naming the request's path and how many
     * requests it has served.
     */
    private function route(array $headers = ['Cache-Control' => 'max-age=60'], int $status = 200, ?string $body = null): RequestHandlerInterface
    {
        return new class ($this->served, $headers, $status, $body) implements RequestHandlerInterface {
            public function __construct(private array &$served, private array $headers, private int $status, private ?string $body)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->served[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
                $response = new Response($this->status);
                $response->getBody()->write($this->body ?? 'response ' . count($this->served) . ' for ' . $request->getHeaderLine('Accept-Language'));
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }

    private function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function testStoresAndServesFreshResponses(): void
    {
        $cache = $this->cache();

        $miss = $cache->process(self::request(), $this->route());
        $this->advance(10);
        $hit = $cache->process(self::request(), $this->route());

        $this->assertSame('MISS', $miss->getHeaderLine('X-Cache'));
        $this->assertSame('HIT', $hit->getHeaderLine('X-Cache'));
        $this->assertSame('10', $hit->getHeaderLine('Age'));
        $this->assertSame((string) $miss->getBody(), (string) $hit->getBody());
        $this->assertSame('max-age=60', $hit->getHeaderLine('Cache-Control'));
        $this->assertCount(1, $this->served);

        $this->advance(51);
        $this->assertSame('MISS', $cache->process(self::request(), $this->route())->getHeaderLine('X-Cache'), 'Stale after max-age');
        $this->assertCount(2, $this->served);
    }

    public function testFreshnessPrecedence(): void
    {
        $this->cache()->process(self::request(), $this->route(['Cache-Control' => 'max-age=600, s-maxage=5']));
        $this->advance(6);
        $this->assertSame('MISS', $this->cache()->process(self::request(), $this->route())->getHeaderLine('X-Cache'), 's-maxage wins for a shared cache');

        $this->cache()->process(self::request('GET', '/v1/expires'), $this->route([
            'Date' => 'Thu, 29 Oct 2026 12:00:06 GMT',
            'Expires' => 'Thu, 29 Oct 2026 12:00:36 GMT',
        ]));
        $this->advance(20);
        $this->assertSame('HIT', $this->cache()->process(self::request('GET', '/v1/expires'), $this->route())->getHeaderLine('X-Cache'));
    }

    public function testResponsesWithoutFreshnessNeedATtl(): void
    {
        $this->assertSame('BYPASS', $this->cache()->process(self::request(), $this->route([]))->getHeaderLine('X-Cache'));

        $this->cache(ttl: 30)->process(self::request(), $this->route([]));
        $this->assertSame('HIT', $this->cache(ttl: 30)->process(self::request(), $this->route([]))->getHeaderLine('X-Cache'));

        $this->cache(['default_ttl' => 30])->process(self::request('GET', '/v1/other'), $this->route([]));
        $this->assertSame('HIT', $this->cache(['default_ttl' => 30])->process(self::request('GET', '/v1/other'), $this->route([]))->getHeaderLine('X-Cache'));
    }

    public static function unstorable(): iterable
    {
        yield 'no-store' => [['Cache-Control' => 'no-store, max-age=60'], 200];
        yield 'no-cache' => [['Cache-Control' => 'no-cache, max-age=60'], 200];
        yield 'private' => [['Cache-Control' => 'private, max-age=60'], 200];
        yield 'max-age=0' => [['Cache-Control' => 'max-age=0'], 200];
        yield 'Set-Cookie' => [['Cache-Control' => 'max-age=60', 'Set-Cookie' => 'a=b'], 200];
        yield 'Vary: *' => [['Cache-Control' => 'max-age=60', 'Vary' => '*'], 200];
        yield 'server error' => [['Cache-Control' => 'max-age=60'], 500];
        yield 'created' => [['Cache-Control' => 'max-age=60'], 201];
        yield 'invalid Expires' => [['Expires' => 'soon'], 200];
    }

    #[DataProvider('unstorable')]
    public function testUnstorableResponses(array $headers, int $status): void
    {
        $this->assertSame('BYPASS', $this->cache()->process(self::request(), $this->route($headers, $status))->getHeaderLine('X-Cache'));
        $this->assertSame('BYPASS', $this->cache()->process(self::request(), $this->route($headers, $status))->getHeaderLine('X-Cache'));
        $this->assertCount(2, $this->served);
    }

    public function testCacheableErrorStatuses(): void
    {
        $this->cache()->process(self::request(), $this->route(status: 404));
        $this->assertSame(404, $this->cache()->process(self::request(), $this->route())->getStatusCode());
        $this->assertCount(1, $this->served);
    }

    public function testAuthenticatedResponsesNeedPermissionToBeShared(): void
    {
        $authorised = self::request(headers: ['Authorization' => 'Bearer x']);
        $this->cache()->process($authorised, $this->route(['Cache-Control' => 'max-age=60']));
        $this->assertSame('BYPASS', $this->cache()->process($authorised, $this->route())->getHeaderLine('X-Cache'));

        $apiKey = self::request(headers: ['x-api-key' => 'acme']);
        $this->assertSame('BYPASS', $this->cache()->process($apiKey, $this->route(['Cache-Control' => 'max-age=60']))->getHeaderLine('X-Cache'), 'An API key consumer counts as authenticated');

        foreach (['public, max-age=60', 's-maxage=60', 'max-age=60, must-revalidate'] as $index => $control) {
            $request = self::request(uri: "/v1/shared/{$index}", headers: ['Authorization' => 'Bearer x']);
            $this->cache()->process($request, $this->route(['Cache-Control' => $control]));
            $this->assertSame('HIT', $this->cache()->process($request->withHeader('Authorization', 'Bearer someone-else'), $this->route())->getHeaderLine('X-Cache'), $control);
        }
    }

    public function testConsumerScope(): void
    {
        $cache = $this->cache(['scope' => 'consumer']);
        $acme = self::request(headers: ['x-api-key' => 'acme']);
        $globex = self::request(headers: ['x-api-key' => 'globex']);

        $cache->process($acme, $this->route(['Cache-Control' => 'private, max-age=60'], body: 'acme data'));
        $this->assertSame('acme data', (string) $cache->process($acme, $this->route())->getBody());

        $other = $cache->process($globex, $this->route(['Cache-Control' => 'private, max-age=60'], body: 'globex data'));
        $this->assertSame('MISS', $other->getHeaderLine('X-Cache'));
        $this->assertSame('globex data', (string) $other->getBody(), 'One consumer never gets another\'s response');
    }

    public function testVary(): void
    {
        $cache = $this->cache();
        $route = $this->route(['Cache-Control' => 'max-age=60', 'Vary' => 'Accept-Language']);

        $english = $cache->process(self::request(headers: ['Accept-Language' => 'en']), $route);
        $french = $cache->process(self::request(headers: ['Accept-Language' => 'fr']), $route);

        $this->assertSame('MISS', $french->getHeaderLine('X-Cache'));
        $this->assertSame((string) $english->getBody(), (string) $cache->process(self::request(headers: ['Accept-Language' => 'en']), $route)->getBody());
        $this->assertSame((string) $french->getBody(), (string) $cache->process(self::request(headers: ['Accept-Language' => 'fr']), $route)->getBody());
        $this->assertCount(2, $this->served);
    }

    public function testQueries(): void
    {
        $cache = $this->cache();
        $cache->process(self::request(uri: '/v1/countries?region=eu&page=2'), $this->route());

        $this->assertSame('HIT', $cache->process(self::request(uri: '/v1/countries?page=2&region=eu'), $this->route())->getHeaderLine('X-Cache'), 'Order does not matter');
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/countries?page=3&region=eu'), $this->route())->getHeaderLine('X-Cache'));
    }

    public function testEtagsAndConditionalRequests(): void
    {
        $cache = $this->cache();
        $first = $cache->process(self::request(), $this->route(body: 'payload'));
        $etag = $first->getHeaderLine('ETag');
        $this->assertSame('"' . substr(hash('sha256', 'payload'), 0, 32) . '"', $etag);

        foreach ([$etag, 'W/' . $etag, '"other", ' . $etag, '*'] as $ifNoneMatch) {
            $notModified = $cache->process(self::request(headers: ['If-None-Match' => $ifNoneMatch]), $this->route());
            $this->assertSame(304, $notModified->getStatusCode(), $ifNoneMatch);
            $this->assertSame('', (string) $notModified->getBody());
            $this->assertSame($etag, $notModified->getHeaderLine('ETag'));
            $this->assertSame('max-age=60', $notModified->getHeaderLine('Cache-Control'));
            $this->assertSame('HIT', $notModified->getHeaderLine('X-Cache'));
        }

        $this->assertSame(200, $cache->process(self::request(headers: ['If-None-Match' => '"stale"']), $this->route())->getStatusCode());

        $uncached = $this->cache()->process(self::request(uri: '/v1/live', headers: ['If-None-Match' => '"' . substr(hash('sha256', 'live'), 0, 32) . '"']), $this->route([], body: 'live'));
        $this->assertSame(304, $uncached->getStatusCode(), 'Conditional requests work on responses that are not cached too');
        $this->assertSame('BYPASS', $uncached->getHeaderLine('X-Cache'));
    }

    public function testRoutesOwnEtagsAreKept(): void
    {
        $response = $this->cache()->process(self::request(), $this->route(['Cache-Control' => 'max-age=60', 'ETag' => 'W/"v7"']));

        $this->assertSame('W/"v7"', $response->getHeaderLine('ETag'));
        $this->assertSame(304, $this->cache()->process(self::request(headers: ['If-None-Match' => '"v7"']), $this->route())->getStatusCode());
    }

    public function testIfModifiedSince(): void
    {
        $route = $this->route(['Cache-Control' => 'max-age=60', 'ETag' => '"x"', 'Last-Modified' => 'Wed, 28 Oct 2026 10:00:00 GMT']);

        $this->assertSame(304, $this->cache()->process(self::request(headers: ['If-Modified-Since' => 'Wed, 28 Oct 2026 10:00:00 GMT']), $route)->getStatusCode());
        $this->assertSame(200, $this->cache()->process(self::request(headers: ['If-Modified-Since' => 'Tue, 27 Oct 2026 10:00:00 GMT']), $route)->getStatusCode());
        $this->assertSame(200, $this->cache()->process(self::request(headers: ['If-Modified-Since' => 'Thu, 29 Oct 2026 10:00:00 GMT', 'If-None-Match' => '"y"']), $route)->getStatusCode(), 'If-None-Match takes precedence');
    }

    public function testEtagsCanBeTurnedOff(): void
    {
        $this->assertFalse($this->cache(['etags' => false])->process(self::request(), $this->route())->hasHeader('ETag'));
    }

    public function testHead(): void
    {
        $miss = $this->cache()->process(self::request('HEAD'), $this->route());
        $this->assertSame('', (string) $miss->getBody());
        $this->assertSame('BYPASS', $miss->getHeaderLine('X-Cache'), 'HEAD responses have no body to store');

        $this->cache()->process(self::request(), $this->route(body: 'payload'));
        $hit = $this->cache()->process(self::request('HEAD'), $this->route());
        $this->assertSame('HIT', $hit->getHeaderLine('X-Cache'));
        $this->assertSame('', (string) $hit->getBody());
        $this->assertCount(2, $this->served);
    }

    public function testRequestCacheControl(): void
    {
        $cache = $this->cache();
        $cache->process(self::request(), $this->route(body: 'first'));
        $this->advance(30);

        $this->assertSame('MISS', $cache->process(self::request(headers: ['Cache-Control' => 'no-cache']), $this->route(body: 'second'))->getHeaderLine('X-Cache'));
        $this->assertSame('second', (string) $cache->process(self::request(), $this->route())->getBody(), 'no-cache refreshed the stored copy');

        $this->assertSame('MISS', $cache->process(self::request(headers: ['Pragma' => 'no-cache']), $this->route(body: 'third'))->getHeaderLine('X-Cache'));

        $this->advance(30);
        $this->assertSame('MISS', $cache->process(self::request(headers: ['Cache-Control' => 'max-age=10']), $this->route(body: 'fourth'))->getHeaderLine('X-Cache'), 'Older than the client accepts');

        $noStore = $cache->process(self::request(uri: '/v1/fresh', headers: ['Cache-Control' => 'no-store']), $this->route());
        $this->assertSame('BYPASS', $noStore->getHeaderLine('X-Cache'));
        $this->assertSame(504, $cache->process(self::request(uri: '/v1/fresh', headers: ['Cache-Control' => 'only-if-cached']), $this->route())->getStatusCode());
    }

    public function testRequestCacheControlCanBeIgnored(): void
    {
        $cache = $this->cache(['honour_request_cache_control' => false]);
        $cache->process(self::request(), $this->route());

        $this->assertSame('HIT', $cache->process(self::request(headers: ['Cache-Control' => 'no-cache']), $this->route())->getHeaderLine('X-Cache'));
    }

    public function testWritesInvalidateTheirPaths(): void
    {
        $cache = $this->cache();
        $cache->process(self::request(uri: '/v1/users/7'), $this->route());
        $cache->process(self::request(uri: '/v1/users/7?fields=name'), $this->route());
        $cache->process(self::request(uri: '/v1/users'), $this->route());

        $cache->process(self::request('PATCH', '/v1/users/7'), $this->route([], 400));
        $this->assertSame('HIT', $cache->process(self::request(uri: '/v1/users/7'), $this->route())->getHeaderLine('X-Cache'), 'A failed write changes nothing');

        $cache->process(self::request('PATCH', '/v1/users/7'), $this->route([], 204));
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/users/7'), $this->route())->getHeaderLine('X-Cache'));
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/users/7?fields=name'), $this->route())->getHeaderLine('X-Cache'), 'Every query of the path');
        $this->assertSame('HIT', $cache->process(self::request(uri: '/v1/users'), $this->route())->getHeaderLine('X-Cache'), 'Other paths are kept');

        $cache->process(self::request('POST', '/v1/user-imports'), $this->route(['Location' => 'https://api.example.com/v1/users'], 201));
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/users'), $this->route())->getHeaderLine('X-Cache'), 'The Location path too');
    }

    public function testTags(): void
    {
        $cache = $this->cache();
        $tagged = $cache->process(self::request(uri: '/v1/users/7/orders'), $this->route(['Cache-Control' => 'max-age=60', 'Cache-Tag' => 'user-7, orders']));
        $cache->process(self::request(uri: '/v1/users/8/orders'), $this->route(['Cache-Control' => 'max-age=60', 'Cache-Tag' => 'user-8 orders']));
        $this->assertFalse($tagged->hasHeader('Cache-Tag'), 'Stripped from clients by default');

        $this->store->invalidateTags('user-7');
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/users/7/orders'), $this->route())->getHeaderLine('X-Cache'));
        $this->assertSame('HIT', $cache->process(self::request(uri: '/v1/users/8/orders'), $this->route())->getHeaderLine('X-Cache'));

        $cache->process(self::request('POST', '/v1/orders'), $this->route(['Cache-Tag' => 'orders'], 201));
        $this->assertSame('MISS', $cache->process(self::request(uri: '/v1/users/8/orders'), $this->route())->getHeaderLine('X-Cache'), 'A write can invalidate tags');
    }

    public function testLargeResponsesAreNotStored(): void
    {
        $cache = $this->cache(['max_body_size' => 10]);

        $this->assertSame('BYPASS', $cache->process(self::request(), $this->route(body: str_repeat('x', 11)))->getHeaderLine('X-Cache'));
    }

    public function testIgnoredPathsAndOtherMethods(): void
    {
        $cache = $this->cache(['ignore_paths' => ['/status']]);

        $this->assertFalse($cache->process(self::request(uri: '/status'), $this->route())->hasHeader('X-Cache'));
        $this->assertFalse($cache->process(self::request('OPTIONS'), $this->route())->hasHeader('X-Cache'));
    }

    public function testCacheFailuresAreNotFatal(): void
    {
        $broken = new class extends ArrayAdapter {
            public function getItem(mixed $key): \Symfony\Component\Cache\CacheItem
            {
                throw new \RuntimeException('cache down');
            }
        };
        $cache = new Cache(settings: Settings::fromArray([]), store: new ResponseStore(new TagAwareAdapter($broken)), logger: new NullLogger());

        $this->assertSame(200, $cache->process(self::request(), $this->route())->getStatusCode());
        $this->assertSame(204, $cache->process(self::request('DELETE'), $this->route([], 204))->getStatusCode());
    }

    public function testStatusHeaderCanBeRenamedOrRemoved(): void
    {
        $this->assertSame('MISS', $this->cache(['status_header' => 'X-Kipchak-Cache'])->process(self::request(), $this->route())->getHeaderLine('X-Kipchak-Cache'));
        $this->assertSame([], array_filter(array_keys($this->cache(['status_header' => ''])->process(self::request(), $this->route())->getHeaders()), static fn ($h) => stripos($h, 'cache') === 0 && $h !== 'Cache-Control'));
    }
}
