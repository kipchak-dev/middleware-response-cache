<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Tests\Integration;

use DI\Container;
use Kipchak\Middleware\ResponseCache\Config\Settings;
use Kipchak\Middleware\ResponseCache\Handlers\Cache;
use Kipchak\Middleware\ResponseCache\Library\CacheFactory;
use Kipchak\Middleware\ResponseCache\Library\ResponseStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The cache on the pools the Filecache, Valkey and Memcached drivers provide, each through a separate store per
 * "worker" so nothing is shared in PHP memory. Start Valkey and Memcached with:
 *
 *   docker run -d --name rc-valkey -p 56380:6379 valkey/valkey:8-alpine
 *   docker run -d --name rc-memcached -p 51212:11211 memcached:1.6-alpine
 *
 * Tests for a service that is not running are skipped.
 */
final class StoresTest extends TestCase
{
    private static string $directory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/kipchak-response-cache-test-' . getmypid();
    }

    public static function pools(): iterable
    {
        yield 'filecache' => ['file'];
        yield 'valkey' => ['valkey'];
        yield 'memcached' => ['memcached'];
    }

    private function pool(string $type): CacheItemPoolInterface
    {
        return match ($type) {
            'file' => new FilesystemAdapter('kipchak', 3600, self::$directory),
            'valkey' => $this->reachable('127.0.0.1', 56380) ? new RedisAdapter(RedisAdapter::createConnection('redis://127.0.0.1:56380'), 'kipchak') : $this->skip('Valkey'),
            'memcached' => $this->reachable('127.0.0.1', 51212) ? new MemcachedAdapter(MemcachedAdapter::createConnection('memcached://127.0.0.1:51212'), 'kipchak') : $this->skip('Memcached'),
        };
    }

    /**
     * A separate middleware and store on a fresh connection to the same cache, as another worker or host would have.
     */
    private function worker(string $type): Cache
    {
        $container = new Container();
        $container->set('drivers.cache.shared', $this->pool($type));
        $store = new ResponseStore((new CacheFactory($container))->create('drivers.cache.shared'));

        return new Cache(settings: Settings::fromArray([]), store: $store, logger: new NullLogger());
    }

    private function reachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function skip(string $service): never
    {
        $this->markTestSkipped("{$service} is not running");
    }

    private static function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://api.example.com' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private static function route(string $body, array $headers = ['Cache-Control' => 'max-age=60'], int $status = 200): RequestHandlerInterface
    {
        return new class ($body, $headers, $status) implements RequestHandlerInterface {
            public function __construct(private string $body, private array $headers, private int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response($this->status);
                $response->getBody()->write($this->body);
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }

    #[DataProvider('pools')]
    public function testSharedBetweenWorkers(string $type): void
    {
        $path = '/v1/test/' . bin2hex(random_bytes(4));
        $first = $this->worker($type);
        $second = $this->worker($type);

        $first->process(self::request('GET', $path), self::route("binary \x00\xff body", ['Cache-Control' => 'max-age=60', 'Vary' => 'Accept', 'Cache-Tag' => 'shared-tag']));
        $hit = $second->process(self::request('GET', $path), self::route('never served'));

        $this->assertSame('HIT', $hit->getHeaderLine('X-Cache'), 'Another worker serves what the first stored');
        $this->assertSame("binary \x00\xff body", (string) $hit->getBody());
        $this->assertSame('MISS', $second->process(self::request('GET', $path, ['Accept' => 'text/csv']), self::route('csv'))->getHeaderLine('X-Cache'), 'Vary');

        $second->process(self::request('DELETE', $path), self::route('', [], 204));
        $this->assertSame('MISS', $first->process(self::request('GET', $path), self::route('again'))->getHeaderLine('X-Cache'), 'A write on one worker invalidates for all');
    }

    #[DataProvider('pools')]
    public function testTagInvalidationReachesEveryWorker(string $type): void
    {
        $path = '/v1/tagged/' . bin2hex(random_bytes(4));
        $tag = 'tag-' . bin2hex(random_bytes(4));
        $this->worker($type)->process(self::request('GET', $path), self::route('tagged', ['Cache-Control' => 'max-age=60', 'Cache-Tag' => $tag]));

        $container = new Container();
        $container->set('drivers.cache.shared', $this->pool($type));
        (new ResponseStore((new CacheFactory($container))->create('drivers.cache.shared')))->invalidateTags($tag);

        $this->assertSame('MISS', $this->worker($type)->process(self::request('GET', $path), self::route('fresh'))->getHeaderLine('X-Cache'));
    }
}
