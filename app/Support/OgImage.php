<?php

namespace App\Support;

use GdImage;

/**
 * Share images (1200 x 630 PNG) drawn with GD: a tenement's outline, or a
 * watch area with the tenements that changed in it. No basemap: a plain
 * light ground, the shapes, a scale bar and the facts beside them.
 */
final class OgImage
{
    public const W = 1200;

    public const H = 630;

    /** Drawn at twice the size, then scaled down, so edges are smooth. */
    private const S = 2;

    private const INK = '#0b0b0c';

    private const GROUND = '#fafafa';

    private GdImage $im;

    /** @var array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} west, north, px per degree, x and y offset, cos(latitude) */
    private array $fit = [0, 0, 1, 0, 0, 1];

    private function __construct()
    {
        $this->im = imagecreatetruecolor(self::W * self::S, self::H * self::S);
        imagealphablending($this->im, true);
        imagefill($this->im, 0, 0, $this->colour(self::GROUND));
    }

    /** A tenement from Register::tenementBrief($slug, outline: true). */
    public static function tenement(object $t): string
    {
        $c = new self;
        $key = Format::statusKey($t->status);
        $colour = Format::STATUS_COLOURS[$key];
        $shape = json_decode($t->geojson ?? 'null', true);

        $c->eyebrow(strtoupper(Format::sentence($t->type).' · '.Format::STATUS_LABELS[$key]), $colour);
        $c->fitText(trim($t->fmt_tenid), 'Bold', 96, 204, 500, self::INK);

        $names = PgArray::parse($t->holders);
        $c->line($names[0] ?? '', 'Medium', 28, 262, '#262626', count($names) > 1 ? ' +'.(count($names) - 1) : '');
        $blocks = str_starts_with(strtoupper((string) $t->area_unit), 'BL');
        $facts = $t->status === 'DEAD'
            ? trim(($t->death_reason ?? 'Dead').' '.Format::localDate($t->ends_at))
            : implode(' · ', array_filter([
                $t->legal_area !== null ? Format::legalArea((float) $t->legal_area, $t->area_unit) : null,
                ($t->legal_area === null || $blocks) && $t->area_ha ? number_format((float) $t->area_ha).' ha' : null,
                Format::field($t->fmt_tenid),
            ]));
        $c->line($facts, 'Medium', 26, 306, '#737373');
        $c->brand();

        if ($shape) {
            $c->fitTo([$shape], 640, 64, 496, 470);
            $c->shape($shape, $colour, 0.22, 5);
            $c->scaleBar(640, 566, 496);
        }

        return $c->png();
    }

    /**
     * A watch area and the tenements that changed in it.
     *
     * @param  array<int, array{geojson: string, status: string}>  $changed  status is a Format::STATUS_COLOURS key
     * @param  array<int, string>  $context  outlines of other tenements in the area, drawn faintly
     */
    public static function area(string $name, string $areaGeojson, array $changed, array $context, string $summary): string
    {
        $c = new self;
        $area = json_decode($areaGeojson, true);

        $c->eyebrow('WATCH AREA', self::INK);
        $c->fitText($name, 'Bold', 72, 184, 500, self::INK);
        $c->line($summary, 'Medium', 26, 240, '#404040');
        $c->brand();

        $c->fitTo([$area], 640, 64, 496, 470);
        $c->shape($area, '#ffd400', 0.12, 0);
        foreach ($context as $g) {
            $c->shape(json_decode($g, true), '#a3a3a3', 0, 1);
        }
        $c->shape($area, self::INK, 0, 3);
        foreach ($changed as $ch) {
            $colour = Format::STATUS_COLOURS[$ch['status']] ?? self::INK;
            $c->shape(json_decode($ch['geojson'], true), $colour, 0.35, 4);
        }
        $c->scaleBar(640, 566, 496);

        return $c->png();
    }

    private function eyebrow(string $text, string $dot): void
    {
        imagefilledellipse($this->im, 80 * self::S, 92 * self::S, 14 * self::S, 14 * self::S, $this->colour($dot));
        $this->text($text, 'Medium', 20, 96, 100, '#737373');
    }

    /** Text at the left margin, shrunk until it fits $maxWidth. */
    private function fitText(string $text, string $weight, int $size, int $y, int $maxWidth, string $colour): void
    {
        while ($size > 24 && $this->width($text, $weight, $size) > $maxWidth) {
            $size -= 4;
        }
        $this->text($text, $weight, $size, 72, $y, $colour);
    }

    /** One line at the left margin, cut with an ellipsis to the column; the suffix ("+2") always shows. */
    private function line(string $text, string $weight, int $size, int $y, string $colour, string $suffix = ''): void
    {
        $cut = $text;
        while ($cut !== '' && $this->width(($cut === $text ? $cut : $cut.'…').$suffix, $weight, $size) > 500) {
            $cut = mb_substr($cut, 0, -1);
        }
        $this->text(($cut === $text ? $text : rtrim($cut).'…').$suffix, $weight, $size, 72, $y, $colour);
    }

    private function brand(): void
    {
        $mark = imagecreatefrompng(public_path('icon.png'));
        imagecopyresampled($this->im, $mark, 72 * self::S, 510 * self::S, 0, 0, 56 * self::S, 56 * self::S, imagesx($mark), imagesy($mark));
        $this->text(PageMeta::SITE_NAME, 'Bold', 30, 142, 549, self::INK);
    }

    private function text(string $text, string $weight, int $size, int $x, int $y, string $colour): void
    {
        imagettftext($this->im, $size * self::S * 0.75, 0, $x * self::S, $y * self::S, $this->colour($colour), $this->font($weight), $text);
    }

    private function width(string $text, string $weight, int $size): int
    {
        $box = imagettfbbox($size * 0.75, 0, $this->font($weight), $text);

        return $box[2] - $box[0];
    }

    private function font(string $weight): string
    {
        return resource_path("fonts/Inter-$weight.otf");
    }

    /** Fit the bounding box of these GeoJSON geometries into a box, keeping shape (equirectangular, scaled by latitude). */
    private function fitTo(array $geometries, int $x, int $y, int $w, int $h): void
    {
        $pts = [];
        foreach ($geometries as $g) {
            array_push($pts, ...array_merge(...array_merge(...self::polygons($g))));
        }
        if (! $pts) {
            return;
        }
        $lons = array_column($pts, 0);
        $lats = array_column($pts, 1);
        $k = cos(deg2rad((min($lats) + max($lats)) / 2));
        $gw = max((max($lons) - min($lons)) * $k, 1e-9);
        $gh = max(max($lats) - min($lats), 1e-9);
        $scale = min($w / $gw, $h / $gh) * self::S;
        $this->fit = [
            min($lons), max($lats), $scale,
            ($x + ($w - $gw * $scale / self::S) / 2) * self::S,
            ($y + ($h - $gh * $scale / self::S) / 2) * self::S,
            $k,
        ];
    }

    /** @return array{0: float, 1: float} */
    private function project(array $p): array
    {
        [$lon0, $lat0, $scale, $ox, $oy, $k] = $this->fit;

        return [$ox + ($p[0] - $lon0) * $k * $scale, $oy + ($lat0 - $p[1]) * $scale];
    }

    /**
     * Fill every polygon with a tint of the colour (opaque, so overlapping
     * parts do not darken; holes go back to the ground), then stroke every
     * ring with round joins. A zero opacity or width skips that step.
     */
    private function shape(?array $geometry, string $colour, float $fillOpacity, int $strokePx): void
    {
        if (! $geometry) {
            return;
        }
        $stroke = $this->colour($colour);
        $tint = $this->tint($colour, $fillOpacity);
        $r = $strokePx * self::S;
        $polygons = array_map(
            fn ($rings) => array_map(fn ($ring) => array_merge(...array_map(fn ($p) => $this->project($p), $ring)), $rings),
            self::polygons($geometry)
        );
        foreach ($fillOpacity > 0 ? $polygons : [] as $rings) {
            foreach ($rings as $i => $ring) {
                if (count($ring) >= 6) {
                    imagefilledpolygon($this->im, $ring, $i === 0 ? $tint : $this->colour(self::GROUND));
                }
            }
        }
        imagesetthickness($this->im, max($r, 1));
        foreach ($r > 0 ? array_merge(...$polygons) : [] as $ring) {
            for ($i = 0; $i + 3 < count($ring); $i += 2) {
                imageline($this->im, (int) $ring[$i], (int) $ring[$i + 1], (int) $ring[$i + 2], (int) $ring[$i + 3], $stroke);
                if ($r > 2) {
                    imagefilledellipse($this->im, (int) $ring[$i], (int) $ring[$i + 1], $r, $r, $stroke);
                }
            }
        }
        imagesetthickness($this->im, 1);
    }

    /** A bar of a round length (100 m to 100 km) about a quarter of the drawing's width, under it. */
    private function scaleBar(int $x, int $y, int $w): void
    {
        $metresPerPx = 111_320 / ($this->fit[2] / self::S);
        $target = $metresPerPx * $w / 4;
        $len = 100;
        foreach ([100, 200, 500, 1000, 2000, 5000, 10_000, 20_000, 50_000, 100_000] as $m) {
            if ($m <= $target) {
                $len = $m;
            }
        }
        $px = $len / $metresPerPx;
        $x0 = ($x + $w - $px) * self::S;
        imagefilledrectangle($this->im, (int) $x0, $y * self::S, (int) (($x + $w) * self::S), ($y + 4) * self::S, $this->colour('#525252'));
        $label = $len >= 1000 ? ($len / 1000).' km' : $len.' m';
        $this->text($label, 'Medium', 18, (int) ($x + $w - $px), $y - 10, '#525252');
    }

    /** Polygon or MultiPolygon as a list of polygons, each a list of rings. */
    private static function polygons(array $g): array
    {
        return match ($g['type']) {
            'Polygon' => [$g['coordinates']],
            'MultiPolygon' => $g['coordinates'],
            'GeometryCollection' => array_merge(...array_map(self::polygons(...), $g['geometries'])),
            default => [],
        };
    }

    private function colour(string $hex): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocate($this->im, $r, $g, $b);
    }

    /** The colour at this opacity over the ground, as an opaque colour. */
    private function tint(string $hex, float $opacity): int
    {
        $c = sscanf($hex, '#%02x%02x%02x');
        $g = sscanf(self::GROUND, '#%02x%02x%02x');
        [$r, $gr, $b] = array_map(fn ($i) => (int) round($g[$i] + ($c[$i] - $g[$i]) * $opacity), [0, 1, 2]);

        return imagecolorallocate($this->im, $r, $gr, $b);
    }

    private function png(): string
    {
        $out = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($out, $this->im, 0, 0, 0, 0, self::W, self::H, self::W * self::S, self::H * self::S);
        ob_start();
        imagepng($out, null, 6);

        return ob_get_clean();
    }
}
