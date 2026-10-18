<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Http\Controllers\Concerns\PullsFlashedNotifications;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\AlfakitTranslationManager\Models\TranslationSuggestion;
use AlfaSix\AlfakitTranslationManager\Support\AiTranslations;
use AlfaSix\AlfakitTranslationManager\Support\Catalog;
use AlfaSix\AlfakitTranslationManager\Support\LangFiles;
use AlfaSix\AlfakitTranslationManager\Support\Plurals;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The translation editor: a grid of keys by locale (the renderers' `translation-manager`
 * component) and header actions to add a locale, translate missing lines with AI, scan
 * the code, and import from or export to the lang files.
 *
 * The grid talks to the page through JSON actions of the `translations` component (rows,
 * save, history, suggestions); each checks the plugin's authorisation and the editable
 * locales again. Extend it and pass your class to `TranslationManagerPlugin::usingPage()`.
 */
class Translations extends Page
{
    use PullsFlashedNotifications;

    /** The action context of the grid. */
    public const COMPONENT = 'translations';

    public const ROWS_ACTION = 'translationsRows';

    public const SAVE_ACTION = 'translationsSave';

    public const HISTORY_ACTION = 'translationsHistory';

    public const SUGGESTION_ACTION = 'translationsSuggestion';

    protected static ?string $slug = 'translations';

    protected static bool $isDiscovered = false;

    protected ?Catalog $catalog = null;

    public static function getPlugin(): TranslationManagerPlugin
    {
        return TranslationManagerPlugin::get();
    }

    public static function canAccess(): bool
    {
        return static::getPlugin()->isAuthorized();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-translation-manager::translation-manager.page.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('alfakit-translation-manager::translation-manager.page.subheading.'.$this->manager()->mode()->value);
    }

    public static function getNavigationLabel(): string
    {
        return static::getPlugin()->getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()->getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()->getNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::getPlugin()->shouldRegisterNavigation();
    }

    protected function manager(): TranslationManager
    {
        return app(TranslationManager::class);
    }

    protected function user(): ?Authenticatable
    {
        return $this->getPanel()->auth()->user();
    }

    /**
     * The keys of every shown locale, built once per request.
     */
    public function getCatalog(): Catalog
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $manager = $this->manager();

        return $this->catalog = new Catalog($manager, static::getPlugin()->getLocales(), $manager->baseLocale(), $manager->scanner()->last());
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->catalog = null;
    }

    /**
     * Locales are application-wide: tenant-scoped editors can't add them.
     */
    protected function canAddLocales(): bool
    {
        return ! static::getPlugin()->isTenantScoped();
    }

    // ---------------------------------------------------------------- Header actions

    protected function getHeaderActions(): array
    {
        return [
            $this->getAddLocaleAction(),
            $this->getTranslateWithAiAction(),
            ActionGroup::make([
                $this->getScanAction(),
                $this->getImportAction(),
                $this->getExportAction(),
            ])
                ->label(__('alfakit-translation-manager::translation-manager.actions.more'))
                ->icon('ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function localeOptions(bool $withoutBase = true): array
    {
        $base = $this->manager()->baseLocale();
        $options = [];

        foreach (static::getPlugin()->getEditableLocales() as $locale) {
            if (! $withoutBase || $locale !== $base) {
                $options[$locale] = Catalog::localeLabel($locale).' ('.$locale.')';
            }
        }

        return $options;
    }

    protected function getAddLocaleAction(): Action
    {
        return Action::make('addLocale')
            ->label(__('alfakit-translation-manager::translation-manager.actions.add_locale.label'))
            ->icon('plus')
            ->color('gray')
            ->modalHeading(__('alfakit-translation-manager::translation-manager.actions.add_locale.heading'))
            ->modalDescription(__('alfakit-translation-manager::translation-manager.actions.add_locale.description'))
            ->modalSubmitActionLabel(__('alfakit-translation-manager::translation-manager.actions.add_locale.submit'))
            ->schema([
                TextInput::make('locale')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.add_locale.field'))
                    ->placeholder('pt_BR')
                    ->required()
                    ->maxLength(32)
                    ->regex(LangFiles::LOCALE_PATTERN)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && in_array($value, $this->getCatalog()->getLocales(), true)) {
                            $fail((string) __('alfakit-translation-manager::translation-manager.actions.add_locale.exists'));
                        }
                    }),
            ])
            ->visible(fn (): bool => $this->canAddLocales())
            ->authorize(fn (): bool => static::getPlugin()->isAuthorized() && $this->canAddLocales())
            ->action(function (array $data): void {
                $locale = (string) ($data['locale'] ?? '');
                $this->manager()->addLocale($locale);

                Notification::make()
                    ->success()
                    ->title(__('alfakit-translation-manager::translation-manager.actions.add_locale.notification', ['locale' => Catalog::localeLabel($locale)]))
                    ->send();
            });
    }

    protected function getTranslateWithAiAction(): Action
    {
        return Action::make('translateWithAi')
            ->label(__('alfakit-translation-manager::translation-manager.actions.ai.label'))
            ->icon('sparkles')
            ->modalHeading(__('alfakit-translation-manager::translation-manager.actions.ai.heading'))
            ->modalDescription(__('alfakit-translation-manager::translation-manager.actions.ai.description'))
            ->modalSubmitActionLabel(__('alfakit-translation-manager::translation-manager.actions.ai.submit'))
            ->schema([
                Select::make('locale')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.ai.locale'))
                    ->options(fn (): array => $this->localeOptions())
                    ->required(),
                Select::make('group')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.ai.group'))
                    ->placeholder(__('alfakit-translation-manager::translation-manager.actions.ai.all_groups'))
                    ->options(fn (): array => array_column($this->getCatalog()->groups(), 'label', 'value'))
                    ->searchable(),
            ])
            ->visible(static fn (): bool => static::getPlugin()->hasAiTranslation())
            ->authorize(static fn (): bool => static::getPlugin()->isAuthorized() && static::getPlugin()->hasAiTranslation())
            ->action(function (array $data): void {
                $plugin = static::getPlugin();
                $locale = (string) ($data['locale'] ?? '');

                if (! array_key_exists($locale, $this->localeOptions())) {
                    throw ValidationException::withMessages(['locale' => __('alfakit-translation-manager::translation-manager.errors.locale_not_editable')]);
                }

                $translator = $plugin->getMachineTranslator() ?? abort(404);
                $group = is_string($data['group'] ?? null) && $data['group'] !== '' ? $data['group'] : null;

                try {
                    $result = (new AiTranslations($this->manager(), $translator, $plugin->getAiBatchSize()))
                        ->translateMissing($this->getCatalog(), $locale, $group, $plugin->getAiLimit(), $this->user());
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('alfakit-translation-manager::translation-manager.actions.ai.failed'))
                        ->send();

                    return;
                }

                $this->catalog = null;

                $notification = Notification::make();
                $result['suggested'] > 0 ? $notification->success() : $notification->warning();

                $notification
                    ->title(trans_choice('alfakit-translation-manager::translation-manager.actions.ai.notification', $result['suggested'], ['count' => $result['suggested']]))
                    ->body(collect([
                        $result['suggested'] > 0 ? __('alfakit-translation-manager::translation-manager.actions.ai.review') : null,
                        $result['failed'] > 0 ? trans_choice('alfakit-translation-manager::translation-manager.actions.ai.skipped', $result['failed'], ['count' => $result['failed']]) : null,
                        $result['remaining'] > 0 ? trans_choice('alfakit-translation-manager::translation-manager.actions.ai.remaining', $result['remaining'], ['count' => $result['remaining']]) : null,
                    ])->filter()->implode(' '))
                    ->send();
            });
    }

    protected function getScanAction(): Action
    {
        return Action::make('scan')
            ->label(__('alfakit-translation-manager::translation-manager.actions.scan.label'))
            ->icon('scan-search')
            ->authorize(static fn (): bool => static::getPlugin()->isAuthorized())
            ->action(function (): void {
                $result = $this->manager()->scanner()->scan();
                $this->catalog = null;
                $catalog = $this->getCatalog();
                $unused = count(array_filter($catalog->keys(), static fn (TranslationKey $key): bool => $catalog->isUsed($key) === false));
                $undefined = count(array_filter($catalog->keys(), $catalog->isUndefined(...)));

                Notification::make()
                    ->success()
                    ->title(__('alfakit-translation-manager::translation-manager.actions.scan.notification', ['files' => $result['files'], 'keys' => count($result['keys'])]))
                    ->body(__('alfakit-translation-manager::translation-manager.actions.scan.summary', ['undefined' => $undefined, 'unused' => $unused]))
                    ->send();
            });
    }

    protected function getImportAction(): Action
    {
        return Action::make('import')
            ->label(__('alfakit-translation-manager::translation-manager.actions.import.label'))
            ->icon('file-down')
            ->modalHeading(__('alfakit-translation-manager::translation-manager.actions.import.heading'))
            ->modalDescription(__('alfakit-translation-manager::translation-manager.actions.import.description'))
            ->modalSubmitActionLabel(__('alfakit-translation-manager::translation-manager.actions.import.submit'))
            ->schema([
                Select::make('locales')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.import.locales'))
                    ->placeholder(__('alfakit-translation-manager::translation-manager.actions.all_locales'))
                    ->multiple()
                    ->options(fn (): array => $this->localeOptions(withoutBase: false)),
                Toggle::make('overwrite')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.import.overwrite'))
                    ->helperText(__('alfakit-translation-manager::translation-manager.actions.import.overwrite_help')),
            ])
            ->visible(fn (): bool => $this->manager()->mode()->usesDatabase() && ! static::getPlugin()->isTenantScoped())
            ->authorize(fn (): bool => static::getPlugin()->isAuthorized() && $this->manager()->mode()->usesDatabase() && ! static::getPlugin()->isTenantScoped())
            ->action(function (array $data): void {
                $count = $this->manager()->import($this->selectedLocales($data['locales'] ?? null, withoutBase: false), (bool) ($data['overwrite'] ?? false), $this->user());

                Notification::make()
                    ->success()
                    ->title(trans_choice('alfakit-translation-manager::translation-manager.actions.import.notification', $count, ['count' => $count]))
                    ->send();
            });
    }

    protected function getExportAction(): Action
    {
        return Action::make('export')
            ->label(__('alfakit-translation-manager::translation-manager.actions.export.label'))
            ->icon('file-up')
            ->requiresConfirmation()
            ->modalHeading(__('alfakit-translation-manager::translation-manager.actions.export.heading'))
            ->modalDescription(__('alfakit-translation-manager::translation-manager.actions.export.description'))
            ->modalSubmitActionLabel(__('alfakit-translation-manager::translation-manager.actions.export.submit'))
            ->schema([
                Select::make('locales')
                    ->label(__('alfakit-translation-manager::translation-manager.actions.export.locales'))
                    ->placeholder(__('alfakit-translation-manager::translation-manager.actions.all_locales'))
                    ->multiple()
                    ->options(fn (): array => $this->localeOptions(withoutBase: false)),
            ])
            ->visible(fn (): bool => $this->manager()->mode()->usesDatabase() && ! static::getPlugin()->isTenantScoped())
            ->authorize(fn (): bool => static::getPlugin()->isAuthorized() && $this->manager()->mode()->usesDatabase() && ! static::getPlugin()->isTenantScoped())
            ->action(function (array $data): void {
                $result = $this->manager()->export($this->selectedLocales($data['locales'] ?? null, withoutBase: false) ?? static::getPlugin()->getEditableLocales());

                Notification::make()
                    ->success()
                    ->title(trans_choice('alfakit-translation-manager::translation-manager.actions.export.notification', $result['lines'], ['count' => $result['lines'], 'files' => count($result['files'])]))
                    ->body($result['backup'] !== null ? __('alfakit-translation-manager::translation-manager.actions.export.backup', ['path' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $result['backup'])]) : null)
                    ->send();
            });
    }

    /**
     * The editable locales picked in a form (null: every editable locale).
     *
     * @return list<string>|null
     */
    protected function selectedLocales(mixed $selected, bool $withoutBase = true): ?array
    {
        $allowed = array_keys($this->localeOptions($withoutBase));

        if (! is_array($selected) || $selected === []) {
            return array_values(array_map('strval', $allowed));
        }

        return array_values(array_intersect(array_map('strval', $allowed), array_filter($selected, is_string(...))));
    }

    // ---------------------------------------------------------------- Grid

    protected function getContentBlocks(): array
    {
        return [
            ['type' => 'Schema', 'key' => self::COMPONENT, 'components' => [[...$this->serializeEditor(), 'columnSpan' => ['default' => 'full']]]],
            ...parent::getContentBlocks(),
        ];
    }

    /**
     * The `translation-manager` component: locales with progress, groups and the first
     * page of rows.
     *
     * @return array<string, mixed>
     */
    public function serializeEditor(): array
    {
        $catalog = $this->getCatalog();
        $manager = $this->manager();
        $plugin = static::getPlugin();
        $base = $manager->baseLocale();
        $editable = $plugin->getEditableLocales();
        $progress = $catalog->progress();
        $scan = $manager->scanner()->last();

        $locales = array_map(static fn (string $locale): array => array_filter([
            'code' => $locale,
            'label' => Catalog::localeLabel($locale),
            'direction' => Catalog::direction($locale),
            'base' => $locale === $base ?: null,
            'editable' => in_array($locale, $editable, true),
            'pluralForms' => Plurals::count($locale),
            'progress' => $progress[$locale] ?? null,
        ], static fn (mixed $value): bool => $value !== null), $catalog->getLocales());

        return [
            'type' => 'translation-manager',
            'key' => self::COMPONENT,
            'actionComponent' => self::COMPONENT,
            'actions' => [
                'rows' => self::ROWS_ACTION,
                'save' => self::SAVE_ACTION,
                'history' => self::HISTORY_ACTION,
                'suggestion' => self::SUGGESTION_ACTION,
            ],
            'mode' => $manager->mode()->value,
            'baseLocale' => $base,
            'locales' => $locales,
            'groups' => $catalog->groups(),
            'filters' => array_values(array_filter(Catalog::FILTERS, static fn (string $filter): bool => $scan !== null || ! in_array($filter, [Catalog::FILTER_UNUSED, Catalog::FILTER_UNDEFINED], true))),
            'scannedAt' => $scan['scannedAt'] ?? null,
            'perPage' => $plugin->getPerPage(),
            ...$this->serializeRows($catalog->query(perPage: $plugin->getPerPage())),
        ];
    }

    /**
     * @param  array{keys: list<TranslationKey>, total: int, page: int, lastPage: int}  $result
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, lastPage: int}
     */
    protected function serializeRows(array $result): array
    {
        $catalog = $this->getCatalog();
        $editable = static::getPlugin()->getEditableLocales();

        return [
            'rows' => array_map(static fn (TranslationKey $key): array => $catalog->row($key, $editable), $result['keys']),
            'total' => $result['total'],
            'page' => $result['page'],
            'lastPage' => $result['lastPage'],
        ];
    }

    /**
     * Grid actions resolve here; everything else as usual.
     */
    public function resolveAction(?string $component, string $name, bool $isBulk = false): Action
    {
        if ($component !== self::COMPONENT) {
            return parent::resolveAction($component, $name, $isBulk);
        }

        $action = match ($name) {
            self::ROWS_ACTION => $this->makeGridAction($name, fn (array $arguments): array => $this->rowsResponse($arguments)),
            self::SAVE_ACTION => $this->makeGridAction($name, fn (array $arguments): array => $this->saveResponse($arguments)),
            self::HISTORY_ACTION => $this->makeGridAction($name, fn (array $arguments): array => $this->historyResponse($arguments)),
            self::SUGGESTION_ACTION => $this->makeGridAction($name, fn (array $arguments): array => $this->suggestionResponse($arguments)),
            default => null,
        };

        if ($action === null || $isBulk) {
            throw new ActionNotResolvableException("Action [{$name}] could not be resolved on [".static::class.'] for the translations grid.');
        }

        return $action;
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $callback
     */
    protected function makeGridAction(string $name, Closure $callback): Action
    {
        return Action::make($name)
            ->context($this)
            ->authorize(static fn (): bool => static::getPlugin()->isAuthorized())
            ->action(fn (array $arguments): array => $callback($arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function rowsResponse(array $arguments): array
    {
        $catalog = $this->getCatalog();
        $filter = is_string($arguments['filter'] ?? null) && in_array($arguments['filter'], Catalog::FILTERS, true) ? $arguments['filter'] : Catalog::FILTER_ALL;
        $locales = is_array($arguments['locales'] ?? null) ? array_values(array_filter($arguments['locales'], is_string(...))) : null;

        return [
            ...$this->serializeRows($catalog->query(
                is_string($arguments['search'] ?? null) ? mb_substr($arguments['search'], 0, 200) : null,
                $filter,
                $locales === [] ? null : $locales,
                is_string($arguments['group'] ?? null) ? $arguments['group'] : null,
                is_numeric($arguments['page'] ?? null) ? (int) $arguments['page'] : 1,
                static::getPlugin()->getPerPage(),
            )),
            'progress' => $catalog->progress(),
        ];
    }

    /**
     * Validate a key and an editable locale from the grid's arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: TranslationKey, 1: string}
     */
    protected function resolveCell(array $arguments, bool $mustEdit = true): array
    {
        $key = TranslationKey::fromId($arguments['id'] ?? null);
        $key = $key === null ? null : $this->getCatalog()->find($key);

        if ($key === null) {
            throw ValidationException::withMessages(['id' => __('alfakit-translation-manager::translation-manager.errors.unknown_key')]);
        }

        $locale = $arguments['locale'] ?? null;

        if (! is_string($locale) || ! in_array($locale, $this->getCatalog()->getLocales(), true) || ($mustEdit && ! static::getPlugin()->canEditLocale($locale))) {
            throw ValidationException::withMessages(['locale' => __('alfakit-translation-manager::translation-manager.errors.locale_not_editable')]);
        }

        return [$key, $locale];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function saveResponse(array $arguments): array
    {
        [$key, $locale] = $this->resolveCell($arguments);

        $value = array_key_exists('forms', $arguments) && is_array($arguments['forms'])
            ? Plurals::join($arguments['forms'])
            : ($arguments['value'] ?? null);

        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages(['value' => __('alfakit-translation-manager::translation-manager.errors.invalid_value')]);
        }

        if ($value !== null && mb_strlen($value) > 65535) {
            throw ValidationException::withMessages(['value' => __('alfakit-translation-manager::translation-manager.errors.too_long')]);
        }

        $value = $value === null ? null : str_replace("\r\n", "\n", $value);
        $this->manager()->save($key, $locale, $value, TranslationManager::SOURCE_EDITOR, $this->user());

        return $this->freshRow($key);
    }

    /**
     * The saved row and the progress, from a fresh catalog.
     *
     * @return array<string, mixed>
     */
    protected function freshRow(TranslationKey $key): array
    {
        $this->catalog = null;
        $catalog = $this->getCatalog();

        return [
            'row' => $catalog->row($key, static::getPlugin()->getEditableLocales()),
            'progress' => $catalog->progress(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function historyResponse(array $arguments): array
    {
        [$key, $locale] = $this->resolveCell($arguments, mustEdit: false);

        return [
            'entries' => array_map(static fn ($revision): array => [
                'id' => $revision->id,
                'old' => $revision->old_value,
                'new' => $revision->new_value,
                'source' => $revision->source,
                'user' => $revision->user_name,
                'at' => $revision->created_at?->toIso8601String(),
            ], $this->manager()->history($key, $locale)),
        ];
    }

    /**
     * Accept or reject one machine translation, or all of a locale.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function suggestionResponse(array $arguments): array
    {
        $decision = $arguments['decision'] ?? null;

        if (! in_array($decision, ['accept', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => __('alfakit-translation-manager::translation-manager.errors.invalid_value')]);
        }

        $manager = $this->manager();

        if (($arguments['all'] ?? false) === true) {
            $locale = $arguments['locale'] ?? null;

            if (! is_string($locale) || ! static::getPlugin()->canEditLocale($locale)) {
                throw ValidationException::withMessages(['locale' => __('alfakit-translation-manager::translation-manager.errors.locale_not_editable')]);
            }

            $count = 0;

            foreach (TranslationSuggestion::query()->where('scope', $manager->scope())->where('locale', $locale)->orderBy('id')->get() as $suggestion) {
                // Suggestions with warnings need a look of their own.
                if ($decision === 'accept' && $suggestion->warnings !== null && $suggestion->warnings !== []) {
                    continue;
                }

                $decision === 'accept' ? $manager->acceptSuggestion($suggestion, $this->user()) : $suggestion->delete();
                $count++;
            }

            Notification::make()
                ->success()
                ->title(trans_choice('alfakit-translation-manager::translation-manager.notifications.suggestions_'.($decision === 'accept' ? 'accepted' : 'rejected'), $count, ['count' => $count]))
                ->send();

            $this->catalog = null;

            return ['count' => $count, 'progress' => $this->getCatalog()->progress(), 'notifications' => $this->pullFlashedNotifications(request())];
        }

        [$key, $locale] = $this->resolveCell($arguments);
        $suggestion = $manager->suggestion($key, $locale);

        if ($suggestion === null) {
            throw ValidationException::withMessages(['id' => __('alfakit-translation-manager::translation-manager.errors.no_suggestion')]);
        }

        $decision === 'accept' ? $manager->acceptSuggestion($suggestion, $this->user()) : $suggestion->delete();

        return $this->freshRow($key);
    }
}
