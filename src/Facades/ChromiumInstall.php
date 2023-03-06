<?php

namespace Theodson\ChromiumInstall\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Theodson\ChromiumInstall\ChromiumInstall
 */
class ChromiumInstall extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Theodson\ChromiumInstall\ChromiumInstall::class;
    }
}
