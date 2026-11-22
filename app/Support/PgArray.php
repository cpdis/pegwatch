<?php

namespace App\Support;

/** Parse Postgres text[] / boolean[] literals returned by PDO. */
class PgArray
{
    public static function parse(?string $literal): array
    {
        if ($literal === null || $literal === '{}') {
            return [];
        }
        $inner = substr($literal, 1, -1);
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);

        return array_map(function ($match) {
            if (isset($match[2]) && $match[2] !== '') {
                $v = $match[2];

                return match ($v) {
                    't' => true, 'f' => false, 'NULL' => null, default => $v,
                };
            }

            return stripcslashes($match[1]);
        }, $m);
    }
}
