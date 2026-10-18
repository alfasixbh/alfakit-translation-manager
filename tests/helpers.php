<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Tests;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\AlfakitTranslationManager\TranslationManager;
use AlfaSix\AlfakitTranslationManager\TranslationManagerPlugin;
use Closure;

function tmTest(): TestCase
{
    return TestCase::current();
}

function manager(): TranslationManager
{
    return app(TranslationManager::class);
}

function lang(string $path = ''): string
{
    return tmTest()->workspace.'/lang'.($path === '' ? '' : '/'.ltrim($path, '/'));
}

/**
 * A panel with the plugin; editors are users whose email starts with `admin`.
 *
 * @param  (Closure(TranslationManagerPlugin): TranslationManagerPlugin)|null  $configure
 */
function translationsPanel(?Closure $configure = null, string $id = 'tm'): Panel
{
    $plugin = TranslationManagerPlugin::make()->authorize(static fn (User $user): bool => str_starts_with($user->email, 'admin'));

    return Panels::register($id, static fn (Panel $panel): Panel => $panel->plugin($configure === null ? $plugin : $configure($plugin)));
}

function editor(string $email = 'admin@example.com'): User
{
    return User::query()->create(['name' => 'Ada Admin', 'email' => $email, 'password' => 'secret']);
}

/**
 * The page's token and its `translation-manager` component.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function editorPage(string $url = '/tm/translations'): array
{
    $props = tmTest()->inertiaProps($url);
    $block = collect($props['page']['content'])->firstWhere('key', 'translations');

    return [$props['page']['token'], $block['components'][0]];
}

/**
 * A row of the grid by key label.
 *
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>|null
 */
function row(array $rows, string $key): ?array
{
    return collect($rows)->firstWhere('key', $key);
}
