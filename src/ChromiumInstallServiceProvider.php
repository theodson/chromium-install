<?php

namespace Theodson\ChromiumInstall;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Theodson\ChromiumInstall\Commands\ChromiumInstallCommand;

class ChromiumInstallServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('chromium-install')
            ->hasConfigFile()
            ->hasCommand(ChromiumInstallCommand::class);
    }
}
