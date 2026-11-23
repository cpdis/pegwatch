<?php

namespace App\Support;

/**
 * The /activity filters from a query string, shared by the page and its RSS
 * feed. Anything malformed is dropped, so a bad link shows the whole feed
 * rather than an error.
 */
class FeedFilter
{
    /**
     * @param  array{types?: mixed, type?: mixed, kinds?: mixed, field?: mixed, holder?: mixed, range?: mixed}  $in
     * @return array{types?: list<string>, kinds?: list<string>, field?: string, holder?: string, from?: string, to?: string}
     */
    public static function from(array $in): array
    {
        // `type` is the old single-type parameter; links with it keep working.
        $types = self::strings($in['types'] ?? (isset($in['type']) ? [$in['type']] : []));
        $range = is_array($in['range'] ?? null) ? $in['range'] : [];
        $holder = is_string($in['holder'] ?? null) ? trim(mb_substr($in['holder'], 0, 100)) : '';
        $field = is_string($in['field'] ?? null) ? $in['field'] : '';

        return array_filter([
            'types' => array_values(array_intersect($types, array_keys(Register::EVENT_LABELS))),
            'kinds' => array_values(array_intersect(self::strings($in['kinds'] ?? []), [...Register::KINDS, 'other'])),
            'field' => array_key_exists($field, config('mineral_fields')) ? $field : null,
            'holder' => Slug::make($holder) !== '' ? $holder : null,
            'from' => self::day($range['start'] ?? null),
            'to' => self::day($range['end'] ?? null),
        ]);
    }

    private static function strings(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    private static function day(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? $v : null;
    }
}
