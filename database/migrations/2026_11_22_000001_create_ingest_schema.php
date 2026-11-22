<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ingest (ingest/pegwatch_ingest) owns the tenement tables and creates
 * them itself. Running the same idempotent schema here means a fresh app
 * database, or the test database, has them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(base_path('ingest/pegwatch_ingest/sql/schema.sql')));
        DB::unprepared(<<<'SQL'
            create table if not exists mining_notices (
              fmt_tenid text primary key, received_at timestamptz, area_text text, shire text,
              applicants text[], link text, first_seen_run bigint,
              first_seen_at timestamptz not null default now()
            );
            create or replace function pw_slug(t text) returns text language sql immutable as $$
              select lower(trim(both '-' from regexp_replace(t, '[^A-Za-z0-9]+', '-', 'g')))
            $$;
            create index if not exists tenements_slug_idx on tenements (pw_slug(fmt_tenid));
            create index if not exists dead_tenements_slug_idx on dead_tenements (pw_slug(fmt_tenid));
            -- Schema-qualified: Postgres 17+ builds indexes with search_path
            -- pinned to pg_catalog, so an unqualified pw_slug isn't found.
            create or replace function pw_slugs(t text[]) returns text[] language sql immutable as $$
              select coalesce(array_agg(public.pw_slug(x)), '{}') from unnest(t) x
            $$;
            create index if not exists dead_tenements_holder_slugs_idx on dead_tenements using gin (pw_slugs(holders));
            create index if not exists tenements_holders_idx on tenements using gin (holders);
            create index if not exists tenement_events_tenid_idx on tenement_events (tenid);
        SQL);
    }

    public function down(): void
    {
        // Ingest-owned tables are left alone.
    }
};
