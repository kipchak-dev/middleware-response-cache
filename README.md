# Response Cache Middleware for Kipchak

A shared HTTP cache in front of your routes. GET responses that say how long they stay fresh are stored and served
from the cache until then, without the route running; ETags and conditional requests save bandwidth on top. It
follows HTTP's own caching rules ([RFC 9111](https://www.rfc-editor.org/rfc/rfc9111) and
[RFC 9110](https://www.rfc-editor.org/rfc/rfc9110)), so the `Cache-Control` headers your routes send mean the same
to it as to a CDN.

* **Caching**: freshness from `Cache-Control` (`s-maxage`, `max-age`), `Expires`, or a TTL for a route; `Age` and
  `X-Cache: HIT|MISS|BYPASS` on responses; a separate copy for each value of the headers in `Vary`.
* **Safe by default**: `no-store`, `no-cache` and `private` responses, responses setting cookies, and `Vary: *`
  are never stored, and neither is a response to an authenticated request unless it says it may be shared.
* **ETags and 304s**: an ETag is added to responses without one, and `If-None-Match` or `If-Modified-Since`
  answer `304 Not Modified`, whether or not the response came from the cache.
* **Invalidation**: a successful `POST`, `PUT`, `PATCH` or `DELETE` removes the stored responses for its path, and
  for its `Location`; responses can carry tags (`Cache-Tag`) to remove them by tag from code.
* **Built on Kipchak's drivers**: responses are kept by the Filecache driver by default, or by the Memcached or
  Valkey driver to share them between hosts.

#### Composer Package

`kipchak/middleware-response-cache`

## Installation

```bash
composer require kipchak/middleware-response-cache
```

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.response-cache.php` and add the middleware in
`middlewares/middlewares.php` **before** the authentication middlewares, so it runs after them:

```php
use Kipchak\Middleware\ResponseCache\ResponseCache;

ResponseCache::initialise($app);
// Then the authentication middlewares.
```

With `'enabled' => true` it caches every route. Otherwise, attach it to the routes or groups that should be
cached, with a TTL for responses that do not set their own:

```php
use Kipchak\Middleware\ResponseCache\Handlers\Cache;

$app->get('/v1/countries', [Countries::class, 'list'])->add(new Cache($app->getContainer(), ttl: 3600));
```

The cache needs the Filecache driver initialised in `drivers/drivers.php` (or the Memcached or Valkey driver, if
you use one of those).

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Cache every route |
| `store` | `file` | `file` (Filecache driver), `memory`, or a container key such as `drivers.cache.valkey.cache` |
| `default_ttl` | none | Seconds to keep responses that do not say; none stores only those that do |
| `scope` | `shared` | `shared`, or `consumer` for a copy per consumer |
| `consumer` | from `kipchak.identity` | Who a request is from, for the `consumer` scope |
| `etags` | `true` | Add ETags and answer conditional requests |
| `honour_request_cache_control` | `true` | Obey clients' `no-cache`, `no-store`, `max-age` and `only-if-cached` |
| `invalidate_on_write` | `true` | Remove a path's responses after a successful unsafe request |
| `status_header` | `X-Cache` | `HIT`, `MISS` or `BYPASS` on every response; empty to leave it out |
| `tags_header` | `Cache-Tag` | Response header listing tags |
| `strip_tags_header` | `true` | Remove the tags header before the response leaves |
| `statuses` | RFC 9110's | Statuses that may be stored: 200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501 |
| `max_body_size` | 1 MiB | Larger responses are not stored |
| `ignore_paths` | none | Paths never cached |

| Store | Shared by | Notes |
|---|---|---|
| `file` | every worker on the host | The default |
| `drivers.cache.memcached.cache` | every host | |
| `drivers.cache.valkey.cache` | every host | Kipchak Enterprise |
| `memory` | one worker | Each worker keeps its own copies, and invalidation clears only that worker's. For a single worker or tests. |

## What is stored

A `GET` response is stored when all of these hold:

* its status is one RFC 9110 lets a cache store (200, 203, 204, 300, 301, 308, 404, 405, 410, 414, 501);
* it says how long it stays fresh, in order of precedence `s-maxage`, `max-age` or `Expires`, or the route or
  config gives a TTL;
* it is not marked `no-store`, `no-cache` or `private`, does not set a cookie, and does not say `Vary: *`;
* the request was not authenticated, or the response says `public`, `s-maxage` or `must-revalidate`
  ([RFC 9111 section 3.5](https://www.rfc-editor.org/rfc/rfc9111#section-3.5)).

A request counts as authenticated when it has an `Authorization` header or a consumer from
[Consumer Identity](https://kipchak.dev/docs/identity), such as an API key. With `'scope' => 'consumer'`, every
consumer has their own copies, so responses to authenticated requests and `private` responses are stored too, and
one consumer never receives another's.

Responses are stored per path and query (in any order), and per value of each request header the response lists
in `Vary`. `HEAD` requests are answered from a stored `GET` response.

To cache a route for clients and CDNs as well as here, send the header from the route:

```php
return Http\Response::json($response, $countries, 200)->withHeader('Cache-Control', 'public, max-age=3600');
```

## Conditional requests

A `200` response without an `ETag` gets one, from a hash of its body. A request with a matching `If-None-Match`
(weak comparison), or without one and with an `If-Modified-Since` no earlier than the response's `Last-Modified`,
is answered `304 Not Modified` with no body. This works for responses that were not stored too, so clients that
keep a copy save the transfer even for routes that are not cached.

## Clients' Cache-Control

| Request | Effect |
|---|---|
| `Cache-Control: no-cache` (or `Pragma: no-cache`) | Skips the stored response and refreshes it |
| `Cache-Control: max-age=N` | Skips a stored response older than N seconds |
| `Cache-Control: no-store` | Neither reads nor stores |
| `Cache-Control: only-if-cached` | `504` when nothing is stored |

A client could use `no-cache` to make every request run its route. Set `honour_request_cache_control` to `false` to
ignore clients' directives.

## Invalidation

A successful (2xx or 3xx) `POST`, `PUT`, `PATCH` or `DELETE` removes every stored response for its path, with any
query, variant or consumer, and for the path in its `Location` or `Content-Location` header
([RFC 9111 section 4.4](https://www.rfc-editor.org/rfc/rfc9111#section-4.4)).

For anything else, tag responses and remove them by tag:

```php
// When serving an order list:
return $response->withHeader('Cache-Control', 'public, max-age=300')->withHeader('Cache-Tag', "user-{$userId} orders");

// After a change made elsewhere, such as in a queue consumer:
use Kipchak\Middleware\ResponseCache\ResponseCache;

ResponseCache::invalidateTags("user-{$userId}");
ResponseCache::invalidatePaths('/v1/countries');
ResponseCache::clear();
```

A write's own `Cache-Tag` header invalidates those tags too. Invalidation is seen by every worker and host sharing
the store at once.

## Limits

* Concurrent requests for something not yet stored all run the route; there is no request coalescing.
* Stale responses are not revalidated with the route or served while it refreshes (`stale-while-revalidate`); a
  stale response is simply replaced.
* If the store is down, requests are served without the cache, and the error is logged once a minute.

## FrankenPHP worker mode

Settings and the store are built once per worker and shared by every request in it; nothing about a request is
kept after it.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run against the Filecache driver's filesystem pool, Valkey and Memcached:
docker run -d --name rc-valkey -p 56380:6379 valkey/valkey:8-alpine
docker run -d --name rc-memcached -p 51212:11211 memcached:1.6-alpine
vendor/bin/phpunit
```

## Licence

MIT
