<?php

namespace AichaDigital\Larabill\Commands;

use Illuminate\Console\Command;

class LarabillCommand extends Command
{
    public $signature = 'larabill';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
