"""Run summaries for the spike log and the GitHub Actions job summary."""
from __future__ import annotations

import datetime as dt


def _rows(conn, sql, params=None):
    with conn.cursor() as cur:
        cur.execute(sql, params)
        cols = [c.name for c in cur.description]
        return [dict(zip(cols, r)) for r in cur.fetchall()]


def summarise(conn, run_id: int) -> dict:
    run = _rows(conn, "select * from ingest_runs where id = %s", (run_id,))[0]
    layers = _rows(conn, "select layer_code, layer_key, expected_rows, fetched_rows, max_extract"
                         " from layer_extracts where run_id = %s order by layer_code", (run_id,))
    counts = _rows(conn, "select event_type, count(*) as n from tenement_events"
                         " where run_id = %s group by 1 order by 2 desc", (run_id,))
    deaths = _rows(conn, """
        select coalesce(details->>'kind', '?') as kind,
               coalesce(details->>'death_reason', 'not yet in DEAD layer') as reason,
               count(*) as n,
               count(*) filter (where (details->>'covered_any_share')::numeric >= 0.95) as covered_95,
               count(*) filter (where (details->>'uncovered_share')::numeric >= 0.95) as uncovered_95
        from tenement_events where run_id = %s and event_type = 'death'
        group by 1, 2 order by 3 desc""", (run_id,))
    applications = _rows(conn, """
        select fmt_tenid, tenement_type, holders[1] as holder, occurred_at,
               (details->>'area_ha')::numeric as area_ha
        from tenement_events where run_id = %s and event_type = 'application'
        order by occurred_at desc nulls last limit 50""", (run_id,))
    totals = _rows(conn, "select status, count(*) as n from tenements group by 1 order by 1")
    history = _rows(conn, """
        select r.id, r.started_at, r.status, r.tenements_extract, r.stale,
               r.notes->'notices' as notices,
               (select count(*) from tenement_events e where e.run_id = r.id and e.event_type = 'application') as applications,
               (select count(*) from tenement_events e where e.run_id = r.id and e.event_type = 'death') as deaths,
               (select count(*) from tenement_events e where e.run_id = r.id) as events
        from ingest_runs r order by r.id desc limit 30""")
    return {
        "history": history,
        "run": run, "layers": layers, "event_counts": counts, "deaths": deaths,
        "applications": applications, "tenement_totals": totals,
    }


def _fmt(v) -> str:
    if isinstance(v, dt.datetime):
        return v.strftime("%Y-%m-%d %H:%M")
    return "" if v is None else str(v)


def markdown(s: dict) -> str:
    run = s["run"]
    out = [f"## Pegwatch ingest run {run['id']}: {run['status']}", ""]
    out.append(f"- Live layer extract: **{_fmt(run['tenements_extract'])} UTC**"
               f" (previous {_fmt(run['previous_extract']) or 'none'})")
    if run.get("stale"):
        out.append("- **Stale:** the live layer has not been refreshed since the last run.")
    out.append("- Tenements: " + ", ".join(f"{t['status']} {t['n']:,}" for t in s["tenement_totals"]))
    n = (run.get("notes") or {}).get("notices")
    if n and "error" not in n:
        out.append(f"- Mining Notices cross-check: {n['matched']} of {n['lodged_before_extract']} applications"
                   f" lodged before the extract date are in the layer; {n['lodged_after_extract']} lodged since"
                   + (f"; missing: {', '.join(n['missing'])}" if n["missing"] else ""))
    elif n:
        out.append(f"- Mining Notices cross-check failed: {n['error']}")
    out += ["", "| Layer | Rows | Extract |", "| --- | ---: | --- |"]
    out += [f"| {l['layer_code']} {l['layer_key']} | {l['fetched_rows']:,} | {_fmt(l['max_extract'])} |"
            for l in s["layers"]]
    if run["status"] == "baseline":
        out += ["", "Baseline run: no previous snapshot, so no events."]
        return "\n".join(out + _history(s)) + "\n"
    out += ["", "### Events", "", "| Type | Count |", "| --- | ---: |"]
    out += [f"| {c['event_type']} | {c['n']} |" for c in s["event_counts"]] or ["| none | 0 |"]
    if s["deaths"]:
        out += ["", "### Deaths", "", "| Kind | Reason | Count | ≥95% still covered | ≥95% uncovered |",
                "| --- | --- | ---: | ---: | ---: |"]
        out += [f"| {d['kind']} | {d['reason']} | {d['n']} | {d['covered_95']} | {d['uncovered_95']} |"
                for d in s["deaths"]]
    if s["applications"]:
        out += ["", "### New applications", "", "| Tenement | Type | First holder | Lodged | ha |",
                "| --- | --- | --- | --- | ---: |"]
        out += [f"| {a['fmt_tenid']} | {a['tenement_type']} | {a['holder'] or ''} | {_fmt(a['occurred_at'])}"
                f" | {_fmt(a['area_ha'])} |" for a in s["applications"]]
    return "\n".join(out + _history(s)) + "\n"


def _history(s: dict) -> list[str]:
    out = ["", "### Spike log (last 30 runs)", "",
           "| Run | Started (UTC) | Status | Live extract (UTC) | Stale | Apps | Deaths | Events | Notices matched |",
           "| ---: | --- | --- | --- | --- | ---: | ---: | ---: | --- |"]
    for h in s.get("history", []):
        n = h.get("notices") or {}
        nm = f"{n['matched']}/{n['lodged_before_extract']}" if "matched" in n else ("error" if n else "")
        out.append(f"| {h['id']} | {_fmt(h['started_at'])} | {h['status']} | {_fmt(h['tenements_extract'])}"
                   f" | {'yes' if h['stale'] else ''} | {h['applications']} | {h['deaths']} | {h['events']} | {nm} |")
    return out
