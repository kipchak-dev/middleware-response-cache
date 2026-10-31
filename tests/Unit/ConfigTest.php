<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Tests\Unit;

use DI\Container;
use Kipchak\Middleware\ResponseCache\Config\InvalidConfiguration;
use Kipchak\Middleware\ResponseCache\Config\Settings;
use Kipchak\Middleware\ResponseCache\Library\CacheControl;
use Kipchak\Middleware\ResponseCache\Library\CacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Psr16Cache;

final class ConfigTest extends TestCase
{
    public function testCacheControlParsing(): void
    {
        $control = CacheControl::parse('Public, max-age=60, s-maxage="120", private="Set-Cookie, X-Token", no-transform, max-age=5');

        $this->assertTrue($control->has('public'));
        $this->assertSame(60, $control->seconds('max-age'), 'The first occurrence wins');
        $this->assertSame(120, $control->seconds('s-maxage'));
        $this->assertTrue($control->has('private'));
        $this->assertNull($control->seconds('private'));
        $this->assertTrue($control->has('no-transform'));
        $this->assertFalse($control->has('no-store'));
        $this->assertSame([], CacheControl::parse('')->directives);
        $this->assertNull(CacheControl::parse('max-age=-1')->seconds('max-age'));
    }

    public function testDefaults(): void
    {
        $settings = Settings::fromArray([]);

        $this->assertSame('file', $settings->store);
        $this->assertNull($settings->defaultTtl);
        $this->assertSame('shared', $settings->scope);
        $this->assertTrue($settings->etags);
        $this->assertSame('X-Cache', $settings->statusHeader);
        $this->assertSame('Cache-Tag', $settings->tagsHeader);
        $this->assertTrue($settings->invalidateOnWrite);
    }

    public static function invalid(): iterable
    {
        yield 'store' => [['store' => ''], 'store'];
        yield 'default ttl' => [['default_ttl' => 0], 'default_ttl'];
        yield 'scope' => [['scope' => 'private'], 'scope'];
        yield 'statuses' => [['statuses' => [200, 'ok']], 'statuses'];
        yield 'max body' => [['max_body_size' => -1], 'max_body_size'];
        yield 'status header' => [['status_header' => 'X Cache'], 'status_header'];
        yield 'identity' => [['consumer' => [['source' => 'nowhere']]], 'source'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidConfig(array $config, string $key): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($key);
        Settings::fromArray($config);
    }

    public function testRouteTtl(): void
    {
        $this->assertSame(30, Settings::fromArray([])->withDefaultTtl(30)->defaultTtl);

        $this->expectException(InvalidConfiguration::class);
        Settings::fromArray([])->withDefaultTtl(0);
    }

    public function testCacheStores(): void
    {
        $container = new Container();
        $container->set('drivers.cache.file', new ArrayAdapter());
        $container->set('drivers.cache.valkey.cache', new ArrayAdapter());
        $container->set('psr16', new Psr16Cache(new ArrayAdapter()));
        $factory = new CacheFactory($container);

        foreach (['memory', 'file', 'drivers.cache.valkey.cache', 'psr16'] as $store) {
            $this->assertInstanceOf(TagAwareAdapterInterface::class, $factory->create($store), $store);
        }
    }

    public function testMissingStoresAreExplained(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Initialise Kipchak\Driver\Filecache\Filecache');
        (new CacheFactory(new Container()))->create('file');
    }

    public function testUnknownStoresAreExplained(): void
    {
        $container = new Container();
        $container->set('not-a-cache', new \stdClass());

        $this->expectExceptionMessage('not a PSR-6 or PSR-16 cache');
        (new CacheFactory($container))->create('not-a-cache');
    }
}
