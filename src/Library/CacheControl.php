<?php

declare(strict_types=1);

namespace Kipchak\Middleware\ResponseCache\Library;

/**
 * A parsed Cache-Control header (RFC 9111 section 5.2): directive names, lower case, mapped to their value, or true
 * for directives without one.
 */
final readonly class CacheControl
{
    /**
     * @param array<string, string|true> $directives
     */
    public function __construct(public array $directives = [])
    {
    }

    public static function parse(string $header): self
    {
        $directives = [];
        if (preg_match_all('/([!#$%&\'*+.^_`|~0-9A-Za-z-]+)(?:\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^,\s]*)))?/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                if (isset($directives[$name])) {
                    continue; // the first occurrence wins
                }
                $directives[$name] = isset($match[2]) && $match[2] !== '' ? stripslashes($match[2]) : (($match[3] ?? '') !== '' ? $match[3] : true);
            }
        }

        return new self($directives);
    }

    public function has(string $directive): bool
    {
        return isset($this->directives[$directive]);
    }

    /**
     * A directive's number of seconds, or null when absent or not a number. A list-valued directive such as
     * private="Set-Cookie" is still present for has().
     */
    public function seconds(string $directive): ?int
    {
        $value = $this->directives[$directive] ?? null;

        return is_string($value) && ctype_digit($value) ? (int) min($value, PHP_INT_MAX) : null;
    }
}
