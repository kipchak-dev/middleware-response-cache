<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Library;

use Kipchak\Middleware\ResponseCache\Config\InvalidConfiguration;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Builds the response cache from the 'store' setting, wrapped so entries can be invalidated by tag:
 *
 *  - "file" (the default): Kipchak's Filecache driver, shared by every PHP process on the machine.
 *  - "memory": in the worker's memory, so each FrankenPHP worker keeps its own copy.
 *  - anything else: a container entry holding a PSR-6 or PSR-16 cache, such as "drivers.cache.valkey.cache" from
 *    the Valkey driver or "drivers.cache.memcached.cache" from the Memcached driver, shared by every host.
 */
final readonly class CacheFactory
{
    private const int MEMORY_ITEMS = 10000;
    private const string NAMESPACE = 'kipchak-response-cache';

    /** The container key of Kipchak's Filecache driver. */
    private const string FILECACHE = 'drivers.cache.file';

    public function __construct(private ContainerInterface $container)
    {
    }

    public function create(string $setting): TagAwareAdapterInterface
    {
        $pool = $setting === 'memory' ? new ArrayAdapter(maxItems: self::MEMORY_ITEMS) : $this->shared($setting === 'file' ? self::FILECACHE : $setting);

        // Tag versions are read on every lookup, not cached in the worker (Symfony's default is 0.15 seconds), so
        // an invalidation by one worker or host is seen by every other at once.
        return new TagAwareAdapter($pool, knownTagVersionsTtl: 0);
    }

    private function shared(string $service): CacheItemPoolInterface
    {
        if (!$this->container->has($service)) {
            throw InvalidConfiguration::at('store', $service === self::FILECACHE
                ? "is 'file', which uses the Filecache driver. Initialise Kipchak\\Driver\\Filecache\\Filecache in drivers/drivers.php"
                : "is '{$service}', which is not 'memory', 'file' or a container entry. Is the driver that provides it initialised?");
        }

        $cache = $this->container->get($service);

        return match (true) {
            $cache instanceof NamespacedPoolInterface && $cache instanceof CacheItemPoolInterface => $cache->withSubNamespace(self::NAMESPACE),
            $cache instanceof CacheItemPoolInterface => new ProxyAdapter($cache, self::NAMESPACE),
            $cache instanceof CacheInterface => new Psr16Adapter($cache, self::NAMESPACE),
            default => throw InvalidConfiguration::at('store', "'{$service}' is a " . get_debug_type($cache) . ', not a PSR-6 or PSR-16 cache'),
        };
    }
}
