<?php

namespace App\Support;

/**
 * Holder names come straight from the register. Companies are shown in full;
 * individuals are shown by name only. Postal addresses are never ingested.
 */
class Holders
{
    public static function present(array $names, array $isCompany = []): array
    {
        return array_map(fn ($name, $i) => [
            'name' => $name,
            'slug' => Slug::make($name),
            'is_company' => (bool) ($isCompany[$i] ?? false),
        ], $names, array_keys($names));
    }
}
