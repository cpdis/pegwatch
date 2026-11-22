"""Postgres helpers: schema, staging loads, and running the SQL steps."""
from __future__ import annotations

import json
import logging
from collections.abc import Iterable
from importlib import resources

import psycopg

from .layers import Layer

log = logging.getLogger(__name__)


def sql_file(name: str) -> str:
    return resources.files("pegwatch_ingest").joinpath("sql", name).read_text()


def connect(dsn: str) -> psycopg.Connection:
    return psycopg.connect(dsn, autocommit=False)


def apply_schema(conn: psycopg.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute(sql_file("schema.sql"))
    conn.commit()


def run_script(conn: psycopg.Connection, name: str, run_id: int) -> None:
    """Run a multi-statement SQL file, substituting :run_id (an int we control)."""
    sql = sql_file(name).replace(":run_id", str(int(run_id)))
    with conn.cursor() as cur:
        cur.execute(sql)


def create_staging(conn: psycopg.Connection, layer: Layer) -> None:
    with conn.cursor() as cur:
        cur.execute(f"drop table if exists stg_{layer.key}")
        cur.execute(f"create unlogged table stg_{layer.key} (oid bigint, props jsonb, gj text)")
    conn.commit()


def load_features(conn: psycopg.Connection, layer: Layer, features: Iterable[dict]) -> int:
    """COPY GeoJSON features into stg_<key>. Returns the row count."""
    n = 0
    with conn.cursor() as cur:
        with cur.copy(f"copy stg_{layer.key} (oid, props, gj) from stdin") as copy:
            for f in features:
                props = f.get("properties") or {}
                oid = props.get(layer.oid_field, f.get("id"))
                geom = f.get("geometry")
                copy.write_row((oid, json.dumps(props), json.dumps(geom) if geom else None))
                n += 1
    conn.commit()
    return n


def max_extract(conn: psycopg.Connection, layer: Layer):
    if not layer.extract_field:
        return None
    with conn.cursor() as cur:
        cur.execute(
            f"select max(pw_ts_utc(props->%s)) from stg_{layer.key}", (layer.extract_field,))
        return cur.fetchone()[0]


def scalar(conn: psycopg.Connection, sql: str, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        row = cur.fetchone()
        return row[0] if row else None
