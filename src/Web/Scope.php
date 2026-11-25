<?php

declare(strict_types=1);

namespace Viz\Web;

/**
 * Project scope: all projects or selected ones — the app's only project filter (overview views `ScopeViews`, Overview,
 * "all sessions" `s=all` with all tabs and CSV). Project = session working directory, key = `App::fileHash(cwd)` (12 hex).
 *
 * One choice for the whole app: the `projects` parameter (comma-separated list; empty = all) takes precedence over the cookie
 * `viz-scope`, which sets the choice in the left panel (`Viz.Scope`). The cookie also goes with `/feed.json` polling (all overview views),
 * so there a choice change works without a page reload; Overview and `s=all` reload (`viz-scope-reload`).
 */
final class Scope
{
    public const PARAM = 'projects';
    public const COOKIE = 'viz-scope';
    private const MAX = 50;

    /** @param list<string> $keys empty list = all projects */
    private function __construct(public readonly array $keys)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $cookies
     */
    public static function fromRequest(array $query, array $cookies): self
    {
        if (is_string($query[self::PARAM] ?? null)) {
            return self::parse($query[self::PARAM]);
        }

        return self::parse(is_string($cookies[self::COOKIE] ?? null) ? $cookies[self::COOKIE] : '');
    }

    /** Whitelist: only 12-hex keys, no repeats, max `MAX`; the rest skipped. */
    public static function parse(string $raw): self
    {
        $keys = [];
        foreach (explode(',', $raw) as $key) {
            $key = trim($key);
            if (preg_match('/^[a-f0-9]{12}$/', $key) && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return new self(array_slice($keys, 0, self::MAX));
    }

    public function all(): bool
    {
        return $this->keys === [];
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /** A session with this working directory belongs to the scope; a session without a directory only with "all". */
    public function includes(?string $cwd): bool
    {
        if ($this->all()) {
            return true;
        }

        return $cwd !== null && $cwd !== '' && $this->has(App::fileHash($cwd));
    }

    public function value(): string
    {
        return implode(',', $this->keys);
    }
}
