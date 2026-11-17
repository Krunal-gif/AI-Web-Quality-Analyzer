<?php

namespace App\Console\Commands;

use App\Analyzer\AiBatch;
use Illuminate\Console\Command;

/** Background worker started by AiBatch::dispatch() — not meant to be run by hand. */
class AiBatchCommand extends Command
{
    protected $signature = 'webqa:ai-batch {id : analysis id} {issues* : issue ids to review}';

    protected $description = 'Ask Ollama about queued issues of one analysis (started by the web app)';

    protected $hidden = true;

    public function handle(): int
    {
        AiBatch::run($this->argument('id'), $this->argument('issues'));

        return self::SUCCESS;
    }
}
