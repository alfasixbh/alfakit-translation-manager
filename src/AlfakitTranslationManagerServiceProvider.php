<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager;

use AlfaSix\Alfakit\Events\TenantSet;
use AlfaSix\AlfakitTranslationManager\Commands\ExportCommand;
use AlfaSix\AlfakitTranslationManager\Commands\ImportCommand;
use AlfaSix\AlfakitTranslationManager\Commands\ScanCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;

/**
 * Registers the `TranslationManager` service and puts its override loader in front of the
 * application's translation loader, so lines saved in the panel apply on the next lookup.
 */
class AlfakitTranslationManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/alfakit-translation-manager.php', 'alfakit-translation-manager');

        $this->app->singleton(TranslationManager::class);

        // The override cache version is read once per request; long-lived workers read it again.
        $this->app->terminating(function (): void {
            if ($this->app->resolved(TranslationManager::class)) {
                $this->app->make(TranslationManager::class)->forgetVersion();
            }
        });

        $this->app->afterResolving('translator', function (mixed $translator): void {
            if ($translator instanceof Translator) {
                $this->app->make(TranslationManager::class)->installLoader($translator);
            }
        });

        if ($this->app->resolved('translator')) {
            $this->app->make(TranslationManager::class)->installLoader($this->app->make('translator'));
        }
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'alfakit-translation-manager');

        // Tenant-scoped overrides: lines loaded before the tenant was known are reloaded.
        Event::listen(TenantSet::class, function (): void {
            $manager = $this->app->make(TranslationManager::class);

            if ($manager->isTenantScoped()) {
                $manager->forgetLoadedTranslations();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class, ExportCommand::class, ImportCommand::class]);

            $this->publishesMigrations([
                dirname(__DIR__).'/database/migrations' => database_path('migrations'),
            ], 'alfakit-translation-manager-migrations');

            $this->publishes([
                dirname(__DIR__).'/config/alfakit-translation-manager.php' => config_path('alfakit-translation-manager.php'),
            ], 'alfakit-translation-manager-config');

            $this->publishes([
                dirname(__DIR__).'/resources/lang' => $this->app->langPath('vendor/alfakit-translation-manager'),
            ], 'alfakit-translation-manager-translations');
        }
    }
}
