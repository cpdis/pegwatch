<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The demand test's numbers: digest sign-ups by source, pre-sales and free accounts. */
class DemandReport extends Command
{
    protected $signature = 'pegwatch:demand';

    protected $description = 'Show digest sign-ups, pre-sales and free account numbers';

    public function handle(): int
    {
        $rows = DB::select(
            "select coalesce(source, '?') as source,
                    count(*) as signed_up,
                    count(*) filter (where confirmed_at is not null) as confirmed,
                    count(*) filter (where confirmed_at is not null and unsubscribed_at is null) as active,
                    count(*) filter (where created_at >= now() - interval '7 days') as last_7_days
             from digest_subscribers group by 1 order by 2 desc"
        );
        $this->table(['Source', 'Signed up', 'Confirmed', 'Active', 'Last 7 days'], array_map(fn ($r) => (array) $r, $rows));
        $pre = DB::select('select plan, status, count(distinct email) as people from presales group by 1, 2 order by 1, 2');
        $this->table(['Plan', 'Status', 'People'], array_map(fn ($r) => (array) $r, $pre));
        $this->line('Accounts: '.DB::table('users')->count().', verified '.DB::table('users')->whereNotNull('email_verified_at')->count()
            .', with a watch area '.DB::table('watch_areas')->distinct()->count('user_id'));

        return self::SUCCESS;
    }
}
