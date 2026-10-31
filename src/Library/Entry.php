<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Library;

/**
 * A stored response.
 */
final readonly class Entry
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public int $storedAt,
        public int $ttl,
    ) {
    }

    public function age(int $now): int
    {
        return max(0, $now - $this->storedAt);
    }

    public function isFresh(int $now): bool
    {
        return $this->age($now) < $this->ttl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['s' => $this->status, 'h' => $this->headers, 'b' => $this->body, 't' => $this->storedAt, 'l' => $this->ttl];
    }

    public static function fromArray(mixed $stored): ?self
    {
        if (!is_array($stored) || !isset($stored['s'], $stored['h'], $stored['t'], $stored['l'])) {
            return null;
        }

        return new self((int) $stored['s'], (array) $stored['h'], (string) ($stored['b'] ?? ''), (int) $stored['t'], (int) $stored['l']);
    }
}
