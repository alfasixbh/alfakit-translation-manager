<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationRevision;
use AlfaSix\AlfakitTranslationManager\Pages\Translations;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitTranslationManager\Tests\editor;
use function AlfaSix\AlfakitTranslationManager\Tests\editorPage;
use function AlfaSix\AlfakitTranslationManager\Tests\manager;
use function AlfaSix\AlfakitTranslationManager\Tests\row;
use function AlfaSix\AlfakitTranslationManager\Tests\tmTest;
use function AlfaSix\AlfakitTranslationManager\Tests\translationsPanel;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('registers the page and shares the frontend strings', function (): void {
    $panel = translationsPanel();

    expect($panel->getPlugin('translation-manager'))->toBeInstanceOf(TranslationManagerPlugin::class)
        ->and($panel->getFrontendTranslations()['translation-manager']['filters']['missing'])->toBe('Missing');
});

it('renders the grid with locales, progress, groups and the first rows', function (): void {
    translationsPanel();
    tmTest()->actingAs(editor());

    [, $grid] = editorPage();
    $locales = collect($grid['locales'])->keyBy('code');

    expect($grid['type'])->toBe('translation-manager')
        ->and($grid['actionComponent'])->toBe('translations')
        ->and($grid['baseLocale'])->toBe('en')
        ->and($locales->keys()->all())->toBe(['en', 'ar', 'de'])
        ->and($locales['ar']['direction'])->toBe('rtl')
        ->and($locales['de']['direction'])->toBe('ltr')
        ->and($locales['en']['base'])->toBeTrue()
        ->and($locales['ar']['pluralForms'])->toBe(6)
        ->and($locales['de']['progress'])->toBe(['translated' => 4, 'total' => 12, 'percent' => 0.3333])
        ->and(collect($grid['groups'])->pluck('value')->all())->toBe(['json', 'admin/users', 'auth', 'shop::messages'])
        ->and($grid['filters'])->toBe(['all', 'missing', 'changed', 'warnings', 'suggestions'])
        ->and($grid['total'])->toBe(12)
        ->and($grid['rows'][0]['key'])->toBe('Hello, :name');

    $count = row($grid['rows'], 'admin/users.count');

    expect($count['plural'])->toBeTrue()
        ->and($count['placeholders'])->toBe([':count'])
        ->and($count['cells']['de'])->toBe(['forms' => ['', ''], 'formLabels' => ['1', '0, 2, 3…'], 'editable' => true])
        ->and($count['cells']['ar']['formLabels'])->toHaveCount(6);
});

it('denies access by default, then follows the manageTranslations gate', function (): void {
    Panels::register('tmgate', fn (Panel $panel): Panel => $panel->plugin(TranslationManagerPlugin::make()));
    tmTest()->actingAs(editor('someone@example.com'));

    tmTest()->get('/tmgate/translations')->assertForbidden();

    Gate::define('manageTranslations', fn ($user): bool => $user->email === 'someone@example.com');

    tmTest()->get('/tmgate/translations', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();
});

it('refuses the grid actions to users the authorize() callback rejects', function (): void {
    translationsPanel();
    tmTest()->actingAs(editor());
    [$token] = editorPage();

    tmTest()->actingAs(editor('intruder@example.com'));

    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => TranslationKey::parse('auth.failed')->id(), 'locale' => 'de', 'value' => 'Hacked'])->assertForbidden();
    tmTest()->gridAction('tm', $token, Translations::ROWS_ACTION)->assertForbidden();

    expect(Translation::query()->count())->toBe(0);
});

it('searches, filters by status and group, and pages the rows', function (): void {
    translationsPanel(fn (TranslationManagerPlugin $plugin): TranslationManagerPlugin => $plugin->perPage(5));
    tmTest()->actingAs(editor());
    [$token] = editorPage();
    $rows = fn (array $arguments): array => tmTest()->gridAction('tm', $token, Translations::ROWS_ACTION, $arguments)->assertOk()->json('result');

    expect(collect($rows(['search' => 'welcome'])['rows'])->pluck('key')->all())->toBe(['auth.messages.welcome'])
        // Values are searched too, in every locale.
        ->and(collect($rows(['search' => 'speichern'])['rows'])->pluck('key')->all())->toBe(['Save changes'])
        ->and(collect($rows(['group' => 'admin/users'])['rows'])->pluck('key')->all())->toBe(['admin/users.count', 'admin/users.notice', 'admin/users.title'])
        ->and(collect($rows(['filter' => 'missing', 'locales' => ['de'], 'group' => 'auth'])['rows'])->pluck('key')->all())->toBe(['auth.legacy', 'auth.messages.apples', 'auth.throttle'])
        ->and($rows(['page' => 2]))->toMatchArray(['total' => 12, 'page' => 2, 'lastPage' => 3])
        ->and($rows(['page' => 99])['page'])->toBe(3)
        ->and($rows(['filter' => 'nonsense'])['total'])->toBe(12);

    manager()->save(TranslationKey::parse('auth.legacy'), 'de', 'Alt');
    manager()->save(TranslationKey::parse('auth.throttle'), 'de', 'Langsam!');

    expect(collect($rows(['filter' => 'changed'])['rows'])->pluck('key')->all())->toBe(['auth.legacy', 'auth.throttle'])
        ->and(collect($rows(['filter' => 'warnings'])['rows'])->pluck('key')->all())->toBe(['auth.throttle']);
});

it('saves a cell, returns the fresh row with placeholder warnings and records the history', function (): void {
    translationsPanel();
    $user = editor();
    tmTest()->actingAs($user);
    [$token] = editorPage();
    $id = TranslationKey::parse('auth.messages.welcome')->id();

    $result = tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $id, 'locale' => 'de', 'value' => "Hallo du!\r\nSchön"])->assertOk()->json('result');

    expect($result['row']['cells']['de'])->toMatchArray([
        'value' => "Hallo du!\nSchön",
        'changed' => true,
        'warnings' => ['Missing placeholder :name'],
    ])
        ->and($result['progress']['de']['translated'])->toBe(4)
        ->and(__('auth.messages.welcome', ['name' => 'Ada'], 'de'))->toBe("Hallo du!\nSchön");

    // Plural forms are joined on the server.
    $count = TranslationKey::parse('admin/users.count')->id();
    $plural = tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $count, 'locale' => 'de', 'forms' => [':count Benutzer', ':count Benutzer', '']])->assertOk()->json('result.row.cells.de');

    expect($plural['value'])->toBe(':count Benutzer|:count Benutzer')
        ->and(trans_choice('admin/users.count', 3, [], 'de'))->toBe('3 Benutzer');

    $history = tmTest()->gridAction('tm', $token, Translations::HISTORY_ACTION, ['id' => $id, 'locale' => 'de'])->assertOk()->json('result.entries');

    expect($history)->toHaveCount(1)
        ->and($history[0])->toMatchArray(['old' => 'Willkommen zurück, :name!', 'new' => "Hallo du!\nSchön", 'source' => 'editor', 'user' => 'Ada Admin'])
        ->and(TranslationRevision::query()->first()?->user_id)->toBe($user->getKey());
});

it('validates keys, locales and values sent by the grid', function (): void {
    translationsPanel(fn (TranslationManagerPlugin $plugin): TranslationManagerPlugin => $plugin->editableLocales(['de']));
    tmTest()->actingAs(editor());
    [$token, $grid] = editorPage();
    $id = TranslationKey::parse('auth.failed')->id();

    expect(collect($grid['locales'])->pluck('editable', 'code')->all())->toBe(['en' => false, 'ar' => false, 'de' => true]);

    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $id, 'locale' => 'ar', 'value' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $id, 'locale' => '../de', 'value' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => (new TranslationKey('*', 'auth', 'invented'))->id(), 'locale' => 'de', 'value' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('id');
    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => 'garbage', 'locale' => 'de', 'value' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('id');
    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $id, 'locale' => 'de', 'value' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('value');

    expect(Translation::query()->count())->toBe(0);
});

it('runs the header actions: add a locale, export and import', function (): void {
    translationsPanel();
    tmTest()->actingAs(editor());

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->assertActionVisible('addLocale')
        // laravel/ai is installed in this repository.
        ->assertActionVisible('translateWithAi')
        ->callAction('addLocale', ['locale' => 'de'])
        ->assertHasActionErrors(['locale'])
        ->callAction('addLocale', ['locale' => 'not a locale'])
        ->assertHasActionErrors(['locale'])
        ->callAction('addLocale', ['locale' => 'fr'])
        ->assertHasNoActionErrors();

    expect(manager()->discoverLocales())->toContain('fr');

    manager()->save(TranslationKey::parse('auth.legacy'), 'fr', 'Ancien');

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->callAction('export', ['locales' => ['fr']])
        ->assertHasNoActionErrors()
        ->assertNotified('1 text written to 1 files');

    expect(require \AlfaSix\AlfakitTranslationManager\Tests\lang('fr/auth.php'))->toBe(['legacy' => 'Ancien']);

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->callAction('import', ['locales' => ['fr'], 'overwrite' => false])
        ->assertNotified('1 text imported');
});

it('scans the code from the page and offers the unused and undefined filters', function (): void {
    translationsPanel();
    tmTest()->actingAs(editor());

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->callAction('scan')
        ->assertNotified('Scanned 3 files and found 10 keys');

    [$token, $grid] = editorPage();

    expect($grid['filters'])->toContain('unused', 'undefined')
        ->and($grid['scannedAt'])->not->toBeNull();

    $undefined = tmTest()->gridAction('tm', $token, Translations::ROWS_ACTION, ['filter' => 'undefined'])->json('result.rows');

    expect(collect($undefined)->pluck('key')->all())->toBe(['Brand new sentence', 'auth.not_there_yet'])
        ->and($undefined[0]['undefined'])->toBeTrue();

    // A key the code uses but no file defines can be translated right away.
    tmTest()->gridAction('tm', $token, Translations::SAVE_ACTION, ['id' => $undefined[1]['id'], 'locale' => 'en', 'value' => 'Not there yet'])->assertOk();

    expect(__('auth.not_there_yet'))->toBe('Not there yet');
});

it('scopes edits to the current tenant when the plugin is tenant-scoped', function (): void {
    Panels::register('tmteam', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->plugin(TranslationManagerPlugin::make()->tenantScoped()->authorize(fn (): bool => true)));

    $user = TenantUser::query()->create(['name' => 'T', 'email' => 't@example.com', 'password' => 'secret']);
    $acme = Team::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['name' => 'Globex', 'slug' => 'globex']);
    $user->teams()->attach([$acme->getKey(), $globex->getKey()]);
    tmTest()->actingAs($user);

    [$token] = editorPage('/tmteam/acme/translations');
    tmTest()->gridAction('tmteam/acme', $token, Translations::SAVE_ACTION, ['id' => TranslationKey::parse('auth.failed')->id(), 'locale' => 'en', 'value' => 'Acme says no'])->assertOk();

    expect(Translation::query()->sole()->scope)->toBe($acme->getMorphClass().':'.$acme->getKey());

    [, $acmeGrid] = editorPage('/tmteam/acme/translations');
    [$globexToken, $globexGrid] = editorPage('/tmteam/globex/translations');

    expect(row($acmeGrid['rows'], 'auth.failed')['cells']['en']['value'])->toBe('Acme says no')
        ->and(row($globexGrid['rows'], 'auth.failed')['cells']['en']['value'])->toBe('These credentials do not match our records.')
        ->and(tmTest()->gridAction('tmteam/globex', $globexToken, Translations::HISTORY_ACTION, ['id' => TranslationKey::parse('auth.failed')->id(), 'locale' => 'en'])->json('result.entries'))->toBe([]);

    // The token of one tenant can't be replayed against another.
    expect(tmTest()->gridAction('tmteam/globex', $token, Translations::SAVE_ACTION, ['id' => TranslationKey::parse('auth.failed')->id(), 'locale' => 'en', 'value' => 'x'])->status())->toBeIn([403, 404])
        ->and(Translation::query()->count())->toBe(1);

    alfakit()->testPage(Translations::class, panel: 'tmteam', parameters: ['tenant' => 'acme'])
        ->assertActionHidden('export')
        ->assertActionHidden('import')
        ->assertActionHidden('addLocale');
});
