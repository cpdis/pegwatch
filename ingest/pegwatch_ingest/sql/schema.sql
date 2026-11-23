-- Pegwatch ingest schema. Idempotent: safe to run before every ingest.
create extension if not exists postgis;

-- CREATE INDEX IF NOT EXISTS takes a SHARE lock on the table before it finds
-- the index, so a re-run queues behind any open writer (a deploy's migration
-- during the nightly snapshot) and blocks that table's writes meanwhile. Check
-- the catalogue first, like the ALTER guard below.
create or replace function pw_ensure_index(name text, definition text) returns void
language plpgsql as $$
begin
  if to_regclass(name) is null then
    execute format('create index %I on %s', name, definition);
  end if;
end $$;

-- Dates in the SLIP layers are local (AWST) calendar dates encoded as
-- midnight-UTC epoch milliseconds, with times in separate HH:MM:SS strings.
create or replace function pw_date(ms jsonb) returns date
language sql immutable as $$
  select case when ms is null or ms = 'null'::jsonb then null
              else (to_timestamp((ms #>> '{}')::double precision / 1000) at time zone 'UTC')::date end
$$;

-- Local wall-clock timestamp (Australia/Perth) from a date + time string.
-- 31 Dec 2999 is SLIP's placeholder for "no date" (pending grant and expiry): null.
create or replace function pw_local_ts(ms jsonb, hhmmss jsonb) returns timestamp
language sql immutable as $$
  select case when pw_date(ms) is null or pw_date(ms) >= date '2999-01-01' then null
              else pw_date(ms) + coalesce(nullif(trim(hhmmss #>> '{}'), '')::time, time '00:00') end
$$;

-- Timestamps (extract stamps, release times) are encoded the same way: AWST
-- wall-clock time written as if it were UTC. Release pending rows show 08:30,
-- matching the department's 8:30am release time; the live extract is stamped
-- about 4am, before that day's lodgements (checked against Mining Notices).
create or replace function pw_ts_local(ms jsonb) returns timestamptz
language sql immutable as $$
  select case when ms is null or ms = 'null'::jsonb then null
              else (to_timestamp((ms #>> '{}')::double precision / 1000) at time zone 'UTC')
                   at time zone 'Australia/Perth' end
$$;

create or replace function pw_text(v jsonb) returns text
language sql immutable as $$ select nullif(trim(v #>> '{}'), '') $$;

-- True when a holder name looks like a company, so it can be shown in full.
-- Anything else is treated as a natural person (shown by name, never address).
create or replace function pw_is_company(name text) returns boolean
language sql immutable as $$
  select name ~* '(\mPTY\M|\mLTD\M|\mLIMITED\M|\mCORP\M|\mINC\M|\mN\.?L\.?$|\mCOMPANY\M|\mTRUST\M|\mHOLDINGS\M|\mMINING\M|\mRESOURCES\M|\mMINERALS\M|\mGROUP\M|\mPARTNERSHIP\M|\mSHIRE\M|\mCOUNCIL\M|\mSTATE OF\M|\mMINISTER\M|\mCOMMISSION\M|\mAUTHORITY\M|\mCORPORATION\M|\mBHP\M|\mFORTESCUE\M|\mCOOPERATIVE\M|\mASSOCIATION\M|\mINCORPORATED\M)'
$$;

-- Special prospecting licences for gold may sit on someone else's ground. The
-- live layer flags them in special_in; their IDs end in S ("P 27/2688-S").
create or replace function pw_special_prospecting(type_code text, special_ind text, fmt_tenid text)
returns boolean language sql immutable as $$
  select coalesce(type_code = 'P' and (trim(special_ind) = 'S' or fmt_tenid ~ '[0-9]-?S$'), false)
$$;

-- Whether a tenement closes its ground to others. Miscellaneous licences,
-- general purpose leases and special prospecting licences may overlap other tenure.
create or replace function pw_blocks_ground(type_code text, special_ind text, fmt_tenid text)
returns boolean language sql immutable as $$
  select type_code not in ('L', 'G') and not pw_special_prospecting(type_code, special_ind, fmt_tenid)
$$;
drop function if exists pw_blocks_ground(text);

create table if not exists ingest_runs (
  id            bigserial primary key,
  started_at    timestamptz not null default now(),
  finished_at   timestamptz,
  status        text not null default 'running',     -- running | ok | failed | baseline
  tenements_extract timestamptz,
  previous_extract  timestamptz,
  stale         boolean,                             -- extract unchanged since last run
  notes         jsonb not null default '{}'::jsonb
);

create table if not exists layer_extracts (
  run_id       bigint not null references ingest_runs(id) on delete cascade,
  layer_code   text not null,
  layer_key    text not null,
  expected_rows integer,
  fetched_rows integer not null,
  max_extract  timestamptz,
  fetched_at   timestamptz not null default now(),
  primary key (run_id, layer_code)
);

-- Current snapshot of live and pending tenements; rotated to tenements_prev each run.
create table if not exists tenements (
  tenid        text primary key,
  fmt_tenid    text not null,
  type         text,
  type_code    text,            -- E, P, M, L, G, ...
  status       text,            -- LIVE | PENDING
  survstatus   text,
  holders      text[] not null default '{}',
  holder_is_company boolean[] not null default '{}',
  legal_area   numeric,
  area_unit    text,
  special_ind  text,
  extract_at   timestamptz,
  applied_at   timestamp,       -- startdate + starttime (local), application lodgement for pending
  granted_at   timestamp,
  ends_at      timestamp,
  area_ha      double precision,
  geom         geometry(MultiPolygon, 4326)
);
select pw_ensure_index('tenements_geom_idx', 'tenements using gist (geom)');

create table if not exists tenements_prev (like tenements including all);

-- Accumulating record of dead tenements (recent window nightly, full history once).
create table if not exists dead_tenements (
  tenid        text not null,
  ended_at     timestamp not null,
  fmt_tenid    text,
  type         text,
  death_reason text,
  holder_name  text,
  holder_is_company boolean,
  started_at   timestamp,
  granted_at   timestamp,
  extract_at   timestamptz,
  first_seen_run bigint,
  geom         geometry(MultiPolygon, 4326),
  primary key (tenid, ended_at)
);
-- The DEAD layer has one row per holder; all holders of a death are kept here.
-- ALTER TABLE takes an ACCESS EXCLUSIVE lock even when the column exists, so
-- check the catalogue first: otherwise the nightly re-run queues behind any
-- open read and every later read queues behind it.
do $$
begin
  if not exists (select 1 from information_schema.columns
                 where table_schema = current_schema() and table_name = 'dead_tenements'
                   and column_name = 'holders') then
    alter table dead_tenements add column holders text[] not null default '{}';
  end if;
  if not exists (select 1 from information_schema.columns
                 where table_schema = current_schema() and table_name = 'dead_tenements'
                   and column_name = 'holders_are_company') then
    alter table dead_tenements add column holders_are_company boolean[] not null default '{}';
  end if;
end $$;
select pw_ensure_index('dead_tenements_geom_idx', 'dead_tenements using gist (geom)');
select pw_ensure_index('dead_tenements_tenid_idx', 'dead_tenements (tenid)');
-- Dead-history start (min(ended_at)), recent deaths and the freed-ground window.
select pw_ensure_index('dead_tenements_ended_idx', 'dead_tenements (ended_at)');

create table if not exists release_pending (
  tenid text not null, rlesid text not null, fmt_tenid text, ten_type text,
  rles_order text, rles_status text, release_at timestamptz,
  geom geometry(MultiPolygon, 4326),
  primary key (tenid, rlesid)
);
create table if not exists release_pending_prev (like release_pending including all);

create table if not exists amalgamation_pending (
  tenid text not null, amalgid text not null, fmt_tenid text, ten_type text,
  status text, starts_at timestamptz, ends_at timestamptz,
  geom geometry(MultiPolygon, 4326),
  primary key (tenid, amalgid)
);
create table if not exists amalgamation_pending_prev (like amalgamation_pending including all);

create table if not exists restoration_pending (
  tenid text not null, restnid text not null, fmt_tenid text, ten_type text,
  status text, starts_at timestamptz, ends_at timestamptz,
  geom geometry(MultiPolygon, 4326),
  primary key (tenid, restnid)
);

-- Land closed or restricted for mining (s19, s57(4), s57(2aa), FNAs, clearance areas).
create table if not exists land_restrictions (
  kind     text not null,     -- s19 | s57_4 | s57_2aa | file_notation | clearance
  land_id  text,
  name     text,
  purpose  text,
  status   text,
  props    jsonb,
  geom     geometry(MultiPolygon, 4326)
);
select pw_ensure_index('land_restrictions_geom_idx', 'land_restrictions using gist (geom)');

create table if not exists tenement_events (
  id           bigserial primary key,
  run_id       bigint not null references ingest_runs(id) on delete cascade,
  event_type   text not null,
  -- application | death | grant | holder_change | shrink | grow
  -- | release_scheduled | release_cancelled | reappeared | status_change
  tenid        text not null,
  fmt_tenid    text,
  tenement_type text,
  holders      text[],
  extract_at   timestamptz,
  occurred_at  timestamp,        -- best known local time of the underlying change
  details      jsonb not null default '{}'::jsonb,
  geom         geometry(MultiPolygon, 4326),
  created_at   timestamptz not null default now()
);
select pw_ensure_index('tenement_events_run_idx', 'tenement_events (run_id)');
select pw_ensure_index('tenement_events_type_idx', 'tenement_events (event_type, created_at)');
select pw_ensure_index('tenement_events_geom_idx', 'tenement_events using gist (geom)');
