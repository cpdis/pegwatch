<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The vector tiles the map draws: what each layer carries at each zoom, and how
 * the tables the nightly job adds later degrade to empty tiles until they exist.
 */
class TileTest extends TestCase
{
    use RefreshDatabase;

    private const KALGOORLIE = [121.505, -30.795];

    /** A square near Kalgoorlie, about 1 km across unless widened. */
    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    private static function seedRegister(): void
    {
        DB::table('ingest_runs')->insert(['status' => 'ok', 'finished_at' => now(), 'tenements_extract' => now()->subHours(15), 'stale' => false]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, survstatus, special_ind, holders, holder_is_company,
                                    area_ha, applied_at, granted_at, ends_at, extract_at, geom)
             values ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', 'SURVEYED', null, '{\"ACME GOLD PTY LTD\",\"SMITH, JOHN\"}', '{t,f}',
                     104.6, '2025-01-02 09:00', '2025-06-01 00:00', '2030-01-01 00:00', now(), ?::geometry),
                    ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', 'UNSURVEYED', null, '{\"SMITH, JOHN\"}', '{f}',
                     3.44, '2026-11-18 08:30', null, '2999-12-31 00:00', now(), ?::geometry),
                    ('P  1500003', 'P 15/3-S', 'PROSPECTING LICENCE', 'P', 'LIVE', 'UNSURVEYED', 'S', '{\"BORG, JUSTIN\"}', '{f}',
                     9.96, '2024-03-01 10:00', '2024-09-01 00:00', '2028-08-31 00:00', now(), ?::geometry)",
            [self::square(121.48, -30.82, 0.05), self::square(121.505, -30.80), self::square(121.51, -30.79, 0.003)]
        );
        // Release times are AWST wall-clock stored as timestamptz: 00:30 UTC is 8:30am in Perth.
        DB::insert(
            "insert into release_pending (tenid, rlesid, fmt_tenid, ten_type, release_at, geom)
             values ('E  1500001', 'RN26/1', 'E 15/1', 'EXPLORATION LICENCE', '2026-12-02 00:30+00', ?::geometry)",
            [self::square(121.50, -30.80, 0.004)]
        );
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holder_name, holders, geom)
             values ('P  1500009', now() - interval '1 month', 'P 15/9', 'PROSPECTING LICENCE', 'Surrendered', 'JONES, MARY', '{\"JONES, MARY\"}', ?::geometry)",
            [self::square(121.52, -30.80)]
        );
    }

    /** The optional tables in the shape contracts.md gives them, created here until the ingest schema has them. */
    private static function seedOptionalTables(): void
    {
        DB::statement('create table if not exists coverage_lowzoom (status text, type_group text, geom geometry(MultiPolygon, 4326))');
        DB::statement('create table if not exists freed_ground (tenid text, fmt_tenid text, ended_at timestamp, reason text, holder text,
                                                                 uncovered_ha double precision, geom geometry(MultiPolygon, 4326))');
        DB::statement('create table if not exists no_recorded_tenement (geom geometry(MultiPolygon, 4326))');
        DB::statement('create table if not exists recent_changes (tenid text primary key, event_type text, detected_at timestamptz)');
        DB::insert("insert into coverage_lowzoom (status, type_group, geom) values ('LIVE', 'E', ?::geometry), ('PENDING', 'other', ?::geometry)",
            [self::square(121.0, -31.0, 1.0), self::square(122.5, -31.0, 0.5)]);
        DB::insert("insert into freed_ground (tenid, fmt_tenid, ended_at, reason, holder, uncovered_ha, geom)
                    values ('P  1500009', 'P 15/9', '2026-10-20 00:00', 'Surrendered', 'JONES, MARY', 84.2, ?::geometry)",
            [self::square(121.52, -30.80)]);
        DB::insert('insert into no_recorded_tenement (geom) values (?::geometry)', [self::square(121.3, -30.9, 0.1)]);
        // Detected at 8pm UTC on 20 Nov, which is 4am on 21 Nov in Perth.
        DB::insert("insert into recent_changes (tenid, event_type, detected_at) values ('E  1500001', 'holder_change', '2026-11-20 20:00+00')");
    }

    private static function dropOptionalTables(): void
    {
        DB::statement('drop table if exists coverage_lowzoom, freed_ground, no_recorded_tenement, recent_changes');
    }

    private static function xy(int $z, float $lng, float $lat): array
    {
        $n = 2 ** $z;
        $r = deg2rad($lat);

        return [(int) floor(($lng + 180) / 360 * $n), (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n)];
    }

    /** The decoded tile at this point: layer name => list of feature properties keyed by fmt_tenid where present. */
    private function tile(string $layer, int $z, float $lng = self::KALGOORLIE[0], float $lat = self::KALGOORLIE[1]): array
    {
        [$x, $y] = self::xy($z, $lng, $lat);
        $body = $this->get("/tiles/$layer/$z/$x/$y.mvt", ['Accept-Encoding' => 'identity'])->assertOk()->getContent();

        $out = [];
        foreach (Mvt::decode($body) as $name => $features) {
            foreach ($features as $i => $f) {
                $out[$name][$f['props']['fmt_tenid'] ?? $i] = $f;
            }
        }

        return $out;
    }

    public function test_tenements_carry_hover_card_fields_from_zoom_8_only(): void
    {
        self::seedRegister();
        self::seedOptionalTables();

        $low = $this->tile('tenements', 7)['tenements']['E 15/1'];
        $this->assertSame(['fmt_tenid', 'type_code', 'status', 'holder'], array_keys($low['props']));

        $tile = $this->tile('tenements', 8)['tenements'];
        $live = $tile['E 15/1']['props'];
        $this->assertSame('E  1500001', $live['tenid']);
        $this->assertSame('ACME GOLD PTY LTD', $live['holder']);
        $this->assertSame(2, $live['holders_n']);
        $this->assertEquals(105, $live['ha']);
        $this->assertSame('2025-01-02', $live['applied']);
        $this->assertSame('2025-06-01', $live['granted']);
        $this->assertSame('2030-01-01', $live['ends']);
        $this->assertSame('2026-12-02 08:30', $live['rel_at']);
        $this->assertSame('SURVEYED', $live['survstatus']);
        $this->assertSame('holder_change', $live['changed_type']);
        $this->assertSame('2026-11-21', $live['changed_at']);
        $this->assertArrayNotHasKey('special', $live);

        // Pending: no grant and SLIP's 2999 placeholder expiry are absent; small areas keep one decimal.
        $pending = $tile['P 15/2']['props'];
        $this->assertEquals(3.4, $pending['ha']);
        $this->assertSame('2026-11-18', $pending['applied']);
        $this->assertArrayNotHasKey('granted', $pending);
        $this->assertArrayNotHasKey('ends', $pending);
        $this->assertArrayNotHasKey('changed_type', $pending);

        $this->assertTrue($tile['P 15/3-S']['props']['special']);
        $this->assertEquals(10, $tile['P 15/3-S']['props']['ha']);
    }

    public function test_tenements_get_one_label_point_each_from_zoom_12(): void
    {
        self::seedRegister();

        $this->assertArrayNotHasKey('labels', $this->tile('tenements', 11));
        $labels = $this->tile('tenements', 12)['labels'];
        $this->assertSame(['E 15/1', 'P 15/2', 'P 15/3-S'], array_keys($labels));
        $this->assertSame(1, $labels['E 15/1']['type']); // point
        $this->assertSame('LIVE', $labels['E 15/1']['props']['status']);
        $this->assertSame('2030-01-01', $labels['E 15/1']['props']['ends']);
    }

    public function test_dead_and_release_tiles_carry_holder_area_and_release_markers(): void
    {
        self::seedRegister();

        $dead = $this->tile('dead', 10)['dead']['P 15/9']['props'];
        $this->assertSame('JONES, MARY', $dead['holder']);
        $this->assertSame('P  1500009', $dead['tenid']);
        $this->assertSame('P', $dead['type_code']);
        $this->assertSame('Surrendered', $dead['reason']);
        $this->assertGreaterThan(90, $dead['ha']);

        $releases = $this->tile('releases', 10);
        $parcel = $releases['releases']['E 15/1']['props'];
        $this->assertSame('ACME GOLD PTY LTD', $parcel['holder']);
        $this->assertSame('2026-12-02 08:30', $parcel['release_at']);
        $this->assertGreaterThan(10, $parcel['ha']);

        // A marker for every scheduled release, at every zoom, labelled with its AWST time.
        foreach ([0, 4, 10] as $z) {
            $point = $this->tile('releases', $z)['release-points']['E 15/1'];
            $this->assertSame(1, $point['type'], "point at zoom $z");
            $this->assertSame('Wed 2 Dec, 8:30am', $point['props']['label']);
        }
    }

    public function test_coverage_tile_shades_held_ground_up_to_zoom_8(): void
    {
        self::seedRegister();
        self::seedOptionalTables();

        $coverage = $this->tile('coverage', 5)['coverage'];
        $this->assertEqualsCanonicalizing([['LIVE', 'E'], ['PENDING', 'other']], array_map(fn ($f) => [$f['props']['status'], $f['props']['type_group']], $coverage));
        $this->assertNotEmpty($this->tile('coverage', 8)['coverage']);
        $this->assertSame([], $this->tile('coverage', 9));
    }

    public function test_freed_ground_has_shapes_from_zoom_8_and_points_below_zoom_10(): void
    {
        self::seedRegister();
        self::seedOptionalTables();

        $z5 = $this->tile('freed', 5);
        $this->assertArrayNotHasKey('freed', $z5);
        $this->assertSame(1, $z5['freed-points']['P 15/9']['type']);

        $z12 = $this->tile('freed', 12);
        $this->assertArrayNotHasKey('freed-points', $z12);
        $this->assertEquals(['tenid' => 'P  1500009', 'fmt_tenid' => 'P 15/9', 'ended' => '2026-10-20',
            'reason' => 'Surrendered', 'holder' => 'JONES, MARY', 'uncovered_ha' => 84], $z12['freed']['P 15/9']['props']);

        $this->assertNotEmpty($this->tile('norecord', 10, 121.35, -30.85)['norecord']);
    }

    public function test_missing_optional_tables_give_empty_tiles_until_a_run_brings_them(): void
    {
        self::seedRegister();
        self::dropOptionalTables();
        [$x, $y] = self::xy(5, ...self::KALGOORLIE);

        foreach (['coverage', 'freed', 'norecord'] as $layer) {
            $this->get("/tiles/$layer/8/".implode('/', self::xy(8, ...self::KALGOORLIE)).'.mvt')->assertOk()->assertContent('');
        }
        $live = $this->tile('tenements', 8)['tenements']['E 15/1']['props'];
        $this->assertSame('2025-01-02', $live['applied']);
        $this->assertArrayNotHasKey('changed_type', $live);

        // The nightly schema creates the table and the run that fills it starts new tiles.
        $this->get("/tiles/coverage/5/$x/$y.mvt")->assertOk()->assertContent('');
        self::seedOptionalTables();
        DB::table('ingest_runs')->insert(['status' => 'ok', 'finished_at' => now(), 'tenements_extract' => now(), 'stale' => false]);
        $this->assertNotEmpty($this->tile('coverage', 5)['coverage']);
    }
}

/** Just enough of a Mapbox Vector Tile decoder to read layers, feature types and properties. */
final class Mvt
{
    /** @return array<string, list<array{type: int, props: array<string, mixed>}>> */
    public static function decode(string $bytes): array
    {
        $layers = [];
        foreach (self::fields($bytes) as [$field, $layer]) {
            if ($field !== 3) {
                continue;
            }
            $name = '';
            $keys = $values = $raw = [];
            foreach (self::fields($layer) as [$f, $v]) {
                match ($f) {
                    1 => $name = $v,
                    2 => $raw[] = $v,
                    3 => $keys[] = $v,
                    4 => $values[] = self::value($v),
                    default => null,
                };
            }
            $layers[$name] = array_map(function (string $feature) use ($keys, $values) {
                $tags = [];
                $type = 0;
                foreach (self::fields($feature) as [$f, $v]) {
                    if ($f === 2) {
                        $i = 0;
                        while ($i < strlen($v)) {
                            $tags[] = self::varint($v, $i);
                        }
                    } elseif ($f === 3) {
                        $type = $v;
                    }
                }
                $props = [];
                for ($i = 0; $i < count($tags); $i += 2) {
                    $props[$keys[$tags[$i]]] = $values[$tags[$i + 1]];
                }

                return ['type' => $type, 'props' => $props];
            }, $raw);
        }

        return $layers;
    }

    private static function fields(string $b): \Generator
    {
        $i = 0;
        while ($i < strlen($b)) {
            $key = self::varint($b, $i);
            [$field, $wire] = [$key >> 3, $key & 7];
            if ($wire === 0) {
                yield [$field, self::varint($b, $i)];
            } else {
                $len = match ($wire) {
                    1 => 8, 5 => 4, 2 => self::varint($b, $i)
                };
                yield [$field, substr($b, $i, $len)];
                $i += $len;
            }
        }
    }

    private static function varint(string $b, int &$i): int
    {
        $out = $shift = 0;
        do {
            $byte = ord($b[$i++]);
            $out |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $out;
    }

    private static function value(string $b): mixed
    {
        foreach (self::fields($b) as [$f, $v]) {
            return match ($f) {
                1 => $v,
                2 => unpack('g', $v)[1],
                3 => unpack('e', $v)[1],
                4, 5 => $v,
                6 => ($v >> 1) ^ -($v & 1),
                7 => (bool) $v,
            };
        }

        return null;
    }
}
