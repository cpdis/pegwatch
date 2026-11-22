-- Pegwatch ingest schema. Idempotent: safe to run before every ingest.
create extension if not exists postgis;

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
create index if not exists tenements_geom_idx on tenements using gist (geom);

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
alter table dead_tenements add column if not exists holders text[] not null default '{}';
alter table dead_tenements add column if not exists holders_are_company boolean[] not null default '{}';
create index if not exists dead_tenements_geom_idx on dead_tenements using gist (geom);
create index if not exists dead_tenements_tenid_idx on dead_tenements (tenid);

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
create index if not exists land_restrictions_geom_idx on land_restrictions using gist (geom);

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
create index if not exists tenement_events_run_idx on tenement_events (run_id);
create index if not exists tenement_events_type_idx on tenement_events (event_type, created_at);
create index if not exists tenement_events_geom_idx on tenement_events using gist (geom);
