<?php

namespace AichaDigital\Larabill\Commands;

use Illuminate\Console\Command;

class LarabillCommand extends Command
{
    public $signature = 'larabill';

    public $description = 'Larabill package commands';

    public function handle(): int
    {
        $this->info('Larabill - Professional Billing & Invoicing for Laravel');
        $this->line('Available services:');
        $this->line('- VAT Verification Service');
        $this->line('- Tax Calculation Service');
        $this->line('- Billing Service');

        return self::SUCCESS;
    }
}
