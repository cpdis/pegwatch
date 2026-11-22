<?php

namespace App\Support;

/** Matches the pw_slug() SQL function, so URLs can be resolved by index. */
class Slug
{
    public static function make(string $text): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    }
}
