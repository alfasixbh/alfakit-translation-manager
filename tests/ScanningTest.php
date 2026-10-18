<?php

declare(strict_types=1);

use AlfaSix\AlfakitTranslationManager\Support\Catalog;
use AlfaSix\AlfakitTranslationManager\Support\LangFiles;
use AlfaSix\AlfakitTranslationManager\Support\SourceScanner;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;

use function AlfaSix\AlfakitTranslationManager\Tests\lang;
use function AlfaSix\AlfakitTranslationManager\Tests\manager;
use function AlfaSix\AlfakitTranslationManager\Tests\tmTest;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('reads locales, nested groups, sub directories, JSON files and vendor overrides', function (): void {
    $files = new LangFiles(lang(), null, ['shop' => tmTest()->workspace.'/package/lang']);

    expect($files->locales())->toBe(['ar', 'de', 'en'])
        ->and($files->groups('en'))->toBe(['admin/users', 'auth'])
        ->and($files->namespaces())->toBe(['shop'])
        ->and($files->groups('en', 'shop'))->toBe(['messages'])
        ->and(LangFiles::flatten($files->readGroup('en', 'auth')))->toMatchArray([
            'failed' => 'These credentials do not match our records.',
            'messages.welcome' => 'Welcome back, :name!',
        ])
        ->and($files->readJson('de'))->toBe(['Save changes' => 'Änderungen speichern'])
        // Package lines with the lang/vendor override on top, like Laravel loads them.
        ->and($files->readGroup('en', 'messages', 'shop'))->toBe(['cart' => 'Your cart', 'checkout' => 'Checkout'])
        ->and($files->value(TranslationKey::parse('admin/users.title'), 'en'))->toBe('Users')
        ->and($files->value(TranslationKey::parse('shop::messages.cart'), 'de'))->toBe('Ihr Warenkorb');
});

it('rejects locales and groups that would leave the lang directory', function (): void {
    $files = new LangFiles(lang());

    expect(LangFiles::isValidLocale('pt_BR'))->toBeTrue()
        ->and(LangFiles::isValidLocale('zh-Hant'))->toBeTrue()
        ->and(LangFiles::isValidLocale('../etc'))->toBeFalse()
        ->and($files->readGroup('en', '../../secrets'))->toBe([])
        ->and(fn () => $files->fileFor('en', '../auth'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $files->fileFor('en/../..', 'auth'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $files->fileFor('en', 'auth', '../x'))->toThrow(InvalidArgumentException::class);
});

it('parses keys the way Laravel looks them up', function (): void {
    expect(TranslationKey::parse('auth.failed'))->toEqual(new TranslationKey('*', 'auth', 'failed'))
        ->and(TranslationKey::parse('auth.messages.welcome'))->toEqual(new TranslationKey('*', 'auth', 'messages.welcome'))
        ->and(TranslationKey::parse('admin/users.title'))->toEqual(new TranslationKey('*', 'admin/users', 'title'))
        ->and(TranslationKey::parse('shop::messages.cart'))->toEqual(new TranslationKey('shop', 'messages', 'cart'))
        ->and(TranslationKey::parse('Save changes')->isJson())->toBeTrue()
        ->and(TranslationKey::parse('Done.')->isJson())->toBeTrue();

    $key = new TranslationKey('*', '*', "Quotes ' \" and ünïcode");

    expect(TranslationKey::fromId($key->id()))->toEqual($key)
        ->and(TranslationKey::fromId('not base64 !'))->toBeNull()
        ->and(TranslationKey::fromId(base64_encode('{"a":1}')))->toBeNull();
});

it('finds the keys PHP, Blade and JavaScript files use, and dynamic prefixes', function (): void {
    $scan = manager()->scanner()->scan();

    expect(array_keys($scan['keys']))->toEqualCanonicalizing([
        'auth.failed',
        'auth.throttle',
        'admin/users.count',
        'Save changes',
        'auth.not_there_yet',
        'auth.messages.welcome',
        'Hello, :name',
        'shop::messages.cart',
        'admin/users.title',
        'Brand new sentence',
    ])
        ->and($scan['prefixes'])->toBe(['kind.', 'roles.', 'status.'])
        ->and($scan['files'])->toBe(3)
        ->and($scan['keys']['auth.failed'])->toBe(['code/app/Controller.php'])
        // The result is kept for the editor.
        ->and(manager()->scanner()->last()['keys'])->toHaveKey('auth.failed');
});

it('ignores methods and longer function names that end like a helper', function (): void {
    $scanner = new SourceScanner([]);

    expect($scanner->extract('$this->t("a.b"); format("c.d"); __(\'e.f\'); @lang("g.h")'))
        ->toBe([['e.f', false], ['g.h', false]])
        ->and($scanner->extract("t('x.y'); i18n.t('z.w'); obj.t('no.no'); \$t(`a.\${b}`)", php: false))
        ->toBe([['x.y', false], ['z.w', false], ['a.', true]])
        ->and($scanner->extract("__('it\\'s')"))->toBe([["it's", false]]);
});

it('marks unused keys and adds keys the code uses that no file defines', function (): void {
    manager()->setNamespaces(['shop']);
    $scan = manager()->scanner()->scan();
    $catalog = new Catalog(manager(), ['en', 'de'], 'en', $scan);
    $labels = static fn (callable $filter): array => array_values(array_map(static fn (TranslationKey $key): string => $key->label(), array_filter($catalog->keys(), $filter)));

    expect($labels(fn (TranslationKey $key): bool => $catalog->isUsed($key) === false))->toEqualCanonicalizing([
        'auth.messages.apples',
        'auth.legacy',
        'admin/users.notice',
        'shop::messages.checkout',
    ])
        ->and($labels($catalog->isUndefined(...)))->toEqualCanonicalizing(['auth.not_there_yet', 'Brand new sentence'])
        ->and($catalog->find(TranslationKey::json('Brand new sentence')))->not->toBeNull();
});

it('reports missing and unused keys from the command', function (): void {
    tmTest()->artisan('alfakit-translations:scan', ['--missing' => true, '--unused' => true])
        ->expectsOutputToContain('2 missing in [en]')
        ->expectsOutputToContain('auth.not_there_yet')
        ->expectsOutputToContain('auth.legacy')
        ->assertSuccessful();

    tmTest()->artisan('alfakit-translations:scan', ['--fail-on-missing' => true])->assertFailed();
});
