<?php

namespace App\Console\Commands;

use App\Analyzer\Screenshots;
use Illuminate\Console\Command;

/** Background worker started by Screenshots::dispatch() — not meant to be run by hand. */
class ScreenshotsCommand extends Command
{
    protected $signature = 'webqa:screenshots {id : analysis id}';

    protected $description = 'Take pictures of the analysed pages of one analysis (started by the web app)';

    protected $hidden = true;

    public function handle(): int
    {
        Screenshots::run($this->argument('id'));

        return self::SUCCESS;
    }
}
