<?php

namespace EgyptDevRu\FilamentProjectPassport\Tests;

use EgyptDevRu\FilamentProjectPassport\FilamentProjectPassportServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'EgyptDevRu\\FilamentProjectPassport\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            FilamentProjectPassportServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');
        config()->set('app.url', 'https://app.test');
    }
}
