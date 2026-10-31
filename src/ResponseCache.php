<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache;

use Kipchak\Core\Components\App;
use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Middleware\ResponseCache\Config\Settings;
use Kipchak\Middleware\ResponseCache\Handlers\Cache;
use Kipchak\Middleware\ResponseCache\Library\CacheFactory;
use Kipchak\Middleware\ResponseCache\Library\ResponseStore;
use Psr\Container\ContainerInterface;

final class ResponseCache implements Interfaces\Middleware
{
    /** The container entry holding the ResponseStore every handler in the worker shares. */
    public const string STORE_KEY = 'middlewares.response-cache.store';

    private static ?ContainerInterface $container = null;

    /**
     * Caches responses for every route when kipchak.response-cache has 'enabled' => true. Leave it disabled to
     * attach Handlers\Cache to individual routes or groups instead, optionally with their own TTL:
     *
     *   $group->get('/countries', ...)->add(new Cache($app->getContainer(), ttl: 3600));
     *
     * Call this before the authentication middlewares in middlewares/middlewares.php. Slim runs the last-added
     * middleware first, so the cache then runs after authentication and can tell authenticated requests apart.
     */
    public static function initialise(App $app): void
    {
        $container = $app->getContainer();
        self::$container = $container;
        $config = Config::get(Cache::CONFIG_KEY);
        $settings = Settings::fromArray($config, Config::get('kipchak.identity'), Config::get('kipchak.auth.key') ?: null);

        $container->set(self::STORE_KEY, static fn (ContainerInterface $c): ResponseStore => new ResponseStore((new CacheFactory($c))->create($settings->store)));

        if (($config['enabled'] ?? false) === true) {
            $app->add(new Cache($container, settings: $settings));
        }
    }

    /**
     * Removes every stored response for these paths, with any query, variant or consumer, such as after a change
     * made outside an HTTP request.
     */
    public static function invalidatePaths(string ...$paths): void
    {
        self::store()->invalidatePaths(...$paths);
    }

    /**
     * Removes every stored response whose Cache-Tag header listed one of these tags.
     */
    public static function invalidateTags(string ...$tags): void
    {
        self::store()->invalidateTags(...$tags);
    }

    public static function clear(): void
    {
        self::store()->clear();
    }

    /**
     * The worker's shared store, built on first use.
     */
    public static function store(?ContainerInterface $container = null, ?Settings $settings = null): ResponseStore
    {
        $container ??= self::$container;
        if ($container !== null && $container->has(self::STORE_KEY)) {
            return $container->get(self::STORE_KEY);
        }
        if ($container === null) {
            throw new \LogicException('Call ResponseCache::initialise($app) in middlewares/middlewares.php before using the response cache.');
        }

        $store = new ResponseStore((new CacheFactory($container))->create(($settings ?? Settings::fromArray(Config::get(Cache::CONFIG_KEY)))->store));
        $container->set(self::STORE_KEY, $store);

        return $store;
    }
}
