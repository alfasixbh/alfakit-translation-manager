<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Support;

use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationSuggestion;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use Locale;
use Throwable;

/**
 * Every key of the application in every locale: the lang files, the database overrides of
 * the current scope, the pending machine translations and, after a scan, which keys the
 * code uses. The editor's rows, filters, search, pagination and progress come from here.
 */
class Catalog
{
    public const FILTER_ALL = 'all';

    public const FILTER_MISSING = 'missing';

    public const FILTER_CHANGED = 'changed';

    public const FILTER_UNUSED = 'unused';

    public const FILTER_UNDEFINED = 'undefined';

    public const FILTER_SUGGESTIONS = 'suggestions';

    public const FILTER_WARNINGS = 'warnings';

    public const FILTERS = [self::FILTER_ALL, self::FILTER_MISSING, self::FILTER_CHANGED, self::FILTER_WARNINGS, self::FILTER_SUGGESTIONS, self::FILTER_UNUSED, self::FILTER_UNDEFINED];

    /** Right-to-left scripts (by language subtag). */
    public const RTL_LANGUAGES = ['ar', 'arc', 'ckb', 'dv', 'fa', 'ha', 'he', 'iw', 'khw', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /** @var array<string, array<string, string>> file lines by locale and index */
    protected array $fileLines = [];

    /** @var array<string, array<string, string>> application-wide overrides (tenant-scoped catalogs) */
    protected array $globalLines = [];

    /** @var array<string, array<string, Translation>> overrides of the current scope */
    protected array $overrides = [];

    /** @var array<string, array<string, TranslationSuggestion>> */
    protected array $suggestions = [];

    /** @var array<string, TranslationKey>|null */
    protected ?array $keys = null;

    /** @var array<string, true> */
    protected array $undefined = [];

    /** @var array<string, true>|null */
    protected ?array $used = null;

    /** @var list<string> */
    protected array $prefixes = [];

    /**
     * @param  list<string>  $locales  the locales shown (the base locale first)
     * @param  array{keys: array<string, list<string>>, prefixes: list<string>}|null  $scan
     */
    public function __construct(
        protected TranslationManager $manager,
        protected array $locales,
        protected string $baseLocale,
        protected ?array $scan = null,
    ) {
        $this->load();
    }

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        return $this->locales;
    }

    public function hasScan(): bool
    {
        return $this->scan !== null;
    }

    protected function load(): void
    {
        $scope = $this->manager->scope();

        foreach ($this->locales as $locale) {
            $this->fileLines[$locale] = $this->manager->fileLines($locale);
        }

        try {
            if ($this->manager->mode()->usesDatabase() || $scope !== '') {
                $rows = Translation::query()->whereIn('locale', $this->locales)->whereIn('scope', array_values(array_unique(['', $scope])))->get();

                foreach ($rows as $row) {
                    $index = $row->translationKey()->index();

                    if ($row->scope === $scope) {
                        $this->overrides[$row->locale][$index] = $row;
                    } elseif ($row->value !== null && $row->value !== '') {
                        $this->globalLines[$row->locale][$index] = $row->value;
                    }
                }
            }

            foreach (TranslationSuggestion::query()->whereIn('locale', $this->locales)->where('scope', $scope)->get() as $suggestion) {
                $this->suggestions[$suggestion->locale][$suggestion->translationKey()->index()] = $suggestion;
            }
        } catch (Throwable) {
            // Not migrated yet: the files only.
        }

        if ($this->scan !== null) {
            $this->used = [];
            $this->prefixes = $this->scan['prefixes'];
        }
    }

    /**
     * Every key, sorted by group then key.
     *
     * @return array<string, TranslationKey>
     */
    public function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $indexes = [];

        foreach ([...array_values($this->fileLines), ...array_values($this->globalLines)] as $lines) {
            $indexes += array_fill_keys(array_map('strval', array_keys($lines)), true);
        }

        foreach ([...array_values($this->overrides), ...array_values($this->suggestions)] as $rows) {
            $indexes += array_fill_keys(array_map('strval', array_keys($rows)), true);
        }

        $keys = [];

        foreach (array_keys($indexes) as $index) {
            $keys[$index] = TranslationKey::fromIndex($index);
        }

        if ($this->scan !== null) {
            $this->resolveUsage($keys);
        }

        uasort($keys, static fn (TranslationKey $a, TranslationKey $b): int => [self::groupOrder($a), $a->groupId(), $a->key] <=> [self::groupOrder($b), $b->groupId(), $b->key]);

        return $this->keys = $keys;
    }

    public function find(TranslationKey $key): ?TranslationKey
    {
        return $this->keys()[$key->index()] ?? null;
    }

    /**
     * Mark the keys the code uses and add the used keys no locale defines.
     *
     * @param  array<string, TranslationKey>  $keys
     */
    protected function resolveUsage(array &$keys): void
    {
        $namespaces = array_flip($this->manager->files()->namespaces());
        $labels = [];

        foreach ($keys as $index => $key) {
            $labels[$key->label()] = $index;
        }

        foreach (array_keys($this->scan['keys'] ?? []) as $usage) {
            $usage = (string) $usage;
            $json = TranslationKey::json($usage)->index();

            if (isset($keys[$json])) {
                $this->used[$json] = true;

                continue;
            }

            if (isset($labels[$usage])) {
                $this->used[$labels[$usage]] = true;

                continue;
            }

            // A whole group (`__('validation')`) or a nested array (`__('auth.messages')`).
            $children = array_filter($labels, static fn (string $label): bool => str_starts_with($label, $usage.'.'), ARRAY_FILTER_USE_KEY);

            if ($children !== []) {
                $this->used += array_fill_keys(array_values($children), true);

                continue;
            }

            $key = TranslationKey::parse($usage);

            if ($key->key === '' || ($key->isVendor() && ! isset($namespaces[$key->namespace]))) {
                continue;
            }

            $keys[$key->index()] = $key;
            $this->undefined[$key->index()] = true;
            $this->used[$key->index()] = true;
        }
    }

    public function isUsed(TranslationKey $key): ?bool
    {
        if ($this->scan === null) {
            return null;
        }

        $this->keys();

        if (isset($this->used[$key->index()])) {
            return true;
        }

        $label = $key->label();

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isUndefined(TranslationKey $key): bool
    {
        $this->keys();

        return isset($this->undefined[$key->index()]);
    }

    // ---------------------------------------------------------------- Values

    /**
     * The line before the current scope's override: the files (and the application-wide
     * overrides when the catalog is tenant-scoped).
     */
    public function baseline(TranslationKey $key, string $locale): ?string
    {
        $index = $key->index();

        return $this->globalLines[$locale][$index] ?? $this->fileLines[$locale][$index] ?? null;
    }

    public function value(TranslationKey $key, string $locale): ?string
    {
        $override = $this->overrides[$locale][$key->index()] ?? null;
        $value = $override !== null && $override->value !== null && $override->value !== '' ? $override->value : $this->baseline($key, $locale);

        return $value === '' ? null : $value;
    }

    /**
     * Whether the current scope's database layer changes the line (not exported yet).
     */
    public function isChanged(TranslationKey $key, string $locale): bool
    {
        $override = $this->overrides[$locale][$key->index()] ?? null;

        return $override !== null && $override->value !== null && $override->value !== '' && $override->value !== $this->baseline($key, $locale);
    }

    public function suggestion(TranslationKey $key, string $locale): ?TranslationSuggestion
    {
        return $this->suggestions[$locale][$key->index()] ?? null;
    }

    /**
     * @return list<string>
     */
    public function warnings(TranslationKey $key, string $locale): array
    {
        return $locale === $this->baseLocale ? [] : Placeholders::warnings($this->value($key, $this->baseLocale), $this->value($key, $locale), $locale);
    }

    /**
     * Translated keys per locale: keys with a base line that have a line in the locale.
     *
     * @return array<string, array{translated: int, total: int, percent: float}>
     */
    public function progress(): array
    {
        $progress = [];
        $keys = array_filter($this->keys(), fn (TranslationKey $key): bool => $this->value($key, $this->baseLocale) !== null);
        $total = count($keys);

        foreach ($this->locales as $locale) {
            $translated = count(array_filter($keys, fn (TranslationKey $key): bool => $this->value($key, $locale) !== null));

            $progress[$locale] = [
                'translated' => $translated,
                'total' => $total,
                'percent' => $total === 0 ? 1.0 : round($translated / $total, 4),
            ];
        }

        return $progress;
    }

    // ---------------------------------------------------------------- Groups

    /**
     * @return list<array{value: string, label: string}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->keys() as $key) {
            $groups[$key->groupId()] ??= ['value' => $key->groupId(), 'label' => self::groupLabel($key), 'order' => self::groupOrder($key)];
        }

        uasort($groups, static fn (array $a, array $b): int => [$a['order'], $a['value']] <=> [$b['order'], $b['value']]);

        return array_values(array_map(static fn (array $group): array => ['value' => $group['value'], 'label' => $group['label']], $groups));
    }

    public static function groupLabel(TranslationKey $key): string
    {
        return $key->isJson() ? (string) __('alfakit-translation-manager::translation-manager.groups.json') : $key->groupId();
    }

    protected static function groupOrder(TranslationKey $key): int
    {
        return $key->isJson() ? 0 : ($key->isVendor() ? 2 : 1);
    }

    // ---------------------------------------------------------------- Querying

    /**
     * One page of keys matching the search and filters.
     *
     * @param  list<string>|null  $locales  the locales a status filter looks at (null: all but the base)
     * @return array{keys: list<TranslationKey>, total: int, page: int, lastPage: int}
     */
    public function query(?string $search = null, string $filter = self::FILTER_ALL, ?array $locales = null, ?string $group = null, int $page = 1, int $perPage = 50): array
    {
        $locales = array_values(array_intersect($locales ?? array_values(array_diff($this->locales, [$this->baseLocale])), $this->locales));
        $locales = $locales === [] ? $this->locales : $locales;
        $search = $search === null ? '' : mb_strtolower(trim($search));

        $matches = array_filter($this->keys(), function (TranslationKey $key) use ($search, $filter, $locales, $group): bool {
            if ($group !== null && $group !== '' && $key->groupId() !== $group) {
                return false;
            }

            if (! $this->matchesFilter($key, $filter, $locales)) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            if (str_contains(mb_strtolower($key->label()), $search)) {
                return true;
            }

            foreach ($this->locales as $locale) {
                if (str_contains(mb_strtolower($this->value($key, $locale) ?? ''), $search)) {
                    return true;
                }
            }

            return false;
        });

        $total = count($matches);
        $perPage = max(1, $perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'keys' => array_values(array_slice($matches, ($page - 1) * $perPage, $perPage)),
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
        ];
    }

    /**
     * @param  list<string>  $locales
     */
    protected function matchesFilter(TranslationKey $key, string $filter, array $locales): bool
    {
        $any = static fn (callable $test): bool => array_filter($locales, $test) !== [];

        return match ($filter) {
            self::FILTER_MISSING => $this->value($key, $this->baseLocale) !== null && $any(fn (string $locale): bool => $this->value($key, $locale) === null),
            self::FILTER_CHANGED => $any(fn (string $locale): bool => $this->isChanged($key, $locale)),
            self::FILTER_WARNINGS => $any(fn (string $locale): bool => $this->warnings($key, $locale) !== []),
            self::FILTER_SUGGESTIONS => $any(fn (string $locale): bool => $this->suggestion($key, $locale) !== null),
            self::FILTER_UNUSED => $this->isUsed($key) === false,
            self::FILTER_UNDEFINED => $this->isUndefined($key),
            default => true,
        };
    }

    // ---------------------------------------------------------------- Serialising

    public static function direction(string $locale): string
    {
        $language = strtolower((string) preg_split('/[_-]/', $locale)[0]);

        return in_array($language, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }

    /**
     * A locale's name in the current language (`German`, `Deutsch` in German…).
     */
    public static function localeLabel(string $locale): string
    {
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayName($locale, app()->getLocale());

            if (is_string($name) && $name !== '' && $name !== $locale) {
                return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_TITLE).mb_substr($name, 1);
            }
        }

        return $locale;
    }

    /**
     * A row of the editor: the key, its base line's placeholders and one cell per locale.
     *
     * @param  list<string>  $editable
     * @return array<string, mixed>
     */
    public function row(TranslationKey $key, array $editable = []): array
    {
        $base = $this->value($key, $this->baseLocale);
        $cells = [];

        foreach ($this->locales as $locale) {
            $value = $this->value($key, $locale);
            $plural = Plurals::forms($base, $value, $locale);
            $suggestion = $this->suggestion($key, $locale);

            $cells[$locale] = array_filter([
                'value' => $value,
                'forms' => $plural['forms'] ?? null,
                'formLabels' => $plural['labels'] ?? null,
                'changed' => $this->isChanged($key, $locale) ?: null,
                'warnings' => $this->warnings($key, $locale) ?: null,
                'suggestion' => $suggestion === null ? null : array_filter([
                    'value' => $suggestion->value,
                    'warnings' => $suggestion->warnings ?: null,
                ], static fn (mixed $value): bool => $value !== null),
                'editable' => in_array($locale, $editable, true) ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_filter([
            'id' => $key->id(),
            'key' => $key->label(),
            'group' => $key->groupId(),
            'groupLabel' => self::groupLabel($key),
            'plural' => Plurals::isPlural($base) ?: null,
            'placeholders' => Placeholders::tokens($base) ?: null,
            'unused' => $this->isUsed($key) === false ?: null,
            'undefined' => $this->isUndefined($key) ?: null,
            'files' => isset($this->scan['keys'][$key->label()]) ? array_slice($this->scan['keys'][$key->label()], 0, 5) : null,
            'cells' => $cells,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
