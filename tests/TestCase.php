<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTranslationManager\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitTranslationManager\AlfakitTranslationManagerServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\TestResponse;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /** A copy of tests/Fixtures (lang files, code, a package) the test may change. */
    public string $workspace = '';

    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/alfakit-translation-manager-'.bin2hex(random_bytes(6));
        (new Filesystem)->copyDirectory(__DIR__.'/Fixtures', $this->workspace);

        parent::setUp();

        app('translator')->addNamespace('shop', $this->workspace.'/package/lang');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->workspace);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...(class_exists(\Laravel\Ai\AiServiceProvider::class) ? [\Laravel\Ai\AiServiceProvider::class] : []),
            AlfakitTranslationManagerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->useLangPath($this->workspace.'/lang');
        $app['config']->set('app.locale', 'en');
        $app['config']->set('app.fallback_locale', 'en');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('alfakit-translation-manager.backups', $this->workspace.'/backups');
        $app['config']->set('alfakit-translation-manager.scan.paths', [$this->workspace.'/code']);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    /**
     * The Inertia props of a panel page.
     *
     * @return array<string, mixed>
     */
    public function inertiaProps(string $url): array
    {
        $response = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
        $response->assertOk();

        return $response->json('props');
    }

    /**
     * Run a JSON action of the grid as the renderer's `executeJson()` does.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function gridAction(string $panel, string $token, string $action, array $arguments = []): TestResponse
    {
        return $this->postJson("/{$panel}/_alfakit/actions", [
            'token' => $token,
            'component' => 'translations',
            'action' => $action,
            'arguments' => $arguments,
        ]);
    }
}
