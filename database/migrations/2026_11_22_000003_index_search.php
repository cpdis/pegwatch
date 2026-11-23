<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the Cmd+K search. Without them every keystroke scans both
 * tenement tables: about 0.6s per query once the full DEAD history
 * (~423k rows) is loaded.
 *
 * pw_compact() matches tenement IDs however they are typed ("E45/4894",
 * "e 45 4894"); a text_pattern_ops btree serves its prefix LIKE.
 * pw_holder_text() is the holders in pw_slug form (lower case, punctuation
 * folded to "-") joined into one string, so a trigram index can serve
 * substring matches on holder names; a match is then checked per name.
 * pg_trgm ships with Postgres and is a trusted extension, but the trigram indexes are skipped where it isn't
 * available: search still works, just unindexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function pw_compact(t text) returns text language sql immutable parallel safe as $$
              select regexp_replace(lower(t), '[^a-z0-9]', '', 'g')
            $$;
            create or replace function pw_holder_text(t text[]) returns text language sql immutable parallel safe as $$
              select lower(regexp_replace(array_to_string(t, ' | '), '[^A-Za-z0-9]+', '-', 'g'))
            $$;
            create index if not exists tenements_compact_idx on tenements (pw_compact(fmt_tenid) text_pattern_ops);
            create index if not exists dead_tenements_compact_idx on dead_tenements (pw_compact(fmt_tenid) text_pattern_ops);
        SQL);

        if (DB::selectOne("select 1 as ok from pg_available_extensions where name = 'pg_trgm'")) {
            DB::unprepared(<<<'SQL'
                create extension if not exists pg_trgm;
                create index if not exists tenements_holder_trgm_idx on tenements using gin (pw_holder_text(holders) gin_trgm_ops);
                create index if not exists dead_tenements_holder_trgm_idx on dead_tenements using gin (pw_holder_text(holders) gin_trgm_ops);
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index if exists tenements_compact_idx;
            drop index if exists dead_tenements_compact_idx;
            drop index if exists tenements_holder_trgm_idx;
            drop index if exists dead_tenements_holder_trgm_idx;
        SQL);
    }
};
