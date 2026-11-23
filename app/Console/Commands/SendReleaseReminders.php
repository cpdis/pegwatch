<?php

namespace App\Console\Commands;

use App\Alerts\ReleaseReminders;
use Illuminate\Console\Command;

class SendReleaseReminders extends Command
{
    protected $signature = 'pegwatch:release-reminders';

    protected $description = 'Email day-before and hour-before reminders of compulsory releases in watched ground';

    public function handle(ReleaseReminders $reminders): int
    {
        $this->info($reminders->run().' reminder emails sent.');

        return self::SUCCESS;
    }
}
