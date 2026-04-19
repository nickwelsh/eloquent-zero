<?php

namespace NickWelsh\EloquentZero;

use NickWelsh\EloquentZero\Commands\EloquentZeroCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EloquentZeroServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('eloquent-zero')
            ->hasConfigFile()
            ->hasCommand(EloquentZeroCommand::class);
    }
}
