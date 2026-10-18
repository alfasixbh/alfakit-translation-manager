<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitTranslationManager\Enums\StorageMode;
use AlfaSix\AlfakitTranslationManager\Models\Translation;
use AlfaSix\AlfakitTranslationManager\Models\TranslationRevision;
use AlfaSix\AlfakitTranslationManager\Support\TranslationKey;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;
use AlfaSix\AlfakitTranslationManager\Translation\OverrideLoader;
use Illuminate\Support\Facades\DB;

use function AlfaSix\AlfakitTranslationManager\Tests\editor;
use function AlfaSix\AlfakitTranslationManager\Tests\manager;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('puts the override loader in front of the application loader', function (): void {
    expect(app('translator')->getLoader())->toBeInstanceOf(OverrideLoader::class)
        ->and(__('auth.failed'))->toBe('These credentials do not match our records.')
        ->and(__('shop::messages.cart'))->toBe('Your cart');
});

it('applies database overrides over the files, vendor overrides and package files', function (): void {
    $user = editor();

    manager()->save(TranslationKey::parse('auth.failed'), 'en', 'Wrong email or password.', user: $user);
    manager()->save(TranslationKey::parse('auth.messages.welcome'), 'de', 'Hallo :name!', user: $user);
    manager()->save(TranslationKey::json('Save changes'), 'de', 'Speichern');
    manager()->save(TranslationKey::parse('shop::messages.checkout'), 'en', 'Pay now');

    expect(__('auth.failed'))->toBe('Wrong email or password.')
        // Siblings of an overridden nested key are kept.
        ->and(__('auth.throttle', ['seconds' => 3]))->toBe('Too many login attempts. Please try again in 3 seconds.')
        ->and(__('auth.messages.welcome', ['name' => 'Ada'], 'de'))->toBe('Hallo Ada!')
        ->and(__('auth.messages.apples', [], 'en'))->toBe('{0} No apples|[1,19] Some apples|[20,*] Many apples')
        ->and(__('Save changes', [], 'de'))->toBe('Speichern')
        ->and(__('Hello, :name', ['name' => 'Ada'], 'de'))->toBe('Hello, Ada')
        // Package file < lang/vendor override < database.
        ->and(__('shop::messages.cart'))->toBe('Your cart')
        ->and(__('shop::messages.checkout'))->toBe('Pay now')
        ->and(Translation::query()->count())->toBe(4)
        ->and(TranslationRevision::query()->where('locale', 'en')->latest('id')->first()?->old_value)->toBe('Checkout');
});

it('caches the overrides and refreshes them after every save', function (): void {
    manager()->save(TranslationKey::parse('auth.failed'), 'en', 'First');
    app('translator')->setLoaded([]);

    DB::enableQueryLog();
    expect(__('auth.failed'))->toBe('First');
    app('translator')->setLoaded([]);
    manager()->forgetLoadedTranslations();
    expect(__('auth.failed'))->toBe('First');
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'alfakit_translations'))->count();
    DB::disableQueryLog();

    // Both lookups were answered from the cache (the first save already warmed nothing).
    expect($queries)->toBeLessThanOrEqual(1);

    // A row changed behind the manager's back stays cached…
    Translation::query()->update(['value' => 'Sneaky']);
    app('translator')->setLoaded([]);
    manager()->forgetLoadedTranslations();
    expect(__('auth.failed'))->toBe('First');

    // …until the next save bumps the version.
    manager()->save(TranslationKey::parse('auth.throttle'), 'en', 'Slow down');
    expect(__('auth.failed'))->toBe('Sneaky')
        ->and(__('auth.throttle'))->toBe('Slow down');
});

it('ignores empty overrides so Laravel falls back', function (): void {
    Translation::query()->create(['locale' => 'de', 'namespace' => '*', 'group' => 'auth', 'key' => 'throttle', 'key_hash' => sha1('throttle'), 'value' => '']);
    manager()->flush();

    expect(__('auth.throttle', ['seconds' => 1], 'de'))->toBe('Too many login attempts. Please try again in 1 seconds.');
});

it('reads no overrides in files mode and survives a missing table', function (): void {
    manager()->save(TranslationKey::parse('auth.failed'), 'en', 'From the database');
    manager()->setMode(StorageMode::Files)->flush();

    expect(__('auth.failed'))->toBe('These credentials do not match our records.');

    manager()->setMode(StorageMode::Database);
    Illuminate\Support\Facades\Schema::drop('alfakit_translations');
    manager()->flush();

    expect(__('auth.failed'))->toBe('These credentials do not match our records.');
});

it('keeps tenant overrides apart and on top of the application-wide ones', function (): void {
    $acme = Team::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['name' => 'Globex', 'slug' => 'globex']);
    $tenant = null;
    manager()->setTenantScoped(true, function () use (&$tenant): ?Team {
        return $tenant;
    });

    manager()->save(TranslationKey::parse('auth.failed'), 'en', 'App-wide');
    $tenant = $acme;
    manager()->forgetLoadedTranslations();
    manager()->save(TranslationKey::parse('auth.failed'), 'en', 'Acme only');

    expect(__('auth.failed'))->toBe('Acme only');

    $tenant = $globex;
    manager()->forgetLoadedTranslations();
    expect(__('auth.failed'))->toBe('App-wide');

    $tenant = null;
    manager()->forgetLoadedTranslations();
    expect(__('auth.failed'))->toBe('App-wide')
        ->and(Translation::query()->pluck('scope')->sort()->values()->all())->toBe(['', $acme->getMorphClass().':'.$acme->getKey()]);
});

it('reads the cache version once per request and again after it', function (): void {
    manager()->flush();
    manager()->version();

    // Another worker saves: this request keeps the version it read…
    manager()->cache()->forever(AlfaSix\AlfakitTranslationManager\TranslationManager::CACHE_PREFIX.'version', 41);

    expect(manager()->version())->not->toBe(41);

    // …and the next request (or this worker's next job) reads it again.
    app()->terminate();

    expect(manager()->version())->toBe(41);

    // A save in this request bumps and re-reads it.
    manager()->flush();

    expect(manager()->version())->toBe(42);
});
