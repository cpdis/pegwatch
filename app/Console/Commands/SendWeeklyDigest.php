<?php

namespace App\Console\Commands;

use App\Digest\WeeklyDigest;
use Illuminate\Console\Command;

class SendWeeklyDigest extends Command
{
    protected $signature = 'pegwatch:send-digest {--force : build this week\'s issue even before the send day}';

    protected $description = 'Build the weekly WA digest when due and send it to confirmed subscribers';

    public function handle(WeeklyDigest $digest): int
    {
        $r = $digest->run(force: (bool) $this->option('force'), budgetSeconds: 3600);
        $this->info($r['issue'] === null
            ? 'Nothing to send.'
            : "Issue {$r['issue']}: ".($r['built'] ? 'built, ' : '')."{$r['sent']} sent, {$r['remaining']} remaining.");

        return self::SUCCESS;
    }
}
