<?php

namespace Theodson\ChromiumInstall\Commands;

use Illuminate\Console\Command;

class ChromiumInstallCommand extends Command
{
    public $signature = 'chromium-install';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
