<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager;

use AlfaSix\AlfakitTranslationManager\Enums\StorageMode;
use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationLocale;
use AlfaSix\AlfakitTranslationManager\Models\TranslationRevision;
use AlfaSix\AlfakitTranslationManager\Models\TranslationSuggestion;
use AlfaSix\AlfakitTranslationManager\Support\LangFiles;
use AlfaSix\AlfakitTranslationManager\Support\SourceScanner;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Translation\OverrideLoader;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use Throwable;

/**
 * The app-wide service behind the panel page, the commands and the translation loader:
 * where translations are stored, the database override layer (cached, versioned), saving
 * with history, export to and import from the lang files, and locales.
 *
 * Settings come from `config/alfakit-translation-manager.php`; `TranslationManagerPlugin`
 * overrides them for the panel that registers it.
 */
class TranslationManager
{
    public const CACHE_PREFIX = 'alfakit-translation-manager:';

    /** Edit sources recorded in the history. */
    public const SOURCE_EDITOR = 'editor';

    public const SOURCE_AI = 'ai';

    public const SOURCE_IMPORT = 'import';

    protected ?StorageMode $mode = null;

    protected ?string $langPath = null;

    protected ?string $baseLocale = null;

    protected string|false|null $backupPath = null;

    /** @var list<string>|null */
    protected ?array $namespaces = null;

    /** @var list<string>|null */
    protected ?array $scanPaths = null;

    protected bool $tenantScoped = false;

    protected ?Closure $tenantResolver = null;

    protected ?LangFiles $files = null;

    /** @var array<string, array<string, array<string, array<string, string>>>> */
    protected array $memo = [];

    /** The cache version read once per request (see `forgetVersion()`). */
    protected ?int $version = null;

    public function __construct(protected Container $app) {}

    // ---------------------------------------------------------------- Settings

    public function setMode(StorageMode|string|null $mode): static
    {
        $this->mode = is_string($mode) ? StorageMode::from($mode) : $mode;

        return $this;
    }

    public function mode(): StorageMode
    {
        if ($this->mode !== null) {
            return $this->mode;
        }

        $configured = config('alfakit-translation-manager.mode', StorageMode::Database->value);

        return StorageMode::tryFrom(is_string($configured) ? $configured : '') ?? StorageMode::Database;
    }

    public function setLangPath(?string $path): static
    {
        $this->langPath = $path;
        $this->files = null;

        return $this;
    }

    public function langPath(): string
    {
        $path = $this->langPath ?? config('alfakit-translation-manager.lang_path');

        return is_string($path) && $path !== '' ? $path : $this->app->make('path.lang');
    }

    public function setBaseLocale(?string $locale): static
    {
        $this->baseLocale = $locale;

        return $this;
    }

    public function baseLocale(): string
    {
        $locale = $this->baseLocale ?? config('alfakit-translation-manager.base_locale') ?? config('app.fallback_locale') ?? 'en';

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * Where lang files are copied before they are overwritten; false turns backups off.
     */
    public function setBackupPath(string|false|null $path): static
    {
        $this->backupPath = $path;
        $this->files = null;

        return $this;
    }

    public function backupPath(): ?string
    {
        $path = $this->backupPath ?? config('alfakit-translation-manager.backups', storage_path('app/alfakit-translations/backups'));

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  list<string>|null  $namespaces
     */
    public function setNamespaces(?array $namespaces): static
    {
        $this->namespaces = $namespaces;
        $this->files = null;

        return $this;
    }

    /**
     * Package namespaces listed with their own files (besides `lang/vendor` overrides).
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        $namespaces = $this->namespaces ?? config('alfakit-translation-manager.namespaces', []);

        return is_array($namespaces) ? array_values(array_filter($namespaces, is_string(...))) : [];
    }

    /**
     * @param  list<string>|null  $paths
     */
    public function setScanPaths(?array $paths): static
    {
        $this->scanPaths = $paths;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function scanPaths(): array
    {
        $paths = $this->scanPaths ?? config('alfakit-translation-manager.scan.paths', ['app', 'resources/views', 'resources/js', 'routes']);

        return array_values(array_map(
            static fn (string $path): string => str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : base_path($path),
            is_array($paths) ? array_filter($paths, is_string(...)) : [],
        ));
    }

    /**
     * @return list<string>
     */
    public function scanExtensions(): array
    {
        $extensions = config('alfakit-translation-manager.scan.extensions', ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'svelte']);

        return is_array($extensions) ? array_values(array_filter($extensions, is_string(...))) : ['php'];
    }

    /**
     * Keep overrides per tenant: `$resolver` returns the current tenant (or null for the
     * application-wide layer).
     *
     * @param  (Closure(): ?Model)|null  $resolver
     */
    public function setTenantScoped(bool $scoped, ?Closure $resolver = null): static
    {
        $this->tenantScoped = $scoped;
        $this->tenantResolver = $resolver;
        $this->memo = [];

        return $this;
    }

    public function isTenantScoped(): bool
    {
        return $this->tenantScoped;
    }

    /**
     * The override scope of the current request: '' (application-wide) or `type:key` of
     * the current tenant when overrides are tenant-scoped.
     */
    public function scope(): string
    {
        if (! $this->tenantScoped) {
            return '';
        }

        $tenant = $this->tenantResolver !== null ? ($this->tenantResolver)() : alfakit()->getTenant();

        return $tenant instanceof Model ? $tenant->getMorphClass().':'.$tenant->getKey() : '';
    }

    public function files(): LangFiles
    {
        if ($this->files !== null) {
            return $this->files;
        }

        // Every package's own lines count for values (a `lang/vendor` override of one key
        // keeps its siblings); only the configured packages are listed in full.
        $loader = $this->baseLoader();
        $packagePaths = array_filter($loader?->namespaces() ?? [], is_string(...));

        return $this->files = new LangFiles(
            $this->langPath(),
            $this->backupPath(),
            $packagePaths,
            $this->namespaces(),
            $loader === null || $this->langPath() !== $this->app->make('path.lang') ? null : static fn (string $locale, string $group, string $namespace): array => $loader->load($locale, $group, $namespace),
        );
    }

    public function scanner(): SourceScanner
    {
        return new SourceScanner($this->scanPaths(), $this->scanExtensions(), $this->cache());
    }

    // ---------------------------------------------------------------- Locales

    /**
     * Every known locale: the base locale, the lang files' and the ones added in the panel.
     *
     * @return list<string>
     */
    public function discoverLocales(): array
    {
        $locales = [$this->baseLocale(), ...$this->files()->locales()];

        try {
            foreach (TranslationLocale::query()->pluck('code') as $code) {
                $locales[] = (string) $code;
            }
        } catch (Throwable) {
            // The table doesn't exist yet.
        }

        return array_values(array_unique(array_filter($locales, LangFiles::isValidLocale(...))));
    }

    /**
     * Add a locale: it is listed with every key of the base locale, all empty. In `files`
     * and `both` modes its directory and JSON file are created.
     */
    public function addLocale(string $locale): void
    {
        if (! LangFiles::isValidLocale($locale)) {
            throw new InvalidArgumentException("Invalid locale [{$locale}].");
        }

        TranslationLocale::query()->firstOrCreate(['code' => $locale]);

        if ($this->mode()->writesFiles()) {
            $this->files()->createLocale($locale);
        }
    }

    // ---------------------------------------------------------------- Override layer

    /**
     * The database overrides of a locale for the current scope, by namespace and group:
     * `['*' => ['auth' => ['failed' => '…'], '*' => ['Hello' => '…']]]`.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public function overridesFor(string $locale): array
    {
        if (! $this->mode()->usesDatabase()) {
            return [];
        }

        $scope = $this->scope();
        $version = $this->version();
        $memo = $version."\0".$scope."\0".$locale;

        if (isset($this->memo[$memo])) {
            return $this->memo[$memo];
        }

        try {
            $overrides = $this->cache()->remember(
                self::CACHE_PREFIX.'overrides:'.$version.':'.sha1($scope).':'.$locale,
                $this->cacheTtl(),
                fn (): array => $this->queryOverrides($locale, $scope),
            );
        } catch (Throwable) {
            // No table yet (before `migrate`), or the database is unreachable: use the files.
            return [];
        }

        return $this->memo[$memo] = is_array($overrides) ? $overrides : [];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    protected function queryOverrides(string $locale, string $scope): array
    {
        $scopes = $scope === '' ? [''] : ['', $scope];
        $rows = Translation::query()
            ->where('locale', $locale)
            ->whereIn('scope', $scopes)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderByRaw('case when scope = ? then 0 else 1 end', [''])
            ->get(['scope', 'namespace', 'group', 'key', 'value']);

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[$row->namespace][$row->group][$row->key] = (string) $row->value;
        }

        return $overrides;
    }

    /**
     * The database override of one key in the current scope (null when there is none).
     */
    public function override(TranslationKey $key, string $locale, ?string $scope = null): ?Translation
    {
        if (! $this->mode()->usesDatabase() && ! $this->tenantScoped) {
            return null;
        }

        try {
            return Translation::query()->forKey($key)->where('locale', $locale)->where('scope', $scope ?? $this->scope())->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the application shows for a key: the override, else the files.
     */
    public function effective(TranslationKey $key, string $locale): ?string
    {
        $override = $this->overridesFor($locale)[$key->namespace][$key->group][$key->key] ?? null;

        return $override ?? $this->files()->value($key, $locale);
    }

    /**
     * Store a new value of a key (null or '' clears it) and record who changed it. In the
     * database modes it becomes an override that applies instantly; in `files` and `both`
     * modes the lang file is written too (except for tenant overrides, which only live in
     * the database).
     */
    public function save(TranslationKey $key, string $locale, ?string $value, string $source = self::SOURCE_EDITOR, ?Authenticatable $user = null): void
    {
        if (! LangFiles::isValidLocale($locale)) {
            throw new InvalidArgumentException("Invalid locale [{$locale}].");
        }

        $value = $value === '' ? null : $value;
        $old = $this->effective($key, $locale);

        if ($old === $value) {
            return;
        }

        $scope = $this->scope();
        $mode = $this->mode();

        DB::transaction(function () use ($key, $locale, $value, $scope, $mode, $user, $old, $source): void {
            if ($mode->usesDatabase() || $scope !== '') {
                $fileValue = $scope === '' ? $this->files()->value($key, $locale) : null;

                // No override is needed for an empty line (the files show) or a file's own line.
                if ($value === null || $value === $fileValue) {
                    Translation::query()->forKey($key)->where('locale', $locale)->where('scope', $scope)->delete();
                } else {
                    Translation::query()->updateOrCreate(
                        ['scope' => $scope, 'locale' => $locale, 'namespace' => $key->namespace, 'group' => $key->group, 'key_hash' => $key->hash()],
                        [
                            'key' => $key->key,
                            'value' => $value,
                            'updated_by_type' => $user instanceof Model ? $user->getMorphClass() : null,
                            'updated_by_id' => $user?->getAuthIdentifier(),
                        ],
                    );
                }
            }

            if ($mode->writesFiles() && $scope === '') {
                // `both` keeps the database row too: other servers apply it before they
                // receive the file.
                $this->files()->write($locale, $key->group, $key->namespace, [$key->key => $value]);
            }

            $this->record($key, $locale, $old, $value, $source, $user, $scope);
        });

        $this->flush();
    }

    /**
     * Add a history entry.
     */
    public function record(TranslationKey $key, string $locale, ?string $old, ?string $new, string $source, ?Authenticatable $user, ?string $scope = null): void
    {
        TranslationRevision::query()->create([
            'scope' => $scope ?? $this->scope(),
            'locale' => $locale,
            'namespace' => $key->namespace,
            'group' => $key->group,
            'key' => $key->key,
            'key_hash' => $key->hash(),
            'old_value' => $old,
            'new_value' => $new,
            'source' => $source,
            'user_type' => $user instanceof Model ? $user->getMorphClass() : null,
            'user_id' => $user?->getAuthIdentifier(),
            'user_name' => $user === null ? null : $this->userName($user),
            'created_at' => now(),
        ]);
    }

    /**
     * The latest changes of a key in a locale, newest first.
     *
     * @return list<TranslationRevision>
     */
    public function history(TranslationKey $key, string $locale, int $limit = 20): array
    {
        return array_values(TranslationRevision::query()
            ->forKey($key)
            ->where('locale', $locale)
            ->where('scope', $this->scope())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->all());
    }

    // ---------------------------------------------------------------- Suggestions

    /**
     * Store a machine translation for review (replacing an earlier one for the key).
     *
     * @param  list<string>  $warnings
     */
    public function suggest(TranslationKey $key, string $locale, string $value, array $warnings = [], string $source = self::SOURCE_AI, ?Authenticatable $user = null): TranslationSuggestion
    {
        return TranslationSuggestion::query()->updateOrCreate(
            ['scope' => $this->scope(), 'locale' => $locale, 'namespace' => $key->namespace, 'group' => $key->group, 'key_hash' => $key->hash()],
            [
                'key' => $key->key,
                'value' => $value,
                'source' => $source,
                'warnings' => $warnings === [] ? null : $warnings,
                'created_by_type' => $user instanceof Model ? $user->getMorphClass() : null,
                'created_by_id' => $user?->getAuthIdentifier(),
            ],
        );
    }

    public function suggestion(TranslationKey $key, string $locale): ?TranslationSuggestion
    {
        return TranslationSuggestion::query()->forKey($key)->where('locale', $locale)->where('scope', $this->scope())->first();
    }

    /**
     * Apply a suggestion (as an `ai` change in the history) and remove it.
     */
    public function acceptSuggestion(TranslationSuggestion $suggestion, ?Authenticatable $user = null): void
    {
        $this->save($suggestion->translationKey(), $suggestion->locale, $suggestion->value, $suggestion->source, $user);
        $suggestion->delete();
    }

    // ---------------------------------------------------------------- Files

    /**
     * Write the application-wide database overrides to the lang files and remove them from
     * the database (the files hold them now). Every overwritten file is backed up first.
     *
     * @param  list<string>|null  $locales  null for all
     * @return array{files: list<string>, lines: int, backup: string|null}
     */
    public function export(?array $locales = null): array
    {
        $files = $this->files();
        $files->startBackup();
        $groups = [];

        $query = Translation::query()->where('scope', '');

        if ($locales !== null) {
            $query->whereIn('locale', $locales);
        }

        $rows = $query->orderBy('id')->get();

        foreach ($rows as $row) {
            if (! LangFiles::isValidLocale($row->locale)) {
                continue;
            }

            $groups[$row->locale."\0".$row->namespace."\0".$row->group][$row->key] = $row->value === '' ? null : $row->value;
        }

        $written = [];
        $lines = 0;

        foreach ($groups as $index => $values) {
            [$locale, $namespace, $group] = explode("\0", $index, 3);

            // Empty lines are never written, so Laravel falls back to the fallback locale.
            $values = array_filter($values, static fn (?string $value): bool => $value !== null);

            if ($values === []) {
                continue;
            }

            $written[] = $files->write($locale, $group, $namespace, $values);
            $lines += count($values);
        }

        $rows->each(static fn (Translation $row): ?bool => $row->delete());
        $files->forget();
        $this->flush();

        return ['files' => array_values(array_unique($written)), 'lines' => $lines, 'backup' => $files->getLastBackupDirectory()];
    }

    /**
     * Copy the lines of the lang files into the database (for the database-only mode, or
     * to move translations between environments). Without `$overwrite`, keys that already
     * have a database value keep it.
     *
     * @param  list<string>|null  $locales
     */
    public function import(?array $locales = null, bool $overwrite = false, ?Authenticatable $user = null): int
    {
        $files = $this->files();
        $files->forget();
        $scope = $this->scope();
        $count = 0;

        foreach ($locales ?? $this->discoverLocales() as $locale) {
            foreach ($this->fileLines($locale, withPackages: false) as $index => $value) {
                $key = TranslationKey::fromIndex($index);
                $existing = Translation::query()->forKey($key)->where('locale', $locale)->where('scope', $scope)->first();

                if ($existing !== null && (! $overwrite || $existing->value === $value)) {
                    continue;
                }

                Translation::query()->updateOrCreate(
                    ['scope' => $scope, 'locale' => $locale, 'namespace' => $key->namespace, 'group' => $key->group, 'key_hash' => $key->hash()],
                    [
                        'key' => $key->key,
                        'value' => $value,
                        'updated_by_type' => $user instanceof Model ? $user->getMorphClass() : null,
                        'updated_by_id' => $user?->getAuthIdentifier(),
                    ],
                );

                if ($existing !== null) {
                    $this->record($key, $locale, $existing->value, $value, self::SOURCE_IMPORT, $user, $scope);
                }

                $count++;
            }
        }

        $this->flush();

        return $count;
    }

    /**
     * Every line of a locale's files by catalog index (see `TranslationKey::index()`).
     * Package groups include the package's own lines unless `$withPackages` is false (only
     * the `lang/vendor` overrides).
     *
     * @return array<string, string>
     */
    public function fileLines(string $locale, bool $withPackages = true): array
    {
        $files = $this->files();
        $lines = [];

        foreach ($files->readJson($locale) as $key => $value) {
            $lines[TranslationKey::json($key)->index()] = $value;
        }

        foreach ($files->groups($locale) as $group) {
            foreach (LangFiles::flatten($files->readGroup($locale, $group)) as $key => $value) {
                $lines[(new TranslationKey(TranslationKey::WILDCARD, $group, $key))->index()] = $value;
            }
        }

        foreach ($files->namespaces() as $namespace) {
            foreach ($files->groups($locale, $namespace) as $group) {
                $groupLines = $withPackages ? $files->readGroup($locale, $group, $namespace) : $files->readVendorOverride($locale, $group, $namespace);

                foreach (LangFiles::flatten($groupLines) as $key => $value) {
                    $lines[(new TranslationKey($namespace, $group, $key))->index()] = $value;
                }
            }
        }

        return $lines;
    }

    // ---------------------------------------------------------------- Cache

    public function cache(): CacheRepository
    {
        $store = config('alfakit-translation-manager.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    protected function cacheTtl(): int
    {
        $ttl = config('alfakit-translation-manager.cache.ttl', 86400);

        return is_numeric($ttl) ? max(1, (int) $ttl) : 86400;
    }

    public function version(): int
    {
        // Read once per request: every translation group the translator loads asks for it.
        return $this->version ??= $this->readVersion();
    }

    /**
     * Read the version again on the next lookup (called when a request ends, so a
     * long-lived worker sees another worker's `flush()`).
     */
    public function forgetVersion(): void
    {
        $this->version = null;
    }

    protected function readVersion(): int
    {
        try {
            $version = $this->cache()->get(self::CACHE_PREFIX.'version', 0);
        } catch (Throwable) {
            return 0;
        }

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Forget the cached overrides (every worker picks the new version up on its next
     * request) and the lines the translator already loaded in this one.
     */
    public function flush(): void
    {
        try {
            $this->cache()->forever(self::CACHE_PREFIX.'version', $this->readVersion() + 1);
        } catch (Throwable) {
            // A read-only cache: the overrides refresh when their TTL ends.
        }

        $this->version = null;
        $this->memo = [];
        $this->files?->forget();
        $this->forgetLoadedTranslations();
    }

    /**
     * Drop the lines the translator memoised (after a save, or when the tenant changes).
     */
    public function forgetLoadedTranslations(): void
    {
        $this->memo = [];

        if ($this->app->resolved('translator')) {
            $this->app->make('translator')->setLoaded([]);
        }
    }

    /**
     * Put the override loader in front of the translator's loader (once).
     */
    public function installLoader(Translator $translator): void
    {
        $loader = $translator->getLoader();

        if ($loader instanceof OverrideLoader) {
            return;
        }

        $wrapped = new OverrideLoader($loader, fn (): self => $this);

        (function () use ($wrapped): void {
            /** @var Translator $this */
            $this->loader = $wrapped;
            $this->loaded = [];
        })->call($translator);
    }

    /**
     * The application's own loader (below the override layer).
     */
    protected function baseLoader(): ?\Illuminate\Contracts\Translation\Loader
    {
        if (! $this->app->bound('translation.loader')) {
            return null;
        }

        return $this->app->make('translation.loader');
    }

    protected function userName(Authenticatable $user): string
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel !== null) {
            return $panel->getUserName($user);
        }

        $name = $user instanceof Model ? $user->getAttribute('name') : null;

        return is_string($name) && $name !== '' ? $name : (string) $user->getAuthIdentifier();
    }
}
