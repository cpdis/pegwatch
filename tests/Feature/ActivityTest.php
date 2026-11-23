<?php

namespace Tests\Feature;

use App\Support\FeedGroups;
use App\Support\Register;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    /** An AWST wall-clock value, as SLIP dates are stored. */
    private static function local(int $days, string $time = '08:30:00'): string
    {
        return CarbonImmutable::now('Australia/Perth')->addDays($days)->format('Y-m-d ').$time;
    }

    private static function addRun(string $status = 'ok', bool $stale = false, int $extractHoursAgo = 15): int
    {
        return DB::table('ingest_runs')->insertGetId([
            'status' => $status, 'finished_at' => now()->utc(), 'stale' => $stale,
            'tenements_extract' => now()->subHours($extractHoursAgo)->utc(),
        ]);
    }

    /**
     * Near Kalgoorlie: a pending application and a grant known only from the register's
     * dates, an application the diff saw, a grant dated in the future, a death known only
     * from the DEAD layer and one dated in the future, and a Mining Notice.
     */
    private static function seedRegister(): int
    {
        DB::table('ingest_runs')->insert(['status' => 'baseline', 'finished_at' => now()->subDays(20)->utc(),
            'tenements_extract' => now()->subDays(20)->utc(), 'stale' => false]);
        $run = self::addRun();
        $t = fn (string $tenid, string $fmt, string $type, string $code, string $status, string $holder, ?string $applied, ?string $granted, float $x) => DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, legal_area, area_unit,
                                    applied_at, granted_at, ends_at, extract_at, geom)
             values (?, ?, ?, ?, ?, ?, '{t}', 100, 100, 'HA', ?, ?, '2030-01-01', now(), ?::geometry)",
            [$tenid, $fmt, $type, $code, $status, '{"'.$holder.'"}', $applied, $granted, self::square($x, -30.80)]
        );
        $t('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', 'SMITH, JOHN', self::local(-2), null, 121.50);
        $t('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', 'ACME GOLD PTY LTD', '2025-01-02 09:00:00', self::local(-3), 121.51);
        $t('E  1500003', 'E 15/3', 'EXPLORATION LICENCE', 'E', 'LIVE', 'ACME GOLD PTY LTD', '2025-01-02 09:00:00', self::local(2), 121.52);
        $t('P  1500004', 'P 15/4', 'PROSPECTING LICENCE', 'P', 'PENDING', 'BETA PTY LTD', self::local(-1), null, 125.00);
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, details, occurred_at, geom)
             values (?, 'application', 'P  1500004', 'P 15/4', 'PROSPECTING LICENCE', '{\"BETA PTY LTD\"}', '{\"area_ha\": 100}', ?, ?::geometry)",
            [$run, self::local(-1), self::square(125.00, -30.80)]
        );
        $dead = fn (string $tenid, string $fmt, string $ended) => DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holders, holders_are_company, geom)
             values (?, ?, ?, 'EXPLORATION LICENCE', 'SURRENDERED', '{\"ACME GOLD PTY LTD\"}', '{t}', ?::geometry)",
            [$tenid, $ended, $fmt, self::square(121.53, -30.80)]
        );
        $dead('E  1500009', 'E 15/9', self::local(-1, '00:00:00'));
        $dead('E  1500010', 'E 15/10', self::local(3, '00:00:00'));
        DB::insert(
            "insert into mining_notices (fmt_tenid, received_at, area_text, shire, applicants, link) values
             ('P 15/77', now() - interval '1 minute', '120 HA', 'KALGOORLIE-BOULDER CITY', '{\"JONES, MARY\",\"JONES, MARY\"}', 'https://example.com/n/77'),
             ('P 15/2', now() - interval '1 minute', '100 HA', 'KALGOORLIE-BOULDER CITY', '{\"SMITH, JOHN\"}', 'https://example.com/n/2')"
        );

        return $run;
    }

    public function test_the_feed_has_register_dated_items_without_diff_events_and_nothing_in_the_future(): void
    {
        self::seedRegister();
        $items = Register::feed([], 50);
        $by = [];
        foreach ($items as $e) {
            $by[$e['fmt_tenid']][] = $e['source'].':'.$e['type'];
        }

        $this->assertSame(['register:application'], $by['P 15/2']);
        $this->assertSame(['register:grant'], $by['E 15/1']);
        $this->assertSame(['register:death'], $by['E 15/9']);
        $this->assertSame(['notice:application'], $by['P 15/77']);
        // The diff saw this one, so the register's own date does not add it twice.
        $this->assertSame(['change:application'], $by['P 15/4']);
        // A grant or an end dated after today is not news yet.
        $this->assertArrayNotHasKey('E 15/3', $by);
        $this->assertArrayNotHasKey('E 15/10', $by);

        $now = CarbonImmutable::now('Australia/Perth')->format('Y-m-d H:i:s');
        foreach ($items as $e) {
            $this->assertLessThanOrEqual($now, substr($e['at'], 0, 19), $e['key']);
        }
        $notice = collect($items)->firstWhere('fmt_tenid', 'P 15/77');
        $this->assertSame('120 ha in Kalgoorlie-Boulder City', $notice['summary']);
        $this->assertSame(['JONES, MARY'], array_column($notice['holders'], 'name'));
        $this->assertSame('Surrendered', collect($items)->firstWhere('fmt_tenid', 'E 15/9')['summary']);

        // Newest first.
        $this->assertSame(array_column($items, 'at'), collect($items)->sortByDesc('at')->pluck('at')->all());
    }

    public function test_feed_filters_and_day_counts(): void
    {
        self::seedRegister();
        $ids = fn (array $f) => array_column(Register::feed($f, 50), 'fmt_tenid');

        $this->assertSame(['E 15/9'], $ids(['types' => ['death']]));
        $this->assertEqualsCanonicalizing(['P 15/2', 'P 15/4', 'P 15/77'], $ids(['kinds' => ['P']]));
        $this->assertSame(['P 15/2'], $ids(['holder' => 'smith']));
        $this->assertSame([], $ids(['field' => '16']));
        $this->assertSame(['E 15/1'], $ids(['from' => substr(self::local(-3), 0, 10), 'to' => substr(self::local(-3), 0, 10)]));
        // A part of the map leaves out notices, which have no place on it.
        $this->assertEqualsCanonicalizing(['P 15/2', 'E 15/1', 'E 15/9'], $ids(['bbox' => [121.4, -30.9, 121.6, -30.7]]));

        $counts = Register::feedCounts([], substr(self::local(-7), 0, 10), substr(self::local(0), 0, 10));
        $this->assertSame(['grant' => 1], $counts[substr(self::local(-3), 0, 10)]);
        $this->assertSame(1, $counts[substr(self::local(-2), 0, 10)]['application']);
    }

    public function test_bulk_lodgements_collapse_into_one_row(): void
    {
        $item = fn (string $id, string $day, string $holder = 'ACME GOLD PTY LTD') => [
            'key' => 'r:application:'.$id, 'type' => 'application', 'type_code' => 'P', 'at' => $day.' 08:30:00',
            'holders' => [['name' => $holder, 'slug' => 'x']], 'area_ha' => 100.0, 'fmt_tenid' => $id,
        ];
        $out = FeedGroups::collapse([
            $item('P 15/1', '2026-10-04'), $item('P 15/2', '2026-10-04'), $item('P 15/3', '2026-10-03'),
            $item('P 15/9', '2026-10-03', 'SMITH, JOHN'), $item('P 15/4', '2026-10-03'),
        ]);

        $this->assertCount(2, $out);
        $this->assertTrue($out[0]['bulk']);
        $this->assertCount(4, $out[0]['members']);
        $this->assertSame('ACME GOLD PTY LTD applied for 4 prospecting licences on 3 and 4 Oct, 400 ha', $out[0]['sentence']);
        $this->assertSame('P 15/9', $out[1]['fmt_tenid']);
        // Two are not a bulk lodgement.
        $this->assertCount(2, FeedGroups::collapse([$item('P 15/1', '2026-10-04'), $item('P 15/2', '2026-10-04')]));
    }

    public function test_empty_states_tell_the_truth(): void
    {
        $empty = fn (string $context, ?string $subject = null, bool $filtered = false) => (string) $this->blade(
            '<x-event-list :events="[]" :context="$context" :subject="$subject" :filtered="$filtered" />',
            compact('context', 'subject', 'filtered')
        );

        // Only the first copy of the register.
        $this->assertStringContainsString('Pegwatch has its first copy of the register. Changes show here from the next extract.', $empty('activity'));
        DB::table('ingest_runs')->delete();
        self::addRun('baseline');
        $this->assertStringContainsString('Pegwatch has its first copy of the register.', $empty('activity'));

        // The department has not published a new extract.
        self::addRun('ok', true, 24 * 4);
        $date = now()->subHours(24 * 4)->setTimezone('Australia/Perth')->format('j M');
        $this->assertStringContainsString("No changes since $date: the department has not published a new extract. We check every night.", $empty('activity'));

        // Fresh, and nothing changed: say since when Pegwatch has watched.
        self::addRun();
        $since = now()->setTimezone('Australia/Perth')->format('j M Y');
        $html = $empty('tenement', 'E 15/1');
        $this->assertStringContainsString("No changes to E 15/1 since Pegwatch started watching on $since.", $html);
        $this->assertStringContainsString('Email me when this changes', $html);
        $this->assertStringContainsString('No changes to SMITH, JOHN&#039;s tenements since Pegwatch started watching', $empty('holder', 'SMITH, JOHN'));
        $html = $empty('activity');
        $this->assertStringContainsString("No changes since Pegwatch started watching on $since.", $html);
        // Guests get the digest sign-up under the empty state.
        $this->assertStringContainsString('Get the week&#039;s changes every Monday', $html);
        $this->assertStringContainsString('data-empty-state="activity"', $html);

        // Filters, not the register, explain an empty filtered list.
        $this->assertStringContainsString('No changes match these filters. Widen the dates or clear a filter.', $empty('activity', null, true));
        $this->assertStringContainsString('No changes on the register in this part of the map. Move or zoom out to see more.', $empty('map', null, true));
        $this->get('/activity?types[]=holder_change')->assertOk()->assertSee('No changes match these filters.');
    }

    public function test_the_old_baseline_sentence_is_gone(): void
    {
        $hits = [];
        foreach ((new Finder)->files()->in([base_path('app'), resource_path('views')])->contains('second nightly run') as $f) {
            $hits[] = $f->getRelativePathname();
        }
        $this->assertSame([], $hits);
    }

    public function test_activity_page_lists_groups_and_filters(): void
    {
        self::seedRegister();
        $this->get('/activity')->assertOk()
            ->assertSee('From the register&#039;s dates', false)->assertSee('From a Mining Notice')
            ->assertSee('Yesterday')->assertSee('E 15/9')->assertSee('P 15/77')
            ->assertSee(route('activity.rss'), false);
        $this->get('/activity?types[]=death')->assertOk()->assertSee('E 15/9')->assertDontSee('P 15/77');
        $this->get('/activity?holder=smith')->assertOk()->assertSee('P 15/2')->assertDontSee('E 15/9');
        // The old single-type links still filter.
        Livewire::withQueryParams(['type' => 'grant'])->test('pages::activity')->assertSet('types', ['grant'])->assertSee('E 15/1')->assertDontSee('E 15/9');
    }

    public function test_malformed_activity_filters_are_ignored(): void
    {
        self::seedRegister();
        foreach (['types=x', 'types[]=nope', 'kinds=P', 'kinds[][]=P', 'field[]=15', 'field=999', 'holder[]=x', 'range=abc',
            'range[start]=2026-13-45', 'range[start][]=1', 'page[]=2', 'page=-5'] as $q) {
            $this->get('/activity?'.$q)->assertOk();
            $this->get('/activity.rss?'.$q)->assertOk();
        }
    }

    public function test_rss_is_valid_and_takes_the_same_filters(): void
    {
        self::seedRegister();
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, details, occurred_at, geom)
             values (?, 'death', 'E  1500009', 'E 15/9', 'EXPLORATION LICENCE', '{\"BETA PTY LTD\"}', '{\"kind\": \"ended\", \"area_ha\": 10, \"uncovered_share\": 0}', ?, ?::geometry)",
            [DB::table('ingest_runs')->max('id'), self::local(-1), self::square(125.10, -30.80)]
        );
        // Summaries end in a full stop already ("Other tenements cover all of it."); the description must not double it.
        $all = simplexml_load_string($this->get('/activity.rss')->getContent());
        $this->assertGreaterThan(3, count($all->channel->item));
        foreach ($all->channel->item as $item) {
            $this->assertStringNotContainsString('..', (string) $item->description);
        }
        $r = $this->get('/activity.rss?types[]=application')->assertOk();
        $this->assertStringStartsWith('application/rss+xml', $r->headers->get('Content-Type'));
        $this->assertNull($r->headers->get('Set-Cookie'));

        $xml = simplexml_load_string($r->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertNotSame('', (string) $xml->channel->title);
        $this->assertStringContainsString('/activity', (string) $xml->channel->link);
        $this->assertNotSame('', (string) $xml->channel->description);
        $this->assertCount(3, $xml->channel->item);
        foreach ($xml->channel->item as $item) {
            $this->assertNotSame('', (string) $item->title);
            $this->assertNotFalse(filter_var((string) $item->link, FILTER_VALIDATE_URL));
            $this->assertStringStartsWith('pegwatch:', (string) $item->guid);
            $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_RSS, (string) $item->pubDate));
            $this->assertContains((string) $item->category, ['New application', 'Lodged since the last extract']);
        }
        $guids = array_map('strval', iterator_to_array($xml->channel->item->guid ?? [], false));
        $this->assertSame(count($guids), count(array_unique($guids)));

        $titles = array_map('strval', iterator_to_array(simplexml_load_string($this->get('/activity.rss?kinds[]=P')->getContent())->xpath('//item/title'), false));
        $this->assertCount(3, $titles);
        $this->assertSame([], array_filter($titles, fn ($t) => ! str_contains($t, 'P 15/')));
    }

    public function test_panel_counts_follow_the_map_bounds(): void
    {
        self::seedRegister();
        $kal = [121.4, -30.9, 121.6, -30.7];

        $c = Register::countsInBounds($kal);
        $this->assertSame(3, $c['tenements']);
        $this->assertSame(['LIVE' => 2, 'PENDING' => 1], $c['by_status']);
        $this->assertSame(2, $c['by_kind']['E']);
        // A grant and a death in the last 7 days, plus the application two days ago.
        $this->assertSame(3, $c['changed_week']);
        $this->assertSame(4, Register::countsInBounds(null)['tenements']);
        $this->assertSame(1, Register::countsInBounds($kal, ['statuses' => ['PENDING']])['tenements']);

        Livewire::test('pages::map')
            ->assertSeeText('4 tenements in view')
            ->call('moved', $kal)->assertSeeText('3 tenements in view')->assertSee('E 15/9')->assertDontSee('P 15/77')
            ->call('moved', [100, -40, 140, -10])->assertSet('bounds', null)->assertSeeText('4 tenements in view')
            ->call('moved', ['x', 1])->assertSet('bounds', null)
            ->set('statuses', ['PENDING'])->assertDispatched('map-filter', statuses: ['PENDING']);
    }
}
