# Chromium version specific installer for Laravel

Sometimes, when using Dusk, it is good to specify exactly which version of Chromium to use in the Dusk tests.

This package provides a simple (rough cut) command to install Chromium and optionally align the Dusk chromedriver to the
appropriate version. This is deferred to either the standard Laravel/Dusk `dusk:chrome-driver` command or the
staudenmeir/dusk-updater `dusk:update` command.

Please note this is a very rough cut 🤞🏻! Your mileage may vary.

## Installation

You can install the package via composer:

```bash
composer require theodson/chromium-install
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="chromium-install-config"
```

This is the contents of the published config file:

```php
return [

    /*
     * Path to the directory to store Chromium downloads
     */
    'downloads' => base_path('../'),

    /*
     * Path where all versions of Chromium are installed, installs will follow format
     *   <base_path>/<major_version>
     */
    'base_path' => '$HOME/chromium',

    /*
     * When --with-driver option chosen either `dusk:chrome-driver` (Laravel Dusk) or `dusk:update` (by Staudenmeir)
     * will be called to update the chromedriver in use or Dusk tests to align with the Chromium version installed.
     */
    'prefer_laravel_dusk_chrome_driver' => true,
];
```

## Usage

Install the latest Chromium browser.

```php
php artisan dusk:chromium-install
```

Install a specific major version

```php
php artisan dusk:chromium-install 97
```

Install a specific major version and update the Dusk chromedriver to be the same version.

> Aligning the chromedriver is important for Dusk tests to work.

```php
php artisan dusk:chromium-install --with-driver 97
```

Install a specific major version of the Chromium browser, but change the _base install folder_  where the Chromium
browser is installed.  
The default location of `$HOME/chromium`.

```php
php artisan dusk:chromium-install --basepath=/my/tools 97
```

> This will result in a tree structure  `/my/tools/97/`

## Dusk Tests Setup

Suggested setup is to update your DuskTestCase to refer to the path for the specific Chromium version

#### 1. Dotenv

Within a `.env` or `.env.dusk` add this entry and adjust the actual path to point to the Chromium version required.

``` 
DUSK_CHROMIUM_BINARY_PATH=/
```

#### 2. App Config

Within a Laravel config file, in this example we'll use `config/app.php` add an entry

``` 
    'dusk_chromium_binary' => env('DUSK_CHROMIUM_BINARY_PATH', '/usr/local/bin/chromium'),
```

#### 3. DuskTestCase
And finally you can target the exact Chromium version for your tests with `setBinary()`.

``` 
    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)
            ->setBinary(config('dusk_chromium_binary'))
            ->addArguments([
                '--no-sandbox',
                '--window-size=1024,768'
        ]);
        
        return RemoteWebDriver::create(
            'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
                
    }            
    
```

## Testing

Nope not yet... this is a very rough cut 🤞🏻

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
