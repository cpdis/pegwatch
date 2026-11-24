<?php

namespace App\Agent;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Shapes the register's data for agents: one rule for every date, and
 * geometry left out unless asked for. The API, the MCP server and the
 * Markdown pages all pass their payloads through here.
 */
final class AgentJson
{
    /** Keys that hold GeoJSON or derived shapes; dropped unless the caller asks for geometry. */
    private const GEOMETRY_KEYS = ['geometry', 'focus', 'focus_dead', 'highlight'];

    /** Keys used only by the pages (row keys, colours), never useful to an agent. */
    private const PAGE_KEYS = ['key'];

    /**
     * Register dates are AWST wall-clock values stored without a zone; they
     * come back as "2024-03-01" (midnight) or "2024-03-01T08:30:00+08:00".
     * Timestamps with a zone (when an extract was taken or a change seen)
     * come back in AWST with their offset. 31 Dec 2999, the register's "no
     * date", is null.
     */
    public static function clean(mixed $value, bool $geometry = false): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && (in_array($k, self::PAGE_KEYS, true) || (! $geometry && in_array($k, self::GEOMETRY_KEYS, true)))) {
                    continue;
                }
                $out[$k] = self::clean($v, $geometry);
            }

            return $out;
        }

        return is_string($value) ? self::date($value) : $value;
    }

    public static function date(string $v): ?string
    {
        // Wall-clock register value: "2024-03-01 00:00:00" (Postgres timestamp without time zone).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(\.\d+)?$/', $v, $m)) {
            if ((int) $m[1] >= 2999) {
                return null;
            }

            return "$m[4]:$m[5]:$m[6]" === '00:00:00' ? "$m[1]-$m[2]-$m[3]" : "$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]+08:00";
        }
        // A zoned timestamp ("2026-11-23 11:02:00+00" or ISO 8601 with an offset).
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:?\d{2})?|Z)$/', $v)) {
            try {
                return CarbonImmutable::parse($v)->setTimezone('Australia/Perth')->toIso8601String();
            } catch (Throwable) {
                return $v;
            }
        }

        return $v;
    }
}
