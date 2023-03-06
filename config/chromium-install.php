<?php

// config for Theodson/ChromiumInstall
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
     * will be called to update the chromedriver in use or Dusk tests to align with the chromium version installed.
     */
    'prefer_laravel_dusk_chrome_driver' => true,

];
