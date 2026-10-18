<?php

declare(strict_types=1);

use AlfaSix\AlfakitTranslationManager\Ai\LaravelAiTranslator;
use AlfaSix\AlfakitTranslationManager\Ai\TranslationAgent;
use AlfaSix\AlfakitTranslationManager\Contracts\MachineTranslator;
use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationRevision;
use AlfaSix\AlfakitTranslationManager\Models\TranslationSuggestion;
use AlfaSix\AlfakitTranslationManager\Pages\Translations;
use AlfaSix\AlfakitTranslationManager\Support\AiTranslations;
use AlfaSix\AlfakitTranslationManager\Support\Catalog;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;

use function AlfaSix\AlfakitTranslationManager\Tests\editor;
use function AlfaSix\AlfakitTranslationManager\Tests\editorPage;
use function AlfaSix\AlfakitTranslationManager\Tests\manager;
use function AlfaSix\AlfakitTranslationManager\Tests\row;
use function AlfaSix\AlfakitTranslationManager\Tests\tmTest;
use function AlfaSix\AlfakitTranslationManager\Tests\translationsPanel;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * A translator that prefixes every line with `[de]` and records what it received.
 */
function fakeTranslator(?Closure $translate = null): MachineTranslator
{
    $translator = new class($translate) implements MachineTranslator
    {
        /** @var list<array<string, string>> */
        public array $received = [];

        public function __construct(private ?Closure $translate) {}

        public function translate(array $lines, string $from, string $to, array $context = []): array
        {
            $this->received[] = $lines;

            return $this->translate !== null ? ($this->translate)($lines) : array_map(static fn (string $line): string => "[{$to}] {$line}", $lines);
        }
    };

    app()->instance(MachineTranslator::class, $translator);

    return $translator;
}

function catalog(): Catalog
{
    return new Catalog(manager(), ['en', 'de'], 'en');
}

it('translates the missing lines of a locale into suggestions, keeping placeholders, HTML and plural conditions', function (): void {
    $translator = fakeTranslator();

    $result = (new AiTranslations(manager(), $translator, batchSize: 3))->translateMissing(catalog(), 'de', 'admin/users');

    expect($result)->toBe(['suggested' => 3, 'failed' => 0, 'remaining' => 0])
        ->and($translator->received)->toHaveCount(1)
        // The machine never sees the placeholders or tags.
        ->and($translator->received[0])->toBe(['l1' => '⟦1⟧ user|⟦2⟧ users', 'l2' => 'Read the ⟦1⟧terms⟦2⟧ first.', 'l3' => 'Users'])
        ->and(TranslationSuggestion::query()->pluck('value', 'key')->all())->toBe([
            'count' => '[de] :count user|:count users',
            'notice' => '[de] Read the <a href=":url">terms</a> first.',
            'title' => '[de] Users',
        ])
        // Nothing is published before someone accepts it.
        ->and(Translation::query()->count())->toBe(0)
        ->and(__('admin/users.title', [], 'de'))->toBe('Users');
});

it('skips lines whose markers the machine broke, and respects the limit', function (): void {
    $translator = fakeTranslator(fn (array $lines): array => array_map(static fn (string $line): string => str_replace('⟦1⟧', '', $line), $lines));

    $result = (new AiTranslations(manager(), $translator))->translateMissing(catalog(), 'de', 'auth', limit: 2);

    expect($result)->toBe(['suggested' => 1, 'failed' => 1, 'remaining' => 1])
        // `auth.legacy` has nothing to mask; `auth.messages.apples` lost its first condition.
        ->and(TranslationSuggestion::query()->pluck('key')->all())->toBe(['legacy']);
});

it('stores warnings with suggestions that changed the meaning of placeholders', function (): void {
    fakeTranslator(fn (array $lines): array => array_map(static fn (string $line): string => $line.' :extra', $lines));

    (new AiTranslations(manager(), app(MachineTranslator::class)))->translateMissing(catalog(), 'de', 'auth');

    expect(TranslationSuggestion::query()->where('key', 'throttle')->sole()->warnings)->toBe(['Unknown placeholder :extra']);
});

it('prompts the laravel/ai agent with masked lines and reads its structured answer', function (): void {
    TranslationAgent::fake(fn (string $prompt): array => [
        'translations' => [
            ['id' => '1', 'text' => 'Zu viele Anmeldeversuche. Bitte in ⟦1⟧ Sekunden erneut versuchen.'],
            ['id' => '99', 'text' => 'unknown id'],
        ],
    ]);

    $result = (new LaravelAiTranslator)->translate(['1' => 'Too many login attempts. Please try again in ⟦1⟧ seconds.'], 'en', 'de', ['1' => 'auth.throttle']);

    expect($result)->toBe(['1' => 'Zu viele Anmeldeversuche. Bitte in ⟦1⟧ Sekunden erneut versuchen.']);

    TranslationAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Target language: ') && str_contains($prompt->prompt, '(de)') && str_contains($prompt->prompt, 'auth.throttle'));
});

it('runs "Translate with AI" from the page and reviews the suggestions in the grid', function (): void {
    TranslationAgent::fake(fn (string $prompt): array => [
        'translations' => collect(json_decode((string) str($prompt)->after("Items (JSON):\n"), true))
            ->map(fn (array $item): array => ['id' => $item['id'], 'text' => 'DE '.$item['text']])
            ->all(),
    ]);
    translationsPanel();
    $user = editor();
    tmTest()->actingAs($user);

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->callAction('translateWithAi', ['locale' => 'de', 'group' => 'admin/users'])
        ->assertHasNoActionErrors()
        ->assertNotified('3 suggestions are ready for review');

    [$token, $grid] = editorPage();
    $rows = tmTest()->gridAction('tm', $token, Translations::ROWS_ACTION, ['filter' => 'suggestions'])->json('result.rows');

    expect(collect($rows)->pluck('key')->all())->toBe(['admin/users.count', 'admin/users.notice', 'admin/users.title'])
        ->and(row($rows, 'admin/users.title')['cells']['de']['suggestion'])->toBe(['value' => 'DE Users']);

    $title = row($rows, 'admin/users.title')['id'];
    $notice = row($rows, 'admin/users.notice')['id'];

    $accepted = tmTest()->gridAction('tm', $token, Translations::SUGGESTION_ACTION, ['id' => $title, 'locale' => 'de', 'decision' => 'accept'])->assertOk()->json('result.row.cells.de');
    tmTest()->gridAction('tm', $token, Translations::SUGGESTION_ACTION, ['id' => $notice, 'locale' => 'de', 'decision' => 'reject'])->assertOk();

    expect($accepted)->toMatchArray(['value' => 'DE Users', 'changed' => true])
        ->and($accepted)->not->toHaveKey('suggestion')
        ->and(__('admin/users.title', [], 'de'))->toBe('DE Users')
        ->and(TranslationRevision::query()->sole()->source)->toBe('ai')
        ->and(TranslationSuggestion::query()->pluck('key')->all())->toBe(['count']);

    $all = tmTest()->gridAction('tm', $token, Translations::SUGGESTION_ACTION, ['locale' => 'de', 'decision' => 'accept', 'all' => true])->assertOk()->json('result');

    expect($all['count'])->toBe(1)
        ->and(TranslationSuggestion::query()->count())->toBe(0)
        ->and(__('admin/users.count', [], 'de'))->toBe('DE :count user|:count users')
        ->and(trans_choice('admin/users.count', 1, [], 'de'))->toBe('DE 1 user');
});

it('hides AI translation when turned off and refuses locales the user may not edit', function (): void {
    fakeTranslator();
    translationsPanel(fn (TranslationManagerPlugin $plugin): TranslationManagerPlugin => $plugin->editableLocales(['ar']));
    tmTest()->actingAs(editor());

    alfakit()->testPage(Translations::class, panel: 'tm')
        ->callAction('translateWithAi', ['locale' => 'de'])
        ->assertHasActionErrors(['locale']);

    expect(TranslationSuggestion::query()->count())->toBe(0);

    $plugin = alfakit()->getPanel('tm')->getPlugin(TranslationManagerPlugin::ID);
    assert($plugin instanceof TranslationManagerPlugin);
    $plugin->aiTranslation(false);

    alfakit()->testPage(Translations::class, panel: 'tm')->assertActionHidden('translateWithAi');
});

it('refuses AI suggestions to users who may not manage translations', function (): void {
    translationsPanel();
    tmTest()->actingAs(editor());
    [$token] = editorPage();
    manager()->suggest(TranslationKey::parse('auth.legacy'), 'de', 'Alt');

    tmTest()->actingAs(editor('guest@example.com'));
    tmTest()->gridAction('tm', $token, Translations::SUGGESTION_ACTION, ['id' => TranslationKey::parse('auth.legacy')->id(), 'locale' => 'de', 'decision' => 'accept'])->assertForbidden();

    expect(Translation::query()->count())->toBe(0);
});
