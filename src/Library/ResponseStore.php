<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Library;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Stored responses, keyed by scope, path and query, with a variant for each set of values of the request headers
 * the response's Vary names (RFC 9111 section 4.1).
 *
 * Every entry is tagged with its path, and with the tags the response listed, so invalidating a path removes every
 * variant, query and scope of it.
 */
final readonly class ResponseStore
{
    public function __construct(private TagAwareAdapterInterface $cache)
    {
    }

    public function lookup(ServerRequestInterface $request, string $scope): ?Entry
    {
        $primary = self::primaryKey($request, $scope);
        $vary = $this->cache->getItem($primary . '.vary');
        if (!$vary->isHit() || !is_array($vary->get())) {
            return null;
        }

        $item = $this->cache->getItem(self::variantKey($primary, $vary->get(), $request));

        return $item->isHit() ? Entry::fromArray($item->get()) : null;
    }

    /**
     * @param list<string> $varyHeaders Lower case.
     * @param list<string> $tags
     */
    public function save(ServerRequestInterface $request, string $scope, Entry $entry, array $varyHeaders, array $tags): void
    {
        $primary = self::primaryKey($request, $scope);
        $cacheTags = [self::pathTag($request->getUri()->getPath()), ...array_map(self::tag(...), $tags)];

        $this->cache->saveDeferred($this->cache->getItem($primary . '.vary')->set($varyHeaders)->expiresAfter($entry->ttl)->tag($cacheTags));
        $this->cache->saveDeferred(
            $this->cache->getItem(self::variantKey($primary, $varyHeaders, $request))->set($entry->toArray())->expiresAfter($entry->ttl)->tag($cacheTags)
        );
        $this->cache->commit();
    }

    /**
     * Removes every stored response for these paths: all queries, variants and scopes.
     */
    public function invalidatePaths(string ...$paths): void
    {
        if ($paths !== []) {
            $this->cache->invalidateTags(array_map(self::pathTag(...), $paths));
        }
    }

    /**
     * Removes every stored response that listed one of these tags in its Cache-Tag header.
     */
    public function invalidateTags(string ...$tags): void
    {
        if ($tags !== []) {
            $this->cache->invalidateTags(array_map(self::tag(...), $tags));
        }
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    private static function primaryKey(ServerRequestInterface $request, string $scope): string
    {
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $pairs = explode('&', $query);
            sort($pairs);
            $query = implode('&', $pairs);
        }

        return 'r' . hash('sha256', $scope . "\0" . $request->getUri()->getPath() . "\0" . $query);
    }

    /**
     * @param list<string> $varyHeaders
     */
    private static function variantKey(string $primary, array $varyHeaders, ServerRequestInterface $request): string
    {
        $values = array_map(static fn (string $header): string => $request->getHeaderLine($header), $varyHeaders);

        return $primary . '.' . hash('sha256', json_encode([$varyHeaders, $values], JSON_THROW_ON_ERROR));
    }

    private static function pathTag(string $path): string
    {
        return 'p' . hash('sha1', $path === '' ? '/' : $path);
    }

    /**
     * Cache tags may not contain {}()/\@: so user tags are hashed.
     */
    private static function tag(string $tag): string
    {
        return 't' . hash('sha1', $tag);
    }
}
