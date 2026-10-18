<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitTranslationManager\Ai\LaravelAiTranslator;
use AlfaSix\AlfakitTranslationManager\Contracts\MachineTranslator;
use AlfaSix\AlfakitTranslationManager\Enums\StorageMode;
use AlfaSix\AlfakitTranslationManager\Pages\Translations;
use AlfaSix\AlfakitTranslationManager\Support\LangFiles;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * A translation editor for the people who write the copy: every key of the application
 * by locale, with search, filters, placeholder and plural checks, history, reviewed AI
 * translations and export to the lang files.
 *
 * ```php
 * $panel->plugin(
 *     TranslationManagerPlugin::make()
 *         ->mode(StorageMode::Database)
 *         ->editableLocales(['de', 'fr', 'ar'])
 *         ->authorize(fn (User $user): bool => $user->is_admin),
 * );
 * ```
 *
 * Access is denied unless `authorize()` allows it or the `manageTranslations` gate does.
 */
class TranslationManagerPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'translation-manager';

    /** The gate checked when no `authorize()` callback is set. */
    public const GATE = 'manageTranslations';

    /** @var class-string<Translations> */
    protected string $page = Translations::class;

    protected ?StorageMode $mode = null;

    protected ?string $langPath = null;

    protected ?string $baseLocale = null;

    protected string|false|null $backupPath = null;

    /** @var list<string>|null */
    protected ?array $namespaces = null;

    /** @var list<string>|null */
    protected ?array $scanPaths = null;

    protected bool $isTenantScoped = false;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $locales = null;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $editableLocales = null;

    protected bool|Closure|null $authorizeUsing = null;

    protected bool|Closure $hasAiTranslation = true;

    protected ?string $aiProvider = null;

    protected ?string $aiModel = null;

    protected int $aiBatchSize = 25;

    protected int $aiLimit = 200;

    protected int $perPage = 50;

    protected string|Closure|null $navigationLabel = null;

    protected string|Closure|null $navigationGroup = null;

    protected string|Closure|null $navigationIcon = 'languages';

    protected int|Closure|null $navigationSort = null;

    protected bool|Closure $shouldRegisterNavigation = true;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin registered on the current panel, or one with the defaults.
     */
    public static function get(): static
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel !== null && $panel->hasPlugin(self::ID)) {
            $plugin = $panel->getPlugin(self::ID);

            if ($plugin instanceof static) {
                return $plugin;
            }
        }

        return static::make();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([$this->getPage()]);
        $panel->frontendTranslations(['translation-manager' => 'alfakit-translation-manager::translation-manager.ui']);

        $this->configureManager(app(TranslationManager::class));
    }

    public function boot(Panel $panel): void {}

    /**
     * Push the storage settings to the app-wide service (the loader and the commands use it).
     */
    public function configureManager(TranslationManager $manager): void
    {
        if ($this->mode !== null) {
            $manager->setMode($this->mode);
        }

        if ($this->langPath !== null) {
            $manager->setLangPath($this->langPath);
        }

        if ($this->baseLocale !== null) {
            $manager->setBaseLocale($this->baseLocale);
        }

        if ($this->backupPath !== null) {
            $manager->setBackupPath($this->backupPath);
        }

        if ($this->namespaces !== null) {
            $manager->setNamespaces($this->namespaces);
        }

        if ($this->scanPaths !== null) {
            $manager->setScanPaths($this->scanPaths);
        }

        if ($this->isTenantScoped) {
            $manager->setTenantScoped(true);
        }
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Page

    /**
     * Replace the page with your own subclass of `Translations`.
     *
     * @param  class-string<Translations>  $page
     */
    public function usingPage(string $page): static
    {
        if (! is_a($page, Translations::class, true)) {
            throw new InvalidArgumentException("The translations page [{$page}] must extend [".Translations::class.'].');
        }

        $this->page = $page;

        return $this;
    }

    /**
     * @return class-string<Translations>
     */
    public function getPage(): string
    {
        return $this->page;
    }

    /**
     * Rows per page in the editor.
     */
    public function perPage(int $perPage): static
    {
        $this->perPage = max(5, min(500, $perPage));

        return $this;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    // ---------------------------------------------------------------- Storage

    /**
     * Where edits go: `database` (instant override layer, default), `files` (the lang files
     * directly) or `both`.
     */
    public function mode(StorageMode|string $mode): static
    {
        $this->mode = is_string($mode) ? StorageMode::from($mode) : $mode;

        return $this;
    }

    public function databaseOnly(): static
    {
        return $this->mode(StorageMode::Database);
    }

    public function filesOnly(): static
    {
        return $this->mode(StorageMode::Files);
    }

    public function databaseAndFiles(): static
    {
        return $this->mode(StorageMode::Both);
    }

    public function getMode(): StorageMode
    {
        return $this->mode ?? app(TranslationManager::class)->mode();
    }

    /**
     * The lang directory to read and write (the application's `lang_path()` by default).
     */
    public function langPath(string $path): static
    {
        $this->langPath = $path;

        return $this;
    }

    /**
     * The locale translators translate from (`app.fallback_locale` by default).
     */
    public function baseLocale(string $locale): static
    {
        $this->baseLocale = $locale;

        return $this;
    }

    /**
     * Where lang files are copied before an export overwrites them; false turns it off.
     */
    public function backups(string|false $path): static
    {
        $this->backupPath = $path;

        return $this;
    }

    /**
     * Also list these packages' own translations (by `loadTranslationsFrom()` namespace).
     *
     * @param  array<int, string>  $namespaces
     */
    public function namespaces(array $namespaces): static
    {
        $this->namespaces = array_values($namespaces);

        return $this;
    }

    /**
     * Directories (relative to the project) the source scan looks in.
     *
     * @param  array<int, string>  $paths
     */
    public function scanPaths(array $paths): static
    {
        $this->scanPaths = array_values($paths);

        return $this;
    }

    /**
     * Keep edits per tenant on panels with tenancy: each tenant overrides the application's
     * lines for itself (stored in the database only; export and import are turned off).
     */
    public function tenantScoped(bool $condition = true): static
    {
        $this->isTenantScoped = $condition;

        return $this;
    }

    public function isTenantScoped(): bool
    {
        return $this->isTenantScoped;
    }

    // ---------------------------------------------------------------- Locales

    /**
     * The locales shown (all the lang directory has, by default). The base locale is
     * always shown first.
     *
     * @param  array<int, string>|Closure|null  $locales
     */
    public function locales(array|Closure|null $locales): static
    {
        $this->locales = $locales;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        $manager = app(TranslationManager::class);
        $locales = $this->evaluate($this->locales);
        $base = $manager->baseLocale();
        $locales = is_array($locales) ? array_values(array_filter($locales, static fn (mixed $locale): bool => is_string($locale) && LangFiles::isValidLocale($locale))) : $manager->discoverLocales();
        $locales = array_values(array_unique([$base, ...$locales]));
        $others = array_values(array_diff($locales, [$base]));
        sort($others);

        return [$base, ...$others];
    }

    /**
     * The locales the user may edit (`fn (User $user): array`); all shown locales by default.
     *
     * @param  array<int, string>|Closure|null  $locales
     */
    public function editableLocales(array|Closure|null $locales): static
    {
        $this->editableLocales = $locales;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getEditableLocales(): array
    {
        $shown = $this->getLocales();
        $editable = $this->evaluate($this->editableLocales);

        return is_array($editable) ? array_values(array_intersect($shown, array_filter($editable, is_string(...)))) : $shown;
    }

    public function canEditLocale(string $locale): bool
    {
        return in_array($locale, $this->getEditableLocales(), true);
    }

    // ---------------------------------------------------------------- Authorization

    /**
     * Who may open the editor and change translations: `fn (User $user): bool`. Without
     * it, the `manageTranslations` gate decides, and no one passes when it isn't defined.
     */
    public function authorize(bool|Closure|null $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function isAuthorized(): bool
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
        $user = $panel?->auth()->user();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        if ($this->authorizeUsing !== null) {
            return (bool) $this->evaluate($this->authorizeUsing, ['user' => $user], [Authenticatable::class => $user, $user::class => $user]);
        }

        return Gate::forUser($user)->allows(self::GATE);
    }

    // ---------------------------------------------------------------- AI

    /**
     * Offer "Translate missing with AI" (when laravel/ai is installed or a
     * `MachineTranslator` is bound). Results are suggestions people review and accept.
     */
    public function aiTranslation(bool|Closure $condition = true): static
    {
        $this->hasAiTranslation = $condition;

        return $this;
    }

    /**
     * The laravel/ai provider and model (the application's defaults when null).
     */
    public function aiProvider(?string $provider, ?string $model = null): static
    {
        $this->aiProvider = $provider;
        $this->aiModel = $model;

        return $this;
    }

    /**
     * Lines per AI request, and the most lines one run translates.
     */
    public function aiBatchSize(int $size, ?int $limit = null): static
    {
        $this->aiBatchSize = max(1, $size);
        $this->aiLimit = max(1, $limit ?? $this->aiLimit);

        return $this;
    }

    public function getAiBatchSize(): int
    {
        return $this->aiBatchSize;
    }

    public function getAiLimit(): int
    {
        return $this->aiLimit;
    }

    public function getMachineTranslator(): ?MachineTranslator
    {
        if (app()->bound(MachineTranslator::class)) {
            return app(MachineTranslator::class);
        }

        return LaravelAiTranslator::isAvailable() ? new LaravelAiTranslator($this->aiProvider, $this->aiModel) : null;
    }

    public function hasAiTranslation(): bool
    {
        return (bool) $this->evaluate($this->hasAiTranslation) && $this->getMachineTranslator() !== null;
    }

    // ---------------------------------------------------------------- Navigation

    public function navigationLabel(string|Closure|null $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        $label = $this->evaluate($this->navigationLabel);

        return is_string($label) && $label !== '' ? $label : (string) __('alfakit-translation-manager::translation-manager.navigation.label');
    }

    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationIcon(string|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): ?string
    {
        $icon = $this->evaluate($this->navigationIcon);

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        return is_int($sort) ? $sort : null;
    }

    public function registerNavigation(bool|Closure $condition = true): static
    {
        $this->shouldRegisterNavigation = $condition;

        return $this;
    }

    public function shouldRegisterNavigation(): bool
    {
        return (bool) $this->evaluate($this->shouldRegisterNavigation);
    }
}
