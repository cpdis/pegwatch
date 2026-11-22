<?php

namespace App\Console\Commands;

use App\Alerts\AlertMatcher;
use App\Support\Register;
use Illuminate\Console\Command;

class SendAlerts extends Command
{
    protected $signature = 'pegwatch:send-alerts {run? : ingest run id (default: latest)}';

    protected $description = 'Match an ingest run\'s events to watch areas and email digests';

    public function handle(AlertMatcher $matcher): int
    {
        $runId = (int) ($this->argument('run') ?: Register::latestRun()?->id);
        if (! $runId) {
            $this->error('No ingest run found.');

            return self::FAILURE;
        }
        $result = $matcher->run($runId);
        $this->info("Run {$runId}: {$result['matched']} matches, {$result['emails']} emails.");

        return self::SUCCESS;
    }
}
