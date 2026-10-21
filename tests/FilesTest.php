<?php

declare(strict_types=1);

use AlfaSix\AlfakitTranslationManager\Enums\StorageMode;
use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationRevision;
use AlfaSix\AlfakitTranslationManager\Support\LangFiles;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;

use function AlfaSix\AlfakitTranslationManager\Tests\editor;
use function AlfaSix\AlfakitTranslationManager\Tests\lang;
use function AlfaSix\AlfakitTranslationManager\Tests\manager;
use function AlfaSix\AlfakitTranslationManager\Tests\tmTest;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('exports PHP arrays in var_export style with short arrays', function (): void {
    expect(LangFiles::export(['a' => "It's \\ here", 'nested' => ['b' => 'x', 'list' => ['one', 'two']], 'empty' => []]))->toBe(<<<'PHP'
        [
            'a' => 'It\'s \\ here',
            'nested' => [
                'b' => 'x',
                'list' => [
                    0 => 'one',
                    1 => 'two',
                ],
            ],
            'empty' => [],
        ]
        PHP);
});

it('exports the database overrides to the lang files, backs up the old files and clears the database', function (): void {
    $user = editor();
    manager()->save(TranslationKey::parse('auth.messages.welcome'), 'de', "Hallo :name, it's \"you\"", user: $user);
    manager()->save(TranslationKey::parse('auth.throttle'), 'de', 'Zu viele Versuche. Bitte in :seconds Sekunden erneut versuchen.', user: $user);
    manager()->save(TranslationKey::parse('admin/users.title'), 'de', 'Benutzer', user: $user);
    manager()->save(TranslationKey::json('Hello, :name'), 'de', 'Hallo, :name', user: $user);
    manager()->save(TranslationKey::parse('shop::messages.checkout'), 'de', 'Zur Kasse', user: $user);
    $before = (string) file_get_contents(lang('de/auth.php'));

    $result = manager()->export();

    expect($result['lines'])->toBe(5)
        ->and($result['files'])->toHaveCount(4)
        ->and(Translation::query()->count())->toBe(0)
        // The files are valid PHP / JSON and keep the lines that weren't edited.
        ->and(require lang('de/auth.php'))->toBe([
            'failed' => 'Diese Kombination aus Zugangsdaten wurde nicht gefunden.',
            'messages' => ['welcome' => "Hallo :name, it's \"you\""],
            'throttle' => 'Zu viele Versuche. Bitte in :seconds Sekunden erneut versuchen.',
        ])
        ->and(require lang('de/admin/users.php'))->toBe(['title' => 'Benutzer'])
        ->and(json_decode((string) file_get_contents(lang('de.json')), true))->toBe(['Hello, :name' => 'Hallo, :name', 'Save changes' => 'Änderungen speichern'])
        ->and(file_get_contents(lang('de.json')))->toContain('"Save changes": "Änderungen speichern"')
        ->and(require lang('vendor/shop/de/messages.php'))->toBe(['cart' => 'Ihr Warenkorb', 'checkout' => 'Zur Kasse'])
        ->and($result['backup'])->not->toBeNull()
        ->and(file_get_contents($result['backup'].'/de/auth.php'))->toBe($before)
        // No temporary files are left behind.
        ->and(glob(lang('de').'/.alfakit-*'))->toBe([]);

    // The application reads the same lines from the files now.
    app('translator')->setLoaded([]);
    expect(__('auth.messages.welcome', ['name' => 'Ada'], 'de'))->toBe("Hallo Ada, it's \"you\"")
        ->and(__('admin/users.title', [], 'de'))->toBe('Benutzer');
});

it('round-trips: import copies the files into the database and export writes the same files', function (): void {
    $snapshot = static fn (): array => collect(['de/auth.php', 'en/auth.php', 'en/admin/users.php'])->mapWithKeys(fn (string $file): array => [$file => require lang($file)])->all();
    $before = $snapshot();

    $count = manager()->import(['en', 'de']);

    expect($count)->toBe(15)
        ->and(Translation::query()->where('locale', 'de')->where('key', 'failed')->value('value'))->toBe('Diese Kombination aus Zugangsdaten wurde nicht gefunden.');

    manager()->export(['en', 'de']);

    expect($snapshot())->toBe($before)
        ->and(Translation::query()->count())->toBe(0);
});

it('keeps database values on import unless asked to overwrite them', function (): void {
    $user = editor();
    manager()->save(TranslationKey::parse('auth.failed'), 'de', 'Panel value', user: $user);

    manager()->import(['de']);
    expect(Translation::query()->where('locale', 'de')->where('key', 'failed')->value('value'))->toBe('Panel value');

    manager()->import(['de'], overwrite: true, user: $user);
    expect(Translation::query()->where('locale', 'de')->where('key', 'failed')->value('value'))->toBe('Diese Kombination aus Zugangsdaten wurde nicht gefunden.')
        ->and(TranslationRevision::query()->where('source', 'import')->count())->toBe(1);
});

it('writes edits straight to the files in files mode', function (): void {
    manager()->setMode(StorageMode::Files);

    manager()->save(TranslationKey::parse('auth.legacy'), 'de', 'Nie benutzt');
    manager()->save(TranslationKey::parse('auth.failed'), 'de', null);

    expect(require lang('de/auth.php'))->toBe([
        'messages' => ['welcome' => 'Willkommen zurück, :name!'],
        'legacy' => 'Nie benutzt',
    ])
        ->and(Translation::query()->count())->toBe(0)
        ->and(TranslationRevision::query()->count())->toBe(2)
        ->and(__('auth.legacy', [], 'de'))->toBe('Nie benutzt');
});

it('stores and writes in both mode', function (): void {
    manager()->setMode(StorageMode::Both);

    manager()->save(TranslationKey::json('Hello, :name'), 'ar', 'مرحبا، :name');

    expect(json_decode((string) file_get_contents(lang('ar.json')), true))->toHaveKey('Hello, :name')
        ->and(Translation::query()->count())->toBe(1);
});

it('adds a locale with its directory and JSON file', function (): void {
    manager()->setMode(StorageMode::Both);
    manager()->addLocale('pt_BR');

    expect(is_dir(lang('pt_BR')))->toBeTrue()
        ->and(file_get_contents(lang('pt_BR.json')))->toBe("{}\n")
        ->and(manager()->discoverLocales())->toContain('pt_BR')
        ->and(fn () => manager()->addLocale('../evil'))->toThrow(InvalidArgumentException::class);
});

it('exports and imports from the commands', function (): void {
    manager()->save(TranslationKey::parse('auth.legacy'), 'de', 'Alt');

    tmTest()->artisan('alfakit-translations:export', ['--locale' => ['de']])->expectsOutputToContain('Wrote 1 line to 1 file.')->assertSuccessful();
    expect((require lang('de/auth.php'))['legacy'])->toBe('Alt');

    tmTest()->artisan('alfakit-translations:import', ['--locale' => ['de']])->expectsOutputToContain('Imported 5 lines.')->assertSuccessful();
});
