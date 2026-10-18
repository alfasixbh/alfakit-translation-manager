<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use JsonException;

/**
 * One translation key as Laravel addresses it: a namespace (`*` for the application), a
 * group (a PHP file name, or `*` for the JSON files) and the key inside the group (dotted
 * for nested arrays).
 *
 * ```php
 * TranslationKey::parse('auth.failed');           // namespace *, group auth, key failed
 * TranslationKey::parse('alfakit::ui.save');      // namespace alfakit, group ui, key save
 * TranslationKey::parse('Welcome back, :name!');  // a JSON key
 * ```
 */
final class TranslationKey
{
    public const WILDCARD = '*';

    public function __construct(
        public readonly string $namespace,
        public readonly string $group,
        public readonly string $key,
    ) {}

    public static function json(string $key): self
    {
        return new self(self::WILDCARD, self::WILDCARD, $key);
    }

    /**
     * The key behind a `__()` / `trans()` argument. Strings with whitespace (or without a
     * `group.key` shape) are JSON keys, like Laravel's lookup order.
     */
    public static function parse(string $usage): self
    {
        $namespace = self::WILDCARD;
        $rest = $usage;

        if (preg_match('/^([A-Za-z0-9_\-.]+)::(.+)$/s', $usage, $matches) === 1) {
            [$namespace, $rest] = [$matches[1], $matches[2]];
        }

        if (preg_match('/^([A-Za-z0-9_\-\/]+)\.([^\s]+)$/', $rest, $matches) === 1 && ! str_ends_with($rest, '.')) {
            return new self($namespace, $matches[1], $matches[2]);
        }

        return $namespace === self::WILDCARD ? self::json($usage) : new self($namespace, $rest, '');
    }

    public function isJson(): bool
    {
        return $this->group === self::WILDCARD;
    }

    public function isVendor(): bool
    {
        return $this->namespace !== self::WILDCARD;
    }

    /**
     * A URL-safe id the renderer posts back (decoded and checked against the catalog).
     */
    public function id(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([$this->namespace, $this->group, $this->key], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    public static function fromId(mixed $id): ?self
    {
        if (! is_string($id) || $id === '' || strlen($id) > 8192) {
            return null;
        }

        $json = base64_decode(strtr($id, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            $parts = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($parts) || count($parts) !== 3 || ! array_is_list($parts)) {
            return null;
        }

        [$namespace, $group, $key] = $parts;

        return is_string($namespace) && is_string($group) && is_string($key) ? new self($namespace, $group, $key) : null;
    }

    /**
     * What a developer writes in `__()`: `group.key`, `namespace::group.key` or the JSON key.
     */
    public function label(): string
    {
        if ($this->isJson()) {
            return $this->key;
        }

        return ($this->isVendor() ? $this->namespace.'::' : '').$this->group.($this->key === '' ? '' : '.'.$this->key);
    }

    /**
     * The group filter value: `json`, `auth`, `alfakit::ui`.
     */
    public function groupId(): string
    {
        if ($this->isJson()) {
            return 'json';
        }

        return ($this->isVendor() ? $this->namespace.'::' : '').$this->group;
    }

    public function hash(): string
    {
        return sha1($this->key);
    }

    public function equals(self $other): bool
    {
        return $this->namespace === $other->namespace && $this->group === $other->group && $this->key === $other->key;
    }

    /**
     * The catalog index of the key (namespace, group and key joined by NUL bytes).
     */
    public function index(): string
    {
        return $this->namespace."\0".$this->group."\0".$this->key;
    }

    public static function fromIndex(string $index): self
    {
        [$namespace, $group, $key] = array_pad(explode("\0", $index, 3), 3, '');

        return new self($namespace, $group, $key);
    }
}
