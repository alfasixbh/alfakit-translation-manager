<?php

declare(strict_types=1);

/*
 * App-wide settings of alfasix/alfakit-translation-manager. The plugin's fluent methods
 * (`TranslationManagerPlugin::make()->mode(...)`) override them for the panel that
 * registers it. Publish with `php artisan vendor:publish --tag=alfakit-translation-manager-config`.
 */
return [
    // Where edits are stored: `database` (an override layer that applies instantly),
    // `files` (written straight to the lang files) or `both`.
    'mode' => 'database',

    // The lang directory read and written (null: the application's `lang_path()`).
    'lang_path' => null,

    // The locale every other locale is compared with (null: `app.fallback_locale`).
    'base_locale' => null,

    // Copies of lang files are kept here before they are overwritten (false: no backups).
    'backups' => storage_path('app/alfakit-translations/backups'),

    // The cache that holds the database overrides between requests (null: the default store).
    'cache' => [
        'store' => null,
        'ttl' => 86400,
    ],

    // Where `alfakit-translations:scan` looks for `__()`, `trans()`, `@lang`, `t()`…
    'scan' => [
        'paths' => ['app', 'resources/views', 'resources/js', 'routes'],
        'extensions' => ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'svelte'],
    ],

    // Packages whose own translations (registered with `loadTranslationsFrom()`) are
    // listed besides `lang/vendor` overrides, e.g. ['alfakit'].
    'namespaces' => [],
];
