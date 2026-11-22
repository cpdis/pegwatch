<?php

namespace App\Console\Commands;

use App\Alerts\AlertMatcher;
use Illuminate\Console\Command;

class SendAlerts extends Command
{
    protected $signature = 'pegwatch:send-alerts {run? : ingest run id (default: every run of the last week)}';

    protected $description = 'Match an ingest run\'s events to watch areas and email digests';

    public function handle(AlertMatcher $matcher): int
    {
        $runId = $this->argument('run') ? (int) $this->argument('run') : null;
        $result = $matcher->run($runId);
        $this->info(($runId ? "Run {$runId}" : 'Recent runs').": {$result['matched']} matches, {$result['emails']} emails.");

        return self::SUCCESS;
    }
}
