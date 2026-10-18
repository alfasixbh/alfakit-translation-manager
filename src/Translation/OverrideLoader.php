<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Translation;

use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;

/**
 * Wraps the application's translation loader and puts the database overrides on top of
 * what it loads: lang files < `lang/vendor` overrides < database (< tenant overrides when
 * the plugin is tenant-scoped). The overrides come from the cache, so a request costs at
 * most one query per locale after every save.
 */
class OverrideLoader implements Loader
{
    /**
     * @param  Closure(): TranslationManager  $manager
     */
    public function __construct(
        protected Loader $loader,
        protected Closure $manager,
    ) {}

    public function getLoader(): Loader
    {
        return $this->loader;
    }

    /**
     * @return array<mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->loader->load($locale, $group, $namespace);
        $namespace = $namespace === null || $namespace === '' ? TranslationKey::WILDCARD : $namespace;
        $overrides = ($this->manager)()->overridesFor((string) $locale)[$namespace][$group] ?? [];

        if ($overrides === []) {
            return $lines;
        }

        if ($group === TranslationKey::WILDCARD && $namespace === TranslationKey::WILDCARD) {
            return array_replace($lines, $overrides);
        }

        foreach ($overrides as $key => $value) {
            Arr::set($lines, (string) $key, $value);
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }

    /**
     * Forward everything else (`addPath()`, `jsonPaths()`…) to the wrapped loader.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->loader->{$method}(...$arguments);
    }
}
