<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenementPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 14:00', 'Australia/Perth'));
        DB::table('ingest_runs')->insert([
            'status' => 'ok', 'finished_at' => now(), 'stale' => false,
            'tenements_extract' => '2026-11-20 04:11:00+08',
        ]);
    }

    /** A page's visible text: scripts and tags removed, entities decoded, whitespace collapsed. */
    private function text(string $url): string
    {
        $html = $this->get($url)->assertOk()->getContent();
        $html = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /** Each part appears in the text, in this order. */
    private function assertInOrder(string $text, array $parts): void
    {
        $at = 0;
        foreach ($parts as $part) {
            $pos = mb_strpos($text, $part, $at);
            $this->assertNotFalse($pos, "Not found in order: \"$part\"\nafter: ".mb_substr($text, $at, 400));
            $at = $pos + mb_strlen($part);
        }
    }

    /** A box near Kalgoorlie, w degrees wide (0.01 is about 1 km). */
    private static function box(float $x, float $y, float $w = 0.01, ?float $h = null): string
    {
        $h ??= $w;

        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $h);
    }

    private static function tenement(string $fmt, array $row): void
    {
        $holders = $row['holders'] ?? ['YANDAL RESOURCES LIMITED'];
        DB::table('tenements')->insert([
            'tenid' => $fmt, 'fmt_tenid' => $fmt,
            'type' => $row['type'] ?? null, 'type_code' => $row['type_code'] ?? substr($fmt, 0, 1),
            'status' => $row['status'] ?? 'LIVE', 'survstatus' => $row['survstatus'] ?? 'UNSURVEYED',
            'holders' => '{'.implode(',', array_map(fn ($h) => '"'.$h.'"', $holders)).'}',
            'holder_is_company' => '{'.implode(',', array_map(fn ($h) => str_contains($h, ',') ? 'f' : 't', $holders)).'}',
            'legal_area' => $row['legal_area'] ?? null, 'area_unit' => $row['area_unit'] ?? null,
            'special_ind' => $row['special_ind'] ?? null, 'area_ha' => $row['area_ha'] ?? null,
            'applied_at' => $row['applied_at'] ?? null, 'granted_at' => $row['granted_at'] ?? null,
            'ends_at' => $row['ends_at'] ?? null, 'extract_at' => now(),
            'geom' => DB::raw("'".($row['geom'] ?? self::box(121.50, -30.80))."'::geometry"),
        ]);
    }

    private static function dead(string $fmt, array $row): void
    {
        DB::table('dead_tenements')->insert([
            'tenid' => $fmt, 'fmt_tenid' => $fmt, 'type' => $row['type'] ?? 'EXPLORATION LICENCE',
            'death_reason' => $row['reason'] ?? 'Withdrawn',
            'holders' => '{"'.implode('","', $row['holders'] ?? ['AUSTRALIA MINERAL PROSPECTS PTY LTD']).'"}',
            'holders_are_company' => '{t}',
            'started_at' => $row['started_at'] ?? null, 'granted_at' => $row['granted_at'] ?? null,
            'ended_at' => $row['ended_at'], 'extract_at' => now(),
            'geom' => array_key_exists('geom', $row) && $row['geom'] === null ? null : DB::raw("'".($row['geom'] ?? self::box(121.50, -30.80))."'::geometry"),
        ]);
    }

    private static function pendingLease(): void
    {
        self::tenement('M 27/518', [
            'type' => 'MINING LEASE', 'status' => 'PENDING', 'legal_area' => 198, 'area_unit' => 'HA.',
            'area_ha' => 197.4, 'applied_at' => '2023-04-21 08:24:00',
        ]);
    }

    public function test_pending_page_opens_with_the_headline_and_says_applied(): void
    {
        self::pendingLease();

        $text = $this->text('/tenements/m-27-518');
        $this->assertInOrder($text, [
            'Mining lease · Pending application', 'M 27/518',
            'Pending mining lease application by YANDAL RESOURCES LIMITED, lodged 21 Apr 2023 (3 years 7 months ago). 198 ha in the Kanowna district, unsurveyed.',
            'Field Kanowna (27) Area 198 ha Survey Unsurveyed Applied 21 Apr 2023, 8:24am (3 years 7 months ago)',
            'Holder YANDAL RESOURCES LIMITED Extract Register extract of 20 Nov 2026, 4:11am AWST',
        ]);
        $this->assertStringNotContainsString('Started', $text);
        $this->assertStringNotContainsString('mapped', $text);
        $this->get('/tenements/m-27-518')->assertSee(route('holders.show', 'yandal-resources-limited'));
    }

    public function test_a_transferred_tenement_is_held_since_the_transfer_not_the_grant(): void
    {
        self::tenement('E 27/726', [
            'type' => 'EXPLORATION LICENCE', 'holders' => ['PURE GOLD PTY LTD'], 'legal_area' => 4, 'area_unit' => 'BL.',
            'area_ha' => 1183.9, 'applied_at' => '2023-06-12 10:00:00', 'granted_at' => '2024-08-02 00:00:00',
            'ends_at' => '2029-08-01 23:59:59',
        ]);
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, extract_at, details)
             values ((select max(id) from ingest_runs), 'holder_change', 'E 27/726', 'E 27/726', 'EXPLORATION LICENCE', '{\"PURE GOLD PTY LTD\"}',
                     '2026-11-20 03:11:00+00', '{\"removed\": [\"OLD GOLD PTY LTD\"], \"added\": [\"PURE GOLD PTY LTD\"]}')"
        );

        $this->assertInOrder($this->text('/tenements/e-27-726'), [
            'Exploration licence granted 2 Aug 2024, held by PURE GOLD PTY LTD since a change of holder seen 20 Nov 2026. Expires 1 Aug 2029',
        ]);
        $this->get('/tenements/e-27-726')->assertDontSee('PURE GOLD PTY LTD since 2 Aug 2024');
    }

    public function test_live_page_gives_holder_since_grant_expiry_and_blocks(): void
    {
        self::tenement('E 27/726', [
            'type' => 'EXPLORATION LICENCE', 'holders' => ['PURE GOLD PTY LTD'], 'legal_area' => 4, 'area_unit' => 'BL.',
            'area_ha' => 1183.9, 'applied_at' => '2023-06-12 10:00:00', 'granted_at' => '2024-08-02 00:00:00',
            'ends_at' => '2029-08-01 23:59:59',
        ]);

        $text = $this->text('/tenements/e-27-726');
        $this->assertInOrder($text, [
            'Exploration licence · Live',
            'Exploration licence held by PURE GOLD PTY LTD since 2 Aug 2024. Expires 1 Aug 2029 (in 2 years 8 months). 4 blocks, 1,184 ha in the Kanowna district.',
            'Area 4 blocks (1,184 ha mapped)', 'Applied 12 Jun 2023, 10:00am',
            'Granted 2 Aug 2024 (13 months after application) Expires 1 Aug 2029 (in 2 years 8 months)',
        ]);
        $this->assertStringNotContainsString('12:00am', $text);
        $this->assertStringNotContainsString('11:59pm', $text);
    }

    public function test_expiry_under_six_months_is_amber(): void
    {
        self::tenement('E 27/1', ['type' => 'EXPLORATION LICENCE', 'granted_at' => '2021-03-01', 'ends_at' => '2027-02-28 23:59:59']);
        self::tenement('E 27/2', ['type' => 'EXPLORATION LICENCE', 'granted_at' => '2021-03-01', 'ends_at' => '2028-02-28 23:59:59', 'geom' => self::box(121.6, -30.8)]);

        $amber = fn (string $slug) => preg_match('/<dd class="[^"]*text-amber[^"]*" data-test="expires"/', $this->get("/tenements/$slug")->getContent());

        $this->assertSame(1, $amber('e-27-1'));
        $this->assertSame(0, $amber('e-27-2'));
    }

    public function test_past_expiry_page_says_so_and_names_the_same_holder_application(): void
    {
        $holders = ['HAYES MINING PTY LTD', 'LAVERTY, TERENCE JOSEPH'];
        self::tenement('P 15/4902', [
            'type' => 'PROSPECTING LICENCE', 'holders' => $holders, 'legal_area' => 10, 'area_unit' => 'HA.', 'area_ha' => 9.7,
            'applied_at' => '2007-01-19 08:45:00', 'granted_at' => '2010-07-14 00:00:00', 'ends_at' => '2018-07-13 23:59:59',
            'geom' => self::box(121.12, -30.77, 0.004),
        ]);
        self::tenement('M 15/1850', [
            'type' => 'MINING LEASE', 'status' => 'PENDING', 'holders' => $holders, 'applied_at' => '2018-07-04 13:55:00',
            'geom' => self::box(121.11, -30.78, 0.03),
        ]);

        $text = $this->text('/tenements/p-15-4902');
        $this->assertInOrder($text, [
            'Prospecting licence · Live, past expiry',
            'Prospecting licence held by HAYES MINING PTY LTD and LAVERTY, TERENCE JOSEPH since 14 Jul 2010. The expiry date passed on 13 Jul 2018, but the register still lists it as live. 10 ha in the Coolgardie district.',
            'Area 10 ha (9.7 ha mapped)',
            'Expires 13 Jul 2018 (passed, still live on the register)',
            'Past expiry, still live',
            'A mining lease application by the same holder, M 15/1850, lodged 4 Jul 2018, covers 100% of it.',
        ]);
        // A natural person is shown by name, exactly as registered, and links to their holder page.
        $this->assertStringNotContainsString('Laverty, terence joseph', $text);
        $this->get('/tenements/p-15-4902')->assertSee(route('holders.show', 'laverty-terence-joseph'));
    }

    public function test_dead_page_says_how_much_ground_is_uncovered_and_what_covers_it(): void
    {
        self::dead('E 47/5271', [
            'started_at' => '2024-11-22 08:30:00', 'ended_at' => '2026-11-17 08:30:00', 'geom' => self::box(117.33, -20.98, 0.02),
        ]);
        // A live licence over 40% of it, and an L licence over the rest, which does not block ground.
        self::tenement('E 47/9000', ['type' => 'EXPLORATION LICENCE', 'applied_at' => '2025-01-01 09:00:00', 'geom' => self::box(117.33, -20.98, 0.008, 0.02)]);
        self::tenement('L 47/900', ['type' => 'MISCELLANEOUS LICENCE', 'applied_at' => '2020-01-01 09:00:00', 'geom' => self::box(117.338, -20.98, 0.012, 0.02)]);
        // A special prospecting licence inside the uncovered part does not block it either.
        self::tenement('P 47/1-S', ['type' => 'PROSPECTING LICENCE', 'special_ind' => 'S', 'applied_at' => '2021-01-01 09:00:00', 'geom' => self::box(117.34, -20.975, 0.004)]);

        $this->assertInOrder($this->text('/tenements/e-47-5271'), [
            'Exploration licence · Dead (withdrawn)',
            'Exploration licence applied for by AUSTRALIA MINERAL PROSPECTS PTY LTD on 22 Nov 2024 and withdrawn on 17 Nov 2026, after 1 year 11 months.',
            '60% of this ground has no live or pending tenement over it on the register today.',
            'See freed ground on the map',
            'Applied 22 Nov 2024, 8:30am', 'Ended 17 Nov 2026, 8:30am',
            'Tenements over this ground now (3)', 'L 47/900', 'Can overlap', 'P 47/1-S', 'Can overlap', 'E 47/9000',
        ]);
        $this->get('/tenements/e-47-5271')->assertSee(url('/').'#map=14/-20.97/117.34&l=tenements,pending,dead,freed');
    }

    public function test_dead_tenement_without_an_outline_still_renders(): void
    {
        self::dead('P 27/1', ['ended_at' => '2026-01-01 00:00:00', 'reason' => 'Null and Void', 'geom' => null]);

        $this->assertStringContainsString('and declared null and void on 1 Jan 2026.', $this->text('/tenements/p-27-1'));
    }

    public function test_overlaps_are_open_oldest_first_with_holder_and_overlap_tags(): void
    {
        self::tenement('E 27/100', ['type' => 'EXPLORATION LICENCE', 'applied_at' => '2015-06-01 09:00:00', 'geom' => self::box(121.50, -30.80, 0.02)]);
        self::tenement('M 27/7', ['type' => 'MINING LEASE', 'holders' => ['OTHER GOLD PTY LTD'], 'applied_at' => '2019-01-01 09:00:00', 'geom' => self::box(121.50, -30.80, 0.01)]);
        self::tenement('P 27/3', ['type' => 'PROSPECTING LICENCE', 'applied_at' => '2006-12-01 09:00:00', 'geom' => self::box(121.51, -30.79, 0.005)]);
        self::tenement('P 27/4-S', ['type' => 'PROSPECTING LICENCE', 'special_ind' => 'S', 'holders' => ['SMITH, JOHN'], 'applied_at' => '2024-01-01 09:00:00', 'geom' => self::box(121.505, -30.795, 0.004)]);

        $this->assertInOrder($this->text('/tenements/e-27-100'), [
            'Overlapping tenements (3)',
            'P 27/3', 'Prospecting licence', 'YANDAL RESOURCES LIMITED Same holder', '6% of this one', '1 Dec 2006 · 8 years 6 months before this one',
            'M 27/7', 'Mining lease', 'OTHER GOLD PTY LTD', '25% of this one', '1 Jan 2019 · 3 years 7 months after this one',
            'P 27/4-S', 'Can overlap', 'SMITH, JOHN', '4% of this one',
            'Lodgement order is from the register. Priority under the Mining Act can depend on marking out, which no dataset shows. Confirm with the Mining Registrar.',
        ]);

        $this->assertStringContainsString('Special prospecting licence (can sit inside a live exploration licence)', $this->text('/tenements/p-27-4-s'));
    }

    public function test_restrictions_and_pending_actions_are_called_out(): void
    {
        self::tenement('E 27/100', ['type' => 'EXPLORATION LICENCE', 'geom' => self::box(121.50, -30.80, 0.02)]);
        DB::table('land_restrictions')->insert([
            'kind' => 's19', 'land_id' => 'S190000036', 'purpose' => 'SECTION 19 - EXTRACTION OF ROAD MAKING MATERIALS', 'status' => 'ACTIVE',
            'geom' => DB::raw("'".self::box(121.50, -30.80, 0.01, 0.02)."'::geometry"),
        ]);
        DB::table('land_restrictions')->insert([
            'kind' => 'file_notation', 'land_id' => 'FNA 1', 'purpose' => 'FAR AWAY', 'status' => 'ACTIVE',
            'geom' => DB::raw("'".self::box(118, -25)."'::geometry"),
        ]);
        DB::table('amalgamation_pending')->insert([
            'tenid' => 'E 27/100', 'amalgid' => 'AM0764440', 'status' => 'P', 'starts_at' => '2026-07-14 07:40:05+08',
        ]);

        $text = $this->text('/tenements/e-27-100');
        $this->assertInOrder($text, [
            'Land restrictions over this ground', 'Section 19 land S190000036 · Extraction of road making materials · 50% of this ground',
            'Amalgamation application AM0764440 pending, lodged 14 Jul 2026.',
        ]);
        $this->assertStringNotContainsString('FNA 1', $text);
    }

    public function test_release_callout_counts_down_and_links_a_calendar_file(): void
    {
        self::tenement('E 27/100', ['type' => 'EXPLORATION LICENCE', 'geom' => self::box(121.50, -30.80, 0.02)]);
        DB::table('release_pending')->insert([
            'tenid' => 'E 27/100', 'rlesid' => 'RN26/139', 'fmt_tenid' => 'E 27/100', 'rles_status' => 'P',
            'release_at' => '2026-12-02 08:30:00+08', 'geom' => DB::raw("'".self::box(121.50, -30.80, 0.01, 0.02)."'::geometry"),
        ]);

        $this->assertInOrder($this->text('/tenements/e-27-100'), [
            'Exploration licence · Live · Release due',
            'Part of this licence is due for compulsory release',
            'RN26/139: 212 ha releases Wed 2 Dec 2026, 8:30am AWST (in 9 days).',
            'Applications lodged in the first 15 minutes go to ballot.', 'Add to calendar',
            'Part releases (RN26/139)', '2 Dec 2026, 8:30am AWST',
        ]);
        $this->get('/tenements/e-27-100')->assertSee(route('tenements.release-ics', 'e-27-100'));

        $ics = $this->get('/tenements/e-27-100/release.ics')->assertOk();
        $this->assertStringStartsWith('text/calendar', $ics->headers->get('Content-Type'));
        $body = $ics->getContent();
        $this->assertStringContainsString("DTSTART:20261202T003000Z\r\n", $body);
        $this->assertStringContainsString("DTEND:20261202T004500Z\r\n", $body);
        $this->assertStringContainsString('SUMMARY:Release of E 27/100 (RN26/139)', $body);

        $this->get('/tenements/e-99-1/release.ics')->assertNotFound();
    }

    public function test_holder_context_neighbours_and_ground_history(): void
    {
        self::pendingLease();
        // The same holder over part of it and 4.8 km away; another holder 50 m away; a third 2.9 km away.
        self::tenement('P 27/2206', ['type' => 'PROSPECTING LICENCE', 'applied_at' => '2014-09-26 09:45:00', 'geom' => self::box(121.505, -30.795, 0.004)]);
        self::tenement('E 27/570', ['type' => 'EXPLORATION LICENCE', 'status' => 'PENDING', 'geom' => self::box(121.56, -30.80)]);
        self::tenement('E 27/767', ['type' => 'EXPLORATION LICENCE', 'holders' => ['GREENCORP METALS PTY LTD'], 'status' => 'PENDING', 'applied_at' => '2026-07-30 10:49:40', 'geom' => self::box(121.5105, -30.80)]);
        self::tenement('E 27/999', ['type' => 'EXPLORATION LICENCE', 'holders' => ['FAR PTY LTD'], 'geom' => self::box(121.54, -30.80)]);
        // Dead ground: all of it, then a 5% sliver that stays off the list.
        self::dead('E 24/235', ['ended_at' => '2024-07-05 08:30:00', 'started_at' => '2022-02-14 09:00:00', 'holders' => ['MOHO RESOURCES LIMITED']]);
        self::dead('M 27/246', ['ended_at' => '2014-08-01 15:50:00', 'geom' => self::box(121.5095, -30.80, 0.0005, 0.01)]);

        $text = $this->text('/tenements/m-27-518');
        $this->assertInOrder($text, [
            'YANDAL RESOURCES LIMITED · 3 tenements · 2 pending',
            'Their other tenements within 20 km (2)', 'P 27/2206 live, adjoining', 'E 27/570 pending, 4.8 km',
            'Neighbours within 100 m', 'Watch my neighbours', 'GREENCORP METALS PTY LTD', 'E 27/767 pending, applied 30 Jul 2026',
            'Held before (1)', 'E 24/235', 'MOHO RESOURCES LIMITED', '100% of this ground', '14 Feb 2022 to 5 Jul 2024 · Withdrawn',
        ]);
        $this->assertStringNotContainsString('E 27/999', $text);
        $this->assertStringNotContainsString('M 27/246', $text);
        $this->get('/tenements/m-27-518')->assertSee(url('/pro').'?ref=neighbours');
    }

    public function test_location_line_uses_places_when_they_exist_else_the_field(): void
    {
        self::pendingLease();
        $this->assertStringContainsString('Kanowna district, North East Coolgardie mineral field', $this->text('/tenements/m-27-518'));

        // Kalgoorlie about 12 km south-west of the tenement's centre.
        DB::insert("insert into places (name, kind, geom) values
            ('Kalgoorlie', 'town', ST_SetSRID(ST_MakePoint(121.4144, -30.8673), 4326)),
            ('Perth', 'city', ST_SetSRID(ST_MakePoint(115.86, -31.95), 4326))");

        $this->assertStringContainsString('12 km north-east of Kalgoorlie', $this->text('/tenements/m-27-518'));
    }

    public function test_lifecycle_runs_from_application_through_today_to_expiry(): void
    {
        self::tenement('E 27/726', ['type' => 'EXPLORATION LICENCE', 'applied_at' => '2023-06-12 10:00:00', 'granted_at' => '2024-08-02 00:00:00', 'ends_at' => '2029-08-01 23:59:59']);

        $this->assertInOrder($this->text('/tenements/e-27-726'), [
            'Lifecycle', 'Applied 12 Jun 2023, 10:00am', 'Granted 2 Aug 2024 · 13 months after application',
            'Today 23 Nov 2026', 'Expires 1 Aug 2029', 'Changes seen',
        ]);
    }

    public function test_wait_to_grant_uses_the_field_with_ten_grants_else_wa(): void
    {
        self::pendingLease();
        // Ten mining leases granted in field 27 after 12 months each, and two elsewhere after 30.
        foreach (range(1, 10) as $i) {
            self::tenement("M 27/9$i", ['applied_at' => '2021-01-01', 'granted_at' => '2022-01-01', 'geom' => self::box(122 + $i / 10, -30)]);
        }
        foreach (range(1, 2) as $i) {
            self::tenement("M 15/9$i", ['applied_at' => '2021-01-01', 'granted_at' => '2023-07-01', 'geom' => self::box(120 + $i / 10, -30)]);
        }

        $this->assertStringContainsString(
            'Mining leases in the Kanowna district granted since 2020 took a median of 12 months (10 grants). This one has waited 43 months. This describes past grants, not a forecast.',
            $this->text('/tenements/m-27-518'),
        );

        DB::delete("delete from tenements where tenid = 'M 27/910'");
        cache()->flush();

        $this->assertStringContainsString(
            'Mining leases granted across WA since 2020 took a median of 12 months (11 grants).',
            $this->text('/tenements/m-27-518'),
        );
    }

    public function test_watch_this_tenement_links_to_the_watch_flow_when_it_exists(): void
    {
        self::pendingLease();
        if (! Route::has('watch.start')) {
            Route::get('watch', fn () => 'ok')->name('watch.start');
            Route::getRoutes()->refreshNameLookups();
        }

        $this->get('/tenements/m-27-518')->assertSee(route('watch.start', ['tenement' => 'm-27-518']))
            ->assertSee('Watch this tenement');
    }
}
