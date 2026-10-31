<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Config;

use Kipchak\Identity\Identity;
use Kipchak\Identity\InvalidConfiguration as InvalidIdentity;

/**
 * The kipchak.response-cache settings, validated once per worker.
 */
final readonly class Settings
{
    public const string SCOPE_SHARED = 'shared';
    public const string SCOPE_CONSUMER = 'consumer';

    /** Statuses RFC 9110 section 15.1 lets a cache store. */
    public const array CACHEABLE_STATUSES = [200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501];

    /**
     * @param list<int> $statuses
     * @param list<string> $ignorePaths
     */
    public function __construct(
        public Identity $identity,
        public string $store = 'file',
        public ?int $defaultTtl = null,
        public string $scope = self::SCOPE_SHARED,
        public array $statuses = self::CACHEABLE_STATUSES,
        public bool $etags = true,
        public bool $honourRequestCacheControl = true,
        public string $statusHeader = 'X-Cache',
        public string $tagsHeader = 'Cache-Tag',
        public bool $stripTagsHeader = true,
        public bool $invalidateOnWrite = true,
        public int $maxBodySize = 1048576,
        public array $ignorePaths = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config The kipchak.response-cache config.
     * @param array<string, mixed> $sharedIdentity The kipchak.identity config.
     * @param array<string, mixed>|null $apiKeyConfig The kipchak.auth.key config, for the api_key source.
     */
    public static function fromArray(array $config, array $sharedIdentity = [], ?array $apiKeyConfig = null): self
    {
        try {
            $identity = Identity::merge($config, $sharedIdentity, $apiKeyConfig, 'kipchak.response-cache');
        } catch (InvalidIdentity $exception) {
            throw new InvalidConfiguration($exception->getMessage(), 0, $exception);
        }

        $store = $config['store'] ?? 'file';
        if (!is_string($store) || $store === '') {
            throw InvalidConfiguration::at('store', "must be 'file', 'memory' or a container key such as 'drivers.cache.valkey.cache'");
        }

        $defaultTtl = $config['default_ttl'] ?? null;
        if ($defaultTtl !== null && (!is_int($defaultTtl) || $defaultTtl < 1)) {
            throw InvalidConfiguration::at('default_ttl', 'must be null or a whole number of seconds of at least 1');
        }

        $scope = $config['scope'] ?? self::SCOPE_SHARED;
        if (!in_array($scope, [self::SCOPE_SHARED, self::SCOPE_CONSUMER], true)) {
            throw InvalidConfiguration::at('scope', "must be 'shared' or 'consumer'");
        }

        $statuses = $config['statuses'] ?? self::CACHEABLE_STATUSES;
        if (!is_array($statuses) || $statuses === [] || array_filter($statuses, static fn (mixed $s): bool => !is_int($s) || $s < 100 || $s > 599) !== []) {
            throw InvalidConfiguration::at('statuses', 'must list HTTP status codes');
        }

        $ignorePaths = $config['ignore_paths'] ?? [];
        if (!is_array($ignorePaths)) {
            throw InvalidConfiguration::at('ignore_paths', 'must be a list of paths');
        }

        $maxBodySize = $config['max_body_size'] ?? 1048576;
        if (!is_int($maxBodySize) || $maxBodySize < 1) {
            throw InvalidConfiguration::at('max_body_size', 'must be a whole number of bytes');
        }

        foreach (['status_header', 'tags_header'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '' && !preg_match('/^[A-Za-z0-9-]+$/', (string) $config[$key])) {
                throw InvalidConfiguration::at($key, 'must be a header name, or empty to leave it out');
            }
        }

        return new self(
            identity: $identity,
            store: $store,
            defaultTtl: $defaultTtl,
            scope: $scope,
            statuses: array_values($statuses),
            etags: (bool) ($config['etags'] ?? true),
            honourRequestCacheControl: (bool) ($config['honour_request_cache_control'] ?? true),
            statusHeader: (string) ($config['status_header'] ?? 'X-Cache'),
            tagsHeader: (string) ($config['tags_header'] ?? 'Cache-Tag'),
            stripTagsHeader: (bool) ($config['strip_tags_header'] ?? true),
            invalidateOnWrite: (bool) ($config['invalidate_on_write'] ?? true),
            maxBodySize: $maxBodySize,
            ignorePaths: array_values(array_map('strval', $ignorePaths)),
        );
    }

    public function withDefaultTtl(?int $ttl): self
    {
        if ($ttl !== null && $ttl < 1) {
            throw InvalidConfiguration::at('ttl', 'must be a whole number of seconds of at least 1');
        }

        return new self(
            $this->identity, $this->store, $ttl, $this->scope, $this->statuses, $this->etags, $this->honourRequestCacheControl,
            $this->statusHeader, $this->tagsHeader, $this->stripTagsHeader, $this->invalidateOnWrite, $this->maxBodySize, $this->ignorePaths,
        );
    }
}
