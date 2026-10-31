<?php

/**
 * Copy to config/kipchak.response-cache.php.
 *
 * A shared HTTP cache (RFC 9111) in front of your routes, with ETags and conditional requests.
 * See https://kipchak.dev/docs/mw-response-cache.
 */

use function Kipchak\Core\env;

return [
    // Cache every route (ResponseCache::initialise). Leave false to attach
    // Kipchak\Middleware\ResponseCache\Handlers\Cache to routes or groups instead, with their own TTL.
    'enabled' => false,

    // Where responses are kept: 'file' (the Filecache driver; one host), 'memory' (each worker its own), or a
    // container key such as 'drivers.cache.valkey.cache' (Valkey driver) or 'drivers.cache.memcached.cache'
    // (Memcached driver), shared by every host.
    'store' => env('RESPONSE_CACHE_STORE', 'file'),

    // Seconds to keep responses that do not say (no Cache-Control max-age, s-maxage or Expires). null stores only
    // responses that say.
    'default_ttl' => null,

    // 'shared': one copy for everyone; responses to authenticated requests are stored only when they say public,
    // s-maxage or must-revalidate. 'consumer': a copy per consumer (kipchak.identity), so authenticated and
    // private responses can be cached too.
    'scope' => 'shared',
    // 'consumer' => [['source' => 'token', 'claim' => 'client_id']],

    'etags' => true,                        // add an ETag to 200 responses without one; answer 304s
    'honour_request_cache_control' => true, // clients' no-cache, no-store, max-age and only-if-cached
    'invalidate_on_write' => true,          // successful POST, PUT, PATCH and DELETE remove their path

    'status_header' => 'X-Cache',           // HIT, MISS or BYPASS; '' to leave it out
    'tags_header' => 'Cache-Tag',           // response header listing tags to invalidate by
    'strip_tags_header' => true,

    'max_body_size' => 1048576,             // bytes; larger responses are not stored
    'ignore_paths' => ['/status'],
];
