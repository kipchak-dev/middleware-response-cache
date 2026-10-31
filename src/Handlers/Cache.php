<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Handlers;

use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Middleware\ResponseCache\Config\Settings;
use Kipchak\Middleware\ResponseCache\Library\CacheControl;
use Kipchak\Middleware\ResponseCache\Library\Entry;
use Kipchak\Middleware\ResponseCache\Library\ResponseStore;
use Kipchak\Middleware\ResponseCache\ResponseCache;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/**
 * A shared HTTP cache in front of your routes (RFC 9111), with ETags and conditional requests (RFC 9110).
 *
 * GET responses are stored when they say how long they stay fresh (Cache-Control s-maxage or max-age, or Expires),
 * or when the route or config gives a default TTL, and are served from the cache until then, with Age and
 * X-Cache: HIT. Responses marked no-store, no-cache or private, those setting cookies, and Vary: * are never stored.
 * A response to an authenticated request is stored only when it says public, s-maxage or must-revalidate, as RFC
 * 9111 section 3.5 requires of shared caches, unless the cache is scoped per consumer.
 *
 * ETags are added to responses without one, and If-None-Match or If-Modified-Since answer 304 Not Modified,
 * whether or not the response came from the cache. A successful POST, PUT, PATCH or DELETE removes the stored
 * responses for its path (RFC 9111 section 4.4); Cache-Tag on a response lets code remove it by tag.
 *
 * Register with ResponseCache::initialise($app) before the authentication middlewares, so it runs after them.
 * Safe in FrankenPHP worker mode: nothing about a request is kept after it.
 */
final class Cache implements MiddlewareInterface
{
    public const string CONFIG_KEY = 'kipchak.response-cache';

    public const string HIT = 'HIT';
    public const string MISS = 'MISS';
    public const string BYPASS = 'BYPASS';

    private const array UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Headers that belong to one response, not to what is stored. */
    private const array UNSTORED_HEADERS = ['age', 'date', 'set-cookie', 'content-length', 'transfer-encoding', 'connection', 'keep-alive'];

    /** Headers a 304 carries (RFC 9110 section 15.4.5), and Last-Modified. */
    private const array NOT_MODIFIED_HEADERS = ['cache-control', 'content-location', 'date', 'etag', 'expires', 'vary', 'last-modified', 'age'];

    private const int FAILURE_LOG_INTERVAL = 60;

    private readonly Settings $settings;
    private readonly LoggerInterface $logger;
    private int $lastFailureLoggedAt = 0;

    /**
     * @param int|null $ttl Seconds to store responses that do not say how long they stay fresh, for this route or
     *                      group; overrides default_ttl.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        ?int $ttl = null,
        ?Settings $settings = null,
        private ?ResponseStore $store = null,
        private readonly ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $settings ??= Settings::fromArray(Config::get(self::CONFIG_KEY), Config::get('kipchak.identity'), Config::get('kipchak.auth.key') ?: null);
        $this->settings = $ttl === null ? $settings : $settings->withDefaultTtl($ttl);
        $this->logger = $logger ?? Logger::get();
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->process($request, $handler);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array($request->getUri()->getPath(), $this->settings->ignorePaths, true)) {
            return $handler->handle($request);
        }
        if (in_array($method, self::UNSAFE, true)) {
            return $this->write($request, $handler);
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $handler->handle($request);
        }

        $requestControl = $this->settings->honourRequestCacheControl ? self::requestControl($request) : new CacheControl();
        if ($requestControl->has('no-store')) {
            return $this->finish($request, $this->tagged($handler->handle($request))[0], self::BYPASS);
        }

        $scope = $this->scope($request);
        if (!$requestControl->has('no-cache') && $requestControl->seconds('max-age') !== 0) {
            $entry = $this->lookup($request, $scope);
            $now = $this->now();
            if ($entry !== null && $entry->isFresh($now) && ($requestControl->seconds('max-age') === null || $entry->age($now) <= $requestControl->seconds('max-age'))) {
                return $this->finish($request, $this->replay($entry, $now), self::HIT);
            }
            if ($requestControl->has('only-if-cached')) {
                return new Response(504);
            }
        }

        [$response, $tags] = $this->tagged($handler->handle($request));
        if ($method === 'HEAD') {
            return $this->finish($request, $response, self::BYPASS);
        }

        $response = $this->withEtag($response);
        $ttl = $this->storableFor($request, $response, $scope);
        if ($ttl === null) {
            return $this->finish($request, $response, self::BYPASS);
        }

        $body = (string) $response->getBody();
        $response = $response->withBody((new StreamFactory())->createStream($body));
        $this->save($request, $scope, $response, $body, $ttl, $tags);

        return $this->finish($request, $response, self::MISS);
    }

    /**
     * Unsafe methods pass through, and a success removes what is stored for the request's path and for the paths
     * in its Location and Content-Location headers.
     */
    private function write(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$response, $tags] = $this->tagged($handler->handle($request));
        $status = $response->getStatusCode();
        if (!$this->settings->invalidateOnWrite || $status < 200 || $status >= 400) {
            return $response;
        }

        $paths = [$request->getUri()->getPath()];
        foreach (['Location', 'Content-Location'] as $header) {
            $path = self::sameOriginPath($request, $response->getHeaderLine($header));
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        try {
            $this->store()->invalidatePaths(...array_values(array_unique($paths)));
            $this->store()->invalidateTags(...$tags);
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }

        return $response;
    }

    /**
     * How long the response may be stored, or null when it must not be.
     */
    private function storableFor(ServerRequestInterface $request, ResponseInterface $response, string $scope): ?int
    {
        if (!in_array($response->getStatusCode(), $this->settings->statuses, true) || $response->hasHeader('Set-Cookie')) {
            return null;
        }

        $control = CacheControl::parse($response->getHeaderLine('Cache-Control'));
        if ($control->has('no-store') || $control->has('no-cache')) {
            return null;
        }
        $perConsumer = $this->settings->scope === Settings::SCOPE_CONSUMER;
        if ($control->has('private') && !$perConsumer) {
            return null;
        }
        if (trim($response->getHeaderLine('Vary')) === '*') {
            return null;
        }
        if (!$perConsumer && $this->isAuthenticated($request)
            && !$control->has('public') && !$control->has('s-maxage') && !$control->has('must-revalidate')) {
            return null;
        }

        $ttl = $control->seconds('s-maxage') ?? $control->seconds('max-age') ?? self::expires($response) ?? $this->settings->defaultTtl;
        if ($ttl === null || $ttl < 1) {
            return null;
        }

        $size = $response->getBody()->getSize();

        return $size !== null && $size > $this->settings->maxBodySize ? null : $ttl;
    }

    /**
     * @param list<string> $tags
     */
    private function save(ServerRequestInterface $request, string $scope, ResponseInterface $response, string $body, int $ttl, array $tags): void
    {
        if (strlen($body) > $this->settings->maxBodySize) {
            return;
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::UNSTORED_HEADERS, true) && $lower !== strtolower($this->settings->statusHeader)) {
                $headers[(string) $name] = array_values($values);
            }
        }
        $vary = array_values(array_filter(array_map(
            static fn (string $header): string => strtolower(trim($header)),
            explode(',', $response->getHeaderLine('Vary')),
        )));

        try {
            $this->store()->save($request, $scope, new Entry($response->getStatusCode(), $headers, $body, $this->now(), $ttl), $vary, $tags);
        } catch (\Throwable $exception) {
            $this->failed($exception);
        }
    }

    private function lookup(ServerRequestInterface $request, string $scope): ?Entry
    {
        try {
            return $this->store()->lookup($request, $scope);
        } catch (\Throwable $exception) {
            $this->failed($exception);

            return null;
        }
    }

    private function replay(Entry $entry, int $now): ResponseInterface
    {
        $response = new Response($entry->status);
        foreach ($entry->headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response
            ->withHeader('Age', (string) $entry->age($now))
            ->withBody((new StreamFactory())->createStream($entry->body));
    }

    /**
     * The status header, a 304 for a matching conditional request, and no body for HEAD.
     */
    private function finish(ServerRequestInterface $request, ResponseInterface $response, string $status): ResponseInterface
    {
        if ($this->settings->statusHeader !== '') {
            $response = $response->withHeader($this->settings->statusHeader, $status);
        }

        if (self::notModified($request, $response)) {
            $notModified = new Response(304);
            foreach ($response->getHeaders() as $name => $values) {
                $lower = strtolower((string) $name);
                if (in_array($lower, self::NOT_MODIFIED_HEADERS, true) || $lower === strtolower($this->settings->statusHeader)) {
                    $notModified = $notModified->withHeader($name, $values);
                }
            }

            return $notModified;
        }

        return strtoupper($request->getMethod()) === 'HEAD' ? $response->withBody((new StreamFactory())->createStream('')) : $response;
    }

    /**
     * Whether the client's copy is current (RFC 9110 section 13.1): If-None-Match against the ETag by weak
     * comparison, or, without If-None-Match, If-Modified-Since against Last-Modified. Only for 200 responses.
     */
    public static function notModified(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            $etag = $response->getHeaderLine('ETag');
            if (trim($ifNoneMatch) === '*') {
                return $etag !== '';
            }

            return $etag !== '' && in_array(self::weak($etag), array_map(self::weak(...), array_map('trim', explode(',', $ifNoneMatch))), true);
        }

        $ifModifiedSince = strtotime($request->getHeaderLine('If-Modified-Since'));
        $lastModified = strtotime($response->getHeaderLine('Last-Modified'));

        return $ifModifiedSince !== false && $lastModified !== false && $lastModified <= $ifModifiedSince;
    }

    private static function weak(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    /**
     * Adds a strong ETag, from a hash of the body, to a 200 response that has none.
     */
    private function withEtag(ResponseInterface $response): ResponseInterface
    {
        if (!$this->settings->etags || $response->getStatusCode() !== 200 || $response->hasHeader('ETag')) {
            return $response;
        }
        $size = $response->getBody()->getSize();
        if ($size !== null && $size > $this->settings->maxBodySize) {
            return $response;
        }

        $body = (string) $response->getBody();

        return $response
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('ETag', '"' . substr(hash('sha256', $body), 0, 32) . '"');
    }

    /**
     * The response without its tags header (when strip_tags_header), and the tags it listed.
     *
     * @return array{0: ResponseInterface, 1: list<string>}
     */
    private function tagged(ResponseInterface $response): array
    {
        if ($this->settings->tagsHeader === '' || !$response->hasHeader($this->settings->tagsHeader)) {
            return [$response, []];
        }

        $tags = array_values(array_unique(array_filter(preg_split('/[\s,]+/', $response->getHeaderLine($this->settings->tagsHeader)) ?: [])));

        return [$this->settings->stripTagsHeader ? $response->withoutHeader($this->settings->tagsHeader) : $response, $tags];
    }

    private function scope(ServerRequestInterface $request): string
    {
        return $this->settings->scope === Settings::SCOPE_CONSUMER
            ? 'consumer:' . ($this->settings->identity->consumerOf($request) ?? '')
            : 'shared';
    }

    /**
     * Whether the request carries credentials: an Authorization header, or a consumer the identity sources found
     * (such as an API key header).
     */
    private function isAuthenticated(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('Authorization') || $this->settings->identity->consumerOf($request) !== null;
    }

    private static function requestControl(ServerRequestInterface $request): CacheControl
    {
        $control = CacheControl::parse($request->getHeaderLine('Cache-Control'));

        // HTTP/1.0's Pragma: no-cache, honoured when Cache-Control is absent (RFC 9111 section 5.4).
        if (!$request->hasHeader('Cache-Control') && stripos($request->getHeaderLine('Pragma'), 'no-cache') !== false) {
            return new CacheControl(['no-cache' => true]);
        }

        return $control;
    }

    /**
     * Seconds until Expires, measured from Date (or now), or null without a valid Expires.
     */
    private static function expires(ResponseInterface $response): ?int
    {
        if (!$response->hasHeader('Expires')) {
            return null;
        }
        $expires = strtotime($response->getHeaderLine('Expires'));
        if ($expires === false) {
            return 0; // an invalid Expires means already expired
        }
        $date = strtotime($response->getHeaderLine('Date')) ?: time();

        return max(0, $expires - $date);
    }

    private static function sameOriginPath(ServerRequestInterface $request, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        $parts = parse_url($location);
        if ($parts === false || (isset($parts['host']) && strcasecmp($parts['host'], $request->getUri()->getHost()) !== 0)) {
            return null;
        }

        return $parts['path'] ?? null;
    }

    private function store(): ResponseStore
    {
        return $this->store ??= ResponseCache::store($this->container, $this->settings);
    }

    private function failed(\Throwable $exception): void
    {
        if (time() - $this->lastFailureLoggedAt < self::FAILURE_LOG_INTERVAL) {
            return;
        }
        $this->lastFailureLoggedAt = time();

        $this->logger->error('Response cache: the cache is unavailable; responses are served without it.', ['error' => $exception->getMessage()]);
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
