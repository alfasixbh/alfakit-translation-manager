<?php

declare(strict_types=1);

use AlfaSix\AlfakitTranslationManager\Support\Placeholders;
use AlfaSix\AlfakitTranslationManager\Support\Plurals;
use AlfaSix\AlfakitTranslationManager\Tests\TestCase;

uses(TestCase::class);

it('extracts placeholders the way Laravel replaces them', function (): void {
    expect(Placeholders::extract('Hi :name, :Name and :NAME at 12:30, see http://x.test'))->toBe(['name'])
        ->and(Placeholders::extract(':count items in :place'))->toBe(['count', 'place'])
        ->and(Placeholders::tokens('Hi :Name, you have :count'))->toBe([':Name', ':count'])
        ->and(Placeholders::extract(null))->toBe([]);
});

it('warns about missing and unknown placeholders, changed HTML and lost plural forms', function (): void {
    expect(Placeholders::warnings('Welcome back, :name!', 'Willkommen zurück, :Name!'))->toBe([])
        ->and(Placeholders::warnings('Welcome back, :name!', 'Willkommen zurück!'))->toBe(['Missing placeholder :name'])
        ->and(Placeholders::warnings('Hi :name', 'Hallo :nom'))->toBe(['Missing placeholder :name', 'Unknown placeholder :nom'])
        ->and(Placeholders::warnings(':a :b', 'x'))->toBe(['Missing placeholders :a, :b'])
        ->and(Placeholders::warnings('Read <a href=":url">this</a>', 'Lies das :url'))->toBe(['The HTML tags differ from the base text.'])
        ->and(Placeholders::warnings(':count user|:count users', ':count Benutzer'))->toBe(['The base text has plural forms; separate them with a vertical bar (|).'])
        // Languages without plural forms need only one.
        ->and(Placeholders::warnings(':count user|:count users', ':count 人', 'ja'))->toBe([])
        // Missing translations have no warnings.
        ->and(Placeholders::warnings('Hi :name', null))->toBe([]);
});

it('masks placeholders, HTML and plural conditions for machine translation', function (): void {
    [$masked, $markers] = Placeholders::mask('{0} No <b>:item</b>|[1,*] :count &amp; more');

    expect($masked)->toBe('⟦1⟧ No ⟦2⟧⟦3⟧⟦4⟧|⟦5⟧ ⟦6⟧ ⟦7⟧ more')
        ->and(Placeholders::unmask('⟦1⟧ Kein ⟦2⟧⟦3⟧⟦4⟧|⟦5⟧ ⟦6⟧ ⟦7⟧ mehr', $markers))->toBe('{0} Kein <b>:item</b>|[1,*] :count &amp; mehr')
        // A dropped, duplicated or invented marker can't be trusted.
        ->and(Placeholders::unmask('⟦1⟧ Kein', $markers))->toBeNull()
        ->and(Placeholders::unmask('⟦1⟧⟦1⟧⟦2⟧⟦3⟧⟦4⟧⟦5⟧⟦6⟧⟦7⟧', $markers))->toBeNull()
        ->and(Placeholders::unmask('⟦1⟧⟦2⟧⟦3⟧⟦4⟧⟦5⟧⟦6⟧⟦7⟧⟦8⟧', $markers))->toBeNull();
});

it('knows the plural forms of each locale', function (): void {
    expect(Plurals::count('en'))->toBe(2)
        ->and(Plurals::count('ru'))->toBe(3)
        ->and(Plurals::count('ar'))->toBe(6)
        ->and(Plurals::count('ja'))->toBe(1)
        ->and(Plurals::labels('en'))->toBe(['1', '0, 2, 3…'])
        ->and(Plurals::forms(':count user|:count users', null, 'ru'))->toBe(['forms' => ['', '', ''], 'labels' => Plurals::labels('ru')])
        ->and(Plurals::forms('{0} None|[1,*] Some', '{0} Keine|[1,*] Einige', 'de'))->toBe(['forms' => ['{0} Keine', '[1,*] Einige'], 'labels' => ['{0}', '[1,*]']])
        ->and(Plurals::forms('Plain', 'Schlicht', 'de'))->toBeNull()
        ->and(Plurals::join([':count Datei', ':count Dateien', '', '']))->toBe(':count Datei|:count Dateien')
        ->and(Plurals::join(['a|b', 'c']))->toBe('ab|c');
});
