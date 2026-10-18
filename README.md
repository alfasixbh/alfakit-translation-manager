# AlfaKit Translation Manager

<p><picture><source media="(prefers-color-scheme: dark)" srcset="../../../art/alfasix/alfa-six-micro-white.svg"><img src="../../../art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

Let the people who write your copy edit your Laravel app's translations in an [AlfaKit](https://alfakit.dev) panel: a keyboard-driven grid of keys by language with search and filters, placeholder, HTML and plural checks, right-to-left inputs, history, an instant (cached) database override layer, safe export to and import from the lang files, code scanning for missing and unused keys, and AI translations that are reviewed before they apply. It renders in React, Vue and Svelte.

## Installation

```bash
composer require alfasix/alfakit-translation-manager
php artisan vendor:publish --tag=alfakit-translation-manager-migrations
php artisan migrate
```

## Register

```php
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;

$panel->plugin(
    TranslationManagerPlugin::make()
        ->editableLocales(['de', 'fr', 'ar'])
        ->authorize(fn (User $user): bool => $user->is_admin),
);
```

Access is denied unless `authorize()` or the `manageTranslations` gate allows it.

## Commands

```bash
php artisan alfakit-translations:scan --missing --unused
php artisan alfakit-translations:export
php artisan alfakit-translations:import --overwrite
```

See the [documentation](https://alfakit.dev/docs/plugins/translation-manager) for every option.
