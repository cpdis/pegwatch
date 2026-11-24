<?php

namespace App\Agent;

use App\Models\WatchArea;
use App\Support\Register;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Input for the agent surfaces (API, MCP): one set of rules, so a call means
 * the same thing through either. Lists may be arrays or comma-separated
 * strings, and a box is "west,south,east,north" in WGS84 degrees.
 */
final class Parse
{
    public const SHAPE_MISSING = 'Give a tenement ID, a holder, or latitude and longitude inside Western Australia.';

    private const LISTS = ['kinds', 'statuses', 'types', 'alerts'];

    /** Validate agent input after turning comma lists and a "w,s,e,n" box into arrays. */
    public static function validate(array $in, array $rules): array
    {
        foreach ([...self::LISTS, 'bbox'] as $key) {
            if (is_string($in[$key] ?? null)) {
                $in[$key] = array_values(array_filter(array_map('trim', explode(',', $in[$key])), fn ($v) => $v !== ''));
            }
        }
        if (isset($in['statuses']) && is_array($in['statuses'])) {
            $in['statuses'] = array_map(fn ($s) => is_string($s) ? strtoupper($s) : $s, $in['statuses']);
        }
        if (isset($in['kinds']) && is_array($in['kinds'])) {
            $in['kinds'] = array_map(fn ($s) => is_string($s) && $s !== 'other' ? strtoupper($s) : $s, $in['kinds']);
        }

        return Validator::make($in, $rules)->validate();
    }

    public static function bboxRule(): array
    {
        return ['nullable', 'array', 'size:4', function ($attr, $v, $fail) {
            if (! is_array($v) || count(array_filter($v, 'is_numeric')) !== 4) {
                return $fail('The box is west,south,east,north in degrees.');
            }
            [$w, $s, $e, $n] = array_map('floatval', array_values($v));
            if ($w >= $e || $s >= $n || $w < 100 || $e > 140 || $s < -45 || $n > -5) {
                $fail('The box must be west,south,east,north in degrees, around Western Australia.');
            }
        }];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    public static function bbox(?array $v): ?array
    {
        return $v ? array_map('floatval', array_values($v)) : null;
    }

    public static function tenementFilterRules(): array
    {
        return [
            'bbox' => self::bboxRule(),
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in([...Register::KINDS, 'other'])],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in(['LIVE', 'PENDING'])],
            'applied_from' => ['nullable', 'date_format:Y-m-d'],
            'applied_to' => ['nullable', 'date_format:Y-m-d'],
            'expires_within_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'min_ha' => ['nullable', 'numeric', 'min:0'],
            'max_ha' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** The map filter (Register::tenementWhere) from validated input. */
    public static function tenementFilter(array $v): array
    {
        return array_filter([
            'kinds' => $v['kinds'] ?? null,
            'statuses' => $v['statuses'] ?? null,
            'appliedFrom' => $v['applied_from'] ?? null,
            'appliedTo' => $v['applied_to'] ?? null,
            'expiresWithinMonths' => isset($v['expires_within_months']) ? (int) $v['expires_within_months'] : null,
            'minHa' => isset($v['min_ha']) ? (float) $v['min_ha'] : null,
            'maxHa' => isset($v['max_ha']) ? (float) $v['max_ha'] : null,
        ], fn ($x) => $x !== null && $x !== []);
    }

    public static function activityRules(): array
    {
        return [
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(array_keys(Register::EVENT_LABELS))],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in([...Register::KINDS, 'other'])],
            'field' => ['nullable', 'string', Rule::in(array_keys(config('mineral_fields')))],
            'holder' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'bbox' => self::bboxRule(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Lookup::MAX_LIST],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** The feed filter (Register::feed) from validated input. */
    public static function activityFilter(array $v): array
    {
        return array_filter([
            'types' => $v['types'] ?? null,
            'kinds' => $v['kinds'] ?? null,
            'field' => $v['field'] ?? null,
            'holder' => $v['holder'] ?? null,
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'bbox' => self::bbox($v['bbox'] ?? null),
        ], fn ($x) => $x !== null && $x !== []);
    }

    public static function pointRules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-36,-13'],
            'lng' => ['required', 'numeric', 'between:112,130'],
        ];
    }

    /** A watch shape: one of tenement, holder, or latitude + longitude (+ radius_km). */
    public static function shapeRules(): array
    {
        return [
            'tenement' => ['nullable', 'string', 'max:40'],
            'holder' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'required_with:latitude'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:50'],
        ];
    }

    /** A new watch's options, on top of shapeRules(). */
    public static function watchOptionRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
            'alerts' => ['nullable', 'array', 'list', 'min:1'],
            'alerts.*' => [Rule::in(array_keys(WatchArea::FREE_ALERTS))],
            'include_covered_deaths' => ['nullable', 'boolean'],
        ];
    }
}
