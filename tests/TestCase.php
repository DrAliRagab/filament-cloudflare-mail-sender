<?php

namespace DrAliRagab\FilamentCloudflareMailSender\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\DataStore;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $application = $this->app;

        if ($application === null) {
            throw new LogicException('The Testbench application is unavailable.');
        }

        // Filament replaces Livewire's state store. Keep the replacement scoped to
        // one Testbench application so component state survives a render cycle.
        $application->singleton(DataStore::class, DataStoreOverride::class);
        $view = $application->make(Factory::class);
        $view->share('errors', (new ViewErrorBag)->put('default', new MessageBag));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->runPackageMigration('up');
    }

    protected function destroyDatabaseMigrations(): void
    {
        $this->runPackageMigration('down');
    }

    private function runPackageMigration(string $method): void
    {
        $migration = require dirname(__DIR__).'/database/migrations/create_cloudflare_mail_messages_table.php.stub';

        if (! $migration instanceof Migration) {
            throw new LogicException('The package migration must return a migration instance.');
        }

        $callback = [$migration, $method];

        if (! is_callable($callback)) {
            throw new LogicException("The package migration must define {$method}().");
        }

        call_user_func($callback);
    }

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentCloudflareMailSenderServiceProvider::class,
        ];
    }
}
